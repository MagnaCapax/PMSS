<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Run the real endpoint and POST gate with recorded stop/launch operations only. */
final class TransmissionEndpointTest extends TestCase
{
    private function fixture(): string
    {
        $home = $this->pmssMakeTempDir('pmss-transmission-endpoint-');
        $this->pmssEnsureDir($home.'/www');
        copy($this->pmssRepoPath('etc/skel/www/transmission.php'), $home.'/www/transmission.php');
        $stub = <<<'PHP'
<?php
function pmssCustomerTorrentClientSelectedBinary($client) {
    return $GLOBALS['selected'] ? '/fixture/transmission-daemon' : null;
}
function pmssCustomerNativeStop($binaries) {
    $GLOBALS['calls'][] = ['stop', is_file(dirname(__DIR__).'/.transmissionDisable'), $binaries];
}
function pmssFrontendShellExec($command) {
    $GLOBALS['calls'][] = ['launch', is_file(dirname(__DIR__).'/.transmissionDisable'), $command];
    return $GLOBALS['launchRc'];
}
PHP;
        // Use the shipped request/CSRF contract; no real shell or signal operation is reachable.
        $stub .= "\nrequire_once ".var_export($this->pmssRepoPath('etc/skel/www/scriptsIncFrontend.php'), true).';';
        $this->pmssWriteFile($home.'/www/scriptsInc.php', $stub);
        return $home;
    }

    private function request(string $home, string $action, string $method = 'POST', bool $ajax = true,
        bool $selected = true, string $launchRc = '0'): array
    {
        $script = '$_SERVER["REQUEST_METHOD"]='.var_export($method, true).';'
            .'$_SERVER["HTTP_X_REQUESTED_WITH"]='.var_export($ajax ? 'XMLHttpRequest' : '', true).';'
            .'$_POST["action"]='.var_export($action, true).';'
            .'$GLOBALS["calls"]=[];$GLOBALS["selected"]='.var_export($selected, true).';'
            .'$GLOBALS["launchRc"]='.var_export($launchRc, true).';'
            .'register_shutdown_function(function(){echo json_encode(["status"=>http_response_code() ?: 200,'
            .'"calls"=>$GLOBALS["calls"]]);});'
            .'require '.var_export($home.'/www/transmission.php', true).';';
        $result = $this->pmssRunInlinePhpJson($script);
        clearstatcache();
        return $result;
    }

    public function testDisablePersistsMarkerBeforeStoppingOnlySelectedDaemon(): void
    {
        $home = $this->fixture();
        $result = $this->request($home, 'disable');
        $this->assertSame(200, $result['status']);
        $this->assertTrue(is_file($home.'/.transmissionDisable'));
        $this->assertSame([['stop', true, ['/fixture/transmission-daemon']]], $result['calls']);
    }

    public function testStartRemovesMarkerBeforeResolverLaunch(): void
    {
        $home = $this->fixture();
        $this->pmssWriteFile($home.'/.transmissionDisable', '');
        $result = $this->request($home, 'start');
        $this->assertSame(200, $result['status']);
        $this->assertFalse(is_file($home.'/.transmissionDisable'));
        $this->assertSame('launch', $result['calls'][0][0]);
        $this->assertSame(false, $result['calls'][0][1]);
        $this->assertStringContainsString(escapeshellarg($home.'/www/torrentClientsResolve.php')
            .' transmission --launch', $result['calls'][0][2]);
    }

    public function testRestartRefusesDisabledAccountAndOtherwiseStopsBeforeLaunch(): void
    {
        $home = $this->fixture();
        $this->pmssWriteFile($home.'/.transmissionDisable', '');
        $result = $this->request($home, 'restart');
        $this->assertSame(['status' => 409, 'calls' => []], $result);
        $this->assertTrue(is_file($home.'/.transmissionDisable'));
        unlink($home.'/.transmissionDisable');
        $result = $this->request($home, 'restart');
        $this->assertSame(200, $result['status']);
        $this->assertSame(['stop', 'launch'], array_column($result['calls'], 0));
    }

    public function testRequestGateRejectsGetAndNonAjaxPostBeforeMarkerChanges(): void
    {
        $home = $this->fixture();
        foreach ([['GET', true, 405], ['POST', false, 403]] as [$method, $ajax, $status]) {
            foreach (['start', 'disable', 'restart'] as $action) {
                $this->assertSame(['status' => $status, 'calls' => []], $this->request($home, $action, $method, $ajax));
                $this->assertFalse(file_exists($home.'/.transmissionDisable'));
                $this->assertFalse(is_dir($home.'/.config'));
            }
        }
    }

    public function testMissingSelectionAndInvalidActionsDoNoWork(): void
    {
        $home = $this->fixture();
        foreach (['start', 'disable', 'restart'] as $action) {
            $this->assertSame(['status' => 409, 'calls' => []], $this->request($home, $action, 'POST', true, false));
            $this->assertFalse(file_exists($home.'/.transmissionDisable'));
        }
        foreach (['', 'unknown'] as $action) {
            $this->assertSame(['status' => 400, 'calls' => []], $this->request($home, $action));
        }
    }

    public function testMarkerFailurePreventsStopAndLaunchFailureIsReported(): void
    {
        $home = $this->fixture();
        $this->pmssEnsureDir($home.'/.transmissionDisable');
        $this->assertSame(['status' => 500, 'calls' => []], $this->request($home, 'disable'));
        $this->assertSame(['status' => 500, 'calls' => []], $this->request($home, 'start'));
        rmdir($home.'/.transmissionDisable');
        $this->assertSame(503, $this->request($home, 'start', 'POST', true, true, '1')['status']);
    }

    public function testBusySwitchLockPreventsMarkerChanges(): void
    {
        $home = $this->fixture();
        $dir = $this->pmssEnsureDir($home.'/.config/pmss/torrentClients');
        $lock = fopen($dir.'/transmission.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $this->assertSame(['status' => 409, 'calls' => []], $this->request($home, 'disable'));
            $this->assertFalse(file_exists($home.'/.transmissionDisable'));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
