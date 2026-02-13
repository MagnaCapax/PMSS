<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class SysctlBaselineTest extends TestCase
{
    public function testWritesBaselineWithKptrRestrict(): void
    {
        $dir = $this->makeTempDir('pmss-sysctl');
        $target = $dir.'/sysctl.conf';
        $messages = [];
        $this->runBaseline($target, $messages, false);

        $this->assertTrue(file_exists($target), 'expected sysctl file to be written');
        $content = (string)file_get_contents($target);
        $this->assertStringContainsString('# Pulsed Media Config', $content);
        $this->assertStringContainsString('net.core.default_qdisc = fq', $content);
        $this->assertStringContainsString('net.ipv4.tcp_congestion_control = bbr', $content);
        $this->assertStringContainsString('kernel.kptr_restrict = 1', $content);
        $this->assertStringContainsString('kernel.yama.ptrace_scope = 1', $content);
        $this->assertStringContainsString('fs.protected_regular = 2', $content);

        $this->cleanup($dir);
    }

    public function testSkipsWhenUpToDate(): void
    {
        $dir = $this->makeTempDir('pmss-sysctl-skip');
        $target = $dir.'/sysctl.conf';

        $messages = [];
        $this->runBaseline($target, $messages, false);
        $first = (string)file_get_contents($target);

        $messages = [];
        $this->runBaseline($target, $messages, false);
        $second = (string)file_get_contents($target);

        $this->assertEquals($first, $second, 'expected sysctl file unchanged');
        $this->assertTrue($this->messagesContain($messages, 'already present and up to date'), 'expected skip log');

        $this->cleanup($dir);
    }

    public function testCreatesTargetDirectory(): void
    {
        $dir = $this->makeTempDir('pmss-sysctl-dir');
        $targetDir = $dir.'/nested';
        $target = $targetDir.'/sysctl.conf';

        $messages = [];
        $this->runBaseline($target, $messages, false);

        $this->assertTrue(is_dir($targetDir), 'expected target directory to exist');
        $this->assertTrue(file_exists($target), 'expected sysctl file to be written');

        $this->cleanup($dir);
    }

    public function testReloadSkipLogWhenDisabled(): void
    {
        $dir = $this->makeTempDir('pmss-sysctl-reload');
        $target = $dir.'/sysctl.conf';
        $messages = [];
        $this->runBaseline($target, $messages, false);

        $this->assertTrue($this->messagesContain($messages, 'sysctl reload disabled'), 'expected reload skip log');

        $this->cleanup($dir);
    }

    public function testUpdatesWhenContentDiffers(): void
    {
        $dir = $this->makeTempDir('pmss-sysctl-update');
        $target = $dir.'/sysctl.conf';
        file_put_contents($target, "kernel.kptr_restrict = 0\n");

        $messages = [];
        $this->runBaseline($target, $messages, false);

        $content = (string)file_get_contents($target);
        $this->assertStringContainsString('kernel.kptr_restrict = 1', $content);
        $this->assertTrue($content !== "kernel.kptr_restrict = 0\n", 'expected content updated');

        $this->cleanup($dir);
    }

    private function runBaseline(string $target, array &$messages, bool $reload): void
    {
        $logger = function (string $message) use (&$messages): void {
            $messages[] = $message;
        };
        \pmssEnsureLegacySysctlBaseline($logger, $target, $reload);
    }

    private function messagesContain(array $messages, string $needle): bool
    {
        foreach ($messages as $message) {
            if (strpos($message, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private function makeTempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        return $dir;
    }

    private function cleanup(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }
}
