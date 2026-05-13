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

    public function testDispatcherSkipsCurrentBlacklistsForEveryRegistryEntry(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-kernel-hardening-skip-');
        @mkdir($dir.'/modprobe.d', 0755, true);
        foreach ($this->dispatcherCases($dir) as $case) {
            file_put_contents($case['path'], $case['body']);
        }

        $logs = [];
        $calls = [];
        $this->pmssWithEnv($this->dispatcherEnv($dir, "Module                  Size  Used by\n", "CONFIG_CRYPTO_USER_API_AEAD=m\n"), function () use (&$logs, &$calls): void {
            \pmssApplyKernelHardening(
                function (string $message) use (&$logs): void { $logs[] = $message; },
                function (string $description, string $command) use (&$calls): int {
                    $calls[] = [$description, $command];
                    return 0;
                }
            );
        });

        foreach ($this->dispatcherCases($dir) as $case) {
            $this->assertTrue($this->pmssMessagesContain($logs, $case['skip']), 'expected skip log for '.$case['name']);
        }
        $this->assertEquals([
            'Unloading Copy Fail algif_aead kernel module',
            'Unloading Dirty Frag modules (esp4 esp6 rxrpc ipcomp ipcomp6 espintcp)',
        ], array_column($calls, 0));
    }

    public function testBuiltInKernelDetectionFollowsRegistryFieldForEveryEntry(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-kernel-hardening-built-in-');
        $env = $this->dispatcherEnv($dir, "Module                  Size  Used by\n", "CONFIG_CRYPTO_USER_API_AEAD=y\n");
        $builtIn = [];

        $this->pmssWithEnv($env, function () use (&$builtIn): void {
            foreach (\PMSS_KERNEL_HARDENING as $name => $spec) {
                $builtIn[$name] = \pmssKernelHardeningBuiltIn($spec);
            }
        });

        $this->assertTrue($builtIn['copyfail'], 'expected copyfail builtin probe to match configured kconfig');
        $this->assertFalse($builtIn['dirtyfrag'], 'expected dirtyfrag to skip builtin probe without builtin_kconfig');

        $logs = [];
        list($ignored, $stdout) = $this->pmssCaptureStdout(function () use ($env, &$logs): void {
            $this->pmssWithEnv($env, function () use (&$logs): void {
                \pmssApplyKernelHardening(
                    function (string $message) use (&$logs): void { $logs[] = $message; },
                    function (): int { return 0; }
                );
            });
        });

        $this->assertTrue($this->pmssMessagesContain($logs, 'algif_aead is built into this kernel'), 'expected copyfail built-in warning');
        $this->assertFalse($this->pmssMessagesContain($logs, 'Dirty Frag is built into this kernel'), 'dirtyfrag has no builtin probe');
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

    /**
     * @return array<string, array{name:string,path:string,body:string,skip:string}>
     */
    private function dispatcherCases(string $dir): array
    {
        return [
            'copyfail' => [
                'name' => 'copyfail',
                'path' => $dir.'/modprobe.d/pmss-algif-blacklist.conf',
                'body' => \pmssAlgifAeadBlacklistBody(),
                'skip' => 'Copy Fail algif_aead blacklist already up to date',
            ],
            'dirtyfrag' => [
                'name' => 'dirtyfrag',
                'path' => $dir.'/modprobe.d/dirtyfrag.conf',
                'body' => \pmssDirtyFragBlacklistBody(),
                'skip' => 'Dirty Frag blacklist already up to date',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function dispatcherEnv(string $dir, string $lsmodOutput, string $kernelConfig): array
    {
        return [
            'PMSS_ALGIF_MODPROBE_PATH' => $dir.'/modprobe.d/pmss-algif-blacklist.conf',
            'PMSS_ALGIF_KERNEL_CONFIG_PATH' => $this->pmssWriteTempFile('algif-kernel-config', $kernelConfig),
            'PMSS_DIRTYFRAG_MODPROBE_PATH' => $dir.'/modprobe.d/dirtyfrag.conf',
            'PMSS_DIRTYFRAG_FLAG_PATH' => $dir.'/runtime/dirtyfrag-modules-loaded',
            'PMSS_LSMOD_OUTPUT_PATH' => $this->pmssWriteTempFile('lsmod', $lsmodOutput),
        ];
    }
}
