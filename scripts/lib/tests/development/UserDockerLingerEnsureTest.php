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
        $this->assertStringContainsString('pmssUserDockerLingerEnsureCommands($user, $uid)', $src);
        $this->assertStringContainsString('if (!is_dir($runtimeDir)) {', $src);
        $ensurePos = strpos($src, 'pmssUserDockerLingerEnsureCommands($user, $uid)');
        $startPos = strpos($src, 'nohup dockerd-rootless.sh');
        $this->assertTrue($ensurePos !== false && $startPos !== false && $ensurePos < $startPos,
            'linger-ensure must run before the rootless dockerd launch');
    }
}
