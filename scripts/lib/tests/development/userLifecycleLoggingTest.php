<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/userLifecycle.php';

class userLifecycleLoggingTest extends TestCase
{
    public function testFormatTextFieldLeavesPlainTextUntouched(): void
    {
        $this->assertEquals('plain text', \pmssUserLifecycleFormatTextField('plain text'));
    }

    public function testFormatTextFieldCollapsesControlCharacters(): void
    {
        $this->assertEquals('hello world line', \pmssUserLifecycleFormatTextField("hello\r\nworld\tline"));
    }

    public function testFormatTextFieldNormalizesNonStringScalars(): void
    {
        $this->assertEquals('42', \pmssUserLifecycleFormatTextField(42));
        $this->assertEquals('true', \pmssUserLifecycleFormatTextField(true));
        $this->assertEquals('false', \pmssUserLifecycleFormatTextField(false));
    }

    public function testFormatTextFieldHandlesNullAndArraySafely(): void
    {
        $this->assertEquals('', \pmssUserLifecycleFormatTextField(null));
        $this->assertEquals('array', \pmssUserLifecycleFormatTextField(['nested' => 'value']));
    }

    public function testFormatTextFieldUsesStringableObjects(): void
    {
        $stringable = new class {
            public function __toString(): string
            {
                return "hello\nworld";
            }
        };

        $this->assertEquals('hello world', \pmssUserLifecycleFormatTextField($stringable));
    }

    public function testUserLifecycleWriterUsesFormattingHelperForTextLogFields(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/userLifecycle.php');

        $this->assertStringContainsAllStrings([
            "pmssUserLifecycleFormatTextField(\$payload['status'] ?? 'INFO')",
            "pmssUserLifecycleFormatTextField(\$payload['action'] ?? 'unknown')",
            "pmssUserLifecycleFormatTextField(\$payload['phase'] ?? 'unknown')",
            "pmssUserLifecycleFormatTextField(\$payload['username'] ?? '')",
            "pmssUserLifecycleFormatTextField(\$payload['message'])",
            "pmssUserLifecycleFormatTextField(\$payload['step'])",
        ], $source);
    }

