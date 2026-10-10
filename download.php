<?php
// Sends a delivered order's items as a file in the format the customer picked.
//   GET /download.php?id=<order id>&fmt=<key from order_formats()>
require __DIR__ . '/lib.php';
require __DIR__ . '/order_fmt.php';
$u = require_login();
session_write_close();

$fmts = order_formats();
$fmt = (string)($_GET['fmt'] ?? 'txt');
if (!isset($fmts[$fmt])) $fmt = 'txt';

$s = db()->prepare("SELECT id, delivery FROM orders WHERE id = ? AND user_id = ? AND status = 'delivered'");
$s->execute([(int)($_GET['id'] ?? 0), $u['id']]);
$o = $s->fetch();
$lines = $o ? order_lines($o['delivery']) : [];
if (!$lines) { http_response_code(404); exit('No delivered data for this order.'); }

[$label, $ext] = $fmts[$fmt];
$name = 'order-' . (int)$o['id'] . '-' . str_replace('_', '-', $fmt) . '.' . $ext;
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Disposition: attachment; filename="' . $name . '"');

if ($fmt === 'txt') {
    header('Content-Type: text/plain; charset=utf-8');
    echo implode("\r\n", $lines), "\r\n";
} elseif ($fmt === 'txt_mp') {
    header('Content-Type: text/plain; charset=utf-8');
    foreach ($lines as $l) { $p = order_parts($l); echo $p[0], '|', $p[1] ?? '', "\r\n"; }
} elseif ($ext === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    echo "\xEF\xBB\xBF";                       // BOM so Excel reads UTF-8 correctly
    $out = fopen('php://output', 'w');
    foreach (order_rows($fmt, $lines) as $row) fputcsv($out, $row, ',', '"', '');
    fclose($out);
} elseif ($ext === 'xls') {
    // Old Excel format: an HTML table that Excel and LibreOffice open as a normal sheet.
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    echo '<html><head><meta charset="utf-8"></head><body><table>';
    foreach (order_rows($fmt, $lines) as $row) {
        echo '<tr>'; foreach ($row as $v) echo '<td style="mso-number-format:\'\@\'">', e($v), '</td>'; echo '</tr>';
    }
    echo '</table></body></html>';
} else {
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    echo xlsx_build(order_rows($fmt, $lines));
}
