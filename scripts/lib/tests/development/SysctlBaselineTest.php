<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/FilesystemCleanupTrait.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class SysctlBaselineTest extends TestCase
{
    use FilesystemCleanupTrait;

    public function testWritesBaselineWithKptrRestrict(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-sysctl-', 0700);
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
        $this->assertStringContainsString("/etc/sysctl.d/99-pmss.conf", $this->systemPrepSource());

        $this->cleanup($dir);
    }

    public function testWritesBbrModulesLoadFile(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-sysctl-bbr-', 0700);
        $target = $dir.'/sysctl.conf';
        $modulesLoad = $dir.'/modules-load.conf';
        $messages = [];
        $this->runBaseline($target, $messages, false, $modulesLoad);

        $this->assertTrue(file_exists($modulesLoad), 'expected BBR modules-load file to be written');
        $content = (string)file_get_contents($modulesLoad);
        $this->assertStringContainsString('tcp_bbr', $content);

        $this->cleanup($dir);
    }

    public function testSkipsWhenUpToDate(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-sysctl-skip-', 0700);
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
        $dir = $this->pmssMakeTempDir('pmss-sysctl-dir-', 0700);
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
        $dir = $this->pmssMakeTempDir('pmss-sysctl-reload-', 0700);
        $target = $dir.'/sysctl.conf';
        $messages = [];
        $this->runBaseline($target, $messages, false);

        $this->assertTrue($this->messagesContain($messages, 'sysctl reload disabled'), 'expected reload skip log');

        $this->cleanup($dir);
    }

    public function testUpdatesWhenContentDiffers(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-sysctl-update-', 0700);
        $target = $dir.'/sysctl.conf';
        file_put_contents($target, "kernel.kptr_restrict = 0\n");

        $messages = [];
        $this->runBaseline($target, $messages, false);

        $content = (string)file_get_contents($target);
        $this->assertStringContainsString('kernel.kptr_restrict = 1', $content);
        $this->assertTrue($content !== "kernel.kptr_restrict = 0\n", 'expected content updated');

        $this->cleanup($dir);
    }

    private function runBaseline(string $target, array &$messages, bool $reload, ?string $modulesLoad = null): void
    {
        $logger = function (string $message) use (&$messages): void {
            $messages[] = $message;
        };
        $modulesLoad = $modulesLoad ?? dirname($target).'/modules-load.conf';
        \pmssEnsureLegacySysctlBaseline($logger, $target, $reload, $modulesLoad);
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

    private function systemPrepSource(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/update/systemPrep.php');
    }

}
