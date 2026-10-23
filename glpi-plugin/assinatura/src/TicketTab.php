<?php

namespace GlpiPlugin\Assinatura;

use CommonGLPI;
use Document;
use Document_Item;
use Html;
use Session;
use Ticket;
use User;

/**
 * Aba "Assinatura digital" no chamado.
 */
class TicketTab extends CommonGLPI
{
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Ticket || $item->isNewItem()) {
            return '';
        }
        $count = count((new SignRequest())->find(['tickets_id' => $item->getID(), 'NOT' => ['status' => 'canceled']]));
        return self::createTabEntry('Assinatura digital', $_SESSION['glpishow_count_on_tabs'] ? $count : 0, null, 'ti ti-signature');
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof Ticket) {
            return false;
        }
        global $CFG_GLPI;
        $action = htmlspecialchars($CFG_GLPI['root_doc'] . '/plugins/assinatura/front/signrequest.form.php', ENT_QUOTES);
        $ticketId = $item->getID();

        if (!Settings::isConfigured()) {
            echo "<div class='alert alert-warning m-3'>O plugin de assinatura ainda não foi configurado. "
                . "Um administrador deve preencher a conexão com o LibreSign em <b>Configurar → Plugins → Assinatura digital</b>.</div>";
            return true;
        }

        // atualiza as pendentes deste chamado ao abrir a aba
        $requests = [];
        foreach ((new SignRequest())->find(['tickets_id' => $ticketId], ['id DESC']) as $row) {
            $req = new SignRequest();
            $req->getFromDB($row['id']);
            if ($req->fields['status'] === 'pending') {
                $req->sync();
                $req->getFromDB($row['id']);
            }
            $requests[] = $req->fields;
        }

        echo "<div class='p-3'>";
        self::showList($requests, $action, $ticketId, $item->canUpdateItem());
        if ($item->canUpdateItem()) {
            self::showRequestForm($item, $action);
        }
        echo "</div>";
        return true;
    }

    private static function showList(array $requests, string $action, int $ticketId, bool $canEdit): void
    {
        echo "<div class='card mb-3'><div class='card-header d-flex justify-content-between align-items-center'>";
        echo "<h3 class='card-title'><i class='ti ti-signature me-2'></i>Assinaturas deste chamado</h3>";
        echo "</div>";
        if (!$requests) {
            echo "<div class='card-body text-secondary'>Nenhuma assinatura solicitada ainda.</div></div>";
            return;
        }
        echo "<div class='table-responsive'><table class='table table-vcenter card-table'>";
        echo "<thead><tr><th>Status</th><th>Assinante</th><th>Documento</th><th>Solicitado em</th><th>Assinado em</th><th></th></tr></thead><tbody>";
        foreach ($requests as $r) {
            [$label, $color, $icon] = SignRequest::STATUS_LABELS[$r['status']] ?? [$r['status'], 'secondary', 'ti-help'];
            $doc = $r['source'] === 'document' ? self::docLink((int) $r['documents_id_source']) : 'PDF do chamado';
            $signed = $r['documents_id_signed'] ? self::docLink((int) $r['documents_id_signed'], 'PDF assinado') : '—';
            $err = $r['last_error'] && $r['status'] === 'pending'
                ? "<div class='text-danger small' title='" . htmlspecialchars($r['last_error'], ENT_QUOTES) . "'><i class='ti ti-alert-circle'></i> falha na última consulta</div>"
                : '';
            echo "<tr>";
            echo "<td><span class='badge bg-$color-lt'><i class='ti $icon me-1'></i>$label</span>$err</td>";
            echo "<td><b>" . htmlspecialchars($r['signer_name']) . "</b><div class='text-secondary small'>" . htmlspecialchars($r['signer_email']) . "</div></td>";
            echo "<td>$doc</td>";
            echo "<td>" . Html::convDateTime($r['date_creation']) . "</td>";
            echo "<td>" . ($r['date_signed'] ? Html::convDateTime($r['date_signed']) . "<div class='small'>$signed</div>" : '—') . "</td>";
            echo "<td class='text-end'>";
            if ($canEdit && $r['status'] === 'pending') {
                echo "<form method='post' action='$action' class='d-inline'>"
                    . "<input type='hidden' name='id' value='{$r['id']}'><input type='hidden' name='tickets_id' value='$ticketId'>"
                    . "<button class='btn btn-sm btn-ghost-secondary' name='sync' value='1' title='Consultar agora'><i class='ti ti-refresh'></i></button>"
                    . "<button class='btn btn-sm btn-ghost-danger' name='cancel' value='1' title='Cancelar solicitação' onclick=\"return confirm('Cancelar esta solicitação de assinatura?')\"><i class='ti ti-x'></i></button>";
                Html::closeForm();
            }
            echo "</td></tr>";
        }
        echo "</tbody></table></div></div>";
    }

    private static function showRequestForm(Ticket $ticket, string $action): void
    {
        $ticketId = $ticket->getID();
        // assinante padrão: o primeiro requerente do chamado
        $requesters = $ticket->getUsers(\CommonITILActor::REQUESTER);
        $default = $requesters ? (int) reset($requesters)['users_id'] : 0;

        $pdfs = [];
        foreach ((new Document_Item())->find(['itemtype' => Ticket::class, 'items_id' => $ticketId]) as $link) {
            $doc = new Document();
            if ($doc->getFromDB($link['documents_id']) && $doc->fields['mime'] === 'application/pdf') {
                $pdfs[$doc->getID()] = $doc->fields['filename'];
            }
        }
        $msg = htmlspecialchars(Settings::get('default_message'), ENT_QUOTES);

        echo "<div class='card'><div class='card-header'><h3 class='card-title'><i class='ti ti-send me-2'></i>Solicitar assinatura</h3></div>";
        echo "<form method='post' action='$action' class='card-body'>";
        echo "<input type='hidden' name='tickets_id' value='$ticketId'>";
        echo "<div class='row g-3'>";
        echo "<div class='col-md-5'><label class='form-label'>Assinante</label>";
        User::dropdown(['name' => 'users_id', 'value' => $default, 'right' => 'all', 'entity' => $ticket->fields['entities_id'], 'width' => '100%']);
        echo "<div class='form-hint'>Recebe por e-mail o link do LibreSign para assinar.</div></div>";

        echo "<div class='col-md-7'><label class='form-label'>Documento</label>";
        echo "<select class='form-select' name='document'>";
        echo "<option value='ticket_pdf'>PDF do chamado (gerado automaticamente)</option>";
        foreach ($pdfs as $id => $name) {
            echo "<option value='doc:$id'>Anexo: " . htmlspecialchars($name) . "</option>";
        }
        echo "</select>";
        if (!$pdfs) {
            echo "<div class='form-hint'>Anexe um PDF ao chamado para enviá-lo no lugar do PDF do chamado.</div>";
        }
        echo "</div>";

        echo "<div class='col-12'><label class='form-label'>Mensagem ao assinante</label>";
        echo "<textarea class='form-control' name='message' rows='2'>$msg</textarea></div>";
        echo "<div class='col-12'><button class='btn btn-primary' type='submit' name='add' value='1'><i class='ti ti-signature me-1'></i>Enviar para assinatura</button></div>";
        echo "</div>";
        Html::closeForm();
        echo "</div>";
    }

    private static function docLink(int $id, string $label = ''): string
    {
        $doc = new Document();
        if (!$id || !$doc->getFromDB($id)) {
            return '—';
        }
        $text = htmlspecialchars($label ?: $doc->fields['filename']);
        return "<a href='" . htmlspecialchars($doc->getDownloadLink(null), ENT_QUOTES) . "' target='_blank'><i class='ti ti-file-type-pdf me-1'></i>$text</a>";
    }
}
