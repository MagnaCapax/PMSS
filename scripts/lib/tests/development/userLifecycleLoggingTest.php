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
        $source = $this->pmssReadRepoFile('scripts/lib/userLifecycle.php');

        $this->assertStringContainsString('function pmssUserLifecycleContextLog(', $source);
        $this->assertStringContainsString('pmssUserWriteLogs(pmssUserBaseContext($action, $phase, $username, $extra));', $source);
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
}
