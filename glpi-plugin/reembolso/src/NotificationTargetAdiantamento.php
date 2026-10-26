<?php

namespace GlpiPlugin\Reembolso;

use NotificationTarget;
use User;

/**
 * Destinatários e dados dos e-mails do adiantamento.
 */
class NotificationTargetAdiantamento extends NotificationTarget
{
    public const TECH     = 5201;
    public const STEP     = 5202;
    public const INVOLVED = 5203;

    public function getEvents()
    {
        return [
            'ad_assinar'   => 'Adiantamento: conferir e assinar (técnico)',
            'ad_aprovar'   => 'Adiantamento: aguardando aprovação ou pagamento',
            'ad_devolvido' => 'Adiantamento: devolvido ao técnico',
            'ad_pago'      => 'Adiantamento: pago (prestar contas)',
            'ad_prestacao' => 'Adiantamento: prestação de contas para conferir',
            'ad_encerrado' => 'Adiantamento: encerrado (finalizado)',
        ];
    }

    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(self::TECH, 'Técnico do adiantamento');
        $this->addTarget(self::STEP, 'Quem age na etapa atual do adiantamento');
        $this->addTarget(self::INVOLVED, 'Todos os envolvidos no adiantamento');
    }

    public function addSpecificTargets($data, $options)
    {
        $ad = $this->obj;
        $ids = match ((int) $data['items_id']) {
            self::TECH     => [(int) $ad->fields['users_id']],
            self::STEP     => $ad->stepUsers(),
            self::INVOLVED => $ad->involved(),
            default        => [],
        };
        foreach ($ids as $uid) {
            $u = new User();
            if ($uid && $u->getFromDB($uid) && $u->fields['is_active']) {
                $this->addToRecipientsList(['users_id' => $uid, 'language' => $u->fields['language'] ?: '', 'email' => $u->getDefaultEmail()]);
            }
        }
    }

    public function addDataForTemplate($event, $options = [])
    {
        $ad = $this->obj;
        $f = $ad->fields;
        $this->data['##ad.code##'] = Adiantamento::code((int) $f['id']);
        $this->data['##ad.tecnico##'] = (string) getUserName((int) $f['users_id']);
        $this->data['##ad.chamado##'] = (int) $f['tickets_id'] ? 'Chamado nº ' . (int) $f['tickets_id'] : 'Sem chamado';
        $this->data['##ad.centro##'] = Centrocusto::label((int) $f['plugin_reembolso_centrocustos_id']);
        $this->data['##ad.atendimento##'] = strip_tags(Pdf::when($f));
        $this->data['##ad.total##'] = Money::format((float) $f['total']);
        $this->data['##ad.gasto##'] = Money::format((float) $f['spent_total']);
        $this->data['##ad.saldo##'] = Adiantamento::balanceLabel((float) $f['balance']);
        $this->data['##ad.prazo##'] = date('d/m/Y', strtotime($ad->deadline()));
        $this->data['##ad.etapa##'] = Adiantamento::STATUSES[(int) $f['status']][0] ?? '';
        $this->data['##ad.pagamento##'] = $f['date_payment'] ? date('d/m/Y', strtotime((string) $f['date_payment'])) . ($f['payment_reference'] ? ' · ' . $f['payment_reference'] : '') : '—';
        $last = $ad->decisions();
        $this->data['##ad.motivo##'] = $last ? (string) (end($last)['comment'] ?? '') : '';
        $this->data['##ad.url##'] = Avisos::absolute(Adiantamento::getFormURLWithID((int) $f['id']));
        $rows = '';
        foreach ($ad->items() as $x) {
            $rows .= '<tr><td style="padding:4px 8px;border-bottom:1px solid #e2e8f0">' . htmlspecialchars((string) $x['tipo']) . '</td><td style="padding:4px 8px;border-bottom:1px solid #e2e8f0">'
                . htmlspecialchars((string) $x['description']) . '</td><td style="padding:4px 8px;border-bottom:1px solid #e2e8f0;text-align:right"><b>' . Money::format((float) $x['amount']) . '</b></td></tr>';
        }
        $this->data['##ad.despesas##'] = $rows ? '<table style="border-collapse:collapse;font-size:13px;margin:8px 0"><tr style="background:#f1f5f9"><th style="padding:4px 8px;text-align:left">Tipo</th>'
            . '<th style="padding:4px 8px;text-align:left">Descrição</th><th style="padding:4px 8px;text-align:right">Valor</th></tr>' . $rows . '</table>' : '';
    }

    public function getTags()
    {
        $tags = ['code' => 'Código do adiantamento', 'tecnico' => 'Técnico', 'chamado' => 'Chamado', 'centro' => 'Centro de custo', 'atendimento' => 'Data e hora do atendimento',
            'total' => 'Valor solicitado', 'gasto' => 'Gasto na prestação de contas', 'saldo' => 'Saldo a acertar', 'prazo' => 'Prazo da prestação de contas', 'etapa' => 'Etapa atual',
            'pagamento' => 'Pagamento (data e referência)', 'motivo' => 'Último comentário ou motivo', 'url' => 'Link do adiantamento', 'despesas' => 'Despesas previstas'];
        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => "ad.$tag", 'label' => $label, 'value' => true]);
        }
        asort($this->tag_descriptions);
        return $this->tag_descriptions;
    }
}
