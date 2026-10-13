<?php
/** Script vehicle executed only by the MODX Package Manager. */
if (!isset($transport) || !($transport->xpdo instanceof modX)) { return false; }
$modx = $transport->xpdo;
$action = isset($options[xPDOTransport::PACKAGE_ACTION]) ? (int)$options[xPDOTransport::PACKAGE_ACTION] : xPDOTransport::ACTION_INSTALL;
if ($action === xPDOTransport::ACTION_UNINSTALL) {
    $menu = $modx->getObject('modMenu', array('text' => 'archivefile', 'namespace' => 'archivefile'));
    if ($menu && !$menu->remove()) { return false; }
    $setting = $modx->getObject('modSystemSetting', 'archivefile.installed');
    if ($setting) { $setting->set('value', '0'); if (!$setting->save()) { return false; } }
    $modx->getCacheManager()->refresh();
    // Keep files, namespace, settings, tables and copies for safe reinstallation.
    return true;
}
if (version_compare(PHP_VERSION, '7.4.0', '<') || !extension_loaded('curl') || !class_exists('XMLReader') || !extension_loaded('pdo_mysql')) {
    $modx->log(modX::LOG_LEVEL_ERROR, 'ArchiveFile requires PHP 7.4+, cURL, XMLReader and PDO MySQL');
    return false;
}
try {
    $core = MODX_CORE_PATH . 'components/archivefile/';
    $assets = MODX_ASSETS_PATH . 'components/archivefile/';
    $payload = __DIR__ . '/payload/';
    $cache = $modx->getCacheManager();
    if (!$cache->copyTree($payload . 'core/components/archivefile/', $core)
        || !$cache->copyTree($payload . 'assets/components/archivefile/', $assets)) {
        throw new RuntimeException('Не удалось разместить файлы компонента');
    }
    $runtime = "<?php\nif (PHP_SAPI !== 'cli' && !defined('FEEDARCHIVE_ENTRY')) { http_response_code(403); exit; }\nrequire_once " . var_export(MODX_BASE_PATH . 'config.core.php', true) . ";\n";
    if (file_put_contents($core . 'runtime.php', $runtime) === false || file_put_contents($assets . 'runtime.php', $runtime) === false) {
        throw new RuntimeException('Не удалось записать пути MODX');
    }
    $ns = $modx->getObject('modNamespace', 'archivefile');
    if (!$ns) { $ns = $modx->newObject('modNamespace'); }
    $ns->fromArray(array('name' => 'archivefile', 'path' => '{core_path}components/archivefile/', 'assets_path' => '{assets_path}components/archivefile/'), '', true, true);
    if (!$ns->save()) { throw new RuntimeException('Не удалось зарегистрировать namespace'); }
    require_once $core . 'model/archivefile.class.php';
    $service = new ArchiveFile($modx);
    $service->install();
    $service->migratePublic();
    foreach (array('archivefile.max_size_mb' => '50', 'archivefile.storage_path' => $core . 'storage/', 'archivefile.installed' => '1') as $key => $value) {
        $s = $modx->getObject('modSystemSetting', $key);
        if (!$s) {
            $s = $modx->newObject('modSystemSetting');
            $s->fromArray(array('key' => $key, 'value' => $value, 'namespace' => 'archivefile', 'area' => 'general', 'xtype' => $key === 'archivefile.installed' ? 'combo-boolean' : 'textfield'), '', true, true);
        } elseif ($key === 'archivefile.installed') { $s->set('value', '1'); }
        if (!$s->save()) { throw new RuntimeException('Не удалось сохранить настройку ' . $key); }
    }
    $menu = $modx->getObject('modMenu', array('text' => 'archivefile'));
    if (!$menu) { $menu = $modx->newObject('modMenu'); }
    $menu->fromArray(array('text' => 'archivefile', 'parent' => 'components', 'action' => 'home', 'namespace' => 'archivefile', 'description' => 'archivefile_desc', 'permissions' => 'settings', 'menuindex' => 10), '', true, true);
    if (!$menu->save()) { throw new RuntimeException('Не удалось добавить пункт меню'); }
    $modx->getCacheManager()->refresh();
    $modx->log(modX::LOG_LEVEL_INFO, 'ArchiveFile installed. Configure CLI cron using the command shown in the component.');
    return true;
} catch (Throwable $e) {
    $modx->log(modX::LOG_LEVEL_ERROR, 'ArchiveFile: ' . $e->getMessage());
    return false;
}
