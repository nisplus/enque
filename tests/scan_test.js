/* 受付のQR読み取り（public/assets/scan.js）のうち、カメラを使わない部分のテスト。
 *
 *   node tests/scan_test.js
 *
 * ・読み取った文字列から交換コードを取り出す判定
 * ・同梱した jsQR が、このシステムの生成するQRコードを読めること
 *
 * Node があるときだけ実行できる補助的なテスト（本体の動作に Node は不要）。
 * カメラの起動そのものは自動テストできないため、実機で確認すること。
 */
'use strict';

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const root = path.dirname(__dirname);
let passed = 0;
let failed = 0;

function check(name, condition, detail) {
  if (condition) {
    passed++;
    console.log('[PASS] ' + name);
    return;
  }
  failed++;
  console.log('[FAIL] ' + name + (detail ? ' -- ' + detail : ''));
}

// ---------------------------------------------------------------- 交換コードの取り出し

global.window = global;
require(path.join(root, 'public/assets/scan.js'));
const extract = global.window.enqueExtractClaimCode;

check('scan.js exposes the extractor', typeof extract === 'function');

check('a full url is accepted', extract('https://survey.example.jp/c/ABCD-2345') === 'ABCD-2345');
check('a query string url is accepted', extract('https://survey.example.jp/c.php?code=ABCD-2345') === 'ABCD-2345');
check('a bare code is accepted', extract('ABCD-2345') === 'ABCD-2345');
check('a code without the hyphen is accepted', extract('ABCD2345') === 'ABCD-2345');
check('lower case is normalised', extract('abcd-2345') === 'ABCD-2345');
check('surrounding spaces are ignored', extract('  ABCD-2345 ') === 'ABCD-2345');

check('another site url is rejected', extract('https://example.com/') === null);
check('a booth survey url is rejected', extract('https://survey.example.jp/s/abc123/def456') === null);
check('look-alike characters are rejected', extract('ABCD-2O45') === null);
check('a too short code is rejected', extract('ABCD-234') === null);
check('empty input is rejected', extract('') === null && extract(null) === null);

// ---------------------------------------------------------------- jsQR との組み合わせ

const jsqrPath = path.join(root, 'public/assets/vendor/jsqr.min.js');
check('jsQR is vendored', fs.existsSync(jsqrPath));
check('the jsQR license is vendored', fs.existsSync(path.join(root, 'public/assets/vendor/jsqr-LICENSE.txt')));

global.self = global;
const mod = { exports: {} };
new Function('module', 'exports', 'window', fs.readFileSync(jsqrPath, 'utf8'))(mod, mod.exports, global);
const jsQR = mod.exports.default || mod.exports;
check('jsQR loads', typeof jsQR === 'function');

// このシステムが作るQRコード（PHP側）を、受付が使う jsQR で読めるか確認する
const php = process.env.PHP_BIN || 'C:\\xampp\\php\\php.exe';
const helper = path.join(root, 'tests', 'tmp_qr_dump.php');
const url = 'https://survey.example.jp/c/ABCD-2345';

fs.writeFileSync(helper, `<?php
require_once __DIR__ . '/../src/qrcode.php';
$m = qr_matrix('${url}');
$size = count($m); $scale = 6; $quiet = 4; $w = ($size + $quiet * 2) * $scale;
$out = '';
for ($y = 0; $y < $w; $y++) {
  for ($x = 0; $x < $w; $x++) {
    $mx = intdiv($x, $scale) - $quiet; $my = intdiv($y, $scale) - $quiet;
    $dark = ($mx >= 0 && $my >= 0 && $mx < $size && $my < $size) ? $m[$my][$mx] : 0;
    $v = $dark ? "\\x00" : "\\xFF";
    $out .= $v . $v . $v . "\\xFF";
  }
}
fwrite(STDOUT, pack('N', $w) . $out);
`);

try {
  const raw = execFileSync(php, [helper], { maxBuffer: 64 * 1024 * 1024 });
  const width = raw.readUInt32BE(0);
  const pixels = new Uint8ClampedArray(raw.subarray(4));
  const result = jsQR(pixels, width, width, { inversionAttempts: 'dontInvert' });

  check('jsQR reads the QR code this system generates', result !== null && result.data === url,
    result ? result.data : 'デコードできませんでした');
  check('the decoded value yields the claim code',
    result !== null && extract(result.data) === 'ABCD-2345');
} catch (error) {
  check('the QR code could be generated with PHP', false, String(error.message).slice(0, 120));
  console.log('       PHP_BIN 環境変数で php の場所を指定できます。');
} finally {
  fs.unlinkSync(helper);
}

console.log('');
console.log('passed: ' + passed);
console.log('failed: ' + failed);
process.exit(failed === 0 ? 0 : 1);
