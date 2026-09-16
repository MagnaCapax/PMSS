<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/rootlessDockerConfig.php';

/**
 * Regression coverage for the rootless-Docker start self-heal: when /run/user/UID is
 * missing, userDocker start must enable linger so logind creates the runtime dir,
 * otherwise the 5-minute checkRootlessDocker watchdog retries forever.
 */
class UserDockerLingerEnsureTest extends TestCase
{
    public function testLingerEnsureCommandsEnableLingerThenStartUserManager(): void
    {
        $cmds = \pmssUserDockerLingerEnsureCommands('alice', 1057);
        $this->assertSame([
            "loginctl enable-linger 'alice'",
            'systemctl start user@1057.service',
        ], $cmds);
    }

    public function testLingerEnsureCommandsShellEscapeUsername(): void
    {
        $cmds = \pmssUserDockerLingerEnsureCommands('alice; rm -rf /', 42);
        $this->assertSame("loginctl enable-linger 'alice; rm -rf /'", $cmds[0]);
        $this->assertSame('systemctl start user@42.service', $cmds[1]);
    }

    public function testUserDockerStartPathWiresLingerEnsureBeforeDaemonStart(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/util/userDocker.php');
        $this->assertStringContainsString('if (!is_dir($runtimeDir)) {', $src);
        $this->assertOrderedStrings([
            'pmssUserDockerLingerEnsureCommands($user, $uid)',
            'nohup dockerd-rootless.sh',
        ], $src, '', 'linger-ensure must run before the rootless dockerd launch: ');
    }

    public function testRootDockerStartUsesSliceAwareLauncherButSameUserPathStaysDirect(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/util/userDocker.php');
        $this->assertStringContainsAllStrings([
            "require_once __DIR__.'/../lib/user/serviceLaunch.php';",
            'bool $placeInUserSlice = false',
            '$wrapper = pmssBuildUserServiceShellCommand($user, $cmd);',
            'userDockerRunAs($user, $envCmd, $userDockerStartTimeoutSec, $launchRc, true);',
        ], $src);

        $this->assertOrderedStrings([
            "if (\$target !== null && \$currentUid > 0 && \$currentUid === (int) \$target['uid']) {",
            'elseif ($placeInUserSlice && $currentUid === 0)',
        ], $src, '', 'same-user invocation must remain direct before the root slice-aware branch: ');
    }

    public function testUserDockerStopUsesRuntimeDirFallbackAndLivenessGate(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/util/userDocker.php');
        $this->assertStringContainsString('XDG_RUNTIME_DIR=%s systemctl --user %s docker.service', $src);
        $this->assertStringContainsString('$systemdAction = !$dockerEnabled ? \'disable --now\' : \'stop\';', $src);
        $this->assertStringContainsString('if ($stopRc !== 0) {', $src);
        $this->assertStringContainsString('userDockerCollectPids($user, $debug, $stopCheckOk)', $src);
        $this->assertStringContainsString('if (!$stopCheckOk || !empty($remainingPids)) {', $src);
        $oldTimeoutGuard = 'if ($stopRc === '.(string) 124;
        $oldTimeoutGuard .= ')';
        $this->assertTrue(strpos($src, $oldTimeoutGuard) === false,
            'stop fallback must not be limited to timeout rc 124');
        $this->assertOrderedStrings([
            'userDockerCollectPids($user, $debug, $stopCheckOk)',
            'echo "Docker stop requested',
        ], $src, '', 'stop success output must follow liveness verification: ');
    }

    public function testUserDockerStopKillsTheRootlesskitParent(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/util/userDocker.php');
        $this->assertOrderedStrings(['$dockerStopCmd =', 'pkill -x rootlesskit'], $src,
            '', 'rootless stop must target the rootlesskit user-namespace parent: ');
        $this->assertStringContainsAllStrings([
            "'pkill -x dockerd || true'",
            "'pkill -x rootlesskit || true'",
            'foreach ($dockerStopCmd as $dockerStopCommand)',
        ], $src);
    }
}
