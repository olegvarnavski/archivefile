<?php
require dirname(__DIR__) . '/core/components/archivefile/model/archivefile.class.php';
function check($condition, $label) {
    if (!$condition) { throw new RuntimeException('FAIL ' . $label); }
    echo 'PASS ' . $label . PHP_EOL;
}
function fails($fn, $label) {
    try { $fn(); } catch (Throwable $e) { echo 'PASS ' . $label . ': ' . $e->getMessage() . PHP_EOL; return; }
    throw new RuntimeException('FAIL expected exception ' . $label);
}
class FakeModx {
    public $db; public $pdo; public $storage;
    public function __construct($path) {
        $this->storage = $path;
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE test_archivefile_sources (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT,url TEXT,format TEXT,enabled INTEGER,last_success TEXT,last_daily_date TEXT,last_error TEXT,created_at TEXT)');
        $this->db->exec('CREATE TABLE test_archivefile_copies (id INTEGER PRIMARY KEY AUTOINCREMENT,source_id INTEGER,filename TEXT,downloaded_at TEXT,generation_date TEXT,offers_count INTEGER,size_bytes INTEGER,sha256 TEXT)');
    }
    public function connect() { $this->pdo = $this->db; return true; }
    public function getConnection() { return (object)array('pdo' => $this->db); }
    public function getOption($key, $options = null, $default = null) {
        return array('table_prefix' => 'test_', 'archivefile.storage_path' => $this->storage, 'site_url' => 'https://example.org/', 'assets_url' => '/assets/')[$key] ?? $default;
    }
    public function log($level, $message) { echo 'LOG ' . $message . PHP_EOL; }
}
class FixtureService extends ArchiveFile {
    public $xml = '<r xmlns="urn:test"><generation-date>2026-10-13T02:45:00+03:00</generation-date><offer internal-id="1"/><offer internal-id="1"/><offer/><offer internal-id=""/></r>';
    public $networkError = false;
    protected function download($url, $file) {
        if ($this->networkError) { throw new RuntimeException('Simulated HTTP failure'); }
        file_put_contents($file, $this->xml);
    }
}
$storage = sys_get_temp_dir() . '/archivefile-test-' . bin2hex(random_bytes(6)) . '/';
mkdir($storage);
define('MODX_CORE_PATH', $storage);
define('MODX_ASSETS_URL', '/assets/');
define('MODX_ASSETS_PATH', $storage . 'assets/');
$modx = new FakeModx($storage);
$service = new FixtureService($modx);
try {
    check(!($modx->getConnection() instanceof PDO) && !method_exists($modx->getConnection(), 'prepare'), 'MODX connection wrapper has no prepare method');
    $a = $service->save(array('name' => 'Источник А', 'url' => 'https://example.org/feed.xml', 'enabled' => 1));
    $b = $service->save(array('name' => 'Источник Б', 'url' => 'https://example.org/other.xml', 'enabled' => 1));
    for ($i = 0; $i < 6; $i++) { $service->fetch($a); }
    $service->fetch($b);
    $rows = $service->copies($a);
    check(count($rows) === 5, 'keep five copies after six successful downloads');
    check(count(glob($storage . 'source-' . $a . '-*.xml')) === 5, 'remove old bytes from disk');
    check(count($service->copies($b)) === 1, 'sources have separate archives');
    check((int)$rows[0]['offers_count'] === 3, 'count namespaced offers with attribute, including duplicates');
    check($rows[0]['generation_date'] === '2026-10-13T02:45:00+03:00', 'read generation-date from XML');
    list($latest, $handle) = $service->file($a); fclose($handle);
    check((int)$latest['id'] === (int)$rows[0]['id'], 'latest points to newest successful copy');
    fails(function () use ($service, $a) { $service->file($a, 1); }, 'expired copy no longer available');
    check(strpos($rows[0]['url'], 'https://example.org/assets/components/archivefile/file.php?source=') === 0, 'absolute public domain links');
    $public = MODX_ASSETS_PATH . 'archivefile/' . $a . '/lastfile.xml';
    check(is_file($public), 'static latest file exists');
    check($service->publicUrl($a) === 'https://example.org/assets/archivefile/' . $a . '/lastfile.xml', 'latest URL has no PHP or parameters');
    $publicHash = hash_file('sha256', $public);
    $ids = array_column($rows, 'id');
    rename($public, $public . '.backup'); mkdir($public);
    fails(function () use ($service, $a) { $service->fetch($a); }, 'publication failure rejected');
    check(array_column($service->copies($a), 'id') === $ids, 'publication failure rolls back rotation and database');
    rmdir($public); rename($public . '.backup', $public);

    $service->xml = '<r><offer internal-id="1"></r>';
    fails(function () use ($service, $a) { $service->fetch($a); }, 'malformed XML rejected');
    check(array_column($service->copies($a), 'id') === $ids, 'malformed XML preserves all previous copies');
    $service->networkError = true;
    fails(function () use ($service, $a) { $service->fetch($a); }, 'download error');
    check(array_column($service->copies($a), 'id') === $ids, 'download error preserves all previous copies');
    check(hash_file('sha256', $public) === $publicHash, 'failed download preserves static latest');
    check(count(glob($storage . 'tmp-*')) === 0, 'temporary files cleaned after failures');
    $service->networkError = false; $service->xml = '<r/>';
    $service->fetch($a, true);
    check(!empty($service->fetch($a, true)['skipped']), 'daily cron deduplicated by Moscow date');
    check((int)$service->copies($a)[0]['offers_count'] === 0, 'zero offers is valid');
    check($service->copies($a)[0]['generation_date'] === null, 'missing generation-date stays unknown');
    check($service->source($a)['last_error'] === null, 'successful retry clears error');
    $service->save(array('id' => $b, 'name' => 'Источник Б', 'url' => 'https://example.org/other.xml', 'enabled' => 0));
    check(!empty($service->fetch($b, true)['skipped']), 'disabled source skipped by cron');
    $before = $service->copies($b)[0]['id']; $service->fetch($b);
    check($service->copies($b)[0]['id'] > $before, 'manual force downloads disabled source');
    $f = fopen($storage . 'source-' . $a . '.lock', 'c'); flock($f, LOCK_EX);
    fails(function () use ($service, $a) { $service->fetch($a); }, 'concurrent download blocked');
    flock($f, LOCK_UN); fclose($f);
    $service->xml = '<!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]><r>&x;</r>';
    fails(function () use ($service, $b) { $service->fetch($b); }, 'DTD and external entities rejected');
    fails(function () use ($service) { $service->save(array('name' => 'bad', 'url' => 'file:///etc/passwd')); }, 'non-HTTP URL rejected');
    fails(function () use ($service) { $service->save(array('name' => 'bad', 'url' => 'https://user:pass@example.org/f')); }, 'URL credentials rejected');
    fails(function () use ($service) { $service->save(array('name' => 'bad', 'url' => 'https://example.org:22/f')); }, 'nonstandard port rejected');
    $urlMethod = new ReflectionMethod(ArchiveFile::class, 'publicIp'); $urlMethod->setAccessible(true);
    foreach (array('127.0.0.1', '10.0.0.1', '169.254.169.254', '100.64.0.1') as $ip) {
        fails(function () use ($urlMethod, $service, $ip) { $urlMethod->invoke($service, $ip); }, 'SSRF blocks ' . $ip);
    }
    check($urlMethod->invoke($service, '8.8.8.8') === '8.8.8.8', 'public IP accepted');
    $service->delete($b);
    fails(function () use ($service,$b) { $service->source($b); }, 'deleted source absent');
    check(!$service->copies($b) && !glob($storage . 'source-' . $b . '-*.*') && !glob(MODX_ASSETS_PATH . 'archivefile/' . $b . '/lastfile.*'), 'delete clears history and public bytes');
    if (isset($argv[1])) {
        $meta = ArchiveFile::xmlMetadata($argv[1]);
        check($meta['offers_count'] === 116, 'real Profitbase XML: 116 offers');
        check($meta['generation_date'] === '2026-09-29T14:36:45+05:00', 'real Profitbase generation-date');
    }
} finally {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($storage, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $path) { if($path->isDir()) rmdir($path->getPathname()); else unlink($path->getPathname()); }
    rmdir($storage);
}
