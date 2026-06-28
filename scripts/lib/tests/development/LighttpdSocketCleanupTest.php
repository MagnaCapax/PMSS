<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class LighttpdSocketCleanupTest extends TestCase
{
    public function testStartScriptCreatesManagedDirectoriesViaSuBeforeLaunch(): void
    {
        $script = $this->readStartScript();
        $helperPos = strpos($script, 'function pmssStartLighttpdEnsureDirectory(');
        $dirsPos = strpos($script, 'foreach ($requiredDirs as $dir) {');
        $mkdirPos = strpos($script, "passthru(pmssBuildUserShellCommand(\$user, 'mkdir -p '.escapeshellarg(\$dir)), \$rc);");
        $guardCallPos = strpos($script, 'pmssStartLighttpdEnsureDirectory($user, $homeDir, $dir)');
        $launchPos = strpos($script, "\$startCommand = 'cd '.escapeshellarg(\$homeDir).' && /usr/sbin/lighttpd -f '.escapeshellarg(\$configPath);");

        $this->assertTrue($helperPos !== false);
        $this->assertTrue($dirsPos !== false);
        $this->assertTrue($mkdirPos !== false && $mkdirPos > $helperPos);
        $this->assertTrue($guardCallPos !== false && $guardCallPos > $dirsPos);
        $this->assertTrue($launchPos !== false && $guardCallPos < $launchPos);
        $this->assertTrue(strpos($script, "if (\$deflateEnabled) {") !== false);
        $this->assertTrue(strpos($script, "\$requiredDirs[] = \$lighttpdDir.'/compress';") !== false);
        $this->assertTrue(strpos($script, 'pmssStartLighttpdPathWithinHome($dir, $homeDir)') !== false);
        $this->assertTrue(strpos($script, 'if ($rc !== 0) {') !== false);
        $this->assertTrue(strpos($script, 'Unable to prepare lighttpd directory') !== false);
    }

    public function testStartScriptRemovesPhpSocketEntriesBeforeLaunch(): void
    {
        $script = $this->readStartScript();
        $cleanupPos = strpos($script, "foreach (glob(rtrim(\$lighttpdDir, '/').'/php.socket*') ?: [] as \$socketPath) {");
        $unlinkPos = strpos($script, 'pmssStartLighttpdRemoveSocket($homeDir, $socketPath)');
        $launchPos = strpos($script, "\$startCommand = 'cd '.escapeshellarg(\$homeDir).' && /usr/sbin/lighttpd -f '.escapeshellarg(\$configPath);");

        $this->assertTrue($cleanupPos !== false);
        $this->assertTrue($unlinkPos !== false && $unlinkPos > $cleanupPos);
        $this->assertTrue($launchPos !== false && $unlinkPos < $launchPos);
        $this->assertTrue(strpos($script, 'is_link($socketPath)') !== false);
        $this->assertTrue(strpos($script, 'Unable to remove stale lighttpd socket') !== false);
    }

    public function testStartScriptEntersUserHomeBeforeLaunchingLighttpd(): void
    {
        $script = $this->readStartScript();
        $chdirPos = strpos($script, '@chdir($homeDir)');
        $commandPos = strpos($script, "\$startCommand = 'cd '.escapeshellarg(\$homeDir).' && /usr/sbin/lighttpd -f '.escapeshellarg(\$configPath);");
        $scopePos = strpos($script, 'pmssBuildUserServiceShellCommand($user, $startCommand)');

        $this->assertTrue($chdirPos !== false);
        $this->assertTrue($commandPos !== false && $commandPos > $chdirPos);
        $this->assertTrue($scopePos !== false && $scopePos > $commandPos);
        $this->assertTrue(strpos($script, "require_once __DIR__.'/lib/user/serviceLaunch.php';") !== false);
        $this->assertTrue(strpos($script, 'fwrite(STDERR, "Unable to enter user home\n");') !== false);
        $this->assertTrue(strpos($script, 'fwrite(STDERR, "Unable to start lighttpd inside user slice\n");') !== false);
        $this->assertTrue(strpos($script, 'exit(1);') !== false);
        $this->assertTrue(strpos($script, 'cd {$homeDir}; su {$user}') === false);
        $this->assertTrue(strpos($script, "passthru('su '.escapeshellarg(\$user).' -c '") === false);
        $this->assertTrue(strpos($script, "ps aux | grep '.escapeshellarg(\$user)") !== false);
    }

    private function readStartScript(): string
    {
        return $this->pmssReadRepoFile('scripts/startLighttpd');
    }
}
