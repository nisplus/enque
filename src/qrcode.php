<?php
declare(strict_types=1);

/**
 * QRコード生成（8ビットバイトモード・誤り訂正レベルM・型番1〜15）。
 *
 * Composer を使わない構成のため、外部ライブラリではなく必要な範囲だけを実装する。
 * 型番15（415バイト）まで対応しており、ブースURLや交換コードには十分。
 *
 * 参考：JIS X 0510 / ISO/IEC 18004 のバイトモード。
 */

/** 誤り訂正レベルM の型番別ブロック構成： version => [ECコードワード数/ブロック, [[ブロック数, データCW数], ...]] */
const QR_EC_BLOCKS_M = [
    1  => [10, [[1, 16]]],
    2  => [16, [[1, 28]]],
    3  => [26, [[1, 44]]],
    4  => [18, [[2, 32]]],
    5  => [24, [[2, 43]]],
    6  => [16, [[4, 27]]],
    7  => [18, [[4, 31]]],
    8  => [22, [[2, 38], [2, 39]]],
    9  => [22, [[3, 36], [2, 37]]],
    10 => [26, [[4, 43], [1, 44]]],
    11 => [30, [[1, 50], [4, 51]]],
    12 => [22, [[6, 36], [2, 37]]],
    13 => [22, [[8, 37], [1, 38]]],
    14 => [24, [[4, 40], [5, 41]]],
    15 => [24, [[5, 41], [5, 42]]],
];

/** 位置合わせパターンの中心座標（型番別） */
const QR_ALIGNMENT_CENTERS = [
    1  => [],
    2  => [6, 18],
    3  => [6, 22],
    4  => [6, 26],
    5  => [6, 30],
    6  => [6, 34],
    7  => [6, 22, 38],
    8  => [6, 24, 42],
    9  => [6, 26, 46],
    10 => [6, 28, 50],
    11 => [6, 30, 54],
    12 => [6, 32, 58],
    13 => [6, 34, 62],
    14 => [6, 26, 46, 66],
    15 => [6, 26, 48, 70],
];

/** 残余ビット数（型番別） */
const QR_REMAINDER_BITS = [
    1 => 0, 2 => 7, 3 => 7, 4 => 7, 5 => 7, 6 => 7,
    7 => 0, 8 => 0, 9 => 0, 10 => 0, 11 => 0, 12 => 0, 13 => 0,
    14 => 3, 15 => 3,
];

/** GF(256) の指数表・対数表（原始多項式 0x11D） */
function qr_gf_tables(): array
{
    static $tables = null;
    if ($tables !== null) {
        return $tables;
    }

    $exp = array_fill(0, 512, 0);
    $log = array_fill(0, 256, 0);
    $x   = 1;
    for ($i = 0; $i < 255; $i++) {
        $exp[$i] = $x;
        $log[$x] = $i;
        $x <<= 1;
        if ($x & 0x100) {
            $x ^= 0x11D;
        }
    }
    for ($i = 255; $i < 512; $i++) {
        $exp[$i] = $exp[$i - 255];
    }
    $tables = ['exp' => $exp, 'log' => $log];

    return $tables;
}

/** GF(256) の乗算 */
function qr_gf_mul(int $a, int $b): int
{
    if ($a === 0 || $b === 0) {
        return 0;
    }
    ['exp' => $exp, 'log' => $log] = qr_gf_tables();

    return $exp[$log[$a] + $log[$b]];
}

/**
 * 誤り訂正符号の生成多項式（次数 $degree）。
 *
 * @return list<int>
 */
function qr_generator_poly(int $degree): array
{
    $poly = [1];
    ['exp' => $exp] = qr_gf_tables();
    for ($i = 0; $i < $degree; $i++) {
        // g(x) := g(x) * (x + α^i)。係数は次数の降順で持つ
        $next = array_fill(0, count($poly) + 1, 0);
        foreach ($poly as $j => $coef) {
            $next[$j]     ^= $coef;                          // x を掛けた項
            $next[$j + 1] ^= qr_gf_mul($coef, $exp[$i]);     // α^i を掛けた項
        }
        $poly = $next;
    }

    return $poly;
}

