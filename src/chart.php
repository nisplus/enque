<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * 集計グラフ（インラインSVG）。
 *
 * 外部のチャートライブラリを読み込まず、サーバー側でSVGを組み立てる
 * （Node.jsのビルドもCDNへの依存も不要で、印刷にもそのまま乗る）。
 *
 * 色は style.css の CSS変数（--series-1 など）を参照するため、
 * ライト／ダークどちらの配色でも読めるまま切り替わる。
 */

/** 角の右側だけを丸めた棒のパス（棒の起点は左端に固定する） */
function svg_bar_path(float $x, float $y, float $w, float $h, float $r = 4.0): string
{
    $r = min($r, $w, $h / 2);
    if ($w <= $r) {
        // ごく短い棒は角丸を付けない（つぶれて見えなくなるため）
        return sprintf('M%.1f %.1f h%.1f v%.1f h-%.1f Z', $x, $y, max($w, 1.0), $h, max($w, 1.0));
    }

    return sprintf(
        'M%.1f %.1f h%.1f a%.1f %.1f 0 0 1 %.1f %.1f v%.1f a%.1f %.1f 0 0 1 -%.1f %.1f h-%.1f Z',
        $x, $y, $w - $r, $r, $r, $r, $r, $h - 2 * $r, $r, $r, $r, $r, $w - $r
    );
}

/**
 * 横棒グラフ。
 *
 * 選択肢のラベルは日本語で長くなりがちなので、棒の上に置いて折り返さない。
 * 系列は1つ（回答数）なので凡例は付けず、値を棒の右に直接書く。
 *
 * @param array<string,int> $counts ラベル => 件数
 * @param int $total 母数（割合の分母。0なら割合を出さない）
 */
function svg_bar_chart(array $counts, int $total): string
{
    if ($counts === []) {
        return '<p class="muted">回答がありません。</p>';
    }

    $max      = max(1, max($counts));
    $rowH     = 46;
    $barH     = 18;
    $labelH   = 17;
    $width    = 640;
    $left     = 4;
    $right    = 92; // 値の表示域
    $barMaxW  = $width - $left - $right;
    $height   = count($counts) * $rowH + 8;

    $svg  = '<svg class="chart" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" ';
    $svg .= 'aria-label="設問の回答分布" preserveAspectRatio="xMinYMin meet">';

    $y = 8;
    foreach ($counts as $label => $count) {
        $barW = $max > 0 ? $barMaxW * $count / $max : 0;
        $pct  = percentage($count, $total);

        $svg .= '<text class="chart-label" x="' . $left . '" y="' . ($y + $labelH - 5) . '">'
              . e(mb_strimwidth((string) $label, 0, 46, '…')) . '</text>';
        $svg .= '<rect class="chart-track" x="' . $left . '" y="' . ($y + $labelH) . '" '
              . 'width="' . $barMaxW . '" height="' . $barH . '" rx="4"/>';
        if ($count > 0) {
            $svg .= '<path class="chart-bar" d="'
                  . svg_bar_path((float) $left, (float) ($y + $labelH), (float) $barW, (float) $barH) . '"/>';
        }
        $svg .= '<text class="chart-value" x="' . ($left + $barMaxW + 8) . '" y="' . ($y + $labelH + 14) . '">'
              . $count . '件' . ($total > 0 ? ' (' . $pct . '%)' : '') . '</text>';

        $y += $rowH;
    }

    return $svg . '</svg>';
}

/**
 * 時間帯別の推移（折れ線）。
 *
 * @param array<string,int> $series ラベル => 件数（時系列順）
 */
function svg_line_chart(array $series): string
{
    if (count($series) < 2) {
        return '<p class="muted">推移を描くにはまだ回答が足りません（2時間帯以上必要です）。</p>';
    }

    $labels = array_keys($series);
    $values = array_values($series);
    $max    = max(1, max($values));

    $width   = 640;
    $height  = 220;
    $padL    = 40;
    $padR    = 12;
    $padT    = 14;
    $padB    = 34;
    $plotW   = $width - $padL - $padR;
    $plotH   = $height - $padT - $padB;
    $stepX   = count($values) > 1 ? $plotW / (count($values) - 1) : 0;

    $x = static fn(int $i): float => $padL + $stepX * $i;
    $y = static fn(int $v): float => $padT + $plotH - ($plotH * $v / $max);

    $svg  = '<svg class="chart" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" ';
    $svg .= 'aria-label="時間帯別の回答数の推移" preserveAspectRatio="xMinYMin meet">';

    // 目盛り（4本、控えめに）
    for ($g = 0; $g <= 4; $g++) {
        $value = (int) round($max * $g / 4);
        $gy    = $y($value);
        $svg  .= '<line class="chart-grid" x1="' . $padL . '" y1="' . $gy . '" x2="' . ($width - $padR) . '" y2="' . $gy . '"/>';
        $svg  .= '<text class="chart-axis" x="' . ($padL - 6) . '" y="' . ($gy + 4) . '" text-anchor="end">' . $value . '</text>';
    }

    $points = [];
    foreach ($values as $i => $v) {
        $points[] = sprintf('%.1f,%.1f', $x($i), $y($v));
    }
    $svg .= '<polyline class="chart-line" points="' . implode(' ', $points) . '"/>';

    foreach ($values as $i => $v) {
        $svg .= '<circle class="chart-dot" cx="' . sprintf('%.1f', $x($i)) . '" cy="' . sprintf('%.1f', $y($v)) . '" r="4">';
        $svg .= '<title>' . e((string) $labels[$i]) . '：' . $v . '件</title></circle>';
    }

    // X軸ラベルは詰まりすぎないよう間引く
    $every = (int) max(1, ceil(count($labels) / 8));
    foreach ($labels as $i => $label) {
        if ($i % $every !== 0 && $i !== count($labels) - 1) {
            continue;
        }
        $svg .= '<text class="chart-axis" x="' . sprintf('%.1f', $x((int) $i)) . '" y="' . ($height - 12) . '" text-anchor="middle">'
              . e((string) $label) . '</text>';
    }

    return $svg . '</svg>';
}

/**
 * NPS（推奨者9-10、中立7-8、批判者0-6）のスコアと内訳。
 *
 * @param array<string,int> $counts "0"〜"10" => 件数
 * @return array{score: int, promoters: int, passives: int, detractors: int, total: int}
 */
function nps_breakdown(array $counts): array
{
    $promoters = $passives = $detractors = 0;
    foreach ($counts as $value => $n) {
        $v = (int) $value;
        if ($v >= 9) {
            $promoters += $n;
        } elseif ($v >= 7) {
            $passives += $n;
        } else {
            $detractors += $n;
        }
    }
    $total = $promoters + $passives + $detractors;
    $score = $total > 0 ? (int) round(($promoters - $detractors) * 100 / $total) : 0;

    return [
        'score'      => $score,
        'promoters'  => $promoters,
        'passives'   => $passives,
        'detractors' => $detractors,
        'total'      => $total,
    ];
}

/**
 * 星評価の平均。
 *
 * @param array<string,int> $counts "1"〜"5" => 件数
 */
function rating_average(array $counts): float
{
    $sum = $n = 0;
    foreach ($counts as $value => $count) {
        $sum += (int) $value * $count;
        $n   += $count;
    }

    return $n > 0 ? round($sum / $n, 2) : 0.0;
}
