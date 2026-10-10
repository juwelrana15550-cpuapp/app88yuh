<?php
/**
 * Delivered-item helpers shared by order.php (page) and download.php (file export).
 * An order's `delivery` text holds one item per line, e.g.  mail@example.com|password|recovery...
 */

/** Download formats: key => [label shown in the dropdown, file extension]. */
function order_formats(): array {
    return [
        'txt'      => ['TXT', 'txt'],
        'csv'      => ['CSV', 'csv'],
        'csv_split'=> ['CSV (Split)', 'csv'],
        'xls'      => ['XLS', 'xls'],
        'xlsx'     => ['XLSX', 'xlsx'],
        'xlsx_split' => ['XLSX (Split)', 'xlsx'],
        'csv_full' => ['CSV (Email,Pass,Full)', 'csv'],
        'xlsx_full'=> ['XLSX (Email,Pass,Full)', 'xlsx'],
        'txt_mp'   => ['TXT (mail|pass)', 'txt'],
        'xlsx_mp'  => ['XLSX (mail, pass)', 'xlsx'],
    ];
}

/** Non-empty lines of the delivery text. */
function order_lines(?string $delivery): array {
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', (string)$delivery) as $l) {
        $l = trim($l);
        if ($l !== '') $out[] = $l;
    }
    return $out;
}

/** Split one item line into its parts (delimiter is | or : or ; or tab or comma, whichever the line uses first in that order). */
function order_parts(string $line): array {
    foreach (['|', ':', ';', "\t", ','] as $d) {
        if (strpos($line, $d) !== false) return array_map('trim', explode($d, $line));
    }
    return [$line];
}

/** Rows (arrays of cells) for a format, header row first where the format has one. */
function order_rows(string $fmt, array $lines): array {
    $rows = [];
    switch ($fmt) {
        case 'csv_split': case 'xlsx_split':
            foreach ($lines as $l) $rows[] = order_parts($l);
            break;
        case 'csv_full': case 'xlsx_full':
            $rows[] = ['Email', 'Pass', 'Full'];
            foreach ($lines as $l) { $p = order_parts($l); $rows[] = [$p[0], $p[1] ?? '', $l]; }
            break;
        case 'xlsx_mp':
            $rows[] = ['mail', 'pass'];
            foreach ($lines as $l) { $p = order_parts($l); $rows[] = [$p[0], $p[1] ?? '']; }
            break;
        default: // csv, xls, xlsx: one column holding the whole line
            foreach ($lines as $l) $rows[] = [$l];
    }
    return $rows;
}

/** Minimal .xlsx writer (no extensions needed): stores a single sheet of text cells in an uncompressed zip. */
function xlsx_build(array $rows): string {
    $col = function (int $i): string { $s = ''; for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s; return $s; };
    $x = function (string $v): string {
        $v = preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $v) ?? '';
        return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    };
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    foreach ($rows as $r => $cells) {
        $sheet .= '<row r="' . ($r + 1) . '">';
        foreach (array_values($cells) as $c => $v) {
            $sheet .= '<c r="' . $col($c) . ($r + 1) . '" t="inlineStr"><is><t xml:space="preserve">' . $x((string)$v) . '</t></is></c>';
        }
        $sheet .= '</row>';
    }
    $sheet .= '</sheetData></worksheet>';
    $hdr = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $files = [
        '[Content_Types].xml' => $hdr . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
        '_rels/.rels' => $hdr . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => $hdr . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Data" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => $hdr . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
        'xl/worksheets/sheet1.xml' => $sheet,
    ];
    $out = ''; $cd = ''; $n = 0;
    foreach ($files as $name => $data) {
        $crc = crc32($data); $len = strlen($data); $nl = strlen($name); $off = strlen($out);
        $out .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 33, $crc, $len, $len, $nl, 0) . $name . $data;
        $cd  .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 33, $crc, $len, $len, $nl, 0, 0, 0, 0, 0, $off) . $name;
        $n++;
    }
    return $out . $cd . pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($cd), strlen($out), 0);
}