/**
 * データコードワード列に対する誤り訂正コードワードを計算する。
 *
 * @param list<int> $data
 * @return list<int>
 */
function qr_ec_codewords(array $data, int $ecCount): array
{
    $gen       = qr_generator_poly($ecCount);
    $remainder = array_merge($data, array_fill(0, $ecCount, 0));

    for ($i = 0; $i < count($data); $i++) {
        $factor = $remainder[$i];
        if ($factor === 0) {
            continue;
        }
        foreach ($gen as $j => $coef) {
            $remainder[$i + $j] ^= qr_gf_mul($coef, $factor);
        }
    }

    return array_slice($remainder, count($data), $ecCount);
}

/** バイトモードで格納できる最小の型番を返す（入りきらなければ null） */
function qr_pick_version(int $byteLength): ?int
{
    foreach (QR_EC_BLOCKS_M as $version => [$ecPerBlock, $groups]) {
        $dataCw = 0;
        foreach ($groups as [$blocks, $cwPerBlock]) {
            $dataCw += $blocks * $cwPerBlock;
        }
        $headerBits = 4 + ($version < 10 ? 8 : 16);
        if ($dataCw * 8 >= $headerBits + $byteLength * 8) {
            return $version;
        }
    }

    return null;
}

/**
 * 文字列をQRコードのモジュール行列（0/1の二次元配列）に変換する。
 *
 * @return list<list<int>>
 */
function qr_matrix(string $text): array
{
    $bytes  = array_values(unpack('C*', $text) ?: []);
    $length = count($bytes);

    $version = qr_pick_version($length);
    if ($version === null) {
        throw new InvalidArgumentException('QRコードに収まらない長さです（最大415バイト）：' . $length . 'バイト');
    }

    [$ecPerBlock, $groups] = QR_EC_BLOCKS_M[$version];

    // --- ビット列を組み立てる ---------------------------------------------
    $bits = '0100'; // バイトモード
    $bits .= str_pad(decbin($length), $version < 10 ? 8 : 16, '0', STR_PAD_LEFT);
    foreach ($bytes as $byte) {
        $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
    }

    $totalDataCw = 0;
    foreach ($groups as [$blocks, $cwPerBlock]) {
        $totalDataCw += $blocks * $cwPerBlock;
    }
    $capacityBits = $totalDataCw * 8;

    // 終端パターン（最大4ビット）と、バイト境界までの0埋め
    $bits .= str_repeat('0', min(4, $capacityBits - strlen($bits)));
    if (strlen($bits) % 8 !== 0) {
        $bits .= str_repeat('0', 8 - strlen($bits) % 8);
    }
    // 埋め草コードワード（0xEC, 0x11 の繰り返し）
    $padBytes = ['11101100', '00010001'];
    $i = 0;
    while (strlen($bits) < $capacityBits) {
        $bits .= $padBytes[$i % 2];
        $i++;
    }

    $dataCodewords = [];
    for ($p = 0; $p < $capacityBits; $p += 8) {
        $dataCodewords[] = bindec(substr($bits, $p, 8));
    }

    // --- ブロック分割・誤り訂正・インターリーブ ---------------------------
    $dataBlocks = [];
    $ecBlocks   = [];
    $offset     = 0;
    foreach ($groups as [$blocks, $cwPerBlock]) {
        for ($b = 0; $b < $blocks; $b++) {
            $block        = array_slice($dataCodewords, $offset, $cwPerBlock);
            $offset      += $cwPerBlock;
            $dataBlocks[] = $block;
            $ecBlocks[]   = qr_ec_codewords($block, $ecPerBlock);
        }
    }

    $final   = [];
    $maxData = max(array_map('count', $dataBlocks));
    for ($i = 0; $i < $maxData; $i++) {
        foreach ($dataBlocks as $block) {
            if (isset($block[$i])) {
                $final[] = $block[$i];
            }
        }
    }
    for ($i = 0; $i < $ecPerBlock; $i++) {
        foreach ($ecBlocks as $block) {
            if (isset($block[$i])) {
                $final[] = $block[$i];
            }
        }
    }

    // --- モジュールの配置 --------------------------------------------------
    $size = $version * 4 + 17;
    $best = null;
    $bestPenalty = PHP_INT_MAX;

    for ($mask = 0; $mask < 8; $mask++) {
        $matrix = qr_build_matrix($version, $size, $final, $mask);
        $penalty = qr_penalty($matrix, $size);
        if ($penalty < $bestPenalty) {
            $bestPenalty = $penalty;
            $best        = $matrix;
        }
    }

    return $best ?? [];
}

