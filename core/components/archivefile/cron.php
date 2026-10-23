<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$service = null; $lock = null; $run = null; $failed = false;
$started = microtime(true); $ok = 0; $errors = 0; $skipped = 0;
try {
    require __DIR__ . '/runtime.php';
    require_once MODX_CORE_PATH . 'model/modx/modx.class.php';
    $modx = new modX(); $modx->initialize('web');
    if (!$modx->getOption('archivefile.installed', null, false)) throw new RuntimeException('ArchiveFile disabled');
    require_once __DIR__ . '/model/archivefile.class.php';
    $service = new ArchiveFile($modx); $lock = $service->cronLock();
    if (!$lock) { echo "ArchiveFile cron already running\n"; exit(0); }
    $run = $service->beginEvent('cron');
    foreach ($service->sources(false) as $source) {
        if (!$source['enabled']) continue;
        try {
            $r = $service->fetch($source['id'], true);
            if (!empty($r['skipped'])) $skipped++; else $ok++;
            echo '['.date('c').'] #'.$source['id'].(!empty($r['skipped'])?' SKIP':' OK').PHP_EOL;
        } catch (Throwable $e) {
            $failed = true; $errors++;
            fwrite(STDERR,'#'.$source['id'].': '.$e->getMessage().PHP_EOL);
        }
    }
} catch (Throwable $e) { $failed = true; $errors++; fwrite(STDERR,'ArchiveFile cron: '.$e->getMessage().PHP_EOL); }
finally {
    try {
        if ($service && $run) $service->finishEvent($run,$failed?'error':'ok',$started,'Успешно: '.$ok.'; ошибок: '.$errors.'; пропущено: '.$skipped);
    } catch (Throwable $e) { $failed = true; fwrite(STDERR,'ArchiveFile journal: '.$e->getMessage().PHP_EOL); }
    finally { if (is_resource($lock)) { flock($lock,LOCK_UN); fclose($lock); } }
}
exit($failed ? 1 : 0);
