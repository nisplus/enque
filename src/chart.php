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
 * 棒の長さは母数に対する割合（0〜100%）で、いちばん多い項目でも母数の半分なら
 * 半分までしか伸びない。灰色の下地がちょうど100%にあたる。25%ごとの目盛り線を
 * 引いてあるので、短い棒でも位置で読める。
 *
 * 母数が分からないとき（0を渡したとき）だけ、いちばん多い項目を満タンにする。
 *
 * @param array<string,int> $counts ラベル => 件数
 * @param int $total 母数（割合の分母。0なら割合を出さず、最大値を基準にする）
 */
function svg_bar_chart(array $counts, int $total): string
{
    if ($counts === []) {
        return '<p class="muted">回答がありません。</p>';
    }

    // 母数が分かっていれば100%を基準にする（分からないときだけ最大値を基準にする）
    $scale    = $total > 0 ? $total : max(1, max($counts));
    $rowH     = 46;
    $barH     = 18;
    $labelH   = 17;
    $width    = 640;
    $left     = 4;
    $right    = 92; // 値の表示域
    $barMaxW  = $width - $left - $right;
    $axisH    = 16;
    $height   = count($counts) * $rowH + 8 + $axisH;

    $svg  = '<svg class="chart" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" ';
    $svg .= 'aria-label="設問の回答分布" preserveAspectRatio="xMinYMin meet">';

    // 下地（100%ぶん）を先に全部描き、その上に目盛り線、いちばん上に棒を重ねる
    $y = 8;
    foreach ($counts as $label => $count) {
        $svg .= '<text class="chart-label" x="' . $left . '" y="' . ($y + $labelH - 5) . '">'
              . e(mb_strimwidth((string) $label, 0, 46, '…')) . '</text>';
        $svg .= '<rect class="chart-track" x="' . $left . '" y="' . ($y + $labelH) . '" '
              . 'width="' . $barMaxW . '" height="' . $barH . '" rx="4"/>';
        $y += $rowH;
    }

    $gridTop    = 8 + $labelH;
    $gridBottom = $y - $rowH + $labelH + $barH;
    foreach ([25, 50, 75] as $tick) {
        $x    = $left + $barMaxW * $tick / 100;
        $svg .= '<line class="chart-grid chart-tick" x1="' . $x . '" y1="' . $gridTop . '" '
              . 'x2="' . $x . '" y2="' . $gridBottom . '"/>';
    }

    $y = 8;
    foreach ($counts as $label => $count) {
        $barW = $barMaxW * $count / $scale;
        if ($count > 0) {
            $svg .= '<path class="chart-bar" d="'
                  . svg_bar_path((float) $left, (float) ($y + $labelH), (float) $barW, (float) $barH) . '"/>';
        }
        $svg .= '<text class="chart-value" x="' . ($left + $barMaxW + 8) . '" y="' . ($y + $labelH + 14) . '">'
              . $count . '件' . ($total > 0 ? ' (' . percentage($count, $total) . '%)' : '') . '</text>';
        $y += $rowH;
    }

    // 両端の目盛り（母数が分からないときは割合として読めないので出さない）
    if ($total > 0) {
        $axisY = $gridBottom + 13;
        $svg  .= '<text class="chart-axis" x="' . $left . '" y="' . $axisY . '">0%</text>';
        $svg  .= '<text class="chart-axis" x="' . ($left + $barMaxW / 2) . '" y="' . $axisY
               . '" text-anchor="middle">50%</text>';
        $svg  .= '<text class="chart-axis" x="' . ($left + $barMaxW) . '" y="' . $axisY
               . '" text-anchor="end">100%</text>';
    }

    return $svg . '</svg>';
}

/**
 * 帯グラフの配色。
 *
 * series … 名義尺度（選択肢・区分など）。9つ目からは先頭に戻る
 * ramp   … 順序尺度（星1〜5・人数の区分など）。同じ青の濃淡で並び順が分かる
 *
 * 色だけに頼らず読めるよう、帯のすぐ下に必ず凡例（ラベル・件数・割合）を出す。
 */
const CHART_SERIES_COLORS = [
    'var(--series-1)', 'var(--series-2)', 'var(--series-3)', 'var(--series-4)',
    'var(--series-5)', 'var(--series-6)', 'var(--series-7)', 'var(--series-8)',
];
const CHART_RAMP_COLORS = [
    'var(--ramp-1)', 'var(--ramp-2)', 'var(--ramp-3)', 'var(--ramp-4)', 'var(--ramp-5)',
];

/**
 * 帯グラフ（100%積み上げの横棒）で使う色を、項目数だけ並べる。
 *
 * 順序尺度は、項目が5つを超えても端から端まで濃淡が付くように間引く。
 *
 * @param string|list<string> $palette 'series' / 'ramp' / 色の並び（CSSの色指定）
 * @return list<string>
 */
