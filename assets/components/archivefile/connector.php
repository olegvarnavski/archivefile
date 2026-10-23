<?php
define('FEEDARCHIVE_ENTRY', true);
$outputLevel = ob_get_level();
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    require __DIR__ . '/bootstrap.php';
    $modx->initialize('mgr');
    foreach ($_POST as $key => $value) {
        if (!is_string($value)) throw new InvalidArgumentException('Недопустимое значение: '.$key);
    }
    if (!$modx->getOption('archivefile.installed', null, false)) { throw new RuntimeException('Компонент отключён'); }
    if (!$modx->user->hasSessionContext('mgr') || !$modx->hasPermission('settings')) {
        http_response_code(403); throw new RuntimeException('Нет доступа к управлению архивом');
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['archivefile_csrf'])
        || !isset($_POST['csrf']) || !is_string($_POST['csrf']) || !hash_equals($_SESSION['archivefile_csrf'], $_POST['csrf'])) {
        http_response_code(403); throw new RuntimeException('Обновите страницу админки и повторите действие');
    }
    $service = new ArchiveFile($modx);
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    switch ($action) {
        case 'list':
            $data = array('sources' => $service->sources(), 'monitor' => $service->cronStatus(), 'events' => $service->events(), 'cron' => $service->cronCommand());
            break;
        case 'preview': @set_time_limit(150); session_write_close(); $data=$service->preview($_POST); break;
        case 'copies': $data = $service->copies(ArchiveFile::id(isset($_POST['id']) ? $_POST['id'] : 0)); break;
        case 'save': $data = array('id' => $service->save($_POST)); break;
        case 'delete': $data = $service->delete(ArchiveFile::id(isset($_POST['id']) ? $_POST['id'] : 0)); break;
        case 'fetch':
            @set_time_limit(150);
            // Release the session lock so the manager stays usable during downloading.
            session_write_close();
            $data = $service->fetch(ArchiveFile::id(isset($_POST['id']) ? $_POST['id'] : 0));
            break;
        default: throw new RuntimeException('Неизвестное действие');
    }
    while (ob_get_level() > $outputLevel) ob_end_clean();
    echo json_encode(array('success' => true, 'data' => $data), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    while (ob_get_level() > $outputLevel) ob_end_clean();
    if (isset($modx)) $modx->log(1,'ArchiveFile connector: '.$e->getMessage());
    echo json_encode(array('success' => false, 'message' => $e->getMessage()), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}
