<?php
/** Restricted local-name paths; no arbitrary XPath or external entities. */
class ArchiveFileXmlTools
{
    public static function options(array $input)
    {
        $o = array('offer_element'=>'offer','offer_attribute'=>'internal-id','date_element'=>'generation-date','container_path'=>'','split_enabled'=>0,'split_limit'=>1000);
        foreach ($o as $k=>$v) if (isset($input[$k])) $o[$k] = is_string($v) ? trim((string)$input[$k]) : (int)$input[$k];
        foreach (array('offer_element','offer_attribute','date_element') as $k) {
            if ($o[$k] === '' && $k !== 'offer_element') continue;
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{0,99}$/D',$o[$k])) throw new RuntimeException('Недопустимое XML-имя: '.$k);
        }
        if ($o['container_path'] !== '' && !preg_match('~^/?[A-Za-z_][A-Za-z0-9_.-]*(/[A-Za-z_][A-Za-z0-9_.-]*)*$~D',$o['container_path'])) throw new RuntimeException('Контейнер: путь из имён элементов через /, без XPath');
        $o['split_enabled'] = $o['split_enabled'] ? 1 : 0;
        if ($o['split_limit'] < 1 || $o['split_limit'] > 100000) throw new RuntimeException('Лимит части: от 1 до 100000');
        return $o;
    }
    public static function parse($file, array $options)
    {
        $o=self::options($options);$errors=libxml_use_internal_errors(true);$loader=libxml_disable_entity_loader(true);libxml_clear_errors();
        try {
            $limit=trim(ini_get('memory_limit'));$budget=(int)$limit;if(stripos($limit,'G')!==false)$budget*=1024*1024*1024;elseif(stripos($limit,'M')!==false)$budget*=1024*1024;elseif(stripos($limit,'K')!==false)$budget*=1024;
            if($budget>0 && filesize($file)*12+memory_get_usage(true)+4194304>$budget)throw new RuntimeException('Недостаточно memory_limit PHP для разбора XML; увеличьте лимит памяти');
            $bytes=file_get_contents($file);$doc=new DOMDocument();
            if ($bytes===false || trim($bytes)==='' || !$doc->loadXML($bytes,LIBXML_NONET) || $doc->doctype || !$doc->documentElement) throw new RuntimeException('Некорректный XML или запрещённый DTD');
            $xpath=new DOMXPath($doc);$expr='//*[local-name()="'.$o['offer_element'].'"]';
            if($o['offer_attribute']!=='') $expr.='[@*[local-name()="'.$o['offer_attribute'].'"]]';
            $all=$xpath->query($expr);$offers=array();$container=null;
            if($o['container_path']!=='') {
                $path='';foreach(explode('/',trim($o['container_path'],'/')) as $name)$path.='/*[local-name()="'.$name.'"]';
                $nodes=$xpath->query($path);if($nodes->length!==1)throw new RuntimeException('Контейнер должен находиться ровно один раз');$container=$nodes->item(0);
            }
            foreach($all as $node) if(!$container || $node->parentNode->isSameNode($container))$offers[]=$node;
            if(!$container && count($offers)) {
                $container=$offers[0]->parentNode;
                if($o['split_enabled']) foreach($offers as $node)if(!$node->parentNode->isSameNode($container))throw new RuntimeException('Предложения находятся в разных контейнерах: укажите путь контейнера');
            }
            $date=null;
            if($o['date_element']!=='') {$nodes=$xpath->query('//*[local-name()="'.$o['date_element'].'"]');if($nodes->length)$date=substr(trim($nodes->item(0)->textContent),0,190);}
            return array('document'=>$doc,'offers'=>$offers,'container'=>$container,'options'=>$o,'generation_date'=>$date===''?null:$date,'offers_count'=>count($offers),'sample'=>count($offers)?substr($doc->saveXML($offers[0]),0,2000):null);
        } finally {libxml_clear_errors();libxml_use_internal_errors($errors);libxml_disable_entity_loader($loader);}
    }
    public static function split($file,array $options,$directory)
    {
        $data=self::parse($file,$options);$o=$data['options'];$count=$data['offers_count'];
        if(!$count)throw new RuntimeException('Не найдено предложений по настройкам XML');
        if(!$o['split_enabled'] || $count <= $o['split_limit'])return array();
        if(!is_dir($directory)&&!mkdir($directory,0750,true))throw new RuntimeException('Не удалось создать папку частей');
        // One common direct-child container is required. Preserve every other node and namespace.
        $doc=$data['document'];$container=$data['container'];$offers=$data['offers'];
        $marker=$doc->createComment('archivefile-insert-offers');$container->insertBefore($marker,$offers[0]);
        foreach($offers as $node)$container->removeChild($node);
        $parts=array();
        foreach(array_chunk($offers,$o['split_limit']) as $i=>$batch) {
            foreach($batch as $node)$container->insertBefore($node,$marker);
            $name=sprintf('part-%03d.xml',$i+1);$path=$directory.'/'.$name;
            // The marker is an internal placeholder and must not become feed content.
            $output=$doc->saveXML();$output=str_replace('<!--archivefile-insert-offers-->','',$output);
            if(file_put_contents($path,$output)===false)throw new RuntimeException('Не удалось сохранить часть XML');
            $check=self::parse($path,$o);
            if($check['offers_count']!==count($batch))throw new RuntimeException('Проверка количества предложений части не прошла');
            $parts[]=array('filename'=>$name,'offers_count'=>count($batch),'size_bytes'=>filesize($path),'sha256'=>hash_file('sha256',$path));
            foreach($batch as $node)$container->removeChild($node);
        }
        if(file_put_contents($directory.'/manifest.json',json_encode($parts,JSON_UNESCAPED_UNICODE))===false)throw new RuntimeException('Не удалось сохранить список частей');
        return $parts;
    }
}
