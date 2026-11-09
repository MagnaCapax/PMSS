<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/nginxService.php';

/** Exercise managed nginx commands with a private systemctl stub, never host services. */
class NginxServiceTest extends TestCase
{
    public function testManagedActionsRequireActiveMaster(): void
    {
        foreach (['start', 'restart', 'reload'] as $action) {
            $operation = $action === 'reload' ? 'reload-or-restart' : $action;
            $this->assertManagedCommand($action, [], 0, [
                $operation.' nginx', 'is-active --quiet nginx', 'show nginx --property=MainPID --value',
            ]);
        }
    }

    public function testActionFailureNeverFallsBackToInitScript(): void
    {
        foreach (['start', 'restart', 'reload'] as $action) {
            $operation = $action === 'reload' ? 'reload-or-restart' : $action;
            $this->assertManagedCommand($action, ['PMSS_TEST_NGINX_ACTION_RC' => '7'], 7, [$operation.' nginx']);
            $this->assertTrue(strpos(\pmssNginxServiceCommand($action, true), '/etc/init.d') === false);
        }
    }

    public function testInactiveUnitFailsBeforeMasterLookup(): void
    {
        $this->assertManagedCommand('restart', ['PMSS_TEST_NGINX_ACTIVE_RC' => '3'], 3, [
            'restart nginx', 'is-active --quiet nginx',
        ]);
    }

    public function testMissingOrInvalidMasterFailsVerification(): void
    {
        foreach (['0', '', '-1', 'invalid'] as $pid) {
            $this->assertManagedCommand('start', ['PMSS_TEST_NGINX_PID' => $pid], null, [
                'start nginx', 'is-active --quiet nginx', 'show nginx --property=MainPID --value',
            ]);
        }
    }

    public function testFailedMasterLookupFailsVerification(): void
    {
        $this->assertManagedCommand('start', ['PMSS_TEST_NGINX_SHOW_RC' => '1'], null, [
            'start nginx', 'is-active --quiet nginx', 'show nginx --property=MainPID --value',
        ]);
    }

    public function testNonSystemdCommandsRetainInitHandling(): void
    {
        $this->assertSame('/etc/init.d/nginx start', \pmssNginxServiceCommand('start', false));
        $this->assertSame('/etc/init.d/nginx restart', \pmssNginxServiceCommand('restart', false));
        $this->assertSame('/etc/init.d/nginx reload || /etc/init.d/nginx start', \pmssNginxServiceCommand('reload', false));
        $this->assertSame(
            \pmssNginxServiceCommand('start', is_dir('/run/systemd/system')),
            \pmssNginxServiceCommand('start')
        );
    }

    public function testUnknownActionsAreRejected(): void
    {
        foreach (['', 'stop', 'restart; true', "reload\nstart"] as $action) {
            try {
                \pmssNginxServiceCommand($action, true);
                $this->fail('Unsupported nginx action accepted');
            } catch (\InvalidArgumentException $error) {
                $this->assertSame('Unsupported nginx service action', $error->getMessage());
            }
        }
    }

    /** A null expected status means any nonzero shell failure is acceptable. */
    private function assertManagedCommand(string $action, array $environment, ?int $expectedRc, array $expectedCalls): void
    {
        $root = $this->pmssMakeTempDir('pmss-nginx-service-');
        $stub = <<<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "$PMSS_TEST_NGINX_LOG"
case "$1" in
    start|restart|reload-or-restart) exit "$PMSS_TEST_NGINX_ACTION_RC" ;;
    is-active) exit "$PMSS_TEST_NGINX_ACTIVE_RC" ;;
    show)
        [ "$PMSS_TEST_NGINX_SHOW_RC" = 0 ] || exit "$PMSS_TEST_NGINX_SHOW_RC"
        printf '%s\n' "$PMSS_TEST_NGINX_PID"
        ;;
    *) exit 99 ;;
esac
SH;
        $this->pmssWriteExecutableFile($root.'/systemctl', $stub);
        $defaults = [
            'PMSS_TEST_NGINX_LOG' => $root.'/calls', 'PMSS_TEST_NGINX_ACTION_RC' => '0',
            'PMSS_TEST_NGINX_ACTIVE_RC' => '0', 'PMSS_TEST_NGINX_SHOW_RC' => '0', 'PMSS_TEST_NGINX_PID' => '12345',
        ];
        $this->pmssWithPathPrefixedEnv($root, $environment + $defaults, function () use ($action, $expectedRc, $expectedCalls, $root): void {
            $rc = $this->pmssExecShellCommand(\pmssNginxServiceCommand($action, true), [], '2>/dev/null')['rc'];
            if ($expectedRc === null) $this->assertTrue($rc !== 0);
            else $this->assertSame($expectedRc, $rc);
            $this->assertSame($expectedCalls, file($root.'/calls', FILE_IGNORE_NEW_LINES));
        });
    }
}
