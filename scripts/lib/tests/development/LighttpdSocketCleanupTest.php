<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class LighttpdSocketCleanupTest extends TestCase
{
    public function testStartScriptCreatesManagedDirectoriesViaSuBeforeLaunch(): void
    {
        $script = $this->readStartScript();
        $dirsPos = strpos($script, 'foreach ($requiredDirs as $dir) {');
        $mkdirPos = strpos($script, "'mkdir -p '.escapeshellarg(\$dir)");
        $launchPos = strpos($script, "\$startCommand = 'cd '.escapeshellarg(\$homeDir).' && /usr/sbin/lighttpd -f '.escapeshellarg(\$configPath);");

        $this->assertTrue($dirsPos !== false);
        $this->assertTrue($mkdirPos !== false && $mkdirPos > $dirsPos);
        $this->assertTrue($launchPos !== false && $mkdirPos < $launchPos);
        $this->assertTrue(strpos($script, "if (\$deflateEnabled) {") !== false);
        $this->assertTrue(strpos($script, "\$requiredDirs[] = \$lighttpdDir.'/compress';") !== false);
    }

    public function testStartScriptRemovesPhpSocketEntriesBeforeLaunch(): void
    {
        $script = $this->readStartScript();
        $cleanupPos = strpos($script, "foreach (glob(rtrim(\$lighttpdDir, '/').'/php.socket*') ?: [] as \$socketPath) {");
        $unlinkPos = strpos($script, '@unlink($socketPath);');
        $launchPos = strpos($script, "\$startCommand = 'cd '.escapeshellarg(\$homeDir).' && /usr/sbin/lighttpd -f '.escapeshellarg(\$configPath);");

        $this->assertTrue($cleanupPos !== false);
        $this->assertTrue($unlinkPos !== false && $unlinkPos > $cleanupPos);
        $this->assertTrue($launchPos !== false && $unlinkPos < $launchPos);
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
