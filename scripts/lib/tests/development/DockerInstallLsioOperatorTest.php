<?php
/** The operator LSIO entrypoint hands installs to the account and never writes as root (ADR 0121). */

namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../../util/dockerInstallLsio.php';

final class DockerInstallLsioOperatorTest extends TestCase
{
    private const ENTRYPOINT = __DIR__.'/../../../util/dockerInstallLsio.php';

    public function testAccountRequiresAccountOwnedDirectoryThatIsItsPasswdHome(): void
    {
        $home = $this->pmssMakeTempDir('pmss-lsio-operator-home-');
        $root = function_exists('posix_geteuid') && posix_geteuid() === 0;
        if ($root) {
            $this->assertTrue(chown($home, 65534));
            clearstatcache();
        }
        $uid = (int) fileowner($home);
        $entry = static function (string $dir, int $owner): callable {
            return static function () use ($dir, $owner): array {
                return ['name' => 'pmss-test', 'uid' => $owner, 'gid' => $owner, 'dir' => $dir];
            };
        };
        $link = $home.'-link';
        $this->pmssCreateSymlinkOrSkip($home, $link);
        $file = $this->pmssWriteFile($home.'/file', 'x');
        try {
            $this->assertSame($home, \pmssDockerInstallLsioOperatorAccount($home, $entry($home, $uid))['dir'] ?? null);
            foreach ([
                [$home, $entry($home.'/other', $uid)],
                [$home, $entry($home, $uid + 1)],
                [$home, static function () { return false; }],
                [$home.'/', $entry($home, $uid)],
                [$link, $entry($link, $uid)],
                [$file, $entry($file, $uid)],
                [$home.'/absent', $entry($home.'/absent', $uid)],
                ['/', $entry('/', 0)],
                ['', $entry('', $uid)],
            ] as [$path, $passwd]) {
                $this->assertSame(null, \pmssDockerInstallLsioOperatorAccount($path, $passwd), $path);
            }
        } finally {
            @unlink($link);
            if ($root) {
                chown($home, 0);
            }
        }
    }

    public function testAccountCommandRunsTheAccountsOwnInstallerWithArgvIntact(): void
    {
        $home = $this->pmssMakeTempDir('pmss-lsio-operator-run-');
        $this->pmssWriteFile($home.'/bin/docker-install-lsio', '<?php echo json_encode(['
            ."'argv' => array_slice(\$argv, 1), 'home' => getenv('HOME'), 'docker' => getenv('DOCKER_HOST')]);");
        $args = ['mariadb', "x'; touch pmss-injected #", '--dry-run'];
        $command = \pmssDockerInstallLsioOperatorCommand(['name' => 'pmss-test', 'uid' => 4321, 'dir' => $home], $args);
        $this->assertStringNotContainsString('/etc/skel', $command);
        $this->assertStringNotContainsString('/scripts/', $command);
        if (!is_executable('/usr/bin/php')) {
            throw new SkipTest('/usr/bin/php unavailable');
        }
        $result = $this->pmssExecShellCommand('cd '.escapeshellarg($home).' && '.$command);
        $this->assertSame(0, $result['rc'], $result['output']);
        $this->assertSame(
            ['argv' => $args, 'home' => $home, 'docker' => 'unix:///run/user/4321/docker.sock'],
            json_decode($result['output'], true)
        );
        $this->assertFalse(file_exists($home.'/pmss-injected'));
    }

    public function testEntrypointRefusesWithoutAnAccountHomeAndLeavesItUntouched(): void
    {
        $home = $this->pmssMakeTempDir('pmss-lsio-operator-refuse-');
        foreach ([$home, '/'] as $candidate) {
            $command = 'env '.implode(' ', array_map('escapeshellarg', [
                'HOME='.$candidate, 'PATH=/usr/bin:/bin', PHP_BINARY, self::ENTRYPOINT, 'mariadb',
            ])).' 2>&1';
            $result = $this->pmssExecShellCommand($command, [], '');
            $this->assertSame(1, $result['rc'], $result['output']);
            $this->assertStringContainsString("Set HOME to the account's home directory", $result['output']);
        }
        $this->assertSame(['.', '..'], scandir($home));
    }

    public function testEntrypointRunsTheInstallOnlyThroughTheAccountRunner(): void
    {
        $source = $this->pmssReadRepoFile('scripts/util/dockerInstallLsio.php');
        $this->assertStringContainsString("passthru(pmssBuildUserShellNoPamCommand((string) \$account['name'], \$command), \$rc);", $source);
        $this->assertStringNotContainsString('/etc/skel', $source);
        $this->assertStringNotContainsString('pmssDockerInstallLsioMain', $source);
    }
}
