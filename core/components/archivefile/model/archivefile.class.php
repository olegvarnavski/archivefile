<?php
require_once __DIR__ . '/xmltools.class.php';
/** ArchiveFile service. PHP 7.4 compatible; no requests are run from page rendering. */
class ArchiveFile
{
    private $modx;
    private $db;
    private $prefix;
    private $storage;
    private $public;
    public function __construct($modx)
    {
        $this->modx = $modx;
        // xPDO::getConnection() returns an xPDOConnection wrapper, not PDO.
        // connect() populates the documented public PDO handle on MODX/xPDO.
        if (!$modx->connect() || !($modx->pdo instanceof PDO)) {
            throw new RuntimeException('Не удалось подключиться к базе данных MODX');
        }
        $this->db = $modx->pdo;
        $this->prefix = $modx->getOption('table_prefix', null, 'modx_');
        if (!preg_match('/^[a-zA-Z0-9_]*$/D', $this->prefix)) { throw new RuntimeException('Invalid table prefix'); }
        $this->public = MODX_ASSETS_PATH . 'archivefile/';
        $this->storage = rtrim($modx->getOption('archivefile.storage_path', null, MODX_CORE_PATH . 'components/archivefile/storage/'), '/\\') . '/';
    }
    public function query($sql, array $params = array())
    {
        $s = $this->db->prepare($sql);
        if (!$s || !$s->execute($params)) { throw new RuntimeException('Ошибка базы данных ArchiveFile'); }
        return $s;
    }
    public function table($name)
    {
        if (!in_array($name, array('sources', 'copies', 'events', 'options'), true)) { throw new InvalidArgumentException('Invalid table'); }
        return '`' . $this->prefix . 'archivefile_' . $name . '`';
    }
    public function install()
    {
        $this->query('CREATE TABLE IF NOT EXISTS ' . $this->table('sources') . ' (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(190) NOT NULL, url TEXT NOT NULL, format VARCHAR(10) NOT NULL DEFAULT "xml",
            enabled TINYINT NOT NULL DEFAULT 1, last_success VARCHAR(35) DEFAULT NULL,
            last_daily_date VARCHAR(10) DEFAULT NULL, last_error TEXT DEFAULT NULL,
            created_at VARCHAR(35) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->query('CREATE TABLE IF NOT EXISTS ' . $this->table('copies') . ' (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, source_id INT UNSIGNED NOT NULL,
            filename VARCHAR(190) NOT NULL, downloaded_at VARCHAR(35) NOT NULL,
            generation_date VARCHAR(190) DEFAULT NULL, offers_count INT UNSIGNED DEFAULT NULL,
            size_bytes BIGINT UNSIGNED NOT NULL, sha256 CHAR(64) NOT NULL,
            INDEX source_copy (source_id,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->query('CREATE TABLE IF NOT EXISTS ' . $this->table('events', 'options') . ' (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, source_id INT UNSIGNED DEFAULT NULL,
            source_name VARCHAR(190) DEFAULT NULL, kind VARCHAR(10) NOT NULL,
            status VARCHAR(12) NOT NULL, started_at VARCHAR(35) NOT NULL, finished_at VARCHAR(35) DEFAULT NULL,
            duration_ms INT UNSIGNED DEFAULT NULL, size_bytes BIGINT UNSIGNED DEFAULT NULL,
            offers_count INT UNSIGNED DEFAULT NULL, message TEXT DEFAULT NULL, warning TEXT DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->query('CREATE TABLE IF NOT EXISTS ' . $this->table('options') . ' (
            id INT UNSIGNED NOT NULL PRIMARY KEY, settings TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        if (!is_dir($this->storage) && !mkdir($this->storage, 0750, true)) { throw new RuntimeException('Не удалось создать папку архива'); }
        if (!is_writable($this->storage)) { throw new RuntimeException('Папка архива недоступна для записи'); }
        $this->ensurePublic();
        $deny = "Require all denied\n";
        if (file_put_contents($this->storage . '.htaccess', $deny) === false) { throw new RuntimeException('Не удалось защитить папку архива'); }
        file_put_contents($this->storage . 'index.html', '');
    }
    public function source($id)
    {
        $row = $this->query('SELECT * FROM ' . $this->table('sources') . ' WHERE id=?', array((int)$id))->fetch(PDO::FETCH_ASSOC);
        if (!$row) { throw new RuntimeException('Источник не найден'); }
        return $row;
    }
    public function sources()
    {
        $rows = $this->query('SELECT * FROM ' . $this->table('sources') . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $copies = $this->copies($r['id']);
            $r['latest'] = isset($copies[0]) ? $copies[0] : null;
            $event = $this->query('SELECT warning FROM ' . $this->table('events', 'options') . ' WHERE source_id=? AND status=? ORDER BY id DESC LIMIT 1', array($r['id'],'ok'))->fetch(PDO::FETCH_ASSOC);
            $r['warning'] = $event ? $event['warning'] : null;
            $r['xml_options'] = $this->xmlOptions($r['id']);
            $r['parts'] = $this->parts($r['id']);
            $r['latest_url'] = $this->publicUrl($r['id']);
        }
        return $rows;
    }
    public function copies($sourceId)
    {
        $rows = $this->query('SELECT * FROM ' . $this->table('copies') . ' WHERE source_id=? ORDER BY id DESC', array((int)$sourceId))->fetchAll(PDO::FETCH_ASSOC);
        $rows = array_slice(array_values(array_filter($rows, array($this,'validCopy'))),0,3);
        foreach ($rows as &$r) { $r['url'] = $this->publicUrl($sourceId, $r['id']); }
        return $rows;
    }
    public function xmlOptions($id)
    {
        $json=$this->query('SELECT settings FROM '.$this->table('options').' WHERE id=?',array((int)$id))->fetchColumn();
        return ArchiveFileXmlTools::options($json ? (array)json_decode($json,true) : array());
    }
    public function parts($id)
    {
        $dir=$this->public.(int)$id.'/parts/';$manifest=$dir.'manifest.json';
        if(!is_file($manifest))return array();
        $rows=json_decode(file_get_contents($manifest),true);if(!is_array($rows))return array();
        $url=dirname($this->publicUrl($id)).'/parts/';
        foreach($rows as &$row)$row['url']=$url.basename($row['filename']);
        return $rows;
    }
    public function preview(array $data)
    {
        $options=ArchiveFileXmlTools::options($data);$url=trim(isset($data['url'])?$data['url']:'');$this->urlParts($url);
        $temp=tempnam($this->storage,'preview-');if(!$temp)throw new RuntimeException('Не удалось создать временный файл');
        try {
            $this->download($url,$temp);$result=ArchiveFileXmlTools::parse($temp,$options);
            return array('generation_date'=>$result['generation_date'],'offers_count'=>$result['offers_count'],'sample'=>$result['sample'],
                'parts_count'=>$options['split_enabled']&&$result['offers_count']>$options['split_limit']?(int)ceil($result['offers_count']/$options['split_limit']):0,
                'valid'=>$result['offers_count']>0);
        } finally {if(is_file($temp))unlink($temp);}
    }
    private function removeTree($path)
    {
        if(!is_dir($path))return;
        foreach(new DirectoryIterator($path) as $item){if($item->isDot())continue;if($item->isDir())$this->removeTree($item->getPathname());else if(!unlink($item->getPathname()))throw new RuntimeException('Не удалось удалить файл части');}
        if(!rmdir($path))throw new RuntimeException('Не удалось удалить папку частей');
    }
    private function prepareParts($id,$archiveParts)
    {
        $dir=$this->public.(int)$id.'/';if(!is_dir($dir)&&!mkdir($dir,0755,true))throw new RuntimeException('Не удалось создать публичную папку');
        $stage=$dir.'.parts-new-'.bin2hex(random_bytes(6));if(!mkdir($stage,0755))throw new RuntimeException('Не удалось подготовить части');
        try {
            if($archiveParts && is_dir($archiveParts))foreach(new DirectoryIterator($archiveParts) as $item){if($item->isDot())continue;if(!$item->isFile()||!copy($item->getPathname(),$stage.'/'.$item->getFilename())||!chmod($stage.'/'.$item->getFilename(),0644))throw new RuntimeException('Не удалось опубликовать части');}
            else if(file_put_contents($stage.'/manifest.json','[]')===false)throw new RuntimeException('Не удалось подготовить список частей');
            return $stage;
        }catch(Throwable $e){$this->removeTree($stage);throw $e;}
    }
    private function validCopy(array $row)
    {
        $path = $this->storage . basename($row['filename']);
        return is_file($path) && filesize($path) > 0 && filesize($path) === (int)$row['size_bytes'] && hash_equals($row['sha256'],hash_file('sha256',$path))
            && (strtolower(pathinfo($row['filename'], PATHINFO_EXTENSION)) !== 'xml' || (int)$row['offers_count'] > 0);
    }
    public function events()
    {
        return $this->query('SELECT * FROM ' . $this->table('events', 'options') . ' ORDER BY id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
    }
    public function beginEvent($kind, $source = null)
    {
        $this->query('INSERT INTO ' . $this->table('events', 'options') . ' (source_id,source_name,kind,status,started_at) VALUES (?,?,?,?,?)',
            array($source ? $source['id'] : null, $source ? $source['name'] : null, $kind, 'running', $this->now()));
        return (int)$this->db->lastInsertId();
    }
    public function finishEvent($id, $status, $start, $message = null, $size = null, $count = null, $warning = null)
    {
        $this->query('UPDATE ' . $this->table('events', 'options') . ' SET status=?,finished_at=?,duration_ms=?,size_bytes=?,offers_count=?,message=?,warning=? WHERE id=?',
            array($status,$this->now(),min(4294967295,(int)round((microtime(true)-$start)*1000)),$size,$count,$message,$warning,$id));
        $old = $this->query('SELECT id FROM ' . $this->table('events', 'options') . ' ORDER BY id DESC LIMIT 1 OFFSET 199')->fetchColumn();
        if ($old) $this->query('DELETE FROM ' . $this->table('events', 'options') . ' WHERE id<? AND status<>? AND kind<>?', array($old,'running','cron'));
    }
    public function cronStatus()
    {
        $row = $this->query('SELECT * FROM ' . $this->table('events', 'options') . ' WHERE kind=? ORDER BY id DESC LIMIT 1', array('cron'))->fetch(PDO::FETCH_ASSOC);
        $hours = max(1,(int)$this->modx->getOption('archivefile.cron_stale_hours',null,36));
        return array('last' => $row ?: null, 'stale' => !$row || time()-strtotime($row['started_at']) > $hours*3600, 'hours' => $hours);
    }
    public function cronLock()
    {
        $handle = fopen($this->storage . 'cron.lock','c');
        if (!$handle || !flock($handle,LOCK_EX|LOCK_NB)) { if ($handle) fclose($handle); return false; }
        return $handle;
    }
    public function publicUrl($sourceId, $copyId = null)
    {
        $site = rtrim($this->modx->getOption('site_url'), '/');
        $assets = $this->modx->getOption('assets_url', null, MODX_ASSETS_URL);
        if (preg_match('~^https?://~i', $assets)) { $base = rtrim($assets, '/'); }
        elseif (substr($assets, 0, 1) === '/') {
            $p = parse_url($site);
            $base = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . rtrim($assets, '/');
        } else { $base = $site . '/' . trim($assets, '/'); }
        if ($copyId === null) { $source = $this->source($sourceId); return $base . '/archivefile/' . (int)$sourceId . '/lastfile.' . $source['format']; }
        return $base . '/components/archivefile/file.php?source=' . (int)$sourceId . ($copyId === null ? '' : '&copy=' . (int)$copyId);
    }
    private function ensurePublic()
    {
        if (!is_dir($this->public) && !mkdir($this->public, 0755, true)) { throw new RuntimeException('Не удалось создать папку публичных файлов'); }
        if (!is_writable($this->public)) { throw new RuntimeException('Папка публичных файлов недоступна для записи'); }
        file_put_contents($this->public . 'index.html', '');
        if (file_put_contents($this->public . '.htaccess', "Options -Indexes\n<IfModule mod_headers.c>\nHeader set Cache-Control \"no-cache\"\nHeader set X-Content-Type-Options \"nosniff\"\n</IfModule>\n") === false) { throw new RuntimeException('Не удалось настроить публичную папку'); }
    }
    private function publish($id, $format, $path)
    {
        $this->ensurePublic();
        $dir = $this->public . (int)$id . '/';
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) { throw new RuntimeException('Не удалось создать папку источника'); }
        $target = $dir . 'lastfile.' . $format;
        $temp = tempnam($dir, '.pending-');
        if (!$temp) { throw new RuntimeException('Не удалось подготовить публичный файл'); }
        try {
            if (!@copy($path, $temp) || !@chmod($temp, 0644) || !@rename($temp, $target)) { throw new RuntimeException('Не удалось обновить публичный файл'); }
        } finally { if (is_file($temp)) { unlink($temp); } }
        foreach (glob($dir . 'lastfile.*') as $old) { if ($old !== $target) { @unlink($old); } }
    }
    public function migratePublic()
    {
        foreach ($this->sources() as $source) {
            if ($source['latest'] && pathinfo($source['latest']['filename'], PATHINFO_EXTENSION) === $source['format']) {
                $this->publish($source['id'], $source['format'], $this->storage . basename($source['latest']['filename']));
            }
        }
    }
    public function delete($id)
    {
        $id = (int)$id; $lock = $this->lock($id);
        try {
            $this->source($id);
            $this->db->beginTransaction();
            $this->query('DELETE FROM ' . $this->table('copies') . ' WHERE source_id=?', array($id));
            $this->query('DELETE FROM '.$this->table('options').' WHERE id=?',array($id));
            $this->query('DELETE FROM ' . $this->table('sources') . ' WHERE id=?', array($id));
            $this->db->commit();
            $this->removeTree($this->public.$id.'/parts');
            foreach(glob($this->storage.'source-'.$id.'-*.parts') as $partsDir)$this->removeTree($partsDir);
            foreach (array_merge(glob($this->storage . 'source-' . $id . '-*.*'), glob($this->public . $id . '/lastfile.*')) as $path) {
                if (is_file($path) && !unlink($path)) { throw new RuntimeException('Источник удалён, но не удалось удалить файл архива'); }
            }
        } catch (Throwable $e) { if ($this->db->inTransaction()) { $this->db->rollBack(); } throw $e; }
        finally { $this->unlock($lock); }
        return array('deleted' => true);
    }
    public function save(array $data)
    {
        $name = trim(isset($data['name']) ? $data['name'] : '');
        $url = trim(isset($data['url']) ? $data['url'] : '');
        $format = isset($data['format']) ? $data['format'] : 'xml';
        if ($name === '' || strlen($name) > 190) { throw new RuntimeException('Укажите название длиной до 190 байт'); }
        $this->urlParts($url);
        if (!in_array($format, array('xml', 'csv', 'xlsx', 'xls', 'pdf', 'zip', 'json', 'txt'), true)) { throw new RuntimeException('Недопустимый формат'); }
        $xmlOptions = ArchiveFileXmlTools::options($data);
        $enabled = !empty($data['enabled']) ? 1 : 0;
        $id = isset($data['id']) ? (int)$data['id'] : 0;
        if ($id) {
            $lock = $this->lock($id);
            try {
                $old = $this->source($id);
                if ($old['format'] !== $format) { throw new RuntimeException('Для смены формата создайте новый источник — это сохранит постоянную ссылку и архив'); }
                $this->db->beginTransaction();
                // Previously archived files keep their own format and identity.
                $this->query('UPDATE ' . $this->table('sources') . ' SET name=?,url=?,format=?,enabled=? WHERE id=?', array($name, $url, $format, $enabled, $id));
                $this->writeOptions($id,$xmlOptions);$this->db->commit();
            } finally { if($this->db->inTransaction())$this->db->rollBack();$this->unlock($lock); }
        } else {
            $this->db->beginTransaction();
            $this->query('INSERT INTO ' . $this->table('sources') . ' (name,url,format,enabled,created_at) VALUES (?,?,?,?,?)', array($name, $url, $format, $enabled, $this->now()));
            $id = (int)$this->db->lastInsertId();
            $this->writeOptions($id,$xmlOptions);$this->db->commit();
        }
        return $id;
    }
    private function writeOptions($id,array $xmlOptions)
    {
        $this->query('DELETE FROM '.$this->table('options').' WHERE id=?',array($id));
        $this->query('INSERT INTO '.$this->table('options').' (id,settings) VALUES (?,?)',array($id,json_encode($xmlOptions)));
    }
    private function now() { return (new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow')))->format(DateTime::ATOM); }
    private function lock($id)
    {
        $handle = fopen($this->storage . 'source-' . (int)$id . '.lock', 'c');
        if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) {
            if ($handle) { fclose($handle); }
            throw new RuntimeException('Источник уже обрабатывается. Повторите позже.');
        }
        return $handle;
    }
    private function unlock($handle) { flock($handle, LOCK_UN); fclose($handle); }
    private function urlParts($url)
    {
        $p = parse_url($url);
        if (!$p || empty($p['host']) || !isset($p['scheme']) || !in_array(strtolower($p['scheme']), array('https', 'http'), true)
            || isset($p['user']) || isset($p['pass']) || isset($p['fragment']) || strlen($url) > 4096) {
            throw new RuntimeException('Нужна прямая ссылка HTTP/HTTPS без логина и пароля');
        }
        $port = isset($p['port']) ? (int)$p['port'] : (strtolower($p['scheme']) === 'https' ? 443 : 80);
        if (!in_array($port, array(80, 443), true) || !preg_match('/^[a-zA-Z0-9.\-]+$/D', $p['host'])) {
            throw new RuntimeException('Допускаются доменные имена и порты 80/443');
        }
        $p['port'] = $port;
        return $p;
    }
    private function publicIp($host)
    {
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? array($host) : gethostbynamel($host);
        if (!$ips) { throw new RuntimeException('Не удалось определить адрес сервера'); }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                && !preg_match('/^(0\.|127\.|169\.254\.|100\.(6[4-9]|[7-9][0-9]|1[01][0-9]|12[0-7])\.)/', $ip)) { return $ip; }
        }
        throw new RuntimeException('Загрузка с локальных и служебных адресов запрещена');
    }
    private function redirectUrl($base, $location)
    {
        if (preg_match('~^https?://~i', $location)) { return $location; }
        $p = $this->urlParts($base);
        if (substr($location, 0, 2) === '//') { return $p['scheme'] . ':' . $location; }
        $origin = $p['scheme'] . '://' . $p['host'] . ':' . $p['port'];
        if (substr($location, 0, 1) === '/') { return $origin . $location; }
        if (substr($location, 0, 1) === '?') { return $origin . (isset($p['path']) ? $p['path'] : '/') . $location; }
        $path = isset($p['path']) ? $p['path'] : '/';
        return $origin . substr($path, 0, strrpos($path, '/') + 1) . $location;
    }
    protected function download($url, $file)
    {
        if (!extension_loaded('curl')) { throw new RuntimeException('Требуется расширение PHP cURL'); }
        $max = max(1, min(1024, (int)$this->modx->getOption('archivefile.max_size_mb', null, 50))) * 1024 * 1024;
        for ($redirects = 0; $redirects <= 5; $redirects++) {
            $p = $this->urlParts($url);
            $ip = $this->publicIp($p['host']);
            $stream = fopen($file, 'wb');
            if (!$stream) { throw new RuntimeException('Не удалось создать временный файл'); }
            $size = 0; $location = null;
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 120,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_PROXY => '', CURLOPT_USERAGENT => 'ArchiveFile/1.0 (MODX)', CURLOPT_ENCODING => '',
                CURLOPT_RESOLVE => array($p['host'] . ':' . $p['port'] . ':' . $ip),
                CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$location) {
                    if (stripos($line, 'Location:') === 0) { $location = trim(substr($line, 9)); }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use ($stream, &$size, $max) {
                    $size += strlen($chunk);
                    if ($size > $max) { return 0; }
                    $written = fwrite($stream, $chunk);
                    return $written === false ? 0 : $written;
                }
            ));
            try {
                $ok = curl_exec($ch);
                $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
            } finally { curl_close($ch); fclose($stream); }
            if (!$ok) { throw new RuntimeException($size > $max ? 'Превышен максимальный размер файла' : 'Ошибка скачивания: ' . $error); }
            if ($status >= 300 && $status < 400 && $location !== null) {
                $url = $this->redirectUrl($url, $location);
                continue;
            }
            if ($status !== 200) { throw new RuntimeException('Сервер вернул HTTP ' . $status); }
            if (!$size) { throw new RuntimeException('Получен пустой файл'); }
            return;
        }
        throw new RuntimeException('Слишком много перенаправлений');
    }
    public static function xmlMetadata($file)
    {
        if (!class_exists('XMLReader')) { throw new RuntimeException('Требуется PHP XMLReader'); }
        $oldErrors = libxml_use_internal_errors(true);
        $oldLoader = libxml_disable_entity_loader(true);
        libxml_clear_errors();
        $reader = new XMLReader();
        $count = 0; $date = null; $root = false;
        try {
            // XMLReader may not open filenames with the global entity loader disabled;
            // open an already-read string and never resolve external entities.
            $content = file_get_contents($file);
            if ($content === false || !$reader->XML($content, null, LIBXML_NONET)) { throw new RuntimeException('Не удалось прочитать XML'); }
            $reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);
            $reader->setParserProperty(XMLReader::LOADDTD, false);
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::DOC_TYPE) { throw new RuntimeException('XML с DTD не поддерживается'); }
                if ($reader->nodeType === XMLReader::ELEMENT) {
                    $root = true;
                    if ($reader->localName === 'offer' && $reader->getAttribute('internal-id') !== null) { $count++; }
                    if ($reader->localName === 'generation-date' && $date === null) { $date = substr(trim($reader->readString()), 0, 190); }
                }
            }
            foreach (libxml_get_errors() as $error) {
                if ($error->level >= LIBXML_ERR_ERROR) { throw new RuntimeException('Некорректный XML: ' . trim($error->message)); }
            }
            if (!$root) { throw new RuntimeException('XML не содержит корневого элемента'); }
            return array('generation_date' => $date === '' ? null : $date, 'offers_count' => $count);
        } finally {
            $reader->close(); libxml_clear_errors(); libxml_use_internal_errors($oldErrors); libxml_disable_entity_loader($oldLoader);
        }
    }
    public function fetch($id, $daily = false)
    {
        $id = (int)$id;
        $lock = $this->lock($id); $temp = null; $final = null; $committed = false; $publicBackup = null; $published = false; $publicTarget = null; $event = null; $start = microtime(true); $status = 'error'; $message = null; $size = null; $meta = array('offers_count'=>null); $warning = null; $archiveParts = null; $partsStage = null; $partsBackup = null; $partsSwapped = false;
        try {
            $source = $this->source($id);
            $event = $this->beginEvent($daily ? 'daily' : 'manual', $source);
            $today = substr($this->now(), 0, 10);
            if ($daily && (!$source['enabled'] || $source['last_daily_date'] === $today)) { $status = 'skipped'; return array('skipped' => true); }
            $temp = tempnam($this->storage, 'tmp-');
            if ($temp === false) { throw new RuntimeException('Нет места для временного файла'); }
            $this->download($source['url'], $temp);
            clearstatcache(true,$temp); $size = filesize($temp);
            $options = $this->xmlOptions($id);
            $meta = $source['format'] === 'xml' ? ArchiveFileXmlTools::parse($temp,$options) : array('generation_date' => null, 'offers_count' => null);
            if (!filesize($temp)) { throw new RuntimeException('Получен пустой файл'); }
            if ($source['format'] === 'xml' && $meta['offers_count'] < 1) { throw new RuntimeException('XML без предложений по настройкам источника: архив и lastfile сохранены'); }
            $previous = $this->copies($id);
            $before = $previous ? (int)$previous[0]['offers_count'] : 0;
            $threshold = max(1,min(100,(int)$this->modx->getOption('archivefile.drop_warning_percent',null,30)));
            if ($source['format'] === 'xml' && $before > 0 && $meta['offers_count'] < $before && ($before-$meta['offers_count'])*100/$before >= $threshold) {
                $warning = 'Количество предложений снизилось: ' . $before . ' → ' . $meta['offers_count'] . ' (на ' . round(($before-$meta['offers_count'])*100/$before) . '%)';
            }
            $head = file_get_contents($temp, false, null, 0, 512);
            if (preg_match('~^\s*(?:<!DOCTYPE\s+html|<html\b)~i', $head)) { throw new RuntimeException('Вместо файла получена HTML-страница'); }
            $filename = 'source-' . $id . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.' . $source['format'];
            $final = $this->storage . $filename;
            if($source['format']==='xml' && $options['split_enabled']){
                $archiveParts=$this->storage.$filename.'.parts';
                ArchiveFileXmlTools::split($temp,$options,$archiveParts);
            }
            $partsStage=$this->prepareParts($id,$archiveParts);
            $size = filesize($temp); $hash = hash_file('sha256', $temp); $now = $this->now();
            if (!rename($temp, $final)) { throw new RuntimeException('Не удалось сохранить файл'); }
            $temp = null;
            $publicTarget = $this->public . $id . '/lastfile.' . $source['format'];
            if (is_file($publicTarget)) {
                $publicBackup = tempnam($this->storage, 'tmp-public-');
                if (!$publicBackup || !copy($publicTarget, $publicBackup)) { throw new RuntimeException('Не удалось подготовить обновление постоянной ссылки'); }
            }
            $this->db->beginTransaction();
            $this->query('INSERT INTO ' . $this->table('copies') . ' (source_id,filename,downloaded_at,generation_date,offers_count,size_bytes,sha256) VALUES (?,?,?,?,?,?,?)',
                array($id, $filename, $now, $meta['generation_date'], $meta['offers_count'], $size, $hash));
            $newId = (int)$this->db->lastInsertId();
            $this->query('UPDATE ' . $this->table('sources') . ' SET last_success=?,last_error=NULL' . ($daily ? ',last_daily_date=?' : '') . ' WHERE id=?',
                $daily ? array($now, $today, $id) : array($now, $id));
            $all = $this->query('SELECT * FROM ' . $this->table('copies') . ' WHERE source_id=? ORDER BY id DESC', array($id))->fetchAll(PDO::FETCH_ASSOC);
            $valid = array_values(array_filter($all, array($this,'validCopy')));
            $keep = array_column(array_slice($valid,0,3),'filename');
            $old = array_values(array_filter($all,function($copy) use($keep){return !in_array($copy['filename'],$keep,true);}));
            foreach ($old as $copy) { $this->query('DELETE FROM ' . $this->table('copies') . ' WHERE id=?', array($copy['id'])); }
            $partsTarget=$this->public.$id.'/parts';
            $partsBackup=$this->public.$id.'/.parts-old-'.bin2hex(random_bytes(6));
            if(is_dir($partsTarget)&&!rename($partsTarget,$partsBackup))throw new RuntimeException('Не удалось сохранить предыдущие части');
            if(!rename($partsStage,$partsTarget)){if(is_dir($partsBackup))rename($partsBackup,$partsTarget);throw new RuntimeException('Не удалось заменить части');}
            $partsStage=null;$partsSwapped=true;
            $this->publish($id, $source['format'], $final); $published = true;
            $this->db->commit(); $committed = true;
            if($partsBackup&&is_dir($partsBackup)){try{$this->removeTree($partsBackup);}catch(Throwable $cleanup){$this->modx->log(1,$cleanup->getMessage());}}
            foreach ($old as $copy) {
                try{$this->removeTree($this->storage.basename($copy['filename']).'.parts');}catch(Throwable $cleanup){$this->modx->log(1,$cleanup->getMessage());}
                $path = $this->storage . basename($copy['filename']);
                if (is_file($path) && !unlink($path)) { $this->modx->log(modX::LOG_LEVEL_ERROR, 'ArchiveFile: cannot remove expired copy ' . $copy['id']); }
            }
            // Retry cleanup of orphaned files from earlier interrupted runs.
            $status = 'ok';
            foreach (glob($this->storage . 'source-' . $id . '-*.*') as $path) {
                if (!in_array(basename($path), $keep, true) && is_file($path)) { @unlink($path); }
            }
            return array('id' => $newId, 'generation_date' => $meta['generation_date'], 'offers_count' => $meta['offers_count']);
        } catch (Throwable $e) {
            $message = $e->getMessage();
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            if($partsSwapped&&!$committed){$this->removeTree($this->public.$id.'/parts');if($partsBackup&&is_dir($partsBackup))rename($partsBackup,$this->public.$id.'/parts');}
            if ($published && !$committed) {
                if ($publicBackup) { $this->publish($id, $source['format'], $publicBackup); }
                elseif ($publicTarget && is_file($publicTarget)) { @unlink($publicTarget); }
            }
            if ($final && !$committed && is_file($final)) { @unlink($final); }
            $this->query('UPDATE ' . $this->table('sources') . ' SET last_error=? WHERE id=?', array(substr($e->getMessage(), 0, 2000), $id));
            throw $e;
        } finally {
            if($partsStage&&is_dir($partsStage))$this->removeTree($partsStage);
            if($archiveParts&&!$committed&&is_dir($archiveParts))$this->removeTree($archiveParts);
            if ($publicBackup && is_file($publicBackup)) { @unlink($publicBackup); }
            if ($temp && is_file($temp)) { @unlink($temp); }
            $this->unlock($lock);
            if ($event) {
                try { $this->finishEvent($event,$status,$start,$message,$size,$meta['offers_count'],$warning); }
                catch (Throwable $logError) { $this->modx->log(1,'ArchiveFile journal: '.$logError->getMessage()); }
            }
        }
    }
    public function file($sourceId, $copyId = null)
    {
        $this->source($sourceId);
        $sql = 'SELECT * FROM ' . $this->table('copies') . ' WHERE source_id=?';
        $args = array((int)$sourceId);
        if ($copyId !== null) { $sql .= ' AND id=?'; $args[] = (int)$copyId; }
        $row = $this->query($sql . ' ORDER BY id DESC LIMIT 1', $args)->fetch(PDO::FETCH_ASSOC);
        if (!$row) { throw new RuntimeException('Копия не найдена или уже удалена'); }
        $path = $this->storage . basename($row['filename']);
        // Open before releasing to the web server so rotation cannot break a running download.
        $handle = @fopen($path, 'rb');
        if (!$handle) { throw new RuntimeException('Файл недоступен'); }
        return array($row, $handle);
    }
}
