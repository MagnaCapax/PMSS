<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Verify HTTP failures reach the real maintenance marker decision and step profiler. */
class LighttpdRefreshMarkerTest extends TestCase
{
    public function testFailedLighttpdStepRemainsUnmarkedAndRetriesWhileCompletedUsersResume(): void
    {
        $root = $this->pmssMakeTempDir('pmss-lighttpd-markers-');
        foreach (['fixtur01', 'fixtur02'] as $user) {
            foreach (['.tmp', '.irssi', 'www/recycle'] as $directory) {
                mkdir($root.'/'.$user.'/'.$directory, 0755, true);
            }
        }
        $script = <<<'PHP'
namespace LighttpdMarkerFixture;
class RuntimeException extends \RuntimeException {}
class UserConfigStore {}
class users { public static function listHomeUsers() { return ['fixtur01', 'fixtur02']; } }
function logMessage($message) {}
function pmssUserLog($user, $message) { $GLOBALS['logs'][$user][] = $message; }
function pmssLogJson($data) { $GLOBALS['summaryStatus'] = $data['status']; }
function pmssUserAccountLookup($user) { return null; }
function pmssUserCgroupSliceSelfHeal(...$args) { return true; }
function pmssEnsureLingerAndDocker($user) { $GLOBALS['continued'][$user][] = 'linger/docker'; }
function is_file($path) { return strpos($path, '/scripts/') === 0 ? true : \is_file($path); }
function file_get_contents($path) { return $path === '/etc/seedbox/config/version' ? 'fixture' : \file_get_contents($path); }
function pmssUserContextIdentity($ctx) { return [$ctx['user'], $ctx['home']]; }
function pmssQbittorrentWebUiAuthEnsure(...$args) {}
function pmssQbittorrentApplyManagedConfig(...$args) { return false; }
function pmssDelugeApplyManagedConfig(...$args) { return false; }
function runUserStep($user, $label, $command, $skip = []) {
    if ($label !== 'Configuring lighttpd vhost') {
        $GLOBALS['continued'][$user][] = $label;
        return 0;
    }
    $GLOBALS['attempted'][] = $user;
    // Exercise the real runUserStep/runStep profiler with the CLI's exit status.
    return \runUserStep($user, $label, 'exit '.$GLOBALS['results'][$user], $skip);
}
function pmssUpdateUserEnvironment($user, $sha, &$reason) {
    pmssUserConfigureHttp(['user' => $user, 'home' => $GLOBALS['root'].'/'.$user, 'user_esc' => escapeshellarg($user)]);
    $GLOBALS['continued'][$user][] = 'later environment handlers';
    return true;
}
PHP;
        $script .= '$GLOBALS["root"] = '.var_export($root, true).';';
        $script .= '\putenv("PMSS_TEST_MODE=1"); \putenv("PMSS_DRY_RUN=0"); \putenv("PMSS_DOCKER_PACKAGES_CHANGED=0");';
        $script .= '\putenv("PMSS_LOG_DIR=".$GLOBALS["root"]); \putenv("PMSS_USER_REFRESH_STATE_DIR=".$GLOBALS["root"]."/state");';
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/update/userMaintenance.php', 'LighttpdMarkerFixture');
        foreach (['refreshState.php', 'legacyCpuQuota.php', 'maintenanceAccount.php'] as $module) {
            $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/update/users/'.$module, 'LighttpdMarkerFixture');
        }
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/update/users/http.php', 'LighttpdMarkerFixture');
        $script .= <<<'PHP'
$runs = [];
foreach ([1, 1, 0] as $fixtur01Rc) {
    $GLOBALS['results'] = ['fixtur01' => $fixtur01Rc, 'fixtur02' => 0];
    $GLOBALS['attempted'] = $GLOBALS['PMSS_PROFILE'] = $GLOBALS['continued'] = $GLOBALS['logs'] = [];
    \ob_start();
    $summary = pmssUpdateAllUsers('index-sha');
    \ob_end_clean();
    $steps = array_values(array_filter($GLOBALS['PMSS_PROFILE'], static function ($row) {
        return $row['description'] === 'Configuring lighttpd vhost';
    }));
    $runs[] = ['summary' => $summary, 'attempted' => $GLOBALS['attempted'], 'steps' => $steps,
        'continued' => $GLOBALS['continued'], 'logs' => $GLOBALS['logs'], 'status' => $GLOBALS['summaryStatus'],
        'marked' => [pmssUserRefreshAlreadyDone('fixtur01', pmssUserRefreshSignature('index-sha')),
            pmssUserRefreshAlreadyDone('fixtur02', pmssUserRefreshSignature('index-sha'))],
        'fixtur01MarkerExists' => \file_exists($GLOBALS['root'].'/state/fixtur01')];
}
echo json_encode($runs);
PHP;
        $runs = $this->pmssRunInlinePhpJson($script);
        foreach ([0, 1] as $index) {
            $this->pmssAssertArraySubsetSame(['total' => 2, 'processed' => 2, 'skipped' => 0, 'skip_reasons' => []], $runs[$index]['summary']);
            $this->pmssAssertArraySubsetSame(['status' => 'warn', 'marked' => [false, true], 'fixtur01MarkerExists' => false], $runs[$index]);
            $this->assertStringContainsAllStrings(['lighttpd configuration refresh failed (rc=1)',
                '[WARN] Skipping user refresh marker: lighttpd refresh incomplete'], implode("\n", $runs[$index]['logs']['fixtur01']));
            $this->pmssAssertArraySubsetSame(['status' => 'ERR', 'rc' => 1], $runs[$index]['steps'][0]);
        }
        foreach ($runs as $run) {
            $this->assertSame(['later environment handlers', 'linger/docker', 'Synchronizing per-user htpasswd', 'Checking lighttpd instance'], $run['continued']['fixtur01']);
        }
        $this->assertSame($runs[0]['continued']['fixtur01'], $runs[0]['continued']['fixtur02']);
        $this->assertSame(['fixtur01', 'fixtur02'], $runs[0]['attempted']);
        $this->assertSame(['fixtur01'], $runs[1]['attempted'], 'completed account keeps its resume skip');
        $this->assertSame(['fixtur01'], $runs[2]['attempted'], 'failed account retries and can converge');
        $this->assertSame([true, true], $runs[2]['marked']);
        $this->pmssAssertArraySubsetSame(['total' => 2, 'processed' => 2, 'skipped' => 0], $runs[2]['summary']);
        $this->assertSame('OK', $runs[2]['steps'][0]['status']);
        $this->assertSame('ok', $runs[2]['status']);
    }
}
