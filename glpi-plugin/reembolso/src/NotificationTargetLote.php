<?php

namespace GlpiPlugin\Reembolso;

use NotificationTarget;
use User;

/**
 * Destinatários e dados dos e-mails do lote de reembolsos.
 */
class NotificationTargetLote extends NotificationTarget
{
    public const TECH     = 5101;
    public const STEP     = 5102;
    public const INVOLVED = 5103;

    public function getEvents()
    {
        return [
            'lote_assinar'   => 'Lote de reembolso: conferir e assinar (técnico)',
            'lote_aprovar'   => 'Lote de reembolso: aguardando aprovação ou pagamento',
            'lote_devolvido' => 'Lote de reembolso: devolvido ao técnico',
            'lote_pago'      => 'Lote de reembolso: pago (finalizado)',
        ];
    }

    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(self::TECH, 'Técnico do lote');
        $this->addTarget(self::STEP, 'Quem aprova a etapa atual do lote');
        $this->addTarget(self::INVOLVED, 'Todos os envolvidos no lote');
    }

    public function addSpecificTargets($data, $options)
    {
        $lote = $this->obj;
        $ids = match ((int) $data['items_id']) {
            self::TECH     => [(int) $lote->fields['users_id']],
            self::STEP     => $lote->stepUsers(),
            self::INVOLVED => $lote->involved(),
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
        $f = $this->obj->fields;
        $cfg = Money::config();
        $this->data['##lote.code##'] = Lote::code((int) $f['id']);
        $this->data['##lote.tecnico##'] = (string) getUserName((int) $f['users_id']);
        $this->data['##lote.periodo##'] = Lote::periodLabel($f);
        $this->data['##lote.total##'] = Money::format((float) $f['total']);
        $this->data['##lote.despesas##'] = (string) (int) $f['expense_count'];
        $this->data['##lote.noturno##'] = (int) $f['night_count']
            ? (int) $f['night_count'] . ' despesa(s) de adicional noturno (' . $cfg['night_start'] . ' às ' . $cfg['night_end'] . '), ' . Money::format((float) $f['night_total'])
            : 'nenhuma despesa de adicional noturno';
        $this->data['##lote.etapa##'] = Lote::STATUSES[(int) $f['status']][0] ?? '';
        $this->data['##lote.pagamento##'] = $f['date_payment'] ? date('d/m/Y', strtotime((string) $f['date_payment'])) . ($f['payment_reference'] ? ' · ' . $f['payment_reference'] : '') : '—';
        $last = $this->obj->decisions();
        $this->data['##lote.motivo##'] = $last ? (string) (end($last)['comment'] ?? '') : '';
        $this->data['##lote.url##'] = Avisos::absolute(Lote::getFormURLWithID((int) $f['id']));
        // atendimentos do lote: chamado, data e valor de cada solicitação
        $rows = '';
        $sum = [];
        foreach ($this->obj->expenses() as $x) {
            $sum[(int) $x['rid']] = ($sum[(int) $x['rid']] ?? 0) + (float) $x['amount'];
        }
        foreach ($this->obj->requests() as $r) {
            $ticket = (int) $r['tickets_id'] ? 'Chamado nº ' . (int) $r['tickets_id'] : 'Sem chamado';
            $rows .= '<tr><td style="padding:4px 8px;border-bottom:1px solid #e2e8f0"><b>' . $ticket . '</b><br><span style="color:#64748b">' . Reembolso::code((int) $r['id']) . '</span></td>'
                . '<td style="padding:4px 8px;border-bottom:1px solid #e2e8f0">' . strip_tags(Pdf::when($r)) . '</td>'
                . '<td style="padding:4px 8px;border-bottom:1px solid #e2e8f0;text-align:right"><b>' . Money::format($sum[(int) $r['id']] ?? 0) . '</b></td></tr>';
        }
        $this->data['##lote.atendimentos##'] = $rows ? '<table style="border-collapse:collapse;font-size:13px;margin:8px 0"><tr style="background:#f1f5f9"><th style="padding:4px 8px;text-align:left">Chamado</th>'
            . '<th style="padding:4px 8px;text-align:left">Atendimento</th><th style="padding:4px 8px;text-align:right">Valor</th></tr>' . $rows . '</table>' : '';
    }

    public function getTags()
    {
        $tags = ['code' => 'Código do lote', 'tecnico' => 'Técnico', 'periodo' => 'Período', 'total' => 'Total', 'despesas' => 'Quantidade de despesas',
            'noturno' => 'Adicional noturno', 'atendimentos' => 'Atendimentos do lote (chamado, data e valor)', 'etapa' => 'Etapa atual', 'pagamento' => 'Pagamento (data e referência)', 'motivo' => 'Último comentário ou motivo', 'url' => 'Link do lote'];
        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => "lote.$tag", 'label' => $label, 'value' => true]);
        }
        asort($this->tag_descriptions);
        return $this->tag_descriptions;
    }
}
