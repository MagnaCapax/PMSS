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
        $launchPos = strpos($script, "/usr/sbin/lighttpd -f {\$configPath}");

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
        $launchPos = strpos($script, "/usr/sbin/lighttpd -f {\$configPath}");

        $this->assertTrue($cleanupPos !== false);
        $this->assertTrue($unlinkPos !== false && $unlinkPos > $cleanupPos);
        $this->assertTrue($launchPos !== false && $unlinkPos < $launchPos);
    }

    private function readStartScript(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3).'/startLighttpd');
    }
}
