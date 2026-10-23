<?php
/** Invoked by recovery.py in an isolated temp directory. */
require dirname(__DIR__).'/core/components/archivefile/model/archivefile.class.php';
require __DIR__.'/fixtures/service.php';
$root=$argv[1].'/'; $mode=$argv[2]; $point=$argv[3];
define('MODX_CORE_PATH',$root);define('MODX_ASSETS_URL','/assets/');define('MODX_ASSETS_PATH',$root.'assets/');
$modx=new FakeModx($root,'sqlite:'.$root.'test.sqlite');
class CrashService extends FixtureService {
    public $pausePublish=false;
    protected function publish($id,$format,$path) {
        parent::publish($id,$format,$path);
        if ($this->pausePublish) { echo "READY\n"; flush(); while (true) usleep(100000); }
    }
}
$s=new CrashService($modx);
$old='<r><offer/><offer/></r>'; $new='<r><offer/><offer/><offer/></r>';
$target=MODX_ASSETS_PATH.'archivefile/1/lastfile.xml';
if($mode==='prepare') {
    $s->save(array('name'=>'Crash fixture','url'=>'https://example.org/feed.xml','offer_attribute'=>'','split_enabled'=>1,'split_limit'=>1));
    $s->xml=$old; $s->fetch(1);
} elseif($mode==='crash') {
    $s->xml=$new;
    if($point==='before-commit') $s->pausePublish=true; else $modx->db->pauseCommit=true;
    $s->fetch(1); throw new RuntimeException('Crash point not reached');
} elseif($mode==='verify') {
    $s->save(array_merge($s->source(1),$s->xmlOptions(1)));
    $committed=$point==='after-commit';
    if(file_get_contents($target)!==($committed?$new:$old)) throw new RuntimeException('Wrong recovered full file');
    if(count($s->parts(1))!==($committed?3:2)) throw new RuntimeException('Wrong recovered parts');
    if(count($s->copies(1))!==($committed?2:1)) throw new RuntimeException('Wrong recovered copies');
    if(is_file($root.'source-1.publication.json')) throw new RuntimeException('Journal not cleared');
    foreach($s->parts(1) as $part) {
        $path=MODX_ASSETS_PATH.'archivefile/1/parts/'.$part['filename'];
        if(hash_file('sha256',$path)!==$part['sha256']) throw new RuntimeException('Wrong part hash');
    }
    $events=$s->events();if(!in_array('error',array_column($events,'status'),true)) throw new RuntimeException('Interrupted event not diagnosed');
    $s->xml=$new;$s->fetch(1);
    echo 'PASS SIGKILL recovery '.$point.PHP_EOL;
} else throw new RuntimeException('Unknown test mode');
