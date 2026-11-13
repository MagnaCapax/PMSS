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
        $cmds = \pmssUserDockerLingerEnsureCommands('fixtur01', 1057);
        $this->assertSame([
            "loginctl enable-linger 'fixtur01'",
            'systemctl start user@1057.service',
        ], $cmds);
    }

    public function testLingerEnsureCommandsShellEscapeUsername(): void
    {
        $cmds = \pmssUserDockerLingerEnsureCommands('fixtur01; rm -rf /', 42);
        $this->assertSame("loginctl enable-linger 'fixtur01; rm -rf /'", $cmds[0]);
        $this->assertSame('systemctl start user@42.service', $cmds[1]);
    }

    public function testUserDockerStartPathWiresLingerEnsureBeforeDaemonStart(): void
    {
        $src = $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/user/docker/start.php', ['if (!is_dir($runtimeDir)) {']);
        $this->assertOrderedStrings([
            'pmssUserDockerLingerEnsureCommands($user, $uid)',
            'nohup dockerd-rootless.sh',
        ], $src, '', 'linger-ensure must run before the rootless dockerd launch: ');
    }

    public function testRootDockerStartUsesSliceAwareLauncherButSameUserPathStaysDirect(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/user/docker/start.php')
            .$this->pmssReadRepoFile('scripts/lib/user/docker/command.php');
        $this->assertStringContainsAllStrings([
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
        $src = $this->pmssReadRepoFile('scripts/lib/user/docker/stop.php')
            .$this->pmssReadRepoFile('scripts/lib/user/docker/cli.php');
        $this->assertStringContainsAllStrings([
            'XDG_RUNTIME_DIR=%s systemctl --user %s docker.service',
            '$systemdAction = !$dockerEnabled ? \'disable --now\' : \'stop\';',
            'step=systemctl-stop rc=%d count=1', 'if ($remainingPids !== []) {',
        ], $src);
        $this->assertOrderedStrings([
            'step=verify rc=%d count=%d',
            'echo "Docker stop requested',
        ], $src, '', 'stop success output must follow liveness verification: ');
    }

    public function testUserDockerStopSignalsDaemonBeforeNamespaceAndUsesLongerDeadlines(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/user/docker/stop.php');
        $this->assertOrderedStrings([
            "userDockerStopSignal(\$user, \$uid, \$stopPids['dockerd'], 15, 'dockerd-term')",
            'USER_DOCKER_DAEMON_WAIT_SECONDS',
            "userDockerStopSignal(\$user, \$uid, array_merge(\$stopPids['rootlesskit'], \$stopPids['wrapper']), 15, 'namespace-term')",
            'USER_DOCKER_STOP_DEADLINE_SECONDS',
            "userDockerStopSignal(\$user, \$uid, \$remainingPids, 9, 'remaining-kill')",
        ], substr($src, strpos($src, 'function userDockerStop(')), '', 'stop order: ');
        $this->assertStringContainsAllStrings([
            'USER_DOCKER_DAEMON_WAIT_SECONDS = 20',
            'USER_DOCKER_STOP_DEADLINE_SECONDS = 30',
            "'dockerd' => []",
            "'rootlesskit' => []",
            "'wrapper' => []",
            "ps -u '.escapeshellarg((string) \$uid)",
        ], $src);
        $this->assertTrue(strpos($src, 'pkill -f') === false);
    }
}
