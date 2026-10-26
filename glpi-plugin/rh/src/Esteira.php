<?php

namespace GlpiPlugin\Rh;

/**
 * Esteira "Onde … estão agora" (o mesmo modelo nos plugins RH, Reembolso, PMO e Ciclo):
 * uma linha com uma marca por etapa do processo (com a quantidade e vermelho quando algum
 * passou do prazo) e, abaixo, os itens agrupados pela etapa, cada um com o tempo na etapa
 * contra o prazo de referência e uma barra de quanto do prazo já foi usado.
 *
 * Esteira::card([
 *   'title' => 'Onde as vagas estão agora', 'icon' => 'ti-route', 'sub' => '4 em andamento',
 *   'ends'  => ['Requisição', 'Aprovação', 'Seleção'],      // legendas da linha (início, meio, fim)
 *   'steps' => [['name' => 'Aguardando gestor', 'area' => 'Gestor', 'items' => [
 *       ['code' => 'VG-3', 'name' => '…', 'sub' => '…', 'url' => '…', 'days' => 4.2, 'limit' => 3, 'unit' => 'd'],
 *   ]]],
 * ])
 */
class Esteira
{
    private static function e($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES);
    }

    private static function num(float $v): string
    {
        return str_replace('.', ',', (string) ($v >= 10 ? round($v) : round($v, 1)));
    }

    public static function card(array $o): string
    {
        $e = [self::class, 'e'];
        $steps = array_values($o['steps']);
        $total = array_sum(array_map(fn ($s) => count($s['items']), $steps));
        $late = 0;
        foreach ($steps as &$s) {
            foreach ($s['items'] as &$it) {
                $it['bad'] = $it['bad'] ?? (!empty($it['limit']) && $it['days'] > $it['limit']);
                $late += $it['bad'] ? 1 : 0;
            }
            unset($it);
            usort($s['items'], fn ($a, $b) => [$b['bad'], $b['days']] <=> [$a['bad'], $a['days']]);
        }
        unset($s);
        $sub = $o['sub'] ?? ($total . ' em andamento · ' . ($late ? "<span class='text-danger fw-bold'>$late além do prazo</span>" : 'todos dentro do prazo'));
        $h = "<div class='card h-100 oak-pipe-card'><div class='card-header'><div><h3 class='card-title'><i class='ti " . $e($o['icon'] ?? 'ti-route') . " me-2'></i>" . $e($o['title']) . "</h3>"
            . "<div class='small text-secondary'>$sub</div></div></div><div class='card-body'>";
        if (!$total) {
            return $h . "<div class='oak-bars-empty'><i class='ti ti-route'></i>" . $e($o['empty'] ?? 'Nada em andamento.') . "</div></div></div>";
        }

        // a linha: uma marca por etapa, com a quantidade e a cor do pior caso
        $n = count($steps);
        $h .= "<div class='oak-pipe'><div class='oak-pipe-track'>";
        foreach ($steps as $i => $s) {
            $cnt = count($s['items']);
            $bad = array_filter($s['items'], fn ($it) => $it['bad']);
            $cls = $cnt ? ($bad ? 'bad' : 'ok') : '';
            $h .= "<div class='oak-pipe-step $cls' title='" . $e(($i + 1) . '. ' . $s['name'] . ($cnt ? " · $cnt" : '')) . "' style='left:" . round(100 * $i / max(1, $n - 1), 2) . "%'>"
                . ($cnt ? "<b>$cnt</b>" : '') . "<i></i></div>";
        }
        $ends = $o['ends'] ?? [$steps[0]['name'], '', $steps[$n - 1]['name']];
        $h .= "</div><div class='oak-pipe-ends'>" . implode('', array_map(fn ($x) => '<span>' . $e($x) . '</span>', $ends)) . "</div></div>";

        // os itens agrupados pela etapa, na ordem do processo
        $h .= "<div class='oak-pipe-list'>";
        foreach ($steps as $i => $s) {
            if (!$s['items']) {
                continue;
            }
            $h .= "<div class='oak-pipe-group'><div class='oak-pipe-phase'><span class='oak-pipe-num'>" . ($i + 1) . "</span><b>" . $e($s['name']) . "</b><span class='text-secondary'>" . $e($s['area'] ?? '') . "</span></div>";
            foreach ($s['items'] as $it) {
                $unit = $it['unit'] ?? 'd';
                $limit = (float) ($it['limit'] ?? 0);
                $days = (float) $it['days'];
                $pct = $limit ? min(100, round(100 * $days / $limit)) : null;
                $note = $it['note'] ?? ($limit ? ($it['bad'] ? '+' . self::num($days - $limit) . "$unit além" : 'faltam ' . self::num(max(0, $limit - $days)) . $unit) : 'sem prazo');
                $h .= "<a class='oak-pipe-item" . ($it['bad'] ? ' bad' : '') . "' href='" . $e($it['url'] ?? '#') . "'>"
                    . "<span class='oak-pipe-code'>" . $e($it['code']) . "</span><span class='oak-pipe-name'>" . $e($it['name']) . "<small>" . $e($it['sub'] ?? '') . "</small></span>"
                    . "<span class='oak-pipe-time'><b>" . $e($it['value'] ?? self::num($days) . $unit) . "</b>" . ($limit ? ' / ' . self::num($limit) : '') . "<small>" . $e($note) . "</small></span>"
                    . ($pct !== null ? "<span class='oak-pipe-bar'><i style='width:$pct%'></i></span>" : '') . "</a>";
            }
            $h .= "</div>";
        }
        return $h . "</div></div></div>";
    }
}
