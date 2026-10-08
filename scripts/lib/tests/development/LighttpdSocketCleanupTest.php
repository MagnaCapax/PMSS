<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../runtime.php';

class LighttpdSocketCleanupTest extends TestCase
{
    public function testStartScriptCreatesManagedDirectoriesViaSuBeforeLaunch(): void
    {
        $script = $this->readStartScript();
        $this->assertOrderedStrings([
            'function pmssStartLighttpdEnsureDirectory(',
            "passthru(pmssLockChildClosePrefix().pmssBuildUserShellCommand(\$user, 'mkdir -p '.escapeshellarg(\$dir)), \$rc);",
        ], $script);
        $this->assertOrderedStrings([
            'foreach ($requiredDirs as $dir) {',
            'pmssStartLighttpdEnsureDirectory($user, $homeDir, $dir)',
            "\$startCommand = 'cd '.escapeshellarg(\$homeDir).' && /usr/sbin/lighttpd -f '.escapeshellarg(\$configPath);",
        ], $script);
        $this->assertTrue(strpos($script, "if (\$deflateEnabled) {") !== false);
        $this->assertTrue(strpos($script, "\$requiredDirs[] = \$lighttpdDir.'/compress';") !== false);
        $this->assertTrue(strpos($script, 'pmssStartLighttpdPathWithinHome($dir, $homeDir)') !== false);
        $this->assertTrue(strpos($script, 'if ($rc !== 0) {') !== false);
        $this->assertTrue(strpos($script, 'Unable to prepare lighttpd directory') !== false);
    }

    public function testStartScriptRemovesPhpSocketEntriesBeforeLaunch(): void
    {
        $script = $this->readStartScript();
        $this->assertOrderedStrings([
            "foreach (glob(rtrim(\$lighttpdDir, '/').'/php.socket*') ?: [] as \$socketPath) {",
            'pmssStartLighttpdRemoveSocket($homeDir, $socketPath)',
            "\$startCommand = 'cd '.escapeshellarg(\$homeDir).' && /usr/sbin/lighttpd -f '.escapeshellarg(\$configPath);",
        ], $script);
        $this->assertTrue(strpos($script, 'is_link($socketPath)') !== false);
        $this->assertTrue(strpos($script, 'Unable to remove stale lighttpd socket') !== false);
    }

    public function testStartScriptEntersUserHomeBeforeLaunchingLighttpd(): void
    {
        $script = $this->readStartScript();
        $this->assertOrderedStrings([
            '@chdir($homeDir)',
            "\$startCommand = 'cd '.escapeshellarg(\$homeDir).' && /usr/sbin/lighttpd -f '.escapeshellarg(\$configPath);",
            'pmssLockChildClosePrefix().pmssBuildUserServiceShellCommand($user, $startCommand)',
        ], $script);
        $this->assertTrue(strpos($script, "require_once __DIR__.'/lib/user/serviceLaunch.php';") !== false);
        $this->assertTrue(strpos($script, 'fwrite(STDERR, "Unable to enter user home\n");') !== false);
        $this->assertTrue(strpos($script, 'fwrite(STDERR, "Unable to start lighttpd inside user slice\n");') !== false);
        $this->assertTrue(strpos($script, 'return 1;') !== false);
        $this->assertTrue(strpos($script, 'cd {$homeDir}; su {$user}') === false);
        $this->assertTrue(strpos($script, "passthru('su '.escapeshellarg(\$user).' -c '") === false);
        $this->assertTrue(strpos($script, "ps aux | grep '.escapeshellarg(\$user)") !== false);
    }

    public function testRunningInstanceKeepsItsSocketAndSkipsLaunch(): void
    {
        $fixture = $this->startFixture(true);
        $result = $this->startFixtureRun($fixture);

        $this->assertSame(0, $result['rc'], $result['output']);
        $this->assertStringContainsString('already running', $result['output']);
        $this->assertSame('socket data', file_get_contents($fixture['socket']));
        $this->assertSame('', (string) @file_get_contents($fixture['launchLog']));
    }

    public function testStoppedInstanceRemovesStaleSocketAndAttemptsStart(): void
    {
        $fixture = $this->startFixture(false);
        $result = $this->startFixtureRun($fixture);

        $this->assertSame(0, $result['rc'], $result['output']);
        $this->assertFalse(file_exists($fixture['socket']));
        $this->assertTrue(is_dir($fixture['home'].'/.lighttpd/upload'));
        $this->assertStringContainsString('/usr/sbin/lighttpd -f', (string) file_get_contents($fixture['launchLog']));
    }