/**
 * 機能パターンとデータを置いた行列を1つ作る（マスク適用済み）。
 *
 * @param list<int> $codewords
 * @return list<list<int>>
 */
function qr_build_matrix(int $version, int $size, array $codewords, int $mask): array
{
    // -1 = 未配置
    $m = array_fill(0, $size, array_fill(0, $size, -1));

    // 位置検出パターン＋分離パターン
    foreach ([[0, 0], [$size - 7, 0], [0, $size - 7]] as [$col, $row]) {
        for ($r = -1; $r <= 7; $r++) {
            for ($c = -1; $c <= 7; $c++) {
                $y = $row + $r;
                $x = $col + $c;
                if ($y < 0 || $y >= $size || $x < 0 || $x >= $size) {
                    continue;
                }
                $inFinder = ($r >= 0 && $r <= 6 && ($c === 0 || $c === 6))
                         || ($c >= 0 && $c <= 6 && ($r === 0 || $r === 6))
                         || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4);
                $m[$y][$x] = $inFinder ? 1 : 0;
            }
        }
    }

    // タイミングパターン
    for ($i = 8; $i < $size - 8; $i++) {
        $bit = $i % 2 === 0 ? 1 : 0;
        if ($m[6][$i] === -1) {
            $m[6][$i] = $bit;
        }
        if ($m[$i][6] === -1) {
            $m[$i][6] = $bit;
        }
    }

    // 位置合わせパターン
    $centers = QR_ALIGNMENT_CENTERS[$version];
    $last    = count($centers) - 1;
    foreach ($centers as $ri => $row) {
        foreach ($centers as $ci => $col) {
            if (($ri === 0 && $ci === 0) || ($ri === 0 && $ci === $last) || ($ri === $last && $ci === 0)) {
                continue; // 位置検出パターンと重なる位置には置かない
            }
            for ($r = -2; $r <= 2; $r++) {
                for ($c = -2; $c <= 2; $c++) {
                    $m[$row + $r][$col + $c] = (abs($r) === 2 || abs($c) === 2 || ($r === 0 && $c === 0)) ? 1 : 0;
                }
            }
        }
    }

    // 形式情報の領域を予約（値は後で書く）
    for ($i = 0; $i < 9; $i++) {
        if ($m[8][$i] === -1) {
            $m[8][$i] = 0;
        }
        if ($m[$i][8] === -1) {
            $m[$i][8] = 0;
        }
    }
    for ($i = 0; $i < 8; $i++) {
        $m[8][$size - 1 - $i] = 0;
        $m[$size - 1 - $i][8] = 0;
    }
    $m[$size - 8][8] = 1; // 常に暗となるモジュール

    // 型番情報の領域を予約
    if ($version >= 7) {
        for ($i = 0; $i < 18; $i++) {
            $m[intdiv($i, 3)][$i % 3 + $size - 11]  = 0;
            $m[$i % 3 + $size - 11][intdiv($i, 3)]  = 0;
        }
    }

    // データを右下からジグザグに配置（マスクはこの時点で適用する）
    $byteIndex = 0;
    $bitIndex  = 7;
    $inc       = -1;
    $row       = $size - 1;

    for ($col = $size - 1; $col > 0; $col -= 2) {
        if ($col === 6) {
            $col--; // タイミングパターンの列は飛ばす
        }
        while (true) {
            for ($c = 0; $c < 2; $c++) {
                $x = $col - $c;
                if ($m[$row][$x] !== -1) {
                    continue;
                }
                $dark = 0;
                if ($byteIndex < count($codewords)) {
                    $dark = ($codewords[$byteIndex] >> $bitIndex) & 1;
                }
                if (qr_mask_bit($mask, $row, $x)) {
                    $dark ^= 1;
                }
                $m[$row][$x] = $dark;

                $bitIndex--;
                if ($bitIndex === -1) {
                    $byteIndex++;
                    $bitIndex = 7;
                }
            }
            $row += $inc;
            if ($row < 0 || $row >= $size) {
                $row -= $inc;
                $inc = -$inc;
                break;
            }
        }
    }

    // 形式情報（レベルM = 00）
    $format = qr_format_bits($mask);
    for ($i = 0; $i < 15; $i++) {
        $bit = ($format >> $i) & 1;
        if ($i < 6) {
            $m[$i][8] = $bit;
        } elseif ($i < 8) {
            $m[$i + 1][8] = $bit;
        } else {
            $m[$size - 15 + $i][8] = $bit;
        }

        if ($i < 8) {
            $m[8][$size - 1 - $i] = $bit;
        } elseif ($i === 8) {
            $m[8][7] = $bit;   // 列6はタイミングパターンなので飛ばす
        } else {
            $m[8][14 - $i] = $bit;
        }
    }

    // 型番情報
    if ($version >= 7) {
        $versionBits = qr_version_bits($version);
        for ($i = 0; $i < 18; $i++) {
            $bit = ($versionBits >> $i) & 1;
            $m[intdiv($i, 3)][$i % 3 + $size - 11] = $bit;
            $m[$i % 3 + $size - 11][intdiv($i, 3)] = $bit;
        }
    }

    return $m;
}

