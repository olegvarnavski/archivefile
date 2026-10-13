<?php
abstract class ArchivefileManagerController extends modExtraManagerController
{
    public function checkPermissions() { return $this->modx->hasPermission('settings'); }
    public function getLanguageTopics() { return array('archivefile:default'); }
}
class IndexManagerController extends ArchivefileManagerController
{
    public static function getDefaultController() { return 'home'; }
}
