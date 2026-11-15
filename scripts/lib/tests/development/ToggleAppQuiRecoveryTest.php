<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/toggleApp.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/toggleAppRuntime.php';

/** Retry the real bounded bootstrap with a fake qui; no host apps or accounts are used. */
class ToggleAppQuiRecoveryTest extends TestCase
{
    public function testTwoKilledPassesKeepOnePasswordUntilSuccessfulPublication(): void
    {
        foreach (array(false, true) as $repair) {
            $home = $this->pmssMakeTempDir('toggle-qui-recovery-');
            $fixture = $home.'/qui-fixture';
            $this->pmssWriteFile($fixture, <<<'PHP'
#!/usr/bin/env php
<?php
umask(0077);
$db = $argv[3].'/qui.db';
$password = stream_get_contents(STDIN);
file_put_contents(getenv('PMSS_TEST_QUI_LOG'), json_encode(array($argv, hash('sha256', $password)))."\n", FILE_APPEND);
if ($argv[1] === 'create-user' && is_file($db)) {
    echo 'User account already exists. Only one user account is allowed.';
    exit(0);
}
if (getenv('PMSS_TEST_QUI_MODE') === 'before') sleep(30);
file_put_contents($db, hash('sha256', $password));
if (getenv('PMSS_TEST_QUI_MODE') === 'after') sleep(30);
PHP
            );
            chmod($fixture, 0700);
            $this->pmssWithEnv(array('PMSS_TEST_MODE' => '1', 'PMSS_RUNTIME_DIR' => $home.'/runtime',
                'PMSS_WATCHDOG_TIMEOUT_TEST_SECONDS' => '1', 'PMSS_TEST_QUI_BINARY' => $fixture,
                'PMSS_TEST_QUI_LOG' => $home.'/argv.log', 'PMSS_TEST_QUI_MODE' => 'fast'), function () use ($home, $repair): void {
                $proxy = \pmssLighttpdManagedProxyFragment('qui', 'fixtur01', 23001);
                $login = $home.'/.pmss/apps/qui.login';
                $pending = $login.'.pending';
                $secured = $home.'/.pmss/apps/qui.secured';
                $db = $home.'/.config/qui/qui.db';
                $oldLogin = false;
                if ($repair) {
                    $this->assertTrue(\pmssToggleAppBootstrap('qui', 'fixtur01', $home, 23001, $proxy));
                    $oldLogin = file_get_contents($login);
                    chmod($home.'/.config/qui/config.toml', 0644);
                    unlink($home.'/argv.log');
                }
                $command = $this->pmssBuildInlinePhpCommand('require '.var_export($this->pmssRepoPath('etc/skel/www/toggleAppRuntime.php'), true)
                    .'; exit(pmssToggleAppBootstrap("qui", "fixtur01", '.var_export($home, true).', 23001, '.var_export($proxy, true).') ? 0 : 1);');
                $savedPending = null;
                foreach (array('before', 'after') as $mode) {
                    putenv('PMSS_TEST_QUI_MODE='.$mode);
                    // Production uses 15 seconds; the existing override shortens only this fixture's deadline.
                    $this->assertFalse(\pmssToggleAppBootstrapRun($command, 'fixtur01', 'qui'));
                    clearstatcache();
                    $this->assertTrue(\pmssUserWatchdogBackoffActive('fixtur01', 'qui'));
                    $this->assertFalse(file_exists($secured));
                    $this->assertSame($oldLogin, is_file($login) ? file_get_contents($login) : false);
                    $this->assertSame(0600, fileperms($pending) & 0777);
                    $bytes = file_get_contents($pending);
                    $this->assertSame(1, preg_match('/^[a-f0-9]{48}\n$/D', $bytes));
                    if ($savedPending !== null) $this->assertSame($savedPending, $bytes);
                    $savedPending = $bytes;
                }
                // The second killed CLI already committed; retry must reconcile that same password.
                $this->assertSame(hash('sha256', $savedPending), file_get_contents($db));
                putenv('PMSS_TEST_QUI_MODE=fast');
                $this->assertTrue(\pmssToggleAppBootstrapRun($command, 'fixtur01', 'qui'));
                clearstatcache();
                $this->assertSame("fixtur01\n".$savedPending, file_get_contents($login));
                if ($repair) $this->assertTrue($oldLogin !== file_get_contents($login));
                $this->assertSame(hash('sha256', $savedPending), file_get_contents($db));
                $this->assertSame("secured\n", file_get_contents($secured));
                $this->assertFalse(file_exists($pending));
                foreach (array($login, $db, $secured) as $path) $this->assertSame(0600, fileperms($path) & 0777);
                foreach (file($home.'/argv.log', FILE_IGNORE_NEW_LINES) as $line) {
                    $this->assertSame(hash('sha256', $savedPending), json_decode($line, true)[1]);
                    $this->assertStringNotContainsString(trim($savedPending), $line);
                }
                $commands = file_get_contents($home.'/argv.log');
                // A kill after marker publication can leave only cleanup unfinished.
                $this->assertTrue(\pmssToggleAppPrivateWrite($pending, $savedPending));
                $this->assertTrue(\pmssToggleAppBootstrapRun($command, 'fixtur01', 'qui'));
                clearstatcache();
                $this->assertSame($commands, file_get_contents($home.'/argv.log'));
                $this->assertFalse(file_exists($pending));
                // Route refusal happens after the login write; keep the repair password until all gates pass.
                $conflict = $home.'/.lighttpd/custom.d/owner.conf';
                $this->pmssWriteFile($conflict, '^/user-fixtur01/qui/');
                unlink($secured);
                $this->assertFalse(\pmssToggleAppBootstrapRun($command, 'fixtur01', 'qui'));
                clearstatcache();
                $retryPassword = file_get_contents($pending);
                $this->assertFalse(file_exists($secured));
                $this->assertSame("fixtur01\n".$retryPassword, file_get_contents($login));
                $this->assertSame(hash('sha256', $retryPassword), file_get_contents($db));
                unlink($conflict);
                $this->assertTrue(\pmssToggleAppBootstrapRun($command, 'fixtur01', 'qui'));
                clearstatcache();
                $this->assertSame("fixtur01\n".$retryPassword, file_get_contents($login));
                $this->assertFalse(file_exists($pending));
                $this->assertSame("secured\n", file_get_contents($secured));
            });
        }
    }

