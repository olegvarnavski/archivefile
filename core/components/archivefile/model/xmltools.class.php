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
    private static function write($stream, $bytes)
    {
        $length=strlen($bytes);$offset=0;
        while($offset<$length){$n=fwrite($stream,substr($bytes,$offset));if($n===false||$n===0)throw new RuntimeException('Не удалось записать временный XML');$offset+=$n;}
    }
    private static function escape($value)
    {
        return htmlspecialchars($value,ENT_QUOTES|ENT_XML1,'UTF-8');
    }
    /** XMLReader retains only the current node; skeleton and offers are spooled to disk. */
    private static function scan($file,array $options,$skeleton=null,$offers=null)
    {
        $o=self::options($options);$errors=libxml_use_internal_errors(true);$loader=libxml_disable_entity_loader(false);libxml_clear_errors();
        $reader=new XMLReader();$count=0;$date=null;$dateSeen=false;$sample=null;$stack=array();$seq=0;$parentId=null;$containers=0;$skip=null;$root=false;$insert=null;
        try {
            // XMLReader::open needs the primary local file loader; DTD/entity loading remains disabled.
            if(!is_file($file)||!filesize($file)||!$reader->open($file,null,LIBXML_NONET))throw new RuntimeException('Не удалось открыть XML');
            $reader->setParserProperty(XMLReader::LOADDTD,false);$reader->setParserProperty(XMLReader::SUBST_ENTITIES,false);$reader->setParserProperty(XMLReader::VALIDATE,false);
            if($skeleton)self::write($skeleton,'<?xml version="1.0" encoding="UTF-8"?>');
            while($reader->read()){
                $type=$reader->nodeType;$depth=$reader->depth;
                if($type===XMLReader::DOC_TYPE||$type===XMLReader::ENTITY_REF)throw new RuntimeException('XML с DTD или сущностями не поддерживается');
                if($skip!==null){if($depth>$skip)continue;if($type===XMLReader::END_ELEMENT&&$depth===$skip){$skip=null;continue;}$skip=null;}
                if($type===XMLReader::ELEMENT){
                    $root=true;$stack=array_slice($stack,0,$depth);$parent=$depth?($stack[$depth-1]['id']??null):null;
                    $parentPath=implode('/',array_column($stack,'name'));$stack[]=array('name'=>$reader->localName,'id'=>++$seq);
                    $path=implode('/',array_column($stack,'name'));if($o['container_path']!==''&&$path===trim($o['container_path'],'/'))$containers++;
                    if(!$dateSeen&&$o['date_element']!==''&&$reader->localName===$o['date_element']){$date=substr(trim($reader->readString()),0,190);$dateSeen=true;}
                    $selected=$reader->localName===$o['offer_element']&&($o['container_path']===''||$parentPath===trim($o['container_path'],'/'));
                    if($selected&&$o['offer_attribute']!==''){
                        $selected=false;if($reader->moveToFirstAttribute()){do{if($reader->localName===$o['offer_attribute'])$selected=true;}while($reader->moveToNextAttribute());$reader->moveToElement();}
                    }
                    if($selected){
                        if($count===0)$parentId=$parent;
                        if($o['split_enabled']&&($parent===null||$parent!==$parentId))throw new RuntimeException('Предложения находятся в разных контейнерах: укажите путь контейнера');
                        $count++;
                        if($sample===null||$offers){$xml=$reader->readOuterXML();if($sample===null)$sample=substr($xml,0,2000);if($offers)self::write($offers,$xml);unset($xml);}
                        if($skeleton){if($insert===null)$insert=ftell($skeleton);if(!$reader->isEmptyElement)$skip=$depth;continue;}
                    }
                    if($skeleton){
                        $tag='<'.$reader->name;
                        if($reader->moveToFirstAttribute()){do{$tag.=' '.$reader->name.'="'.self::escape($reader->value).'"';}while($reader->moveToNextAttribute());$reader->moveToElement();}
                        self::write($skeleton,$tag.($reader->isEmptyElement?'/>':'>'));
                    }
                }elseif($skeleton){
                    if($type===XMLReader::END_ELEMENT)self::write($skeleton,'</'.$reader->name.'>');
                    elseif(in_array($type,array(XMLReader::TEXT,XMLReader::WHITESPACE,XMLReader::SIGNIFICANT_WHITESPACE),true))self::write($skeleton,self::escape($reader->value));
                    elseif($type===XMLReader::CDATA)self::write($skeleton,'<![CDATA['.$reader->value.']]>');
                    elseif($type===XMLReader::COMMENT)self::write($skeleton,'<!--'.$reader->value.'-->');
                    elseif($type===XMLReader::PI)self::write($skeleton,'<?'.$reader->name.' '.$reader->value.'?>');
                }
            }
            foreach(libxml_get_errors() as $error)if($error->level>=LIBXML_ERR_ERROR)throw new RuntimeException('Некорректный XML: '.trim($error->message));
            if(!$root)throw new RuntimeException('XML не содержит корневого элемента');
            if($o['container_path']!==''&&$containers!==1)throw new RuntimeException('Контейнер должен находиться ровно один раз');
            return array('options'=>$o,'offers_count'=>$count,'generation_date'=>$date===''?null:$date,'sample'=>$sample,'insert'=>$insert);
        }finally{$reader->close();libxml_clear_errors();libxml_use_internal_errors($errors);libxml_disable_entity_loader($loader);}
    }
    public static function parse($file,array $options)
    {
        return self::scan($file,$options);
    }
    public static function split($file,array $options,$directory)
    {
        $data=self::parse($file,$options);$o=$data['options'];$count=$data['offers_count'];
        if(!$count)throw new RuntimeException('Не найдено предложений по настройкам XML');
        if(!$o['split_enabled']||$count<=$o['split_limit'])return array();
        if(!is_dir($directory)&&!mkdir($directory,0750,true))throw new RuntimeException('Не удалось создать папку частей');
        $skeleton=tmpfile();$spool=tmpfile();$spoolReader=new XMLReader();$parts=array();$out=null;
        if(!$skeleton||!$spool){if($skeleton)fclose($skeleton);if($spool)fclose($spool);throw new RuntimeException('Не удалось создать временные файлы частей');}
        try{
            self::write($spool,'<archivefile-spool>');$shape=self::scan($file,$o,$skeleton,$spool);self::write($spool,'</archivefile-spool>');fflush($spool);fflush($skeleton);
            $uri=stream_get_meta_data($spool)['uri'];$loader=libxml_disable_entity_loader(false);
            try{if(!$spoolReader->open($uri,null,LIBXML_NONET))throw new RuntimeException('Не удалось прочитать предложения');}finally{libxml_disable_entity_loader($loader);}
            $spoolReader->setParserProperty(XMLReader::LOADDTD,false);$spoolReader->setParserProperty(XMLReader::SUBST_ENTITIES,false);
            $number=0;$inPart=0;$total=0;$skip=null;$name=null;$path=null;
            while($spoolReader->read()){
                if($skip!==null){if($spoolReader->depth>$skip)continue;if($spoolReader->nodeType===XMLReader::END_ELEMENT&&$spoolReader->depth===$skip){$skip=null;continue;}$skip=null;}
                if($spoolReader->nodeType!==XMLReader::ELEMENT||$spoolReader->depth!==1)continue;
                if(!$out){$name=sprintf('part-%03d.xml',++$number);$path=$directory.'/'.$name;$out=fopen($path,'wb');if(!$out)throw new RuntimeException('Не удалось создать часть');rewind($skeleton);if(stream_copy_to_stream($skeleton,$out,$shape['insert'])===false)throw new RuntimeException('Не удалось записать заголовок части');}
                self::write($out,$spoolReader->readOuterXML());$inPart++;$total++;if(!$spoolReader->isEmptyElement)$skip=$spoolReader->depth;
                if($inPart===$o['split_limit']||$total===$count){
                    fseek($skeleton,$shape['insert']);if(stream_copy_to_stream($skeleton,$out)===false)throw new RuntimeException('Не удалось записать окончание части');fclose($out);$out=null;
                    $check=self::parse($path,$o);if($check['offers_count']!==$inPart)throw new RuntimeException('Проверка количества предложений части не прошла');
                    $parts[]=array('filename'=>$name,'offers_count'=>$inPart,'size_bytes'=>filesize($path),'sha256'=>hash_file('sha256',$path));$inPart=0;
                }
            }
            if($total!==$count||$out)throw new RuntimeException('Неполный набор предложений при разделении');
            if(file_put_contents($directory.'/manifest.json',json_encode($parts,JSON_UNESCAPED_UNICODE))===false)throw new RuntimeException('Не удалось сохранить список частей');
            return $parts;
        }finally{if($out)fclose($out);$spoolReader->close();fclose($skeleton);fclose($spool);}
    }
}