    public function testStarterWaitsForAccountLockAndReleasesIt(): void
    {
        $fixture = $this->startFixture(true);
        $this->pmssWithEnv(['PMSS_RUNTIME_LOCK_DIR' => $fixture['locks']], function () use ($fixture): void {
            $path = \pmssRuntimeLockPath('pmss-lighttpdStart-fixture.lock');
            $marker = $fixture['root'].'/lock-held';
            $process = null;
            $pipes = [];
            try {
                // A separate holder avoids passing the parent's lock descriptor to the starter.
                $holder = '$handle = fopen($argv[1], "c"); flock($handle, LOCK_EX); touch($argv[2]); usleep(1000000);';
                $command = escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($holder)
                    .' '.escapeshellarg($path).' '.escapeshellarg($marker);
                $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                $this->assertTrue(is_resource($process));
                for ($attempt = 0; $attempt < 30 && !file_exists($marker); $attempt++) usleep(10000);
                $this->assertTrue(file_exists($marker), 'holder must acquire the account lock');
                $startedAt = microtime(true);
                $result = $this->startFixtureRun($fixture);
                $elapsed = microtime(true) - $startedAt;
                $this->assertSame(0, $result['rc'], $result['output']);
                $this->assertTrue($elapsed >= 0.5, 'starter must wait for held lock');
                $this->assertSame('socket data', file_get_contents($fixture['socket']));
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process));
                $process = null;
                $released = \pmssLockFileAcquire($path, true);
                $this->assertTrue(is_resource($released), 'starter must release its lock');
                if (is_resource($released)) fclose($released);
            } finally {
                if (is_resource($process)) proc_terminate($process);
                foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
                if (is_resource($process)) proc_close($process);
            }
        });
    }

    public function testSocketCleanupFailureReleasesAccountLock(): void
    {
        $fixture = $this->startFixture(false);
        unlink($fixture['socket']);
        $this->pmssCreateSymlinkOrSkip($fixture['home'].'/.lighttpd.conf', $fixture['socket']);
        $result = $this->startFixtureRun($fixture);

        $this->assertSame(1, $result['rc']);
        $this->assertStringContainsString('Unable to remove stale lighttpd socket', $result['output']);
        $this->assertTrue(is_link($fixture['socket']));
        $this->assertSame('', (string) @file_get_contents($fixture['launchLog']));
        $this->pmssWithEnv(['PMSS_RUNTIME_LOCK_DIR' => $fixture['locks']], function (): void {
            $path = \pmssRuntimeLockPath('pmss-lighttpdStart-fixture.lock');
            $released = \pmssLockFileAcquire($path, true);
            $this->assertTrue(is_resource($released), 'failed starter must release its lock');
            if (is_resource($released)) fclose($released);
        });
    }

    /** PATH stubs keep process detection and launch away from live services and /proc. */
    private function startFixture(bool $running): array
    {
        $root = $this->pmssMakeTempDir('pmss-lighttpd-start-home-');
        $home = $root.'/fixture';
        $this->pmssEnsureDir($home.'/.lighttpd');
        $this->pmssEnsureDir($home.'/.lighttpd/upload');
        $this->pmssWriteFile($home.'/.lighttpd.conf', 'server.port = 12345'."\n");
        $socket = $home.'/.lighttpd/php.socket-0';
        $this->pmssWriteFile($socket, 'socket data');
        $marker = $root.'/running';
        if ($running) $this->pmssWriteFile($marker, '1');
        $bin = $this->pmssMakeTempDir('pmss-lighttpd-start-bin-');
        $launchLog = $root.'/launch.log';
        $this->pmssWriteExecutableFiles($bin, [
            'pgrep' => '#!/bin/sh' . "\n" . 'test -f '.escapeshellarg($marker).' || exit 1' . "\n" . 'printf "12345\\n"' . "\n",
            'id' => "#!/bin/sh\nprintf '1234\\n'\n",
            'systemctl' => "#!/bin/sh\nexit 0\n",
            'systemd-run' => '#!/bin/sh' . "\n" . 'printf "%s\\n" "$*" >> '.escapeshellarg($launchLog)."\n",
            'ps' => "#!/bin/sh\nexit 0\n",
        ]);
        return compact('root', 'home', 'socket', 'bin', 'launchLog')
            + ['locks' => $this->pmssMakeTempDir('pmss-lighttpd-start-locks-', 0700)];
    }

    private function startFixtureRun(array $fixture): array
    {
        return $this->pmssRunRepoPhpScriptCommand('scripts/startLighttpd', ['fixture'], [
            'PATH' => $fixture['bin'].':'.getenv('PATH'),
            'PMSS_HOME_DIR' => $fixture['root'],
            'PMSS_RUNTIME_LOCK_DIR' => $fixture['locks'],
        ]);
    }

    private function readStartScript(): string
    {
        return $this->pmssReadRepoFile('scripts/startLighttpd');
    }
}
