<?php
declare(strict_types=1);

/**
 * DB・サーバーを使わない単体テスト。
 *
 *   php tests/unit_test.php
 *
 * QRコードの符号化、回答値の検証、CSVの整形、交換コードの正規化を確認する。
 * 出力は Windows のコンソール（CP932）でも読めるよう英数字のみとする。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once dirname(__DIR__) . '/src/config.php';
require_once dirname(__DIR__) . '/src/helpers.php';
require_once dirname(__DIR__) . '/src/survey.php';
require_once dirname(__DIR__) . '/src/csv.php';
require_once dirname(__DIR__) . '/src/qrcode.php';
require_once dirname(__DIR__) . '/src/chart.php';

mb_internal_encoding('UTF-8');

$passed = 0;
$failed = 0;

function check(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[PASS] {$name}\n";
        return;
    }
    $failed++;
    echo "[FAIL] {$name}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
}

echo "=== qr code ===\n";

// JIS X 0510 の既知の値と突き合わせる（型番1-M "HELLO WORLD" の誤り訂正符号）
$data = [32, 91, 11, 120, 209, 114, 220, 77, 67, 64, 236, 17, 236, 17, 236, 17];
$want = [196, 35, 39, 119, 235, 215, 231, 226, 93, 23];
check('reed-solomon matches the reference vector', qr_ec_codewords($data, 10) === $want);

$formats = [0x5412, 0x5125, 0x5E7C, 0x5B4B, 0x45F9, 0x40CE, 0x4F97, 0x4AA0];
$formatOk = true;
foreach ($formats as $mask => $expected) {
    $formatOk = $formatOk && qr_format_bits($mask) === $expected;
}
check('format information matches the standard table (level M, masks 0-7)', $formatOk);

$versions = [7 => 0x07C94, 8 => 0x085BC, 9 => 0x09A99, 10 => 0x0A4D3, 15 => 0x0F928];
$versionOk = true;
foreach ($versions as $version => $expected) {
    $versionOk = $versionOk && qr_version_bits($version) === $expected;
}
check('version information matches the standard table (7-15)', $versionOk);

check('version 1 holds a short string', qr_pick_version(9) === 1);
check('a long url picks a larger version', (int) qr_pick_version(120) >= 7);
check('over 415 bytes does not fit', qr_pick_version(500) === null);

$matrix = qr_matrix('https://survey.example.jp/s/abc123/deadbeefcafe0011');
$size   = count($matrix);
check('matrix is square and sized 4*version+17', $size === count($matrix[0]) && ($size - 17) % 4 === 0);

// 位置検出パターン（3隅の 7x7）
$finderOk = true;
foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$row, $col]) {
    $finderOk = $finderOk
        && $matrix[$row][$col] === 1
        && $matrix[$row + 1][$col + 1] === 0
        && $matrix[$row + 3][$col + 3] === 1
        && $matrix[$row + 6][$col + 6] === 1;
}
check('finder patterns are placed in three corners', $finderOk);

$timingOk = true;
for ($i = 8; $i < $size - 8; $i++) {
    $expected = $i % 2 === 0 ? 1 : 0;
    $timingOk = $timingOk && $matrix[6][$i] === $expected && $matrix[$i][6] === $expected;
}
check('timing patterns alternate', $timingOk);
check('the always-dark module is set', $matrix[$size - 8][8] === 1);

// 形式情報を読み戻し、レベルMと選ばれたマスクが入っていることを確認する
$read = 0;
for ($i = 0; $i < 15; $i++) {
    $bit = $i < 6 ? $matrix[$i][8] : ($i < 8 ? $matrix[$i + 1][8] : $matrix[$size - 15 + $i][8]);
    $read |= $bit << $i;
}
// マスク解除後の上位5ビットが「誤り訂正レベル(2) + マスク番号(3)」
$decoded = $read ^ 0b101010000010010;
check('format information decodes back to error correction level M', ($decoded >> 13 & 0b11) === 0b00);
check('format information holds the mask used',
    qr_format_bits($decoded >> 10 & 0b111) === $read, 'read=' . decbin($read));
check('both copies of the format information agree', (function () use ($matrix, $size): bool {
    for ($i = 0; $i < 8; $i++) {
        if ($matrix[8][$size - 1 - $i] !== ($i < 6 ? $matrix[$i][8] : $matrix[$i + 1][8])) {
            return false;
        }
    }
    return true;
})());

$svg = qr_svg('ABCD-2345', 4, 4);
check('svg output is well formed', str_starts_with($svg, '<svg') && str_ends_with(trim($svg), '</svg>'));
check('svg has a white background and black modules',
    str_contains($svg, 'fill="#ffffff"') && str_contains($svg, 'fill="#000000"'));

echo "=== answer validation ===\n";

$single = ['id' => 1, 'type' => 'single', 'label' => 'Q', 'options' => '["A","B"]', 'required' => 1];
check('required single choice rejects an empty answer', validate_answer($single, null)['ok'] === false);
check('single choice accepts a listed option', validate_answer($single, 'A') === ['ok' => true, 'value' => 'A']);
check('single choice rejects an unlisted option', validate_answer($single, 'Z')['ok'] === false);

$optional = ['id' => 2, 'type' => 'single', 'label' => 'Q', 'options' => '["A","B"]', 'required' => 0];
check('optional single choice allows no answer', validate_answer($optional, '') === ['ok' => true, 'value' => null]);

$multi = ['id' => 3, 'type' => 'multi', 'label' => 'Q', 'options' => '["A","B","C"]', 'required' => 0];
check('multi choice stores a json array', validate_answer($multi, ['A', 'C'])['value'] === '["A","C"]');
check('multi choice removes duplicates', validate_answer($multi, ['A', 'A'])['value'] === '["A"]');
check('multi choice rejects an unlisted option', validate_answer($multi, ['A', 'Z'])['ok'] === false);

$text = ['id' => 4, 'type' => 'text', 'label' => 'Q', 'options' => null, 'required' => 0];
check('free text keeps line breaks', validate_answer($text, "1行目\n2行目")['value'] === "1行目\n2行目");
check('free text strips control characters', validate_answer($text, "a\x07b")['value'] === 'ab');
check('free text rejects overly long input',
    validate_answer($text, str_repeat('あ', TEXT_ANSWER_MAX_LENGTH + 1))['error'] === 'length');

$rating = ['id' => 5, 'type' => 'rating', 'label' => 'Q', 'options' => null, 'required' => 0];
check('rating accepts 1-5', validate_answer($rating, '5')['value'] === '5');
check('rating rejects 0', validate_answer($rating, '0')['ok'] === false);
check('rating rejects 6', validate_answer($rating, '6')['ok'] === false);
check('rating rejects non-numeric input', validate_answer($rating, '3a')['ok'] === false);

// 数値入力（来場人数など）。全角・範囲外・小数の扱いを固定しておく
$number = [
    'id' => 10, 'type' => 'number', 'label' => '何人で来られましたか',
    'options' => json_encode(['min' => 1, 'max' => 10, 'unit' => '人']), 'required' => 0, 'metric' => 'none',
];
check('number accepts a half-width digit', validate_answer($number, '3')['value'] === '3');
check('number accepts a full-width digit', validate_answer($number, '３')['value'] === '3');
check('number trims surrounding spaces', validate_answer($number, ' 4 ')['value'] === '4');
check('number drops leading zeros', validate_answer($number, '05')['value'] === '5');
check('number rejects a value below the minimum', validate_answer($number, '0')['ok'] === false);
check('number rejects a value above the maximum', validate_answer($number, '11')['ok'] === false);
check('number rejects a decimal', validate_answer($number, '2.5')['ok'] === false);
check('number rejects letters', validate_answer($number, 'あ')['ok'] === false);
check('number rejects a negative value', validate_answer($number, '-1')['ok'] === false);
check('an optional number may be left blank', validate_answer($number, '') === ['ok' => true, 'value' => null]);

$requiredNumber = $number;
$requiredNumber['required'] = 1;
check('a required number must be answered', validate_answer($requiredNumber, '')['error'] === 'required');

check('the number answer is shown with its unit', format_answer_value($number, '3') === '3人');
check('the number settings come from the options',
    number_settings($number) === ['min' => 1, 'max' => 10, 'unit' => '人']);
check('the number settings fall back to the defaults',
    number_settings(['type' => 'number', 'options' => null]) === NUMBER_DEFAULTS);
check('settings text is parsed',
    parse_number_settings_text("min=2\nmax=8\nunit=名") === ['min' => 2, 'max' => 8, 'unit' => '名']);
check('a broken range is corrected', parse_number_settings_text("min=5\nmax=1")['max'] === 5);

// 来場人数の設問は、上限を .env（PARTY_SIZE_MAX）に揃える
$party = $number;
$party['metric'] = 'party_size';
check('the party size question follows the configured maximum',
    number_settings($party)['max'] === party_size_max(), 'max=' . number_settings($party)['max']);
check('a value above the configured maximum is rejected',
    validate_answer($party, (string) (party_size_max() + 1))['ok'] === false);
check('the configured maximum itself is accepted',
    validate_answer($party, (string) party_size_max())['value'] === (string) party_size_max());
$nps = ['id' => 6, 'type' => 'nps', 'label' => 'Q', 'options' => null, 'required' => 0];
check('nps accepts 0', validate_answer($nps, '0')['value'] === '0');
check('nps accepts 10', validate_answer($nps, '10')['value'] === '10');
check('nps rejects 11', validate_answer($nps, '11')['ok'] === false);

check('formatting a multi answer joins the choices',
    format_answer_value($multi, '["A","C"]') === 'A / C');
check('formatting a rating shows the maximum',
    format_answer_value($rating, '4') === '4 / ' . RATING_MAX);

check('options text is parsed line by line',
    parse_options_text("A\r\nB\n\n B \nC") === ['A', 'B', 'C']);

echo "=== aggregation ===\n";

$nps = nps_breakdown(['0' => 1, '6' => 1, '7' => 2, '9' => 3, '10' => 3]);
check('nps counts promoters, passives and detractors',
    $nps['promoters'] === 6 && $nps['passives'] === 2 && $nps['detractors'] === 2);
check('nps score is (promoters - detractors) / total', $nps['score'] === 40, 'score=' . $nps['score']);
check('rating average is rounded to two decimals',
    rating_average(['1' => 1, '5' => 2]) === 3.67, (string) rating_average(['1' => 1, '5' => 2]));
check('percentage avoids division by zero', percentage(3, 0) === 0.0);

echo "=== csv ===\n";

check('csv quotes every cell and ends with CRLF', csv_line(['a', 'b']) === "\"a\",\"b\"\r\n");
check('csv escapes double quotes', csv_line(['say "hi"']) === "\"say \"\"hi\"\"\",\r\n" || csv_line(['say "hi"']) === "\"say \"\"hi\"\"\"\r\n");
check('csv keeps line breaks inside a cell', str_contains(csv_line(["a\nb"]), "a\nb"));
check('csv neutralises formula injection', str_starts_with(csv_line(['=1+1']), '"\'=1+1"'));
check('csv filename drops path characters', csv_safe_filename('a/b:c d') === 'a_b_c_d');

echo "=== claim code ===\n";

$code = random_claim_code();
check('claim code has the XXXX-XXXX shape', preg_match('/\A[2-9A-HJ-NP-Z]{4}-[2-9A-HJ-NP-Z]{4}\z/', $code) === 1, $code);
check('claim code avoids look-alike characters (0,O,1,I)',
    preg_match('/[01OI]/', str_replace('-', '', $code)) === 0, $code);
check('lower case input is normalised', normalize_claim_code('abcd2345') === 'ABCD-2345');
check('spaces and hyphens are ignored', normalize_claim_code(' ab cd-23 45 ') === 'ABCD-2345');

echo "=== helpers ===\n";

check('escaping covers quotes', e('<a href="x">&</a>') === '&lt;a href=&quot;x&quot;&gt;&amp;&lt;/a&gt;');
check('full-width spaces are trimmed', trim_ja('　あ　') === 'あ');
check('email validation accepts a normal address', is_valid_email('user@example.jp'));
check('email validation rejects a broken address', !is_valid_email('user@@example'));
check('utf8 validation walks nested arrays',
    is_valid_utf8(['a' => ['b' => 'あ']]) && !is_valid_utf8(['a' => ["\xC3\x28"]]));

echo "\n";
echo "passed: {$passed}\n";
echo "failed: {$failed}\n";

exit($failed === 0 ? 0 : 1);
