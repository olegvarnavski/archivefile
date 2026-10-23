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
if (version_compare(PHP_VERSION, '7.4.0', '<') || !extension_loaded('curl') || (!class_exists('XMLReader') || !class_exists('DOMDocument')) || !extension_loaded('pdo_mysql')) {
    $modx->log(modX::LOG_LEVEL_ERROR, 'ArchiveFile requires PHP 7.4+, cURL, DOM, XMLReader and PDO MySQL');
    return false;
}
$service = null; $locks = array(); $restore = array(); $backupDir = null; $filesTouched = false; $objectsRestore = array(); $restoreFailed = false;
try {
    if (method_exists($modx,'getVersionData')) {
        $version = $modx->getVersionData(); $number = isset($version['full_version']) ? $version['full_version'] : $version['version'];
        if (version_compare($number,'2.8.0','<') || version_compare($number,'3.0.0','>=')) throw new RuntimeException('Этот пакет предназначен для MODX 2.8.x; MODX 3 пока не поддерживается');
    }
    $core = MODX_CORE_PATH . 'components/archivefile/';
    $assets = MODX_ASSETS_PATH . 'components/archivefile/';
    $payload = __DIR__ . '/payload/';
    $cache = $modx->getCacheManager();
    if (!is_readable(MODX_BASE_PATH.'config.core.php')) throw new RuntimeException('Не найден config.core.php');
    // Preflight every destination before replacing any installed code.
    foreach (array($core,$assets) as $target) {
        $parent = $target;
        while (!file_exists($parent)) $parent = dirname(rtrim($parent,'/'));
        if (!is_dir($parent) || !is_writable($parent)) throw new RuntimeException('Нет прав на запись файлов компонента');
    }
    require_once $payload.'core/components/archivefile/model/archivefile.class.php';
    $service = new ArchiveFile($modx);
    $service->install(); // Only additive tables/indexes; never drops user data.
    $locks = $service->maintenanceLock();
    $backupDir = sys_get_temp_dir().'/archivefile-install-'.bin2hex(random_bytes(8));
    if (!mkdir($backupDir,0700)) throw new RuntimeException('Не удалось создать резервную папку установки');
    foreach (array('core/components/archivefile/'=>$core,'assets/components/archivefile/'=>$assets) as $from=>$to) {
        $paths = array('runtime.php');
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($payload.$from,FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) if ($file->isFile()) $paths[] = substr($file->getPathname(),strlen($payload.$from));
        foreach (array_unique($paths) as $relative) {
            $destination = $to.$relative;
            if (is_link($destination) || (file_exists($destination) && (!is_file($destination) || !is_writable($destination)))) throw new RuntimeException('Недоступен файл установки: '.$relative);
            $saved = null;
            if (is_file($destination)) {
                $saved = $backupDir.'/'.count($restore);
                if (!copy($destination,$saved)) throw new RuntimeException('Не удалось сохранить прежний код компонента');
            }
            $restore[] = array($destination,$saved);
        }
    }
    $filesTouched = true;
    if (!$cache->copyTree($payload . 'core/components/archivefile/', $core)
        || !$cache->copyTree($payload . 'assets/components/archivefile/', $assets)) {
        throw new RuntimeException('Не удалось разместить файлы компонента');
    }
    $runtime = "<?php\nif (PHP_SAPI !== 'cli' && !defined('FEEDARCHIVE_ENTRY')) { http_response_code(403); exit; }\nrequire_once " . var_export(MODX_BASE_PATH . 'config.core.php', true) . ";\n";
    if (file_put_contents($core . 'runtime.php', $runtime) === false || file_put_contents($assets . 'runtime.php', $runtime) === false) {
        throw new RuntimeException('Не удалось записать пути MODX');
    }
    $ns = $modx->getObject('modNamespace', 'archivefile');
    $objectsRestore[] = array('modNamespace','archivefile',$ns ? $ns->toArray() : null);
    if (!$ns) { $ns = $modx->newObject('modNamespace'); }
    $ns->fromArray(array('name' => 'archivefile', 'path' => '{core_path}components/archivefile/', 'assets_path' => '{assets_path}components/archivefile/'), '', true, true);
    if (!$ns->save()) { throw new RuntimeException('Не удалось зарегистрировать namespace'); }
    foreach (array('archivefile.cron_stale_hours' => '36', 'archivefile.drop_warning_percent' => '30', 'archivefile.cron_php_path' => '/usr/bin/php7.4', 'archivefile.max_size_mb' => '50', 'archivefile.storage_path' => $core . 'storage/', 'archivefile.installed' => '1') as $key => $value) {
        $s = $modx->getObject('modSystemSetting', $key);
        $objectsRestore[] = array('modSystemSetting',$key,$s ? $s->toArray() : null);
        if (!$s) {
            $s = $modx->newObject('modSystemSetting');
            $s->fromArray(array('key' => $key, 'value' => $value, 'namespace' => 'archivefile', 'area' => 'general', 'xtype' => $key === 'archivefile.installed' ? 'combo-boolean' : 'textfield'), '', true, true);
        } elseif ($key === 'archivefile.installed') { $s->set('value', '1'); }
        if (!$s->save()) { throw new RuntimeException('Не удалось сохранить настройку ' . $key); }
    }
    $menu = $modx->getObject('modMenu', array('text' => 'archivefile'));
    $objectsRestore[] = array('modMenu',array('text'=>'archivefile'),$menu ? $menu->toArray() : null);
    if (!$menu) { $menu = $modx->newObject('modMenu'); }
    $menu->fromArray(array('text' => 'archivefile', 'parent' => 'components', 'action' => 'home', 'namespace' => 'archivefile', 'description' => 'archivefile_desc', 'permissions' => 'settings', 'menuindex' => 10), '', true, true);
    if (!$menu->save()) { throw new RuntimeException('Не удалось добавить пункт меню'); }
    $modx->getCacheManager()->refresh();
    $modx->log(modX::LOG_LEVEL_INFO, 'ArchiveFile installed. Configure CLI cron using the command shown in the component.');
    return true;
} catch (Throwable $e) {
    if ($filesTouched) foreach (array_reverse($restore) as $item) {
        if ($item[1] !== null) $restored = copy($item[1],$item[0]);
        else $restored = !is_file($item[0]) || unlink($item[0]);
        if (!$restored) { $restoreFailed = true; $modx->log(modX::LOG_LEVEL_ERROR,'ArchiveFile: не удалось восстановить '.$item[0]); }
    }
    foreach (array_reverse($objectsRestore) as $item) {
        try {
            $object = $modx->getObject($item[0],$item[1]);
            if ($item[2] === null) { if ($object && !$object->remove()) throw new RuntimeException('remove failed'); }
            else { if (!$object) $object=$modx->newObject($item[0]); $object->fromArray($item[2],'',true,true); if (!$object->save()) throw new RuntimeException('save failed'); }
        } catch (Throwable $restoreError) { $modx->log(modX::LOG_LEVEL_ERROR,'ArchiveFile: не удалось восстановить '.$item[0]); }
    }
    $modx->getCacheManager()->refresh();
    $modx->log(modX::LOG_LEVEL_ERROR, 'ArchiveFile: ' . $e->getMessage());
    return false;
} finally {
    if ($service) $service->maintenanceUnlock($locks);
    if ($restoreFailed) $modx->log(modX::LOG_LEVEL_ERROR,'ArchiveFile: резервные файлы сохранены в '.$backupDir);
    if (!$restoreFailed && $backupDir && is_dir($backupDir)) { foreach (glob($backupDir.'/*') as $file) @unlink($file); @rmdir($backupDir); }
}
