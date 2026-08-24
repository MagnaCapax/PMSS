<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/userLifecycle.php';

class userLifecycleLoggingTest extends TestCase
{
    public function testFormatTextFieldNormalizesSupportedInputShapes(): void
    {
        $stringable = new class {
            public function __toString(): string
            {
                return "hello\nworld";
            }
        };

        foreach (array(
            array('plain text', 'plain text'),
            array("hello\r\nworld\tline", 'hello world line'),
            array(42, '42'),
            array(true, 'true'),
            array(false, 'false'),
            array(null, ''),
            array(array('nested' => 'value'), 'array'),
            array($stringable, 'hello world'),
        ) as $case) {
            $this->assertEquals($case[1], \pmssUserLifecycleFormatTextField($case[0]));
        }
    }

    public function testUserLifecycleSourceContractsUseSharedHelpers(): void
    {
        $this->pmssAssertRepoFileContract('scripts/lib/userLifecycle.php', ['required' => [
                "pmssUserLifecycleFormatTextField(\$payload['status'] ?? 'INFO')",
                "pmssUserLifecycleFormatTextField(\$payload['action'] ?? 'unknown')",
                "pmssUserLifecycleFormatTextField(\$payload['phase'] ?? 'unknown')",
                "pmssUserLifecycleFormatTextField(\$payload['username'] ?? '')",
                "pmssUserLifecycleFormatTextField(\$payload['message'])",
                "pmssUserLifecycleFormatTextField(\$payload['step'])",
                'function pmssUserLifecycleContextLog(',
                'pmssUserWriteLogs(pmssUserBaseContext($action, $phase, $username, $extra));',
                'function pmssUserLifecycleContextLogStatusMessage(',
                '\'status\' => $status',
                '\'message\' => $message',
                'pmssUserLifecycleContextLog(',
            ]]);
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
            $result = \pmssUserLifecycleRefreshNginxConfig($case[0], 'alice', false, $case[1], $case[2], $case[3], $this->recordLifecycleSteps($calls, $case[4]));

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
            $this->recordLifecycleSteps($calls)
        );

