<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/runtime.php';
require_once MODX_CORE_PATH . 'model/modx/modx.class.php';
$modx = new modX();
$modx->initialize('web');
if (!$modx->getOption('archivefile.installed', null, false)) { fwrite(STDERR, "ArchiveFile disabled\n"); exit(1); }
require_once __DIR__ . '/model/archivefile.class.php';
$service = new ArchiveFile($modx);
$failed = false;
foreach ($service->sources() as $source) {
    if (!$source['enabled']) { continue; }
    try {
        $r = $service->fetch($source['id'], true);
        echo '[' . date('c') . '] #' . $source['id'] . (!empty($r['skipped']) ? ' SKIP' : ' OK') . PHP_EOL;
    } catch (Throwable $e) {
        $failed = true;
        fwrite(STDERR, '#' . $source['id'] . ': ' . $e->getMessage() . PHP_EOL);
    }
}
exit($failed ? 1 : 0);
