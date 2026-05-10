<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/kernelHardening.php';

class KernelHardeningAlgifAeadTest extends TestCase
{
    public function testWritesBlacklistAndRunsUnloadStep(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-algif-write-');
        $logs = [];
        $calls = [];

        $this->pmssWithEnv($this->algifEnv($dir), function () use ($dir, &$logs, &$calls): void {
            \pmssEnsureAlgifAeadBlacklist(
                function (string $message) use (&$logs): void { $logs[] = $message; },
                function (string $description, string $command) use (&$calls): int {
                    $calls[] = [$description, $command];
                    return 0;
                }
            );
        });

        $this->assertEquals(\pmssAlgifAeadBlacklistBody(), file_get_contents($dir.'/modprobe.d/pmss-algif-blacklist.conf'));
        $this->assertTrue($this->pmssMessagesContain($logs, 'Updated '.$dir.'/modprobe.d/pmss-algif-blacklist.conf'), 'expected blacklist update log');
        $this->assertEquals('Unloading Copy Fail algif_aead kernel module', $calls[0][0]);
    }

    public function testKeepsUnloadAttemptWhenBlacklistIsAlreadyCurrent(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-algif-skip-');
        @mkdir($dir.'/modprobe.d', 0755, true);
        file_put_contents($dir.'/modprobe.d/pmss-algif-blacklist.conf', \pmssAlgifAeadBlacklistBody());
        $logs = [];
        $calls = [];

        $this->pmssWithEnv($this->algifEnv($dir), function () use (&$logs, &$calls): void {
            \pmssEnsureAlgifAeadBlacklist(
                function (string $message) use (&$logs): void { $logs[] = $message; },
                function (string $description, string $command) use (&$calls): int {
                    $calls[] = [$description, $command];
                    return 0;
                }
            );
        });

        $this->assertTrue($this->pmssMessagesContain($logs, 'Copy Fail algif_aead blacklist already up to date'), 'expected idempotent skip log');
        $this->assertEquals(1, count($calls));
    }

    public function testSkipsAllMutationsInDryRun(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-algif-dry-run-');
        $logs = [];
        $calls = [];
        $env = $this->algifEnv($dir);
        $env['PMSS_DRY_RUN'] = '1';

        $this->pmssWithEnv($env, function () use (&$logs, &$calls): void {
            \pmssEnsureAlgifAeadBlacklist(
                function (string $message) use (&$logs): void { $logs[] = $message; },
                function (string $description, string $command) use (&$calls): int {
                    $calls[] = [$description, $command];
                    return 0;
                }
            );
        });

        $this->assertTrue($this->pmssMessagesContain($logs, 'Copy Fail algif_aead blacklist: skipping all mutations'), 'expected dry-run skip log');
        $this->assertTrue(!is_file($dir.'/modprobe.d/pmss-algif-blacklist.conf'), 'expected dry-run to skip file creation');
        $this->assertEquals([], $calls);
    }

    public function testWarnsWhenKernelBuildsAlgifAeadIn(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-algif-built-in-');
        $logs = [];
        $env = $this->algifEnv($dir, "CONFIG_CRYPTO_USER_API_AEAD=y\n");

        list($ignored, $stdout) = $this->pmssCaptureStdout(function () use ($env, &$logs): void {
            $this->pmssWithEnv($env, function () use (&$logs): void {
                \pmssEnsureAlgifAeadBlacklist(
                    function (string $message) use (&$logs): void { $logs[] = $message; },
                    function (): int {
                        return 0;
                    }
                );
            });
        });

        $this->assertTrue($this->pmssMessagesContain($logs, 'algif_aead is built into this kernel'), 'expected built-in warning via custom logger');
        $this->assertStringContainsString(\pmssAlgifAeadBuiltInKernelWarning(), $stdout, 'expected pmssLogStatus warning');
    }

    /**
     * @return array<string, string>
     */
    private function algifEnv(string $dir, ?string $kernelConfig = null): array
    {
        $env = [
            'PMSS_ALGIF_MODPROBE_PATH' => $dir.'/modprobe.d/pmss-algif-blacklist.conf',
        ];
        if ($kernelConfig !== null) {
            $env['PMSS_ALGIF_KERNEL_CONFIG_PATH'] = $this->pmssWriteTempFile('algif-kernel-config', $kernelConfig);
        }

        return $env;
    }
}