/** マスク条件（該当するモジュールは白黒を反転する） */
function qr_mask_bit(int $mask, int $i, int $j): bool
{
    return match ($mask) {
        0 => ($i + $j) % 2 === 0,
        1 => $i % 2 === 0,
        2 => $j % 3 === 0,
        3 => ($i + $j) % 3 === 0,
        4 => (intdiv($i, 2) + intdiv($j, 3)) % 2 === 0,
        5 => ($i * $j) % 2 + ($i * $j) % 3 === 0,
        6 => (($i * $j) % 2 + ($i * $j) % 3) % 2 === 0,
        7 => ((($i + $j) % 2) + (($i * $j) % 3)) % 2 === 0,
        default => false,
    };
}

/** 形式情報15ビット（誤り訂正レベルM固定＋マスク番号、BCH符号＋マスク処理） */
function qr_format_bits(int $mask): int
{
    $data = (0b00 << 3) | $mask; // レベルM = 00
    $bch  = $data << 10;
    for ($i = 4; $i >= 0; $i--) {
        if ($bch & (1 << ($i + 10))) {
            $bch ^= 0b10100110111 << $i;
        }
    }

    return (($data << 10) | $bch) ^ 0b101010000010010;
}

/** 型番情報18ビット（型番7以上で使用） */
function qr_version_bits(int $version): int
{
    $bch = $version << 12;
    for ($i = 5; $i >= 0; $i--) {
        if ($bch & (1 << ($i + 12))) {
            $bch ^= 0b1111100100101 << $i; // 生成多項式 0x1F25
        }
    }

    return ($version << 12) | $bch;
}

