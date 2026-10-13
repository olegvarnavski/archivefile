<?php
define('FEEDARCHIVE_ENTRY', true);
require __DIR__ . '/bootstrap.php';
$modx->initialize('mgr');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
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
            $data = array('sources' => $service->sources(), 'monitor' => $service->cronStatus(), 'events' => $service->events(), 'cron' => escapeshellarg($modx->getOption('archivefile.cron_php_path', null, '/usr/bin/php7.4')) . ' ' . escapeshellarg(MODX_CORE_PATH . 'components/archivefile/cron.php'));
            break;
        case 'copies': $data = $service->copies(isset($_POST['id']) ? $_POST['id'] : 0); break;
        case 'save': $data = array('id' => $service->save($_POST)); break;
        case 'delete': $data = $service->delete(isset($_POST['id']) ? $_POST['id'] : 0); break;
        case 'fetch':
            @set_time_limit(150);
            // Release the session lock so the manager stays usable during downloading.
            session_write_close();
            $data = $service->fetch(isset($_POST['id']) ? $_POST['id'] : 0);
            break;
        default: throw new RuntimeException('Неизвестное действие');
    }
    echo json_encode(array('success' => true, 'data' => $data), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    echo json_encode(array('success' => false, 'message' => $e->getMessage()), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}
