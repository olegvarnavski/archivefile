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
    public function cronCommand()
    {
        $argument = function ($path) {
            // Normal absolute hosting paths need no quotes; unusual paths remain shell-safe.
            return preg_match('~^/[A-Za-z0-9_./-]+$~D', $path) ? $path : escapeshellarg($path);
        };
        return $argument((string)$this->modx->getOption('archivefile.cron_php_path', null, '/usr/bin/php7.4'))
            . ' ' . $argument(MODX_CORE_PATH . 'components/archivefile/cron.php');
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
        $this->query('CREATE TABLE IF NOT EXISTS ' . $this->table('events') . ' (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, source_id INT UNSIGNED DEFAULT NULL,
            source_name VARCHAR(190) DEFAULT NULL, kind VARCHAR(10) NOT NULL,
            status VARCHAR(12) NOT NULL, started_at VARCHAR(35) NOT NULL, finished_at VARCHAR(35) DEFAULT NULL,
            duration_ms INT UNSIGNED DEFAULT NULL, size_bytes BIGINT UNSIGNED DEFAULT NULL,
            offers_count INT UNSIGNED DEFAULT NULL, message TEXT DEFAULT NULL, warning TEXT DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->query('CREATE TABLE IF NOT EXISTS ' . $this->table('options') . ' (
            id INT UNSIGNED NOT NULL PRIMARY KEY, settings TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->installIndexes();
        if (!is_dir($this->storage) && !mkdir($this->storage, 0750, true)) { throw new RuntimeException('Не удалось создать папку архива'); }
        if (!is_writable($this->storage)) { throw new RuntimeException('Папка архива недоступна для записи'); }
        $this->ensurePublic();
        $deny = "Require all denied\n";
        if (file_put_contents($this->storage . '.htaccess', $deny) === false) { throw new RuntimeException('Не удалось защитить папку архива'); }
        file_put_contents($this->storage . 'index.html', '');
    }
    /** Additive, idempotent index migration; no data or existing fields are replaced. */
    private function installIndexes()
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return;
        $indexes = $this->query('SHOW INDEX FROM '.$this->table('events'))->fetchAll(PDO::FETCH_ASSOC);
        $names = array_column($indexes,'Key_name');
        foreach (array('source_status_event'=>'source_id,status,id','kind_event'=>'kind,id','status_event'=>'status,id') as $name=>$columns) {
            if (!in_array($name,$names,true)) $this->query('CREATE INDEX `'.$name.'` ON '.$this->table('events').' ('.$columns.')');
        }
    }
    public function maintenanceLock()
    {
        $locks = array();
        try {
            $cron = $this->cronLock();
            if (!$cron) throw new RuntimeException('Выполняется cron ArchiveFile. Повторите установку позже.');
            $locks[] = $cron;
            foreach ($this->sources(false) as $row) $locks[] = $this->lock($row['id']);
            return $locks;
        } catch (Throwable $e) { $this->maintenanceUnlock($locks); throw $e; }
    }
    public function maintenanceUnlock(array $locks)
    {
        foreach (array_reverse($locks) as $lock) $this->unlock($lock);
    }
    public function source($id)
    {
        $row = $this->query('SELECT * FROM ' . $this->table('sources') . ' WHERE id=?', array((int)$id))->fetch(PDO::FETCH_ASSOC);
        if (!$row) { throw new RuntimeException('Источник не найден'); }
        return $row;
    }
    public function sources($decorate = true)
    {
        $rows = $this->query('SELECT * FROM '.$this->table('sources').' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        if (!$decorate || !$rows) return $rows;
        $latest = array(); $options = array(); $warnings = array();
        $statement = $this->query('SELECT * FROM '.$this->table('copies').' ORDER BY source_id,id');
        while ($copy = $statement->fetch(PDO::FETCH_ASSOC)) {
            $id = $copy['source_id'];
            if ($this->validCopy($copy,false)) {
                $copy['url'] = $this->publicUrl($id,$copy['id']); $latest[$id] = $copy;
            }
        }
        $statement->closeCursor();
        foreach ($this->query('SELECT * FROM '.$this->table('options'))->fetchAll(PDO::FETCH_ASSOC) as $o) $options[$o['id']] = ArchiveFileXmlTools::options((array)json_decode($o['settings'],true));
        $sql = 'SELECT e.source_id,e.warning FROM '.$this->table('events').' e JOIN (SELECT source_id,MAX(id) id FROM '.$this->table('events').' WHERE status=? GROUP BY source_id) x ON e.id=x.id';
        foreach ($this->query($sql,array('ok'))->fetchAll(PDO::FETCH_ASSOC) as $event) $warnings[$event['source_id']] = $event['warning'];
        foreach ($rows as &$row) {
            $id = $row['id']; $row['latest'] = isset($latest[$id]) ? $latest[$id] : null;
            $row['warning'] = isset($warnings[$id]) ? $warnings[$id] : null;
            $row['xml_options'] = isset($options[$id]) ? $options[$id] : ArchiveFileXmlTools::options(array());
            $row['parts'] = $this->parts($id,$row['format']); $row['latest_url'] = $this->publicUrl($id,null,$row['format']);
        }
        return $rows;
    }
    public function copies($sourceId, $verify = true)
    {
        $sourceId = self::id($sourceId);
        $statement = $this->query('SELECT * FROM '.$this->table('copies').' WHERE source_id=? ORDER BY id DESC', array($sourceId));
        $rows = array();
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            if (!$this->validCopy($row,$verify)) continue;
            $row['url'] = $this->publicUrl($sourceId,$row['id']); $rows[] = $row;
            if (count($rows) === 3) break;
        }
        $statement->closeCursor();
        return $rows;
    }
    public function xmlOptions($id)
    {
        $json=$this->query('SELECT settings FROM '.$this->table('options').' WHERE id=?',array((int)$id))->fetchColumn();
        return ArchiveFileXmlTools::options($json ? (array)json_decode($json,true) : array());
    }
    public function parts($id, $format = null)
    {
        $dir=$this->public.(int)$id.'/parts/';$manifest=$dir.'manifest.json';
        if(!is_file($manifest))return array();
        $rows=json_decode(file_get_contents($manifest),true);if(!is_array($rows))return array();
        $url=dirname($this->publicUrl($id,null,$format)).'/parts/';
        foreach($rows as &$row)$row['url']=$url.basename($row['filename']);
        return $rows;
    }
    public function preview(array $data)
    {
        $this->validateInput($data);
        $options=ArchiveFileXmlTools::options($data);$url=trim(isset($data['url'])?$data['url']:'');$this->urlParts($url);
        $temp=tempnam($this->storage,'preview-');if(!$temp)throw new RuntimeException('Не удалось создать временный файл');
        try {
            $this->download($url,$temp);$result=ArchiveFileXmlTools::parse($temp,$options);
            return array('generation_date'=>$result['generation_date'],'offers_count'=>$result['offers_count'],'sample'=>$result['sample'],
                'parts_count'=>$options['split_enabled']&&$result['offers_count']>$options['split_limit']?(int)ceil($result['offers_count']/$options['split_limit']):0,
                'valid'=>$result['offers_count']>0);
        } finally {if(is_file($temp))unlink($temp);}
    }
    protected function removeTree($path)
    {
        if (is_link($path)) { if (!unlink($path)) throw new RuntimeException('Не удалось удалить ссылку'); return; }
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
        }catch(Throwable $e){$this->cleanup(function () use ($stage) { $this->removeTree($stage); });throw $e;}
    }
    private function validCopy(array $row, $verify = true)
    {
        $path = $this->storage . basename($row['filename']);
        return is_file($path) && filesize($path) > 0 && filesize($path) === (int)$row['size_bytes'] && (!$verify || hash_equals($row['sha256'], (string)hash_file('sha256',$path)))
            && (strtolower(pathinfo($row['filename'], PATHINFO_EXTENSION)) !== 'xml' || (int)$row['offers_count'] > 0);
    }
    public function events()
    {
        return $this->query('SELECT * FROM ' . $this->table('events') . ' ORDER BY id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
    }
    public function beginEvent($kind, $source = null)
    {
        $this->query('INSERT INTO ' . $this->table('events') . ' (source_id,source_name,kind,status,started_at) VALUES (?,?,?,?,?)',
            array($source ? $source['id'] : null, $source ? $source['name'] : null, $kind, 'running', $this->now()));
        return (int)$this->db->lastInsertId();
    }
    public function finishEvent($id, $status, $start, $message = null, $size = null, $count = null, $warning = null)
    {
        $this->query('UPDATE ' . $this->table('events') . ' SET status=?,finished_at=?,duration_ms=?,size_bytes=?,offers_count=?,message=?,warning=? WHERE id=?',
            array($status,$this->now(),min(4294967295,(int)round((microtime(true)-$start)*1000)),$size,$count,$message,$warning,$id));
        $this->pruneEvents();
    }
    private function pruneEvents()
    {
        $cutoff = $this->query('SELECT id FROM '.$this->table('events').' ORDER BY id DESC LIMIT 1 OFFSET 199')->fetchColumn();
        if (!$cutoff) return;
        // Keep the latest successful warning per source and the last cron heartbeat.
        $keep = $this->query('SELECT MAX(e.id) FROM '.$this->table('events').' e JOIN '.$this->table('sources').' s ON s.id=e.source_id WHERE e.status=? GROUP BY e.source_id', array('ok'))->fetchAll(PDO::FETCH_COLUMN);
        $cron = $this->query('SELECT MAX(id) FROM '.$this->table('events').' WHERE kind=?', array('cron'))->fetchColumn();
        if ($cron) $keep[] = $cron;
        $sql = 'DELETE FROM '.$this->table('events').' WHERE id<? AND status<>?'; $params = array($cutoff,'running');
        if ($keep) { $sql .= ' AND id NOT IN ('.implode(',',array_fill(0,count($keep),'?')).')'; $params = array_merge($params,$keep); }
        $this->query($sql,$params);
    }
    public function cronStatus()
    {
        $row = $this->query('SELECT * FROM ' . $this->table('events') . ' WHERE kind=? ORDER BY id DESC LIMIT 1', array('cron'))->fetch(PDO::FETCH_ASSOC);
        $hours = max(1,(int)$this->modx->getOption('archivefile.cron_stale_hours',null,36));
        return array('last' => $row ?: null, 'stale' => !$row || time()-strtotime($row['started_at']) > $hours*3600, 'hours' => $hours);
    }
    public function cronLock()
    {
        $handle = fopen($this->storage . 'cron.lock','c');
        if (!$handle || !flock($handle,LOCK_EX|LOCK_NB)) { if ($handle) fclose($handle); return false; }
        try {
            $this->query('UPDATE '.$this->table('events').' SET status=?,finished_at=?,message=? WHERE kind=? AND status=?', array('error',$this->now(),'Предыдущий cron был прерван','cron','running'));
        } catch (Throwable $e) { $this->unlock($handle); throw $e; }
        return $handle;
    }
    public function publicUrl($sourceId, $copyId = null, $format = null)
    {
        $site = rtrim($this->modx->getOption('site_url'), '/');
        $assets = $this->modx->getOption('assets_url', null, MODX_ASSETS_URL);
        if (preg_match('~^https?://~i', $assets)) { $base = rtrim($assets, '/'); }
        elseif (substr($assets, 0, 1) === '/') {
            $p = parse_url($site);
            $base = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . rtrim($assets, '/');
        } else { $base = $site . '/' . trim($assets, '/'); }
        if ($copyId === null) { if ($format === null) $format = $this->source($sourceId)['format']; return $base . '/archivefile/' . (int)$sourceId . '/lastfile.' . $format; }
        return $base . '/components/archivefile/file.php?source=' . (int)$sourceId . ($copyId === null ? '' : '&copy=' . (int)$copyId);
    }
    private function ensurePublic()
    {
        if (!is_dir($this->public) && !mkdir($this->public, 0755, true)) { throw new RuntimeException('Не удалось создать папку публичных файлов'); }
        if (!is_writable($this->public)) { throw new RuntimeException('Папка публичных файлов недоступна для записи'); }
        file_put_contents($this->public . 'index.html', '');
        if (file_put_contents($this->public . '.htaccess', "Options -Indexes\n<IfModule mod_headers.c>\nHeader set Cache-Control \"no-cache\"\nHeader set X-Content-Type-Options \"nosniff\"\nHeader set Content-Security-Policy \"sandbox; default-src 'none'\"\nHeader set Content-Disposition \"attachment\"\n</IfModule>\n") === false) { throw new RuntimeException('Не удалось настроить публичную папку'); }
    }
    protected function publish($id, $format, $path)
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
    /** Installation must never republish or rotate user data. */
    public function migratePublic() { }
    public function delete($id)
    {
        $id = self::id($id); $lock = $this->lock($id);
        try {
            $this->source($id);
            // Retain the row until all bytes are removed, so a failed deletion can be retried.
            $this->removeTree($this->public.$id);
            foreach (glob($this->storage.'source-'.$id.'-*.parts') as $path) $this->removeTree($path);
            foreach (glob($this->storage.'source-'.$id.'-*.*') as $path) {
                if (is_file($path) && !unlink($path)) throw new RuntimeException('Не удалось удалить файл. Повторите удаление источника.');
            }
            $this->db->beginTransaction();
            $this->query('DELETE FROM '.$this->table('copies').' WHERE source_id=?', array($id));
            $this->query('DELETE FROM '.$this->table('options').' WHERE id=?', array($id));
            $this->query('DELETE FROM '.$this->table('sources').' WHERE id=?', array($id));
            $this->db->commit();
        } finally {
            try { if ($this->db->inTransaction()) $this->db->rollBack(); }
            finally { $this->unlock($lock); }
        }
        return array('deleted' => true);
    }
    public static function id($value, $allowZero = false)
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[0-9]+$/D', (string)$value)
            || strlen((string)$value) > 10 || (float)$value > 4294967295 || (!$allowZero && (int)$value < 1)) {
            throw new InvalidArgumentException('Недопустимый ID');
        }
        return (int)$value;
    }
    private function validateInput(array $data)
    {
        foreach ($data as $key => $value) {
            if (!is_scalar($value) && $value !== null) throw new InvalidArgumentException('Недопустимое значение: '.$key);
        }
    }
    /** Cleanup cannot hide the original error or prevent unlocking. */
    private function cleanup(callable $operation)
    {
        try { $operation(); return true; }
        catch (Throwable $e) { $this->modx->log(1, 'ArchiveFile recovery/cleanup: '.$e->getMessage()); return false; }
    }
    private function unlinkFile($path)
    {
        if ($path && is_file($path) && !unlink($path)) throw new RuntimeException('Не удалось удалить временный файл');
    }
    /** Under source lock, the DB commit decides whether to retain or undo a publication. */
    private function recoverPublication($id)
    {
        $journal = $this->storage.'source-'.$id.'.publication.json';
        if (!is_file($journal)) return;
        $j = json_decode(file_get_contents($journal), true);
        if (!is_array($j)) throw new RuntimeException('Повреждён журнал восстановления источника '.$id);
        foreach (array('filename','backup','parts_backup','parts_stage') as $key) {
            if (!isset($j[$key]) || !is_string($j[$key]) || basename($j[$key]) !== $j[$key]) throw new RuntimeException('Некорректный журнал восстановления');
        }
        if (!preg_match('/^source-'.$id.'-[A-Za-z0-9-]+\.(xml|csv|xlsx|xls|pdf|zip|json|txt)$/D', $j['filename'])
            || !preg_match('/^tmp-public-'.$id.'-[A-Za-z0-9]+$/D', $j['backup'])
            || !preg_match('/^\.parts-old-[a-f0-9]+$/D', $j['parts_backup'])
            || !preg_match('/^\.parts-new-[a-f0-9]+$/D', $j['parts_stage'])) throw new RuntimeException('Некорректные пути восстановления');
        $dir = $this->public.$id.'/'; $backup = $this->storage.$j['backup'];
        $partsBackup = $dir.$j['parts_backup']; $stage = $dir.$j['parts_stage'];
        $committed = $this->query('SELECT id FROM '.$this->table('copies').' WHERE source_id=? AND filename=?', array($id,$j['filename']))->fetchColumn();
        if (!$committed) {
            if (is_dir($partsBackup)) {
                $this->removeTree($dir.'parts');
                if (!rename($partsBackup,$dir.'parts')) throw new RuntimeException('Не удалось восстановить прежние части');
            } elseif (empty($j['had_parts']) && !is_dir($stage)) { $this->removeTree($dir.'parts'); }
            $format = pathinfo($j['filename'], PATHINFO_EXTENSION);
            if (!empty($j['had_full'])) {
                if (!is_file($backup)) throw new RuntimeException('Нет резервного файла для восстановления');
                $this->publish($id,$format,$backup);
            } else { $this->unlinkFile($dir.'lastfile.'.$format); }
            $this->unlinkFile($this->storage.$j['filename']);
            $this->removeTree($this->storage.$j['filename'].'.parts');
        }
        $this->removeTree($stage); $this->removeTree($partsBackup);
        // Remove the journal first: an interruption may leave an inert backup, not a broken journal.
        if (!unlink($journal)) throw new RuntimeException('Не удалось завершить восстановление');
        $this->unlinkFile($backup);
        return (bool)$committed;
    }
    private function pruneOrphans($id)
    {
        $keep = $this->query('SELECT filename FROM '.$this->table('copies').' WHERE source_id=?',array($id))->fetchAll(PDO::FETCH_COLUMN);
        foreach (glob($this->storage.'source-'.$id.'-*.*') as $path) {
            $name = basename($path); $base = substr($name,-6)==='.parts' ? substr($name,0,-6) : $name;
            if (in_array($base,$keep,true)) continue;
            if (is_dir($path)) $this->removeTree($path); else $this->unlinkFile($path);
        }
        foreach (array('tmp-source-'.$id.'-*','tmp-public-'.$id.'-*') as $pattern) {
            foreach (glob($this->storage.$pattern) as $path) $this->unlinkFile($path);
        }
        foreach (array('.parts-new-*','.parts-old-*','.pending-*') as $pattern) {
            foreach (glob($this->public.$id.'/'.$pattern) as $path) {
                if (is_dir($path)) $this->removeTree($path); else $this->unlinkFile($path);
            }
        }
    }
    private function writePublication($id, array $data)
    {
        $path = $this->storage.'source-'.$id.'.publication.json';
        $temp = tempnam($this->storage,'journal-');
        if (!$temp) throw new RuntimeException('Не удалось создать журнал публикации');
        try {
            if (file_put_contents($temp,json_encode($data),LOCK_EX) === false || !rename($temp,$path)) throw new RuntimeException('Не удалось сохранить журнал публикации');
        } finally { $this->unlinkFile($temp); }
    }
    public function save(array $data)
    {
        $this->validateInput($data);
        $name = trim(isset($data['name']) ? $data['name'] : '');
        $url = trim(isset($data['url']) ? $data['url'] : '');
        $format = isset($data['format']) ? $data['format'] : 'xml';
        if ($name === '' || strlen($name) > 190) { throw new RuntimeException('Укажите название длиной до 190 байт'); }
        $this->urlParts($url);
        if (!in_array($format, array('xml', 'csv', 'xlsx', 'xls', 'pdf', 'zip', 'json', 'txt'), true)) { throw new RuntimeException('Недопустимый формат'); }
        $xmlOptions = ArchiveFileXmlTools::options($data);
        $flag = isset($data['enabled']) ? (string)$data['enabled'] : '0';
        if (!in_array($flag,array('0','1'),true)) throw new InvalidArgumentException('Активность источника: 0 или 1');
        $enabled = (int)$flag;
        $id = isset($data['id']) ? self::id($data['id'], true) : 0;
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
            try {
            $this->db->beginTransaction();
            $this->query('INSERT INTO ' . $this->table('sources') . ' (name,url,format,enabled,created_at) VALUES (?,?,?,?,?)', array($name, $url, $format, $enabled, $this->now()));
            $id = (int)$this->db->lastInsertId();
            $this->writeOptions($id,$xmlOptions);$this->db->commit();
            } finally { if ($this->db->inTransaction()) $this->db->rollBack(); }
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
        try {
            $this->recoverPublication($id);
            $this->cleanup(function () use ($id) { $this->pruneOrphans($id); });
            $this->query('UPDATE '.$this->table('events').' SET status=?,finished_at=?,message=? WHERE source_id=? AND status=?', array('error',$this->now(),'Предыдущая обработка была прервана', $id,'running'));
        } catch (Throwable $e) { $this->unlock($handle); throw $e; }
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
        return ArchiveFileXmlTools::parse($file, array());
    }
    public function fetch($id, $daily = false)
    {
        $id = self::id($id);
        $lock = $this->lock($id); $temp = null; $final = null; $committed = false; $publicBackup = null; $publicTarget = null; $event = null; $start = microtime(true); $status = 'error'; $message = null; $size = null; $meta = array('offers_count'=>null); $warning = null; $archiveParts = null; $partsStage = null; $partsBackup = null; 
        try {
            $source = $this->source($id);
            $event = $this->beginEvent($daily ? 'daily' : 'manual', $source);
            $today = substr($this->now(), 0, 10);
            if ($daily && (!$source['enabled'] || $source['last_daily_date'] === $today)) { $status = 'skipped'; return array('skipped' => true); }
            $temp = tempnam($this->storage, 'tmp-source-'.$id.'-');
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
                $publicBackup = tempnam($this->storage, 'tmp-public-'.$id.'-');
                if (!$publicBackup || !copy($publicTarget, $publicBackup)) { throw new RuntimeException('Не удалось подготовить обновление постоянной ссылки'); }
            }
            $partsTarget=$this->public.$id.'/parts';
            $partsBackup=$this->public.$id.'/.parts-old-'.bin2hex(random_bytes(6));
            $this->writePublication($id,array('filename'=>$filename,'backup'=>$publicBackup ? basename($publicBackup) : 'tmp-public-'.$id.'-none',
                'had_full'=>(bool)$publicBackup,'parts_backup'=>basename($partsBackup),'parts_stage'=>basename($partsStage),'had_parts'=>is_dir($partsTarget)));
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
            if(is_dir($partsTarget)&&!rename($partsTarget,$partsBackup))throw new RuntimeException('Не удалось сохранить предыдущие части');
            if(!rename($partsStage,$partsTarget)){if(is_dir($partsBackup))rename($partsBackup,$partsTarget);throw new RuntimeException('Не удалось заменить части');}
            $partsStage=null;
            $this->publish($id, $source['format'], $final);
            $this->db->commit(); $committed = true;

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
            $this->cleanup(function () { if ($this->db->inTransaction()) $this->db->rollBack(); });
            $this->cleanup(function () use ($id, &$committed) { if (!$this->db->inTransaction()) $committed = $this->recoverPublication($id) === true || $committed; });
            $this->cleanup(function () use ($id, $e) {
                $this->query('UPDATE '.$this->table('sources').' SET last_error=? WHERE id=?', array(substr($e->getMessage(),0,2000),$id));
            });
            throw $e;
        } finally {
            $pending = is_file($this->storage.'source-'.$id.'.publication.json');
            if ($committed) $this->cleanup(function () use ($id) { $this->recoverPublication($id); });
            if (!$pending) {
                $this->cleanup(function () use ($partsStage) { if ($partsStage) $this->removeTree($partsStage); });
                $this->cleanup(function () use ($archiveParts,$committed) { if ($archiveParts && !$committed) $this->removeTree($archiveParts); });
                $this->cleanup(function () use ($final,$committed) { if (!$committed) $this->unlinkFile($final); });
                $this->cleanup(function () use ($publicBackup) { $this->unlinkFile($publicBackup); });
            }
            $this->cleanup(function () use ($temp) { $this->unlinkFile($temp); });
            try {
                if ($event) $this->cleanup(function () use ($event,$status,$start,$message,$size,$meta,$warning) {
                    $this->finishEvent($event,$status,$start,$message,$size,$meta['offers_count'],$warning);
                });
            } finally { $this->unlock($lock); }
        }
    }
    public function file($sourceId, $copyId = null)
    {
        $sourceId = self::id($sourceId);
        if ($copyId !== null) $copyId = self::id($copyId);
        $this->source($sourceId);
        $sql = 'SELECT * FROM ' . $this->table('copies') . ' WHERE source_id=?';
        $args = array((int)$sourceId);
        if ($copyId !== null) { $sql .= ' AND id=?'; $args[] = (int)$copyId; }
        $row = $this->query($sql . ' ORDER BY id DESC LIMIT 1', $args)->fetch(PDO::FETCH_ASSOC);
        if (!$row || !$this->validCopy($row)) { throw new RuntimeException('Копия не найдена, повреждена или уже удалена'); }
        $path = $this->storage . basename($row['filename']);
        // Open before releasing to the web server so rotation cannot break a running download.
        $handle = @fopen($path, 'rb');
        if (!$handle) { throw new RuntimeException('Файл недоступен'); }
        $stat = fstat($handle);
        if (!$stat || (int)$stat['size'] !== (int)$row['size_bytes']) { fclose($handle); throw new RuntimeException('Размер файла изменился'); }
        $row['size_bytes'] = $stat['size'];
        return array($row, $handle);
    }
}
