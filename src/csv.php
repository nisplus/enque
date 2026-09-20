<?php
declare(strict_types=1);

/**
 * CSV出力。
 *
 * Excel でそのまま開けるよう UTF-8 BOM 付き・CRLF 改行で書き出す。
 */

/**
 * CSVをダウンロードさせて終了する。
 *
 * @param list<list<string|int|float|null>> $rows 1行目はヘッダー
 */
function csv_download(string $fileName, array $rows): never
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Cache-Control: no-store');

    echo "\xEF\xBB\xBF"; // BOM
    $out = fopen('php://output', 'w');
    if ($out === false) {
        exit;
    }
    foreach ($rows as $row) {
        // fputcsv は LF で終わるため、CRLF に直して書き出す
        $line = csv_line($row);
        fwrite($out, $line);
    }
    fclose($out);
    exit;
}

/**
 * 1行をCSVの文字列にする（常にダブルクォートで囲み、改行もそのまま保持する）。
 *
 * 先頭が = + - @ の値は、表計算ソフトが数式として解釈しないよう ' を前置する
 * （CSVインジェクション対策）。
 *
 * @param list<string|int|float|null> $row
 */
function csv_line(array $row): string
{
    $cells = [];
    foreach ($row as $value) {
        $text = (string) ($value ?? '');
        if ($text !== '' && str_contains('=+-@', $text[0])) {
            $text = "'" . $text;
        }
        $cells[] = '"' . str_replace('"', '""', $text) . '"';
    }

    return implode(',', $cells) . "\r\n";
}

/** ファイル名に使えない文字を落とす */
function csv_safe_filename(string $name): string
{
    $name = preg_replace('/[\\\\\/:*?"<>|\s]+/u', '_', $name) ?? 'export';

    return mb_substr($name, 0, 60);
}
