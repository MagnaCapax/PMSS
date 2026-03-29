<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/userLifecycle.php';

class UserLifecycleStringableStub
{
    public function __toString(): string
    {
        return "hello\nworld";
    }
}

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
        $this->assertEquals('hello world', \pmssUserLifecycleFormatTextField(new UserLifecycleStringableStub()));
    }

    public function testUserLifecycleWriterUsesFormattingHelperForTextLogFields(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/userLifecycle.php');

        $this->assertStringContainsString("pmssUserLifecycleFormatTextField(\$payload['status'] ?? 'INFO')", $source);
        $this->assertStringContainsString("pmssUserLifecycleFormatTextField(\$payload['action'] ?? 'unknown')", $source);
        $this->assertStringContainsString("pmssUserLifecycleFormatTextField(\$payload['phase'] ?? 'unknown')", $source);
        $this->assertStringContainsString("pmssUserLifecycleFormatTextField(\$payload['username'] ?? '')", $source);
        $this->assertStringContainsString("pmssUserLifecycleFormatTextField(\$payload['message'])", $source);
        $this->assertStringContainsString("pmssUserLifecycleFormatTextField(\$payload['step'])", $source);
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

        $this->assertStringContainsString('function pmssUserLifecycleContextLogStatusMessage(', $source);
        $this->assertStringContainsString("'status' => $status", $source);
        $this->assertStringContainsString("'message' => $message", $source);
        $this->assertStringContainsString('pmssUserLifecycleContextLog(', $source);
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
        $base = sys_get_temp_dir().'/pmss-user-lifecycle-sync-'.bin2hex(random_bytes(4));
        $disabledRoot = $base.'/www-disabled';
        $calls = array();

        @mkdir($disabledRoot, 0755, true);

        try {
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
        } finally {
            @rmdir($disabledRoot);
            @rmdir($base);
        }
    }
}
