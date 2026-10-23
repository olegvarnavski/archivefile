<?php
define('FEEDARCHIVE_ENTRY', true);
require __DIR__ . '/bootstrap.php';
$modx->initialize('web');
try {
    if (!$modx->getOption('archivefile.installed', null, false)) { throw new RuntimeException('Component disabled'); }
    if (!isset($_GET['source']) || !is_string($_GET['source']) || !ctype_digit($_GET['source'])
        || (isset($_GET['copy']) && (!is_string($_GET['copy']) || !ctype_digit($_GET['copy'])))) { throw new RuntimeException('Invalid ID'); }
    $service = new ArchiveFile($modx);
    list($row, $handle) = $service->file((int)$_GET['source'], isset($_GET['copy']) ? (int)$_GET['copy'] : null);
    $ext = pathinfo($row['filename'], PATHINFO_EXTENSION);
    $types = array('xml' => 'application/xml', 'json' => 'application/json', 'csv' => 'text/csv', 'txt' => 'text/plain', 'pdf' => 'application/pdf');
    header('Content-Type: ' . (isset($types[$ext]) ? $types[$ext] : 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: sandbox; default-src 'none'");
    header('Content-Disposition: attachment; filename="' . basename($row['filename']) . '"');
    header('Content-Length: ' . $row['size_bytes']);
    header('Cache-Control: no-store');
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    while (ob_get_level() > 0) { ob_end_clean(); }
    fpassthru($handle); fclose($handle); exit;
} catch (Throwable $e) {
    http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'Файл не найден или срок хранения истёк.';
}
