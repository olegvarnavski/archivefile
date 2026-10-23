<?php
require dirname(__DIR__).'/core/components/archivefile/model/xmltools.class.php';
// Regression: a feed the size of the failing production download must work under 32 MiB PHP heap.
ini_set('memory_limit','32M');
$base=sys_get_temp_dir().'/archivefile-large-'.bin2hex(random_bytes(6));mkdir($base);
$input=$base.'/feed.xml';$parts=$base.'/parts';
function expectLarge($condition,$name){if(!$condition)throw new RuntimeException('FAIL '.$name);echo 'PASS '.$name.PHP_EOL;}
try{
    $out=fopen($input,'wb');fwrite($out,'<?xml version="1.0"?><feed xmlns="urn:feed" xmlns:p="urn:attr"><generation-date>2026-10-23</generation-date><agency>Common metadata</agency><items>');
    $payload=str_repeat('a &amp; b ',300);
    for($i=0;$i<8500;$i++)fwrite($out,'<offer internal-id="'.$i.'" p:tag="test"><description>'.$payload.'</description></offer>');
    fwrite($out,'</items><footer>Preserved</footer></feed>');fclose($out);
    $options=array('container_path'=>'feed/items','split_enabled'=>1,'split_limit'=>1000);
    expectLarge(filesize($input)>24*1024*1024,'fixture exceeds 24 MiB');
    $data=ArchiveFileXmlTools::parse($input,$options);
    expectLarge($data['offers_count']===8500 && $data['generation_date']==='2026-10-23','large metadata under memory_limit=32M');
    $rows=ArchiveFileXmlTools::split($input,$options,$parts);
    expectLarge(count($rows)===9 && $rows[8]['offers_count']===500,'large split: eight 1000-offer parts and one 500-offer part');
    $total=0;foreach($rows as $row){$read=ArchiveFileXmlTools::parse($parts.'/'.$row['filename'],$options);$total+=$read['offers_count'];expectLarge($read['generation_date']==='2026-10-23','part date preserved');}
    expectLarge($total===8500,'no offers lost');
    expectLarge(memory_get_peak_usage(true)<32*1024*1024,'PHP memory stays below 32 MiB');
    echo 'Input bytes: '.filesize($input).'; peak PHP bytes: '.memory_get_peak_usage(true).PHP_EOL;
}finally{
    if(is_dir($parts)){foreach(glob($parts.'/*') as $path)unlink($path);rmdir($parts);}
    if(is_file($input))unlink($input);rmdir($base);
}
