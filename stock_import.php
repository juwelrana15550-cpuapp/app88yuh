<?php
/**
 * Reads items for instant delivery from an upload (TXT / CSV / XLSX) or pasted text.
 * One item per line / row, e.g.  mail@example.com|password|recovery@mail.com
 * Rows from CSV and XLSX files have their cells joined with "|" so every format ends up as the same kind of line.
 * Needs no PHP extension except zlib (gzinflate), which every standard PHP build has.
 */

const STOCK_MAX_FILE  = 8388608;   // 8 MB upload
const STOCK_MAX_LINES = 200000;    // per upload
const STOCK_MAX_LEN   = 1000;      // characters per item

/** Tidy raw text into item lines: fix encoding, drop blank lines, trim. Returns [lines, skippedTooLong]. */
function stock_lines_from_text(string $text, bool $skipHeader = false): array {
    if (strncmp($text, "\xEF\xBB\xBF", 3) === 0) $text = substr($text, 3);
    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    }
    $text = preg_replace('/[^\P{C}\t\r\n]/u', '', $text) ?? '';   // control characters out, tabs/newlines stay
    $out = []; $long = 0; $first = true;
    foreach (preg_split('/\r\n|\r|\n/', $text) as $l) {
        $l = trim($l);
        if ($l === '') continue;
        if ($first) { $first = false; if ($skipHeader) continue; }
        if (mb_strlen($l) > STOCK_MAX_LEN) { $long++; continue; }
        $out[] = $l;
    }
    return [$out, $long];
}

/** Joins the cells of one spreadsheet row with "|" (inner empty cells kept, trailing ones dropped). */
function stock_join_cells(array $cells): string {
    $cells = array_map(fn($c) => trim(str_replace(["\r", "\n"], ' ', (string)$c)), $cells);
    while ($cells && end($cells) === '') array_pop($cells);
    return implode('|', $cells);
}

function stock_lines_from_csv(string $text, bool $skipHeader): array {
    if (strncmp($text, "\xEF\xBB\xBF", 3) === 0) $text = substr($text, 3);
    if (!mb_check_encoding($text, 'UTF-8')) $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    $fh = fopen('php://temp', 'r+'); fwrite($fh, $text); rewind($fh);
    // semicolon / tab separated exports: pick the separator the first line uses most
    $head = (string)strtok($text, "\n");
    $sep = ','; $best = substr_count($head, ',');
    foreach ([';' => ';', "\t" => "\t"] as $c) { if (substr_count($head, $c) > $best) { $sep = $c; $best = substr_count($head, $c); } }
    $lines = [];
    while (($row = fgetcsv($fh, 0, $sep, '"', '')) !== false) {
        $lines[] = stock_join_cells($row);
    }
    fclose($fh);
    return stock_lines_from_text(implode("\n", $lines), $skipHeader);
}

/* ------------------------------ XLSX (zip + xml) ------------------------------ */

/** Reads the named entries out of a zip held in memory. Returns [name => content] for the entries that exist. */
function stock_zip_read(string $zip, array $want): array {
    $n = strlen($zip);
    $eocd = strrpos($zip, "PK\x05\x06");
    if ($eocd === false || $eocd + 22 > $n) throw new RuntimeException('This file is not a valid XLSX.');
    $e = unpack('vdisk/vcd_disk/ventries_disk/ventries/Vcd_size/Vcd_off', substr($zip, $eocd + 4, 18));
    $out = []; $p = $e['cd_off'];
    for ($i = 0; $i < $e['entries']; $i++) {
        if (substr($zip, $p, 4) !== "PK\x01\x02") break;
        $h = unpack('vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen/vclen/vdisk/viattr/Veattr/Voff', substr($zip, $p + 4, 42));
        $name = substr($zip, $p + 46, $h['nlen']);
        $p += 46 + $h['nlen'] + $h['elen'] + $h['clen'];
        if (!in_array($name, $want, true)) continue;
        if ($h['usize'] > 60 * 1048576) throw new RuntimeException('The spreadsheet is too large.');
        $l = unpack('vnlen/velen', substr($zip, $h['off'] + 26, 4));
        $data = substr($zip, $h['off'] + 30 + $l['nlen'] + $l['elen'], $h['csize']);
        if ($h['method'] === 0) $out[$name] = $data;
        elseif ($h['method'] === 8) { $d = @gzinflate($data, 60 * 1048576); if ($d === false) throw new RuntimeException('The spreadsheet could not be read.'); $out[$name] = $d; }
    }
    return $out;
}