/** マスクの良さを測る減点（小さいほど良い） */
function qr_penalty(array $m, int $size): int
{
    $penalty = 0;

    // 規則1：同じ色が5個以上連続
    for ($i = 0; $i < $size; $i++) {
        for ($dir = 0; $dir < 2; $dir++) {
            $runColor = -1;
            $runLen   = 0;
            for ($j = 0; $j < $size; $j++) {
                $value = $dir === 0 ? $m[$i][$j] : $m[$j][$i];
                if ($value === $runColor) {
                    $runLen++;
                    continue;
                }
                if ($runLen >= 5) {
                    $penalty += 3 + ($runLen - 5);
                }
                $runColor = $value;
                $runLen   = 1;
            }
            if ($runLen >= 5) {
                $penalty += 3 + ($runLen - 5);
            }
        }
    }

    // 規則2：2×2の同色ブロック
    for ($y = 0; $y < $size - 1; $y++) {
        for ($x = 0; $x < $size - 1; $x++) {
            $v = $m[$y][$x];
            if ($v === $m[$y][$x + 1] && $v === $m[$y + 1][$x] && $v === $m[$y + 1][$x + 1]) {
                $penalty += 3;
            }
        }
    }

    // 規則3：位置検出パターンに似た並び
    $patterns = [[1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0], [0, 0, 0, 0, 1, 0, 1, 1, 1, 0, 1]];
    for ($i = 0; $i < $size; $i++) {
        for ($j = 0; $j < $size - 10; $j++) {
            foreach ($patterns as $pattern) {
                $rowMatch = true;
                $colMatch = true;
                for ($k = 0; $k < 11; $k++) {
                    if ($m[$i][$j + $k] !== $pattern[$k]) {
                        $rowMatch = false;
                    }
                    if ($m[$j + $k][$i] !== $pattern[$k]) {
                        $colMatch = false;
                    }
                }
                if ($rowMatch) {
                    $penalty += 40;
                }
                if ($colMatch) {
                    $penalty += 40;
                }
            }
        }
    }

    // 規則4：暗モジュールの比率の偏り
    $dark = 0;
    foreach ($m as $row) {
        $dark += array_sum($row);
    }
    $ratio = $dark * 100 / ($size * $size);
    $penalty += (int) (abs($ratio - 50) / 5) * 10;

    return $penalty;
}

/**
 * QRコードをSVG文字列で返す。
 *
 * @param int $moduleSize 1モジュールの大きさ（px）
 * @param int $quietZone  余白のモジュール数（規格上4以上）
 */
function qr_svg(string $text, int $moduleSize = 4, int $quietZone = 4): string
{
    $m     = qr_matrix($text);
    $size  = count($m);
    $total = ($size + $quietZone * 2) * $moduleSize;

    // 同じ行の連続する暗モジュールは1つの矩形にまとめ、SVGを小さくする
    $rects = '';
    for ($y = 0; $y < $size; $y++) {
        $x = 0;
        while ($x < $size) {
            if ($m[$y][$x] !== 1) {
                $x++;
                continue;
            }
            $runStart = $x;
            while ($x < $size && $m[$y][$x] === 1) {
                $x++;
            }
            $rects .= sprintf(
                '<rect x="%d" y="%d" width="%d" height="%d"/>',
                ($runStart + $quietZone) * $moduleSize,
                ($y + $quietZone) * $moduleSize,
                ($x - $runStart) * $moduleSize,
                $moduleSize
            );
        }
    }

    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $total . '" height="' . $total . '" '
        . 'viewBox="0 0 ' . $total . ' ' . $total . '" shape-rendering="crispEdges" role="img" '
        . 'aria-label="QRコード">'
        . '<rect width="' . $total . '" height="' . $total . '" fill="#ffffff"/>'
        . '<g fill="#000000">' . $rects . '</g></svg>';
}

/**
 * QRコードをPNGのバイナリで返す（GD拡張が必要）。
 *
 * 印刷業者へ渡す場合など、画像ファイルが必要なときに使う。
 * GDが無い環境では null を返すので、呼び出し側はSVGにフォールバックする。
 */
function qr_png(string $text, int $moduleSize = 8, int $quietZone = 4): ?string
{
    if (!function_exists('imagecreatetruecolor')) {
        return null;
    }

    $m     = qr_matrix($text);
    $size  = count($m);
    $total = ($size + $quietZone * 2) * $moduleSize;

    $image = imagecreatetruecolor($total, $total);
    $white = imagecolorallocate($image, 255, 255, 255);
    $black = imagecolorallocate($image, 0, 0, 0);
    imagefilledrectangle($image, 0, 0, $total, $total, $white);

    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            if ($m[$y][$x] !== 1) {
                continue;
            }
            $px = ($x + $quietZone) * $moduleSize;
            $py = ($y + $quietZone) * $moduleSize;
            imagefilledrectangle($image, $px, $py, $px + $moduleSize - 1, $py + $moduleSize - 1, $black);
        }
    }

    ob_start();
    imagepng($image);
    $png = ob_get_clean();
    imagedestroy($image);

    return $png === false ? null : $png;
}
