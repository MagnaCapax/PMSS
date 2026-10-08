<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Keep customer-side action callers aligned with the POST/XHR endpoint guard. */
class CustomerActionRequestHeaderTest extends TestCase
{
    public function testCustomerActionCallersSendRequestedWithHeader(): void
    {
        $root = dirname(__DIR__, 4).'/etc/skel/www';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $checked = array();
        $endpoints = '/\b(?:qbittorrent|deluge|rclone|rtorrentRestart|lighttpdRestart|mediaStack)\.php\b|data-endpoint/';

        foreach ($files as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if (strpos($relative, 'rutorrent/') === 0 || $relative === 'filemanager.php'
                || !in_array($file->getExtension(), array('php', 'js', 'html'), true)) {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            $this->assertTrue($source !== false, $relative);
            $scripts = $file->getExtension() === 'js' ? array($source) : array();
            if ($file->getExtension() !== 'js') {
                preg_match_all('/<script\b[^>]*>(.*?)<\/script>/is', $source, $matches);
                $scripts = $matches[1];
                preg_match_all('/\bon(?:click|submit)\s*=\s*([\'\"])(.*?)\1/is', $source, $attributes, PREG_SET_ORDER);
                foreach ($attributes as $attribute) {
                    if (!preg_match($endpoints, $attribute[2])) continue;
                    $this->assertTrue(preg_match('/\bpmss(?:RunAction|ActionRequest)\s*\(/', $attribute[2]) === 1,
                        $relative.' has an inline action outside the header-setting wrappers');
                }
            }

            foreach ($scripts as $script) {
                // A named handler owns its requests; split so another handler's header cannot mask a missing one.
                foreach (preg_split('/(?=\bfunction\s+[A-Za-z_$][\w$]*\s*\()/', $script) as $handler) {
                    if (!preg_match($endpoints, $handler)) continue;
                    if (!preg_match('/\.open\(\s*[\'\"]POST[\'\"]|\$\.post\s*\(|(?:type|method)\s*:\s*[\'\"]POST[\'\"]/i', $handler)) continue;
                    $this->assertTrue(strpos($handler, 'X-Requested-With') !== false,
                        $relative.' has a direct POST action without X-Requested-With');
                    $checked[$relative] = true;
                }
            }
        }

        // The common wrappers must retain their own header, and the dynamic Info-tab caller must be scanned.
        $shared = file_get_contents($root.'/pmssActions.js');
        $this->assertTrue(strpos($shared, "headers: {'X-Requested-With': 'XMLHttpRequest'}") !== false);
        $this->assertTrue(strpos($shared, 'pmssActionRequest(action, passwordValue)') !== false);
        $this->assertTrue(isset($checked['stats.php']), 'Info-tab dynamic endpoint was not checked');
    }
}