function stock_col_index(string $ref): int {
    $c = 0;
    for ($i = 0, $L = strlen($ref); $i < $L; $i++) {
        $o = ord($ref[$i]);
        if ($o >= 65 && $o <= 90) $c = $c * 26 + ($o - 64); elseif ($o >= 97 && $o <= 122) $c = $c * 26 + ($o - 96); else break;
    }
    return max(0, $c - 1);
}

/** Text of every <t> inside the reader's current element (shared string or inline string). */
function stock_xml_text(XMLReader $x): string {
    $t = ''; $d = $x->depth;
    if ($x->isEmptyElement) return '';
    while ($x->read()) {
        if ($x->nodeType === XMLReader::END_ELEMENT && $x->depth === $d) break;
        if ($x->nodeType === XMLReader::ELEMENT && $x->localName === 't') $t .= $x->readString();
    }
    return $t;
}

function stock_xml(string $xml): XMLReader {
    $x = new XMLReader();
    if (!$x->XML($xml, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT)) throw new RuntimeException('The spreadsheet could not be read.');
    return $x;
}

function stock_lines_from_xlsx(string $bin, bool $skipHeader): array {
    $f = stock_zip_read($bin, ['xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/sharedStrings.xml']);
    // find the first sheet's file
    $sheetPath = 'xl/worksheets/sheet1.xml';
    if (isset($f['xl/workbook.xml'], $f['xl/_rels/workbook.xml.rels'])) {
        $rid = null; $x = stock_xml($f['xl/workbook.xml']);
        while ($x->read()) { if ($x->nodeType === XMLReader::ELEMENT && $x->localName === 'sheet') { $rid = $x->getAttributeNs('id', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships'); break; } }
        if ($rid) {
            $x = stock_xml($f['xl/_rels/workbook.xml.rels']);
            while ($x->read()) {
                if ($x->nodeType === XMLReader::ELEMENT && $x->localName === 'Relationship' && $x->getAttribute('Id') === $rid) {
                    $t = (string)$x->getAttribute('Target');
                    $sheetPath = ltrim(strncmp($t, '/', 1) === 0 ? $t : 'xl/' . $t, '/');
                    break;
                }
            }
        }
    }
    $sheet = stock_zip_read($bin, [$sheetPath])[$sheetPath] ?? null;
    if ($sheet === null) throw new RuntimeException('Could not find the first sheet in this file.');

    $shared = [];
    if (isset($f['xl/sharedStrings.xml'])) {
        $x = stock_xml($f['xl/sharedStrings.xml']);
        while ($x->read()) { if ($x->nodeType === XMLReader::ELEMENT && $x->localName === 'si') $shared[] = stock_xml_text($x); }
    }

    $lines = [];
    $x = stock_xml($sheet);
    while ($x->read()) {
        if ($x->nodeType !== XMLReader::ELEMENT || $x->localName !== 'row') continue;
        $cells = []; $rowDepth = $x->depth; $next = 0;
        if (!$x->isEmptyElement) {
            while ($x->read()) {
                if ($x->nodeType === XMLReader::END_ELEMENT && $x->depth === $rowDepth) break;
                if ($x->nodeType !== XMLReader::ELEMENT || $x->localName !== 'c') continue;
                $ref = (string)$x->getAttribute('r'); $type = (string)$x->getAttribute('t');
                $col = $ref !== '' ? stock_col_index($ref) : $next; $next = $col + 1;
                $val = ''; $cd = $x->depth;
                if (!$x->isEmptyElement) {
                    while ($x->read()) {
                        if ($x->nodeType === XMLReader::END_ELEMENT && $x->depth === $cd) break;
                        if ($x->nodeType !== XMLReader::ELEMENT) continue;
                        if ($x->localName === 'v') { $val = $x->readString(); }
                        elseif ($x->localName === 'is') { $val = stock_xml_text($x); }
                    }
                }
                if ($type === 's') $val = $shared[(int)$val] ?? '';
                elseif ($type === 'b' || $type === 'e') $val = '';
                $cells[$col] = $val;
            }
        }
        if ($cells) { $max = max(array_keys($cells)); $row = []; for ($i = 0; $i <= $max; $i++) $row[] = $cells[$i] ?? ''; $lines[] = stock_join_cells($row); }
        if (count($lines) > STOCK_MAX_LINES + 1) break;
    }
    return stock_lines_from_text(implode("\n", $lines), $skipHeader);
}

/**
 * Collects items from the pasted text and/or the uploaded file.
 * Returns ['lines' => [...], 'long' => n]  or throws RuntimeException with a message for the admin.
 */
function stock_collect(string $pasted, ?array $file, bool $skipHeader): array {
    $lines = []; $long = 0;
    if (trim($pasted) !== '') { [$l, $g] = stock_lines_from_text($pasted, $skipHeader); $lines = $l; $long += $g; }
    if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > STOCK_MAX_FILE) throw new RuntimeException('The file is too large (max 8 MB) or the upload failed.');
        $bin = (string)file_get_contents($file['tmp_name']);
        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if ($ext === 'xlsx' || strncmp($bin, "PK\x03\x04", 4) === 0) [$l, $g] = stock_lines_from_xlsx($bin, $skipHeader);
        elseif ($ext === 'xls' && strncmp($bin, "\xD0\xCF\x11\xE0", 4) === 0) throw new RuntimeException('Old .xls files are not supported. Please save the sheet as XLSX or CSV.');
        elseif ($ext === 'csv' || $ext === 'tsv') [$l, $g] = stock_lines_from_csv($bin, $skipHeader);
        elseif ($ext === 'txt' || $ext === '') [$l, $g] = stock_lines_from_text($bin, $skipHeader);
        else throw new RuntimeException('Upload a .txt, .csv or .xlsx file.');
        $lines = array_merge($lines, $l); $long += $g;
    }
    if (count($lines) > STOCK_MAX_LINES) throw new RuntimeException('Too many items in one upload (max ' . number_format(STOCK_MAX_LINES) . '). Please split it.');
    return ['lines' => $lines, 'long' => $long];
}

/**
 * Saves items for a product (inside one transaction), switches the product to instant delivery and refreshes its stock.
 * Returns [added, duplicatesSkipped].
 */
function stock_add(PDO $pdo, int $pid, array $lines, bool $dedupe): array {
    return with_tx($pdo, function (PDO $pdo) use ($pid, $lines, $dedupe) {
        $st = $pdo->prepare('SELECT id FROM products WHERE id = ? FOR UPDATE'); $st->execute([$pid]);
        if (!$st->fetchColumn()) throw new RuntimeException('Product not found.');
        $seen = [];
        if ($dedupe) {
            $q = $pdo->prepare('SELECT line FROM stock_items WHERE product_id = ? AND order_id IS NULL'); $q->execute([$pid]);
            foreach ($q as $r) $seen[$r['line']] = true;
        }
        $new = []; $dup = 0;
        foreach ($lines as $l) {
            if ($dedupe) { if (isset($seen[$l])) { $dup++; continue; } $seen[$l] = true; }
            $new[] = $l;
        }
        foreach (array_chunk($new, 500) as $chunk) {
            $pdo->prepare('INSERT INTO stock_items (product_id, line) VALUES ' . implode(',', array_fill(0, count($chunk), '(?,?)')))
                ->execute(array_merge(...array_map(fn($l) => [$pid, $l], $chunk)));
        }
        $pdo->prepare('UPDATE products SET auto_delivery = 1 WHERE id = ?')->execute([$pid]);
        stock_sync($pdo, $pid);
        return [count($new), $dup];
    });
}
