<?php
/** Test doubles; these never connect to a real MODX site. */
class FaultPDO extends PDO {
    public $commitThenFail = false;
    public $pauseCommit = false;
    public function commit() {
        $result = parent::commit();
        if ($this->pauseCommit) { echo "READY\n"; flush(); while (true) usleep(100000); }
        if ($this->commitThenFail) { $this->commitThenFail = false; throw new RuntimeException('Simulated lost commit acknowledgement'); }
        return $result;
    }
}
class FakeModx {
    public $db; public $pdo; public $storage;
    public function __construct($path, $dsn = 'sqlite::memory:') {
        $this->storage = $path;
        $this->db = new FaultPDO($dsn);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE IF NOT EXISTS test_archivefile_sources (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT,url TEXT,format TEXT,enabled INTEGER,last_success TEXT,last_daily_date TEXT,last_error TEXT,created_at TEXT)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS test_archivefile_options (id INTEGER PRIMARY KEY,settings TEXT)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS test_archivefile_events (id INTEGER PRIMARY KEY AUTOINCREMENT,source_id INTEGER,source_name TEXT,kind TEXT,status TEXT,started_at TEXT,finished_at TEXT,duration_ms INTEGER,size_bytes INTEGER,offers_count INTEGER,message TEXT,warning TEXT)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS test_archivefile_copies (id INTEGER PRIMARY KEY AUTOINCREMENT,source_id INTEGER,filename TEXT,downloaded_at TEXT,generation_date TEXT,offers_count INTEGER,size_bytes INTEGER,sha256 TEXT)');
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
    public $failPublish = false;
    public $failCleanup = false;
    public $failDeletePath = null;
    public $failOptions = false;
    public $failCopyInsert = false;
    protected function publish($id,$format,$path) {
        parent::publish($id,$format,$path);
        if ($this->failPublish) { $this->failPublish = false; throw new RuntimeException('Failure after publishing bytes'); }
    }
    protected function removeTree($path) {
        if ($this->failCleanup && strpos($path,'.parts-new-')!==false) throw new RuntimeException('Injected cleanup failure');
        if ($path === $this->failDeletePath) throw new RuntimeException('Injected deletion failure');
        parent::removeTree($path);
    }
    public function query($sql,array $params=array()) {
        if ($this->failCopyInsert && strpos($sql,'INSERT INTO '.$this->table('copies'))===0) throw new RuntimeException('Injected DB insert failure');
        if ($this->failOptions && strpos($sql,'INSERT INTO '.$this->table('options'))===0) throw new RuntimeException('Injected options failure');
        return parent::query($sql,$params);
    }
    protected function download($url, $file) {
        if ($this->networkError) { throw new RuntimeException('Simulated HTTP failure'); }
        file_put_contents($file, $this->xml);
    }
}
