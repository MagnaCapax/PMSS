<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Render etc/skel/www/index.php with quota-like I/O failures; run only on testing machines. */
class IndexRemoteFramesFailoverTest extends TestCase
{
    public function testWriteFailuresSkipRemoteHealAndRenderFileManager(): void
    {
        foreach (['open-home', 'open-www', 'write-false', 'write-zero', 'flush-false'] as $failure) {
            $result = $this->render($failure, 'echo "REMOTE RAN"; return array();');
            $this->assertLocalPanel($result);
            $this->assertSame(0, $result['fetches'], $failure);
            $this->assertSame('', $result['errors'], $failure);
            $this->assertSame($failure === 'open-www' ? 2 : 1, count($result['probes']), $failure);
        }
    }

    public function testInvalidOrNoisyPayloadsRenderCleanLocalFrames(): void
    {
        foreach ([
            'return false;',
            'echo "BROKEN PAYLOAD"; return array();',
            'trigger_error("BROKEN PAYLOAD", E_USER_WARNING); return array();',
            'echo "BROKEN PAYLOAD"; throw new \\RuntimeException("failed heal");',
            'ob_start(); echo "BROKEN PAYLOAD"; return array();',
            '$pmssHealReload = true; echo "BROKEN PAYLOAD"; return array();',
            '$pmssHealReload = true; return null;',
            'this is not PHP;',
        ] as $payload) {
            $result = $this->render('', $payload);
            $this->assertLocalPanel($result);
            $this->assertSame(1, $result['fetches'], $payload);
        }
    }

    public function testHealthyRemotePayloadKeepsItsHeadAndFrames(): void
    {
        $result = $this->render('', '$htmlHead = "<!-- REMOTE HEAD -->"; return array("welcome" => '
            .'array("url" => "remote-welcome.php", "title" => "Remote", "linkText" => "Welcome"));');
        $this->pmssAssertArraySubsetSame(['status' => 200, 'fetches' => 1, 'errors' => '',
            'leftovers' => [], 'bufferDelta' => 0], $result);
        $this->assertStringContainsAndOmitsStrings(['<!-- REMOTE HEAD -->', 'remote-welcome.php', '</html>'],
            ['id="pmss-tab-filemanager"'], $result['html']);
    }

    public function testExplicitLocalModeDoesNotProbeOrFetch(): void
    {
        foreach (['disabled', 'local-only'] as $mode) {
            $result = $this->render($mode, 'echo "REMOTE RAN"; return array();');
            $this->assertLocalPanel($result);
            $this->pmssAssertArraySubsetSame(['fetches' => 0, 'probes' => []], $result);
        }
    }

    private function assertLocalPanel(array $result): void
    {
        $this->pmssAssertArraySubsetSame(['status' => 200, 'leftovers' => [], 'bufferDelta' => 0], $result);
        $this->assertStringContainsAndOmitsStrings(['<!DOCTYPE HTML', 'id="pmss-tab-welcome"',
            'id="pmss-tab-filemanager"', 'filemanager.php', '</html>'], ['BROKEN PAYLOAD', 'REMOTE RAN'], $result['html']);
    }

    private function render(string $failure, string $payload): array
    {
        $home = $this->pmssMakeTempDir('pmss-index-failover-').'/fixtur01';
        $this->pmssWriteFile($home.'/www/filemanager.php', '<?php // Fixture route.');
        if ($failure === 'local-only') $this->pmssWriteFile($home.'/.guiFramesLocalOnly', '');
        // Shims retain real temporary-file writes except the injected refusal.
        // In particular, the successful create + failed data write models EDQUOT
        // on a filesystem with a free inode but no remaining data-block quota.
        $shims = <<<'PHP'
namespace IndexFramesFixture;
function getenv($key) { return $GLOBALS['failure'] === 'disabled' ? '1' : false; }
function file_get_contents($path, $include = false, $context = null) {
    if ($path === 'https://pulsedmedia.com/remote/guiFrames.php?v=2') {
        $GLOBALS['fetches']++;
        return base64_encode(serialize($GLOBALS['payload']));
    }
    return \file_get_contents($path);
}
function fopen($path, $mode) {
    $GLOBALS['probes'][] = [dirname($path), $mode];
    $parent = $GLOBALS['failure'] === 'open-home' ? $GLOBALS['home'] : $GLOBALS['home'].'/www';
    if (strpos($GLOBALS['failure'], 'open-') === 0 && dirname($path) === $parent) {
        trigger_error('Disk quota exceeded', E_USER_WARNING);
        return false;
    }
    return \fopen($path, $mode);
}
function fwrite($handle, $data) {
    if (in_array($GLOBALS['failure'], ['write-false', 'write-zero'], true)) {
        trigger_error('Disk quota exceeded', E_USER_WARNING);
        return $GLOBALS['failure'] === 'write-zero' ? 0 : false;
    }
    return \fwrite($handle, $data);
}
function fflush($handle) {
    if ($GLOBALS['failure'] === 'flush-false') {
        trigger_error('Disk quota exceeded', E_USER_WARNING);
        return false;
    }
    return \fflush($handle);
}
PHP;
        $source = $this->pmssReadRepoFile('etc/skel/www/index.php');
        $fixture = $this->pmssWriteFile($home.'/www/index.php', '<?php '.$shims.substr($source, 5));
        $script = 'function pmssWelcomeHttpContextCreate() { return null; }'
            .'$home = '.var_export($home, true).'; $failure = '.var_export($failure, true).';'
            .'$payload = '.var_export($payload, true).'; $fetches = 0; $probes = [];'
            .'chdir($home."/www"); $_POST = []; $_SERVER["REQUEST_URI"] = "/user-fixtur01/";'
            .'ini_set("display_errors", "1"); ini_set("html_errors", "0"); error_reporting(E_ALL);'
            .'ini_set("log_errors", "1"); ini_set("error_log", $home."/errors.log");'
            .'http_response_code(200); $level = ob_get_level(); ob_start(); require '.var_export($fixture, true).';'
            .'$html = ob_get_clean(); echo json_encode(["html" => $html, "status" => http_response_code(),'
            .'"fetches" => $fetches, "probes" => $probes, "bufferDelta" => ob_get_level() - $level,'
            .'"leftovers" => array_merge(glob($home."/.pmss-gui-write-*"), glob($home."/www/.pmss-gui-write-*")),'
            .'"errors" => is_file($home."/errors.log") ? file_get_contents($home."/errors.log") : ""]);';
        return $this->pmssRunInlinePhpJson($script);
    }
}