    public function testContextLogHelperDelegatesToBaseContextAndWriter(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/userLifecycle.php', ['function pmssUserLifecycleContextLog(', 'pmssUserWriteLogs(pmssUserBaseContext($action, $phase, $username, $extra));']);
    }

    public function testContextLogStatusMessageHelperBuildsSharedPayload(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/userLifecycle.php');

        $this->assertStringContainsAllStrings([
            'function pmssUserLifecycleContextLogStatusMessage(',
            '\'status\' => $status',
            '\'message\' => $message',
            'pmssUserLifecycleContextLog(',
        ], $source);
    }

    public function testRunStepsPreservesOrderAndDryRunResults(): void
    {
        list($result, $output) = $this->pmssCaptureStdout(static function (): array {
            return \pmssUserLifecycleRunSteps('test', 'alice', array(
                array('first', 'printf first'),
                array('second', 'printf second'),
            ), true);
        });

        $this->assertSame(array('first' => 0, 'second' => 0), $result);
        $this->assertStringContainsAllStrings(array('[DRY-RUN][test] first: printf first', '[DRY-RUN][test] second: printf second'), $output);
    }

    public function testRefreshNginxConfigKeepsFallbackOrder(): void
    {
        $cases = array(
            array('suspend', 'refresh_nginx_config', 'php /scripts/util/createNginxConfig.php --user \'alice\'', array(), null, array('refresh_nginx_config', 'restart_nginx_systemctl')),
            array('terminate', 'regen_nginx_user_configs', '/scripts/util/createNginxConfig.php', array('systemctlStep' => 'restart_nginx', 'initStep' => 'restart_nginx_init'), 'restart_nginx', array('regen_nginx_user_configs', 'restart_nginx', 'restart_nginx_init')),
        );

        foreach ($cases as $case) {
            $calls = array();
            $result = \pmssUserLifecycleRefreshNginxConfig($case[0], 'alice', false, $case[1], $case[2], $case[3], static function (string $action, string $username, string $step, string $command, bool $dryRun) use (&$calls, $case): int {
                $calls[] = array('action' => $action, 'username' => $username, 'step' => $step, 'command' => $command, 'dryRun' => $dryRun);
                return $step === $case[4] ? 1 : 0;
            });

            $this->assertSame(0, $result);
            $this->assertSame($case[5], array_column($calls, 'step'));
            $this->assertSame('alice', $calls[0]['username']);
            $this->assertFalse($calls[0]['dryRun']);
        }
    }

    public function testRefreshManagedNginxConfigUsesCanonicalSingleUserCommand(): void
    {
        $calls = array();
        $result = \pmssUserLifecycleRefreshManagedNginxConfig(
            'unsuspend',
            'alice',
            true,
            static function (string $action, string $username, string $step, string $command, bool $dryRun) use (&$calls): int {
                $calls[] = array('action' => $action, 'username' => $username, 'step' => $step, 'command' => $command, 'dryRun' => $dryRun);
                return 0;
            }
        );

        $this->assertSame(0, $result);
        $this->assertSame(array('refresh_nginx_config', 'restart_nginx_systemctl'), array_column($calls, 'step'));
        $this->assertSame('php /scripts/util/createNginxConfig.php --user \'alice\'', $calls[0]['command']);
        $this->assertSame('alice', $calls[0]['username']);
        $this->assertTrue($calls[0]['dryRun']);
    }

    public function testSyncSuspendedStateMirrorsDisabledRootMarker(): void
    {
        $base = $this->pmssMakeTempDir('pmss-user-lifecycle-sync-');
        $disabledRoot = $base.'/www-disabled';
        $calls = array();

        @mkdir($disabledRoot, 0755, true);

        $suspended = \pmssUserLifecycleSyncSuspendedState(
            'alice',
            $disabledRoot,
            static function (string $username, bool $state) use (&$calls): bool {
                $calls[] = array('username' => $username, 'state' => $state);
                return true;
            }
        );
        $active = \pmssUserLifecycleSyncSuspendedState(
            'alice',
            $base.'/missing-marker',
            static function (string $username, bool $state) use (&$calls): bool {
                $calls[] = array('username' => $username, 'state' => $state);
                return true;
            }
        );

        $this->assertTrue($suspended);
        $this->assertFalse($active);
        $this->assertSame(
            array(
                array('username' => 'alice', 'state' => true),
                array('username' => 'alice', 'state' => false),
            ),
            $calls
        );
    }

    public function testWebRootPathGuardRequiresDirectNonSymlinkedHomeChild(): void
    {
        $home = $this->pmssMakeTempDir('pmss-user-lifecycle-webroot-guard-');
        $valid = $home.'/www';
        $missing = $home.'/www-disabled';
        $nested = $home.'/nested/www';
        $outside = $this->pmssMakeTempDir('pmss-user-lifecycle-webroot-outside-').'/www';
        $wrongName = $home.'/public';

        foreach (array($valid, $nested, $outside, $wrongName) as $path) {
            $this->pmssEnsureDir($path);
        }

        $this->assertTrue(\pmssUserLifecycleWebRootPathIsSafe($home, $valid, 'www', true));
        $this->assertTrue(\pmssUserLifecycleWebRootPathIsSafe($home, $missing, 'www-disabled', false));
        $this->assertFalse(\pmssUserLifecycleWebRootPathIsSafe($home, $missing, 'www-disabled', true));
        $this->assertFalse(\pmssUserLifecycleWebRootPathIsSafe($home, $nested, 'www', true));
        $this->assertFalse(\pmssUserLifecycleWebRootPathIsSafe($home, $outside, 'www', true));
        $this->assertFalse(\pmssUserLifecycleWebRootPathIsSafe($home, $wrongName, 'www', true));
        $this->assertFalse(\pmssUserLifecycleWebRootPathIsSafe('', $valid, 'www', true));
        $this->assertFalse(\pmssUserLifecycleWebRootPathIsSafe($home, $valid, "bad\nname", true));

        $link = $home.'/www-link';
        if (@symlink($valid, $link)) {
            $this->assertFalse(\pmssUserLifecycleWebRootPathIsSafe($home, $link, 'www-link', true));
        }
    }

    public function testSuspendAndUnsuspendGuardWebRootRenameTargets(): void
    {
        $home = $this->pmssMakeTempDir('pmss-user-lifecycle-webroot-state-');
        $activeRoot = $home.'/www';
        $disabledRoot = $home.'/www-disabled';
        $this->pmssEnsureDir($activeRoot);

        $active = \pmssUserLifecycleRequireWebRootState('suspend', 'alice', $home, $activeRoot, $disabledRoot);
        $this->pmssEnsureDir($disabledRoot);
        $suspended = \pmssUserLifecycleRequireWebRootState('unsuspend', 'alice', $home, $activeRoot, $disabledRoot);

        $this->assertSame(array('activeRootExists' => true, 'disabledRootExists' => false), $active);
        $this->assertSame(array('activeRootExists' => true, 'disabledRootExists' => true), $suspended);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/suspend.php', array("pmssUserLifecycleRequireWebRootState('suspend'"));
        $this->pmssAssertRepoFileContainsAllStrings('scripts/unsuspend.php', array("pmssUserLifecycleRequireWebRootState('unsuspend'"));
    }

    public function testFindSuspendedBackupSelectsNewestContentfulCandidate(): void
    {
        $home = $this->pmssMakeTempDir('pmss-user-lifecycle-backup-');
        $old = $home.'/www-suspended-old';
        $new = $home.'/www-suspended-new';
        $empty = $home.'/www-suspended-empty';

        $this->pmssWriteFile($old.'/rutorrent/.keep', '');
        $this->pmssWriteFile($new.'/public/content.txt', '');
        $this->pmssEnsureDir($empty);
        touch($old, 1000);
        touch($new, 2000);
        touch($empty, 3000);

        $this->assertSame($new, \pmssUserLifecycleFindSuspendedBackup($home));

        $tieHome = $this->pmssMakeTempDir('pmss-user-lifecycle-backup-tie-');
        $first = $tieHome.'/www-suspended-a';
        $last = $tieHome.'/www-suspended-z';
        $this->pmssWriteFile($first.'/index.php', '');
        $this->pmssWriteFile($last.'/index.php', '');
        touch($first, 4000);
        touch($last, 4000);

        $this->assertSame($last, \pmssUserLifecycleFindSuspendedBackup($tieHome));

        $emptyHome = $this->pmssMakeTempDir('pmss-user-lifecycle-backup-empty-');
        $this->pmssEnsureDir($emptyHome.'/www-suspended-empty');

        $this->assertSame(null, \pmssUserLifecycleFindSuspendedBackup($emptyHome));
    }

    public function testSuspendedBackupCandidateGuardRequiresDirectHomeDirectory(): void
    {
        $home = $this->pmssMakeTempDir('pmss-user-lifecycle-backup-guard-');
        $valid = $home.'/www-suspended-valid';
        $outside = $this->pmssMakeTempDir('pmss-user-lifecycle-backup-outside-').'/www-suspended-outside';
        $nested = $home.'/nested/www-suspended-nested';
        $wrongPrefix = $home.'/www-disabled';

        foreach (array($valid, $outside, $nested, $wrongPrefix) as $path) {
            $this->pmssWriteFile($path.'/index.php', '');
        }

        $this->assertTrue(\pmssUserLifecycleSuspendedBackupCandidateIsSafe($home, $valid));
        $this->assertFalse(\pmssUserLifecycleSuspendedBackupCandidateIsSafe($home, $outside));
        $this->assertFalse(\pmssUserLifecycleSuspendedBackupCandidateIsSafe($home, $nested));
        $this->assertFalse(\pmssUserLifecycleSuspendedBackupCandidateIsSafe($home, $wrongPrefix));
        $this->assertFalse(\pmssUserLifecycleSuspendedBackupCandidateIsSafe('', $valid));

        $link = $home.'/www-suspended-link';
        if (@symlink($valid, $link)) {
            $this->assertFalse(\pmssUserLifecycleSuspendedBackupCandidateIsSafe($home, $link));
        }
    }

    public function testFindSuspendedBackupSkipsSymlinkedCandidates(): void
    {
        $home = $this->pmssMakeTempDir('pmss-user-lifecycle-backup-link-');
        $target = $this->pmssMakeTempDir('pmss-user-lifecycle-backup-target-');
        $link = $home.'/www-suspended-link';
        if (!@symlink($target, $link)) {
            throw new SkipTest('symlink creation unavailable');
        }

        $this->pmssWriteFile($target.'/index.php', '');
        $valid = $home.'/www-suspended-valid';
        $this->pmssWriteFile($valid.'/index.php', '');
        touch($target, 3000);
        touch($valid, 1000);

        $this->assertSame($valid, \pmssUserLifecycleFindSuspendedBackup($home));
    }
}