function chart_colors(string|array $palette, int $count): array
{
    if (is_array($palette)) {
        $colors = [];
        for ($i = 0; $i < $count; $i++) {
            $colors[] = $palette[$i % max(1, count($palette))];
        }

        return $colors;
    }

    if ($palette === 'ramp') {
        $steps  = count(CHART_RAMP_COLORS) - 1;
        $colors = [];
        for ($i = 0; $i < $count; $i++) {
            $position = $count === 1 ? $steps : (int) round($i * $steps / ($count - 1));
            $colors[] = CHART_RAMP_COLORS[$position];
        }

        return $colors;
    }

    $colors = [];
    for ($i = 0; $i < $count; $i++) {
        $colors[] = CHART_SERIES_COLORS[$i % count(CHART_SERIES_COLORS)];
    }

    return $colors;
}

/**
 * 100%積み上げの横棒（帯グラフ）。
 *
 * 母数いっぱいの帯を1本描き、項目ごとの割合を左から並べる。構成比を一目で見るためのもので、
 * 件数そのものの比較は、下に続く横棒グラフ（svg_bar_chart）で読む。
 *
 * 帯の中には数字を書かない（薄い色の上の白文字が読みにくくなるため）。割合と件数は
 * すぐ下の凡例に出し、25%ごとの目盛り線で位置も読めるようにしている。
 *
 * 合計が母数と一致しない集計（複数選択など）には使えない。
 *
 * @param array<string,int> $counts ラベル => 件数（合計が母数と一致すること）
 * @param int $total 母数（0以下なら描かない）
 * @param string|list<string> $palette 'series' / 'ramp' / 色の並び
 * @param string $unit 凡例に出す単位（件・人・組など）
 */
function svg_stacked_bar(array $counts, int $total, string|array $palette = 'series', string $unit = '件'): string
{
    $counts = array_filter($counts, static fn(int $count): bool => $count >= 0);
    if ($counts === [] || $total <= 0) {
        return '';
    }

    $width  = 640;
    $barH   = 26;
    $axisY  = $barH + 16;
    $height = $axisY + 4;
    $gap    = 1.5; // 隣り合う色を離すすき間（背景色が1本線に見える）

    $svg  = '<svg class="chart chart-stacked" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" ';
    $svg .= 'aria-label="構成比（合計100%）" preserveAspectRatio="xMinYMin meet">';
    $svg .= '<rect class="chart-track" x="0" y="0" width="' . $width . '" height="' . $barH . '" rx="4"/>';

    $colors = chart_colors($palette, count($counts));
    $legend = '';
    $index  = 0;
    $done   = 0;
    foreach ($counts as $label => $count) {
        // 端数で帯がずれないよう、累計から座標を出す（最後の項目はちょうど右端で終わる）
        $start = $width * $done / $total;
        $done += $count;
        $end   = $width * $done / $total;
        $color = $colors[$index];

        // 項目が色数より多いときは、2周目を薄くして同じ色の繰り返しに見えないようにする
        $fade  = $index >= count(CHART_SERIES_COLORS) && !is_array($palette) && $palette === 'series';
        $style = 'fill:' . $color . ($fade ? ';fill-opacity:.55' : '');

        if ($count > 0) {
            $x    = $index === 0 ? 0.0 : $start + $gap / 2;
            $barW = max(0.0, ($index === count($counts) - 1 ? $width : $end - $gap / 2) - $x);
            $svg .= '<rect x="' . round($x, 2) . '" y="0" width="' . round($barW, 2) . '" height="' . $barH . '" '
                  . 'style="' . $style . '"><title>' . e((string) $label) . ' ' . $count . $unit . ' ('
                  . percentage($count, $total) . '%)</title></rect>';
        }

        $legend .= '<li><span class="chip" style="background:' . $color . ($fade ? ';opacity:.55' : '') . '"></span>'
                 . '<span>' . e(mb_strimwidth((string) $label, 0, 28, '…')) . '</span>'
                 . '<span class="muted">' . $count . $unit . ' (' . percentage($count, $total) . '%)</span></li>';
        $index++;
    }

    // 25%ごとの目盛り線と、両端の0%・100%
    foreach ([25, 50, 75] as $tick) {
        $x    = $width * $tick / 100;
        $svg .= '<line class="chart-grid chart-tick" x1="' . $x . '" y1="0" x2="' . $x . '" y2="' . $barH . '"/>';
    }
    $svg .= '<text class="chart-axis" x="0" y="' . $axisY . '">0%</text>';
    $svg .= '<text class="chart-axis" x="' . ($width / 2) . '" y="' . $axisY . '" text-anchor="middle">50%</text>';
    $svg .= '<text class="chart-axis" x="' . $width . '" y="' . $axisY . '" text-anchor="end">100%</text>';
    $svg .= '</svg>';

    return $svg . '<ul class="chart-legend">' . $legend . '</ul>';
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