    public function testUnsafePendingPasswordRefusesRepairWithoutPublishingLogin(): void
    {
        // Never replace damaged or redirected retry state with a newly rotated secret.
        foreach (array('empty', 'malformed', 'oversized', 'mode', 'symlink', 'directory') as $case) {
            $home = $this->pmssMakeTempDir('toggle-qui-pending-');
            $pending = $home.'/.pmss/apps/qui.login.pending';
            $contents = str_repeat('a', 48)."\n";
            if ($case === 'empty') $contents = '';
            if ($case === 'malformed') $contents = str_repeat('z', 48)."\n";
            if ($case === 'oversized') $contents .= 'extra';
            $this->pmssWriteFile($pending, $contents);
            chmod($pending, $case === 'mode' ? 0644 : 0600);
            if ($case === 'symlink') {
                rename($pending, $pending.'.target');
                symlink($pending.'.target', $pending);
            } elseif ($case === 'directory') {
                unlink($pending);
                mkdir($pending, 0700);
            }
            $this->pmssWithEnv(array('PMSS_TEST_MODE' => '1', 'PMSS_TEST_QUI_BINARY' => '/bin/false'), function () use ($home, $pending, $contents, $case): void {
                $this->assertFalse(\pmssToggleAppBootstrap('qui', 'fixtur01', $home, 23001,
                    \pmssLighttpdManagedProxyFragment('qui', 'fixtur01', 23001)));
                $this->assertFalse(file_exists($home.'/.pmss/apps/qui.login'));
                $this->assertFalse(file_exists($home.'/.pmss/apps/qui.secured'));
                $this->assertFalse(file_exists($home.'/.config/qui/config.toml'));
                if ($case !== 'directory') $this->assertSame($contents, file_get_contents($pending));
            });
        }
    }
}
