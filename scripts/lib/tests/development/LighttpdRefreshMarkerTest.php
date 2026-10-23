<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Verify HTTP failures reach the real maintenance marker decision and step profiler. */
class LighttpdRefreshMarkerTest extends TestCase
{
    public function testFailedLighttpdStepRemainsUnmarkedAndRetriesWhileCompletedUsersResume(): void
    {
        $root = $this->pmssMakeTempDir('pmss-lighttpd-markers-');
        foreach (['alice', 'bob'] as $user) {
            foreach (['.tmp', '.irssi', 'www/recycle'] as $directory) {
                mkdir($root.'/'.$user.'/'.$directory, 0755, true);
            }
        }
        $script = <<<'PHP'
namespace LighttpdMarkerFixture;
class RuntimeException extends \RuntimeException {}
class UserConfigStore {}
class users { public static function listHomeUsers() { return ['alice', 'bob']; } }
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
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/update/users/http.php', 'LighttpdMarkerFixture');
        $script .= <<<'PHP'
$runs = [];
foreach ([1, 1, 0] as $aliceRc) {
    $GLOBALS['results'] = ['alice' => $aliceRc, 'bob' => 0];
    $GLOBALS['attempted'] = $GLOBALS['PMSS_PROFILE'] = $GLOBALS['continued'] = $GLOBALS['logs'] = [];
    \ob_start();
    $summary = pmssUpdateAllUsers('index-sha');
    \ob_end_clean();
    $steps = array_values(array_filter($GLOBALS['PMSS_PROFILE'], static function ($row) {
        return $row['description'] === 'Configuring lighttpd vhost';
    }));
    $runs[] = ['summary' => $summary, 'attempted' => $GLOBALS['attempted'], 'steps' => $steps,
        'continued' => $GLOBALS['continued'], 'logs' => $GLOBALS['logs'], 'status' => $GLOBALS['summaryStatus'],
        'marked' => [pmssUserRefreshAlreadyDone('alice', pmssUserRefreshSignature('index-sha')),
            pmssUserRefreshAlreadyDone('bob', pmssUserRefreshSignature('index-sha'))],
        'aliceMarkerExists' => \file_exists($GLOBALS['root'].'/state/alice')];
}
echo json_encode($runs);
PHP;
        $runs = $this->pmssRunInlinePhpJson($script);
        foreach ([0, 1] as $index) {
            $this->pmssAssertArraySubsetSame(['total' => 2, 'processed' => 2, 'skipped' => 0, 'skip_reasons' => []], $runs[$index]['summary']);
            $this->assertSame('warn', $runs[$index]['status']);
            $this->assertSame([false, true], $runs[$index]['marked']);
            $this->assertFalse($runs[$index]['aliceMarkerExists']);
            $this->assertStringContainsString('lighttpd configuration refresh failed (rc=1)', implode("\n", $runs[$index]['logs']['alice']));
            $this->assertStringContainsString('[WARN] Skipping user refresh marker: lighttpd refresh incomplete', implode("\n", $runs[$index]['logs']['alice']));
            $this->assertSame('ERR', $runs[$index]['steps'][0]['status']);
            $this->assertSame(1, $runs[$index]['steps'][0]['rc']);
        }
        foreach ($runs as $run) {
            $this->assertSame(['later environment handlers', 'linger/docker', 'Synchronizing per-user htpasswd', 'Checking lighttpd instance'], $run['continued']['alice']);
        }
        $this->assertSame($runs[0]['continued']['alice'], $runs[0]['continued']['bob']);
        $this->assertSame(['alice', 'bob'], $runs[0]['attempted']);
        $this->assertSame(['alice'], $runs[1]['attempted'], 'completed account keeps its resume skip');
        $this->assertSame(['alice'], $runs[2]['attempted'], 'failed account retries and can converge');
        $this->assertSame([true, true], $runs[2]['marked']);
        $this->pmssAssertArraySubsetSame(['total' => 2, 'processed' => 2, 'skipped' => 0], $runs[2]['summary']);
        $this->assertSame('OK', $runs[2]['steps'][0]['status']);
        $this->assertSame('ok', $runs[2]['status']);
    }
}
