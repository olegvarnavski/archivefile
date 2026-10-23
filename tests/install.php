<?php
/** Installer simulation using the native xPDO script vehicle; not a live MODX site. */
$references = isset($argv[1]) ? $argv[1] : __DIR__ . '/fixtures/modx';
class xPDO { const LOG_LEVEL_ERROR = 1; const LOG_LEVEL_INFO = 3; }
require $references . '/transport-reference.php';
require $references . '/xpdovehicle.class.php';
require $references . '/xpdoscriptvehicle.class.php';
class TestPDO extends PDO {
    public function prepare($statement, $options = array()) {
        if (strpos($statement, 'CREATE TABLE') === 0) {
            $statement = preg_replace('/INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY/', 'INTEGER PRIMARY KEY AUTOINCREMENT', $statement);
            $statement = preg_replace('/,\s*INDEX source_copy \(source_id,id\)/', '', $statement);
            $statement = preg_replace('/\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4$/', ')', $statement);
        }
        return parent::prepare($statement, $options);
    }
}
class ObjectStub {
    public $fields = array(); private $modx; private $class;
    public function __construct($modx, $class) { $this->modx = $modx; $this->class = $class; }
    public function fromArray($fields, $prefix = '', $setPrimaryKeys = false, $raw = false) { $this->fields = array_merge($this->fields, $fields); }
    public function set($key, $value) { $this->fields[$key] = $value; }
    public function get($key) { return $this->fields[$key] ?? null; }
    public function save() {
        $key = $this->fields['key'] ?? $this->fields['name'] ?? $this->fields['text'];
        $this->modx->objects[$this->class][$key] = $this; return true;
    }
    public function remove() { unset($this->modx->objects[$this->class][$this->fields['text']]); return true; }
}
class CacheStub {
    public function copyTree($from, $to) {
        if (!is_dir($to)) { mkdir($to, 0750, true); }
        foreach (new DirectoryIterator($from) as $f) {
            if ($f->isDot()) { continue; }
            if ($f->isDir()) { $this->copyTree($f->getPathname(), $to . '/' . $f->getFilename()); }
            else { if (!copy($f->getPathname(), $to . '/' . $f->getFilename())) { return false; } }
        }
        return true;
    }
    public function refresh() { return true; }
}
class modX extends xPDO {
    public $objects = array(); public $pdo; private $cache;
    public function __construct() { $this->pdo = new TestPDO('sqlite::memory:'); $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $this->cache = new CacheStub(); }
    public function connect() { return true; }
    public function getConnection() { return (object)array('pdo' => $this->pdo); }
    public function getCacheManager() { return $this->cache; }
    public function getObject($class, $criteria) {
        foreach ($this->objects[$class] ?? array() as $key => $object) {
            if (is_string($criteria) && $criteria === $key) { return $object; }
            if (is_array($criteria) && !array_diff_assoc($criteria, $object->fields)) { return $object; }
        }
        return null;
    }
    public function newObject($class) { return new ObjectStub($this, $class); }
    public function getOption($key, $options = null, $default = null) {
        $setting = $this->getObject('modSystemSetting', $key);
        return $setting ? $setting->get('value') : (array('table_prefix' => 'test_', 'site_url' => 'https://example.org/', 'assets_url' => '/assets/')[$key] ?? $default);
    }
    public function log($level, $message) { echo 'LOG ' . $message . PHP_EOL; }
}
function check($condition, $label) { if (!$condition) { throw new RuntimeException('FAIL ' . $label); } echo 'PASS ' . $label . PHP_EOL; }
$temp = sys_get_temp_dir() . '/archivefile-install-' . bin2hex(random_bytes(6)) . '/';
mkdir($temp . 'core', 0750, true); mkdir($temp . 'assets', 0750, true);
define('MODX_CORE_PATH', $temp . 'core/'); define('MODX_ASSETS_PATH', $temp . 'assets/'); define('MODX_BASE_PATH', $temp); define('MODX_ASSETS_URL', '/assets/');
file_put_contents($temp . 'config.core.php', '<?php // test bootstrap');
$stage = dirname(__DIR__) . '/dist/archivefile-1.4.1-pl/';
$manifest = include $stage . 'manifest.php';
$transport = (object)array('xpdo' => new modX(), 'path' => dirname($stage) . '/', 'signature' => 'archivefile-1.4.1-pl');
$vehicle = new xPDOScriptVehicle();
$vehicle->payload = include $stage . $manifest['manifest-vehicles'][0]['filename'];
try {
    check(!method_exists($transport->xpdo->getConnection(), 'prepare'), 'installer fixture uses an xPDO-style connection wrapper');
    check($vehicle->install($transport, array('package_action' => xPDOTransport::ACTION_INSTALL)), 'native script vehicle installs package');
    check(is_file(MODX_CORE_PATH . 'components/archivefile/cron.php') && is_file(MODX_ASSETS_PATH . 'components/archivefile/mgr.js'), 'installer places core and assets files');
    check(is_file(MODX_CORE_PATH . 'components/archivefile/runtime.php'), 'installer creates server-specific bootstrap');
    check($transport->xpdo->getObject('modMenu', 'archivefile')->get('action') === 'home', 'manager menu registered');
    $service = new ArchiveFile($transport->xpdo);
    check(count($service->sources()) === 0, 'install starts without example sources');
    $service->save(array('name' => 'Моё название', 'url' => 'https://example.org/feed.xml', 'enabled' => 0));
    $service->save(array('id' => 1, 'name' => 'Моё название', 'url' => 'https://example.org/feed.xml', 'enabled' => 0));
    $custom=array('id'=>1,'name'=>'Моё название','url'=>'https://example.org/feed.xml','enabled'=>0,'offer_element'=>'item','offer_attribute'=>'','date_element'=>'updated','container_path'=>'catalog/items','split_enabled'=>1,'split_limit'=>750);$service->save($custom);
    check($vehicle->install($transport, array('package_action' => xPDOTransport::ACTION_UPGRADE)), 'upgrade succeeds');
    check($service->xmlOptions(1)['split_limit']===750 && $service->xmlOptions(1)['offer_element']==='item','upgrade preserves XML options');
    check(count($service->sources()) === 1 && $service->source(1)['name'] === 'Моё название', 'upgrade preserves settings and does not duplicate source');
    check($vehicle->uninstall($transport, array('package_action' => xPDOTransport::ACTION_UNINSTALL)), 'uninstall succeeds');
    check($transport->xpdo->getObject('modMenu', 'archivefile') === null, 'uninstall removes menu');
    check($transport->xpdo->getOption('archivefile.installed') === '0', 'uninstall disables endpoints');
    check($service->source(1)['name'] === 'Моё название', 'uninstall preserves source data');
    check($vehicle->install($transport, array('package_action' => xPDOTransport::ACTION_INSTALL)), 'reinstallation succeeds');
    check($transport->xpdo->getOption('archivefile.installed') === '1', 'reinstallation enables endpoints');
    $service->save(array('id'=>1,'name'=>'Первоначальный источник','url'=>'https://example.org/feed.xml','format'=>'xml','enabled'=>1));
    check($vehicle->install($transport, array('package_action'=>xPDOTransport::ACTION_UPGRADE)), 'upgrade of original seed succeeds');
    check(count($service->sources())===1 && $service->source(1)['name']==='Первоначальный источник', 'upgrade never deletes the original source');

} finally {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $path) { if ($path->isDir()) { rmdir($path->getPathname()); } else { unlink($path->getPathname()); } }
    rmdir($temp);
}
