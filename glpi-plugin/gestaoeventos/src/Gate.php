<?php

namespace GlpiPlugin\Gestaoeventos;

/**
 * Regras de bloqueio que valem no servidor, não só na tela: nenhuma despesa é comprometida
 * sem aprovação formal, confirmação de participação, centro de custo e verba disponível.
 */
class Gate
{
    /** Motivos pelos quais o valor não pode ser comprometido agora (lista vazia = liberado). */
    public static function commitBlocks(Evento $ev, float $amount, int $excludeRegistroId = 0, float $alreadyCommitted = 0.0): array
    {
        $f = $ev->fields;
        $why = [];
        if ((int) $f['status'] !== Evento::OPEN) {
            $why[] = 'O evento está ' . ((int) $f['status'] === Evento::DONE ? 'encerrado' : 'cancelado') . '.';
        }
        if ($f['approval_status'] !== 'aprovado') {
            $why[] = 'O evento ainda não tem a aprovação formal da Diretoria (RN-02).';
        }
        if (trim((string) $f['participation_url']) === '' && !Documentos::countPhase($ev->getID(), 'avaliacao')) {
            $why[] = 'Falta a confirmação ou o contrato de participação (informe o link no briefing ou anexe em Documentos, etapa Avaliação).';
        }
        if (trim((string) $f['cc_number']) === '') {
            $why[] = 'O centro de custo não foi informado.';
        }
        if ((float) $f['budget_approved'] <= 0) {
            $why[] = 'O orçamento base ainda não foi aprovado (aba Financeiro).';
        } else {
            $s = Financeiro::summary($ev->getID());
            $delta = $amount - $alreadyCommitted;
            if ($delta > $s['saldo'] + 0.005) {
                $why[] = 'Acima da verba liberada: saldo de ' . Menu::money($s['saldo']) . ' para um compromisso adicional de ' . Menu::money($delta) . '.';
            }
        }
        return $why;
    }
}
