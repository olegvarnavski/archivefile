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
require __DIR__.'/fixtures/service.php';
$storage = sys_get_temp_dir() . '/archivefile-test-' . bin2hex(random_bytes(6)) . '/';
mkdir($storage);
define('MODX_CORE_PATH', $storage);
define('MODX_ASSETS_URL', '/assets/');
define('MODX_ASSETS_PATH', $storage . 'assets/');
$modx = new FakeModx($storage);
$service = new FixtureService($modx);
try {
    check($service->cronCommand()==='/usr/bin/php7.4 '.MODX_CORE_PATH.'components/archivefile/cron.php','cron command uses PHP 7.4 without unnecessary quotes');
    check(!($modx->getConnection() instanceof PDO) && !method_exists($modx->getConnection(), 'prepare'), 'MODX connection wrapper has no prepare method');
    $a = $service->save(array('name' => 'Источник А', 'url' => 'https://example.org/feed.xml', 'enabled' => 1));
    $b = $service->save(array('name' => 'Источник Б', 'url' => 'https://example.org/other.xml', 'enabled' => 1));
    for ($i = 0; $i < 6; $i++) { $service->fetch($a); }
    $service->fetch($b);
    $rows = $service->copies($a);
    check(count($rows) === 3, 'keep three copies after six successful downloads');
    check(count(glob($storage . 'source-' . $a . '-*.xml')) === 3, 'remove old bytes from disk');
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
    $service->xml = '';
    fails(function() use($service,$a){$service->fetch($a);}, 'empty response rejected');
    check(array_column($service->copies($a),'id')===$ids,'empty response preserves copies');
    $service->networkError = true;
    fails(function () use ($service, $a) { $service->fetch($a); }, 'download error');
    check(array_column($service->copies($a), 'id') === $ids, 'download error preserves all previous copies');
    check(hash_file('sha256', $public) === $publicHash, 'failed download preserves static latest');
    check(count(glob($storage . 'tmp-*')) === 0, 'temporary files cleaned after failures');
    $service->networkError = false;
    $service->xml = '<r/>';
    fails(function() use($service,$a){$service->fetch($a);}, 'zero-offer XML rejected');
    check(array_column($service->copies($a),'id')===$ids && hash_file('sha256',$public)===$publicHash, 'zero offers preserves three copies and lastfile');
    $service->xml = '<r><offer internal-id="ok"/></r>';
    $service->fetch($a, true);
    check(!empty($service->fetch($a, true)['skipped']), 'daily cron deduplicated by Moscow date');
    check(!empty($service->sources()[0]['warning']), 'sharp offer drop produces source warning');
    check((int)$service->copies($a)[0]['offers_count'] === 1, 'positive offers retained');
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
    $cronLock=$service->cronLock();
    check($cronLock && $service->cronLock()===false, 'parallel cron blocked');flock($cronLock,LOCK_UN);fclose($cronLock);
    $rows=$service->copies($a);$path=$storage.$rows[count($rows)-1]['filename'];$bytes=file_get_contents($path);file_put_contents($path,str_repeat('x',strlen($bytes)));
    check(count($service->copies($a))===2,'same-size corrupt bytes excluded by checksum');file_put_contents($path,$bytes);
    $events=$service->events();
    check(count($events)>0 && in_array('error',array_column($events,'status'),true), 'journal records failed attempts');
    check(in_array('ok',array_column($events,'status'),true) && in_array('manual',array_column($events,'kind'),true), 'journal records manual success');
    check($service->cronStatus()['last']===null && $service->cronStatus()['stale'], 'manual downloads do not fake cron heartbeat');
    $run=$service->beginEvent('cron');$service->finishEvent($run,'ok',microtime(true),'test cron');
    check(!$service->cronStatus()['stale'] && $service->cronStatus()['last']['status']==='ok', 'completed cron heartbeat');
    $run=$service->beginEvent('cron');$service->finishEvent($run,'error',microtime(true),'HTTP failure');
    check($service->cronStatus()['last']['status']==='error', 'failed cron status retained');
    $service->query('UPDATE '.$service->table('events').' SET started_at=? WHERE id=?',array('2020-01-01T00:00:00+03:00',$run));
    check($service->cronStatus()['stale'], 'overdue cron detected');
    $c=$service->save(array('name'=>'Custom XML','url'=>'https://example.org/custom.xml','format'=>'xml','enabled'=>1,
        'offer_element'=>'item','offer_attribute'=>'','date_element'=>'updated','container_path'=>'catalog/items','split_enabled'=>1,'split_limit'=>1000));
    $service->xml='<catalog xmlns="urn:test" xmlns:p="urn:attr"><updated>2026-10-13</updated><title>Shared</title><items>';
    for($i=0;$i<2500;$i++)$service->xml.='<item p:id="'.$i.'"><name>Object '.$i.'</name></item>';
    $service->xml.='</items><footer>Keep footer</footer></catalog>';
    $preview=$service->preview(array_merge($service->xmlOptions($c),array('url'=>'https://example.org/custom.xml')));
    check($preview['offers_count']===2500 && $preview['parts_count']===3 && strpos($preview['sample'],'Object 0')!==false, 'preview counts custom items and parts without archive');
    check(!$service->copies($c),'preview creates no archived copy');
    $service->fetch($c);$parts=$service->parts($c);
    check(array_column($parts,'offers_count')===array(1000,1000,500),'split 2500 into 1000+1000+500');
    foreach($parts as $part){$path=MODX_ASSETS_PATH.'archivefile/'.$c.'/parts/'.$part['filename'];$parsed=ArchiveFileXmlTools::parse($path,$service->xmlOptions($c));
        check($parsed['offers_count']===$part['offers_count'] && $parsed['generation_date']==='2026-10-13', 'part count and date verified');
        $bytes=file_get_contents($path);check(strpos($bytes,'Shared')!==false && strpos($bytes,'Keep footer')!==false && strpos($bytes,'urn:attr')!==false,'shared metadata and namespaces retained');}
    $target=MODX_ASSETS_PATH.'archivefile/'.$c.'/lastfile.xml';$oldBytes=file_get_contents($target);$oldParts=$service->parts($c);$oldIds=array_column($service->copies($c),'id');
    $service->xml='<catalog><items/></catalog>';
    fails(function() use($service,$c){$service->fetch($c);},'empty custom feed rejected');
    check(file_get_contents($target)===$oldBytes && $service->parts($c)===$oldParts,'zero offers preserves full file and parts');
    $service->xml='<catalog><items>';for($i=0;$i<2001;$i++)$service->xml.='<item/>';$service->xml.='</items></catalog>';
    rename($target,$target.'.saved');mkdir($target);
    fails(function() use($service,$c){$service->fetch($c);},'full-file publication failure after parts swap');
    check($service->parts($c)===$oldParts && array_column($service->copies($c),'id')===$oldIds,'publication failure restores previous parts and history');
    rmdir($target);rename($target.'.saved',$target);
    $service->xml='<catalog><items>';for($i=0;$i<1500;$i++)$service->xml.='<item/>';$service->xml.='</items></catalog>';$service->fetch($c);
    check(count($service->parts($c))===2&&!is_file(MODX_ASSETS_PATH.'archivefile/'.$c.'/parts/part-003.xml'),'obsolete third part removed');
    $service->xml='<catalog><items><item/></items></catalog>';$service->fetch($c);
    check(!$service->parts($c),'feed below limit keeps full file without parts');
    $o=$service->xmlOptions($c);check($o['offer_element']==='item'&&$o['offer_attribute']==='','source XML settings persisted');
    fails(function(){ArchiveFileXmlTools::options(array('offer_element'=>'//item'));},'arbitrary XPath blocked');
    fails(function(){ArchiveFileXmlTools::options(array('split_limit'=>0));},'zero split limit blocked');
    $ambiguous=$storage.'ambiguous.xml';file_put_contents($ambiguous,'<r><a><item/></a><b><item/></b></r>');
    fails(function() use($ambiguous){ArchiveFileXmlTools::parse($ambiguous,array('offer_element'=>'item','offer_attribute'=>'','split_enabled'=>1));},'ambiguous containers rejected');
    // Failure after public bytes changed must restore full file, parts, rows and release lock.
    $service->xml='<catalog><items><item/><item/></items></catalog>';
    $snapshot=file_get_contents($target); $savedIds=array_column($service->copies($c),'id');
    $service->failPublish=true;
    fails(function() use($service,$c){$service->fetch($c);},'injected failure after publish');
    check(file_get_contents($target)===$snapshot && array_column($service->copies($c),'id')===$savedIds,'after-publish failure restores old full file and rows');
    check(!is_file($storage.'source-'.$c.'.publication.json'),'recovery journal cleared after successful rollback');
    $service->failCopyInsert=true; $service->failCleanup=true;
    try { $service->fetch($c); throw new RuntimeException('Expected failure'); }
    catch (Throwable $e) { check($e->getMessage()==='Injected DB insert failure','cleanup failure preserves original exception'); }
    check(is_file($storage.'source-'.$c.'.publication.json'),'failed recovery keeps journal and backup for retry');
    $service->failCopyInsert=false; $service->failCleanup=false;
    $service->save(array_merge($service->source($c),$service->xmlOptions($c)));
    check(!is_file($storage.'source-'.$c.'.publication.json') && file_get_contents($target)===$snapshot,'retry recovers after cleanup failure and releases source lock');
    $modx->db->commitThenFail=true;
    fails(function() use($service,$c){$service->fetch($c);},'lost DB commit acknowledgement');
    check(count($service->copies($c))===3 && file_get_contents($target)===$service->xml,'actual commit retained despite lost acknowledgement');
    check(!is_file($storage.'source-'.$c.'.publication.json'),'committed recovery completes');
    $corrupt=$service->copies($c)[0]; $corruptPath=$storage.$corrupt['filename']; $corruptBytes=file_get_contents($corruptPath);
    file_put_contents($corruptPath,str_repeat('x',strlen($corruptBytes)));
    fails(function() use($service,$c,$corrupt){$service->file($c,$corrupt['id']);},'direct historical download rejects same-size corruption');
    file_put_contents($corruptPath,$corruptBytes);
    $oldCount=count($service->sources()); $service->failOptions=true;
    fails(function() use($service){$service->save(array('name'=>'rollback','url'=>'https://example.org/new.xml'));},'new source options failure');
    $service->failOptions=false;
    check(!$modx->db->inTransaction() && count($service->sources())===$oldCount,'new source failure rolls back transaction and row');
    foreach (array(array('name'=>array()),array('url'=>array()),array('id'=>'1oops')) as $bad) {
        fails(function() use($service,$bad){$service->save(array_merge(array('name'=>'valid','url'=>'https://example.org/f.xml'),$bad));},'malformed source input rejected');
    }
    fails(function(){ArchiveFileXmlTools::options(array('split_limit'=>'1000x'));},'noninteger split limit rejected');
    fails(function(){ArchiveFileXmlTools::options(array('offer_element'=>array()));},'array XML setting rejected');
    $service->failDeletePath=MODX_ASSETS_PATH.'archivefile/'.$c;
    fails(function() use($service,$c){$service->delete($c);},'file deletion failure');
    check((int)$service->source($c)['id']===$c,'failed deletion retains row for retry');
    $service->failDeletePath=null;
    // Journal retention: cron runs are bounded; the latest source warning survives pruning.
    $warningBefore=$service->sources()[0]['warning'];
    for($i=0;$i<215;$i++){$e=$service->beginEvent('cron');$service->finishEvent($e,'ok',microtime(true));}
    check((int)$service->query('SELECT COUNT(*) FROM '.$service->table('events'))->fetchColumn()<=205,'cron journal retention is bounded');
    check($service->sources()[0]['warning']===$warningBefore,'last successful warning survives pruning');
    $e=$service->beginEvent('manual',$service->source($c));$service->fetch($c);
    check($service->query('SELECT status FROM '.$service->table('events').' WHERE id=?',array($e))->fetchColumn()==='error','abandoned source run marked interrupted under lock');
    $service->delete($c);check(!is_dir(MODX_ASSETS_PATH.'archivefile/'.$c.'/parts'),'delete removes parts');
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
