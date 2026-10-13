<?php
require_once dirname(__DIR__) . '/index.class.php';
class ArchivefileHomeManagerController extends ArchivefileManagerController
{
    public function getPageTitle() { return 'Archive File'; }
    public function process(array $scriptProperties = array())
    {
        return file_get_contents(dirname(__DIR__) . '/templates/home.html');
    }
    public function loadCustomCssJs()
    {
        if (empty($_SESSION['archivefile_csrf'])) { $_SESSION['archivefile_csrf'] = bin2hex(random_bytes(32)); }
        $base = $this->modx->getOption('assets_url', null, MODX_ASSETS_URL) . 'components/archivefile/';
        $config = array('connector' => $base . 'connector.php', 'csrf' => $_SESSION['archivefile_csrf']);
        $this->addCss($base . 'mgr.css?v=1.3.0');
        $this->addHtml('<script>window.ArchiveFileConfig=' . json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>');
        $this->addLastJavascript($base . 'mgr.js?v=1.3.0');
    }
}