        $this->assertSame(0, $result);
        $this->assertSame(array('refresh_nginx_config', 'restart_nginx_systemctl'), array_column($calls, 'step'));
        $this->assertSame('php /scripts/util/createNginxConfig.php --user \'alice\'', $calls[0]['command']);
        $this->assertSame('alice', $calls[0]['username']);
        $this->assertTrue($calls[0]['dryRun']);
    }

    public function testWebStackStartRequiresBothProcesses(): void
    {
        $cases = array(
            array('dry-run', 0, true, array(), 0, array()),
            array('launcher failure', 7, false, array('lighttpd' => false, 'php-cgi' => false), 7, array('lighttpd', 'php-cgi')),
            array('lighttpd missing', 0, false, array('lighttpd' => false, 'php-cgi' => true), 1, array('lighttpd', 'php-cgi')),
            array('php-cgi missing', 0, false, array('lighttpd' => true, 'php-cgi' => false), 1, array('lighttpd', 'php-cgi')),
            array('both running', 0, false, array('lighttpd' => true, 'php-cgi' => true), 0, array('lighttpd', 'php-cgi')),
            array('watchdog won race', 7, false, array('lighttpd' => true, 'php-cgi' => true), 0, array('lighttpd', 'php-cgi')),
        );

        foreach ($cases as $case) {
            $stepCalls = array();
            $processCalls = array();
            $result = \pmssUserLifecycleWebStackStartAndVerify(
                'unsuspend',
                'alice',
                $case[2],
                static function (string $action, string $username, string $step, string $command, bool $dryRun) use (&$stepCalls, $case): int {
                    $stepCalls[] = array($action, $username, $step, $command, $dryRun);
                    return $case[1];
                },
                static function (string $username, string $processName) use (&$processCalls, $case): bool {
                    $processCalls[] = $processName;
                    return (bool) ($case[3][$processName] ?? false);
                }
            );

            $this->assertSame($case[4], $result, $case[0]);
            $this->assertSame(array('unsuspend', 'alice', 'start_lighttpd', "/scripts/startLighttpd 'alice'", $case[2]), $stepCalls[0], $case[0]);
            $this->assertSame($case[5], $processCalls, $case[0]);
        }
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

        foreach (array(
            'active direct child' => array($home, $valid, 'www', true, true),
            'missing disabled marker allowed' => array($home, $missing, 'www-disabled', false, true),
            'missing required marker' => array($home, $missing, 'www-disabled', true, false),
            'nested child' => array($home, $nested, 'www', true, false),
            'outside child' => array($home, $outside, 'www', true, false),
            'wrong basename' => array($home, $wrongName, 'www', true, false),
            'empty home' => array('', $valid, 'www', true, false),
            'unsafe expected name' => array($home, $valid, "bad\nname", true, false),
        ) as $label => $case) {
            $this->assertSame($case[4], \pmssUserLifecycleWebRootPathIsSafe($case[0], $case[1], $case[2], $case[3]), $label);
        }

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
        $this->pmssAssertRepoFileContractCases(array(
            'scripts/suspend.php' => array('required' => array(
                "pmssUserLifecycleRequireWebRootState('suspend'",
                "'archive_web_root'",
                'suspension aborted',
                'exit(1);',
            )),
            'scripts/unsuspend.php' => array('required' => array(
                "pmssUserLifecycleRequireWebRootState('unsuspend'",
                'pmssUserLifecycleWebRootContainsUserContent($disabledRoot)',
                'pmssUserLifecycleClearEmptySuspendedMarker($homeDir, $disabledRoot)',
                "'validate_restored_web_root'",
                "is_file(\$activeRoot.'/index.php')",
                "pmssUserLifecycleWebStackStartAndVerify('unsuspend'",
                'if ($webStackRc !== 0)',
            )),
        ));
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

    public function testWebRootContentPredicateAndEmptyMarkerCleanupAreConservative(): void
    {
        $home = $this->pmssMakeTempDir('pmss-user-lifecycle-content-');
        $disabledRoot = $home.'/www-disabled';
        $activeRoot = $home.'/www';
        $this->pmssEnsureDir($disabledRoot);
        $this->pmssEnsureDir($activeRoot);

        $this->assertFalse(\pmssUserLifecycleWebRootContainsUserContent($disabledRoot));
        $this->assertTrue(\pmssUserLifecycleClearEmptySuspendedMarker($home, $disabledRoot));
        $this->assertFalse(is_dir($disabledRoot));

        $this->pmssWriteFile($activeRoot.'/index.php', 'panel');
        $this->pmssEnsureDir($disabledRoot);
        $this->assertTrue(\pmssUserLifecycleWebRootContainsUserContent($activeRoot));
        $this->assertTrue(\pmssUserLifecycleClearEmptySuspendedMarker($home, $disabledRoot));
        $this->pmssEnsureDir($disabledRoot);
        $this->pmssWriteFile($disabledRoot.'/rutorrent/.keep', '');
        $this->assertTrue(\pmssUserLifecycleWebRootContainsUserContent($disabledRoot));
        $this->assertFalse(\pmssUserLifecycleClearEmptySuspendedMarker($home, $disabledRoot));
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

        foreach (array(
            'direct suspended backup' => array($home, $valid, true),
            'outside path' => array($home, $outside, false),
            'nested path' => array($home, $nested, false),
            'wrong prefix' => array($home, $wrongPrefix, false),
            'empty home' => array('', $valid, false),
        ) as $label => $case) {
            $this->assertSame($case[2], \pmssUserLifecycleSuspendedBackupCandidateIsSafe($case[0], $case[1]), $label);
        }

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

    private function recordLifecycleSteps(array &$calls, ?string $failStep = null): callable
    {
        return static function (string $action, string $username, string $step, string $command, bool $dryRun) use (&$calls, $failStep): int {
            $calls[] = array('action' => $action, 'username' => $username, 'step' => $step, 'command' => $command, 'dryRun' => $dryRun);
            return $step === $failStep ? 1 : 0;
        };
    }
}
