<?php

namespace GlpiPlugin\Assinatura;

use CommonDBTM;
use CommonITILValidation;
use CronTask;
use Document;
use Document_Item;
use ITILFollowup;
use RuntimeException;
use Session;
use Ticket;
use TicketValidation;
use Throwable;
use User;

/**
 * Uma solicitação de assinatura de um documento do chamado no LibreSign.
 */
class SignRequest extends CommonDBTM
{
    public const STATUS_LABELS = [
        'pending'  => ['Aguardando assinatura', 'warning', 'ti-clock'],
        'signed'   => ['Assinado', 'success', 'ti-circle-check'],
        'canceled' => ['Cancelado', 'secondary', 'ti-ban'],
        'error'    => ['Erro', 'danger', 'ti-alert-triangle'],
    ];

    public static function getTypeName($nb = 0)
    {
        return 'Assinatura digital';
    }

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_assinatura_signrequests';
    }

    /**
     * Envia o documento ao LibreSign e registra a solicitação.
     *
     * @param string $source 'ticket_pdf' (PDF do chamado, plugin PDF) ou 'document' (PDF anexado)
     */
    public static function createFor(Ticket $ticket, int $signerId, string $source, int $documentId, string $message): self
    {
        $signer = new User();
        if (!$signer->getFromDB($signerId)) {
            throw new RuntimeException('Assinante não encontrado.');
        }
        $email = (string) $signer->getDefaultEmail();
        if ($email === '') {
            throw new RuntimeException(sprintf('O usuário %s não tem e-mail cadastrado no GLPI.', $signer->getFriendlyName()));
        }
        $name = $signer->getFriendlyName();

        [$pdf, $docName] = $source === 'document'
            ? self::attachedPdf($ticket, $documentId)
            : [self::ticketPdf($ticket), sprintf('Chamado %d - %s', $ticket->getID(), $ticket->fields['name'])];

        $client = new LibreSignClient();
        $file = $client->requestSignature(
            mb_substr($docName, 0, 200),
            $pdf,
            [[
                'identifyMethods' => [['method' => 'email', 'value' => $email]],
                'displayName'     => $name,
                'description'     => $message,
                'notify'          => 1,
            ]]
        );
        if (empty($file['uuid'])) {
            throw new RuntimeException('O LibreSign não devolveu o identificador do documento.');
        }

        // bloco de assinatura visível na última página (sem ele a assinatura é só digital)
        $warning = null;
        try {
            $client->addVisibleSignature(
                $file['uuid'],
                (int) $file['id'],
                (int) ($file['signers'][0]['signRequestId'] ?? 0),
                max(1, preg_match_all('#/Type\s*/Page(?![a-z])#', $pdf))
            );
        } catch (Throwable $e) {
            $warning = 'Campo visível não criado (a assinatura digital continua válida): ' . $e->getMessage();
        }

        $req = new self();
        $req->add([
            'tickets_id'          => $ticket->getID(),
            'users_id'            => $signerId,
            'users_id_requester'  => Session::getLoginUserID() ?: 0,
            'signer_name'         => $name,
            'signer_email'        => $email,
            'source'              => $source,
            'documents_id_source' => $source === 'document' ? $documentId : 0,
            'file_uuid'           => $file['uuid'],
            'file_id'             => (int) ($file['id'] ?? 0),
            'status'              => 'pending',
            'message'             => $message,
            'last_error'          => $warning,
            'date_creation'       => $_SESSION['glpi_currenttime'],
            'date_mod'            => $_SESSION['glpi_currenttime'],
        ]);

        self::followup($ticket->getID(), sprintf(
            'Assinatura digital solicitada a <b>%s</b> (%s) via LibreSign. Documento: %s.',
            htmlspecialchars($name),
            htmlspecialchars($email),
            htmlspecialchars($docName)
        ));
        return $req;
    }

    /** Consulta o LibreSign; se assinado, anexa o PDF assinado ao chamado. */
    public function sync(): string
    {
        if ($this->fields['status'] !== 'pending') {
            return $this->fields['status'];
        }
        try {
            $client = new LibreSignClient();
            $data = $client->validate($this->fields['file_uuid']);
            $signers = $data['signers'] ?? [];
            $allSigned = $signers !== [] && count(array_filter($signers, fn($s) => !empty($s['signed']))) === count($signers);
            if (!$allSigned && (int) ($data['status'] ?? 0) !== 3) {
                return 'pending';
            }

            $pdf = $client->downloadPdf($this->fields['file_uuid']);
            $signedAt = $signers[0]['signed'] ?? null;
            $docId = self::attachToTicket(
                (int) $this->fields['tickets_id'],
                sprintf('chamado-%d-assinado-%s.pdf', $this->fields['tickets_id'], date('Ymd-His')),
                $pdf,
                sprintf('Documento assinado digitalmente por %s via LibreSign', $this->fields['signer_name'])
            );

            $this->update([
                'id'                  => $this->getID(),
                'status'              => 'signed',
                'documents_id_signed' => $docId,
                'date_signed'         => $signedAt ? date('Y-m-d H:i:s', strtotime($signedAt)) : $_SESSION['glpi_currenttime'],
                'last_error'          => null,
                'date_mod'            => $_SESSION['glpi_currenttime'],
            ]);
            self::followup((int) $this->fields['tickets_id'], sprintf(
                '✅ Documento <b>assinado digitalmente</b> por <b>%s</b>. O PDF assinado foi anexado ao chamado.',
                htmlspecialchars($this->fields['signer_name'])
            ));
            $this->acceptValidation();
            return 'signed';
        } catch (Throwable $e) {
            $this->update(['id' => $this->getID(), 'last_error' => $e->getMessage(), 'date_mod' => $_SESSION['glpi_currenttime']]);
            return 'pending';
        }
    }

    public function cancel(): void
    {
        if ($this->fields['status'] === 'pending' && (int) $this->fields['file_id'] > 0) {
            (new LibreSignClient())->delete((int) $this->fields['file_id']);
        }
        $this->update(['id' => $this->getID(), 'status' => 'canceled', 'date_mod' => $_SESSION['glpi_currenttime']]);
        self::followup((int) $this->fields['tickets_id'], sprintf(
            'Solicitação de assinatura para <b>%s</b> cancelada.',
            htmlspecialchars($this->fields['signer_name'])
        ));
    }

    /** Aprova a validação pendente do assinante, se configurado. */
    private function acceptValidation(): void
    {
        if (Settings::get('accept_validation', '1') !== '1') {
            return;
        }
        $validation = new TicketValidation();
        foreach ($validation->find([
            'tickets_id'        => $this->fields['tickets_id'],
            'users_id_validate' => $this->fields['users_id'],
            'status'            => CommonITILValidation::WAITING,
        ]) as $row) {
            $validation->update([
                'id'                 => $row['id'],
                'status'             => CommonITILValidation::ACCEPTED,
                'comment_validation' => 'Aprovado por assinatura digital (LibreSign).',
                'validation_date'    => $_SESSION['glpi_currenttime'],
            ]);
        }
    }

    public static function cronInfo($name)
    {
        return ['description' => 'Sincroniza o status das assinaturas pendentes no LibreSign'];
    }

    /** Ação automática: sincroniza todas as solicitações pendentes. */
    public static function cronSyncsignatures(CronTask $task): int
    {
        if (!Settings::isConfigured()) {
            return 0;
        }
        $req = new self();
        $done = 0;
        foreach ($req->find(['status' => 'pending'], ['id ASC'], 50) as $row) {
            $item = new self();
            if ($item->getFromDB($row['id']) && $item->sync() === 'signed') {
                $done++;
            }
            $task->addVolume(1);
        }
        $task->log("$done assinatura(s) concluída(s)");
        return 1;
    }

    private static function ticketPdf(Ticket $ticket): string
    {
        global $PLUGIN_HOOKS;
        $class = $PLUGIN_HOOKS['plugin_pdf']['Ticket'] ?? null;
        if (!$class || !class_exists($class)) {
            throw new RuntimeException('O plugin PDF é necessário para gerar o documento do chamado.');
        }
        $pdf = (new $class(new Ticket()))->generatePDF([$ticket->getID()], ['Ticket$main'], 0, false);
        if (!is_string($pdf) || !str_starts_with($pdf, '%PDF')) {
            throw new RuntimeException('Não foi possível gerar o PDF do chamado.');
        }
        return $pdf;
    }

    /** @return array{0:string,1:string} conteúdo e nome do PDF anexado */
    private static function attachedPdf(Ticket $ticket, int $documentId): array
    {
        $link = new Document_Item();
        if (!$link->getFromDBByCrit(['documents_id' => $documentId, 'itemtype' => Ticket::class, 'items_id' => $ticket->getID()])) {
            throw new RuntimeException('O documento escolhido não está anexado a este chamado.');
        }
        $doc = new Document();
        $doc->getFromDB($documentId);
        $path = GLPI_DOC_DIR . '/' . $doc->fields['filepath'];
        if (!is_file($path) || $doc->fields['mime'] !== 'application/pdf') {
            throw new RuntimeException('Somente documentos PDF podem ser enviados para assinatura.');
        }
        return [file_get_contents($path), pathinfo($doc->fields['filename'], PATHINFO_FILENAME)];
    }

    private static function attachToTicket(int $ticketId, string $filename, string $content, string $comment): int
    {
        $ticket = new Ticket();
        $ticket->getFromDB($ticketId);
        $prefix = uniqid('', true);
        file_put_contents(GLPI_TMP_DIR . '/' . $prefix . $filename, $content);

        $doc = new Document();
        $id = $doc->add([
            'name'              => pathinfo($filename, PATHINFO_FILENAME),
            'comment'           => $comment,
            'entities_id'       => $ticket->fields['entities_id'],
            'is_recursive'      => 0,
            'itemtype'          => Ticket::class,
            'items_id'          => $ticketId,
            '_filename'         => [$prefix . $filename],
            '_prefix_filename'  => [$prefix],
            '_no_message'       => true,
        ]);
        if (!$id) {
            throw new RuntimeException('Não foi possível anexar o PDF assinado ao chamado.');
        }
        return (int) $id;
    }

    private static function followup(int $ticketId, string $html): void
    {
        (new ITILFollowup())->add([
            'itemtype'   => Ticket::class,
            'items_id'   => $ticketId,
            'content'    => $html,
            'is_private' => 0,
            '_no_notif'  => true,
        ]);
    }
}
