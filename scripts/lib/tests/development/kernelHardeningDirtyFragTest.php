<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/kernelHardening.php';

class KernelHardeningDirtyFragTest extends TestCase
{
    public function testWritesBlacklistAndRunsUnloadStep(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-dirtyfrag-write-');
        $logs = [];
        $calls = [];

        $this->pmssWithEnv($this->dirtyFragEnv($dir, "Module                  Size  Used by\n"), function () use ($dir, &$logs, &$calls): void {
            \pmssEnsureDirtyFragBlacklist(
                function (string $message) use (&$logs): void { $logs[] = $message; },
                function (string $description, string $command) use (&$calls): int {
                    $calls[] = [$description, $command];
                    return 0;
                }
            );

            $this->assertEquals(\pmssDirtyFragBlacklistBody(), file_get_contents($dir.'/modprobe.d/dirtyfrag.conf'));
        });

        $this->assertTrue($this->pmssMessagesContain($logs, 'Updated '.$dir.'/modprobe.d/dirtyfrag.conf'), 'expected blacklist update log');
        $this->assertEquals('Unloading Dirty Frag modules (esp4 esp6 rxrpc ipcomp ipcomp6 espintcp)', $calls[0][0]);
        $this->assertTrue(!is_file($dir.'/runtime/dirtyfrag-modules-loaded'), 'expected runtime flag to stay absent');
    }

    public function testCreatesRuntimeFlagWhenResidualModulesRemainPinned(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-dirtyfrag-flag-');
        $logs = [];

        $snapshot = "Module                  Size  Used by\nesp4                   20480  2\nrxrpc                  45056  1\n";
        $this->pmssWithEnv($this->dirtyFragEnv($dir, $snapshot), function () use ($dir, &$logs): void {
            \pmssEnsureDirtyFragBlacklist(function (string $message) use (&$logs): void { $logs[] = $message; }, function (): int {
                return 0;
            });
        });

        $flagPath = $dir.'/runtime/dirtyfrag-modules-loaded';
        $this->assertTrue(is_file($flagPath), 'expected runtime flag for pinned modules');
        $this->assertEquals("esp4\t2\nrxrpc\t1\n", file_get_contents($flagPath));
        $this->assertTrue($this->pmssMessagesContain($logs, 'Dirty Frag runtime flag written'), 'expected runtime flag log');
    }

    public function testWarnsWithoutFlagWhenResidualModulesAreNotPinned(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-dirtyfrag-zero-use-');
        $logs = [];

        $snapshot = "Module                  Size  Used by\nesp6                   20480  0\n";
        $this->pmssWithEnv($this->dirtyFragEnv($dir, $snapshot), function () use (&$logs): void {
            \pmssEnsureDirtyFragBlacklist(function (string $message) use (&$logs): void { $logs[] = $message; }, function (): int {
                return 0;
            });
        });

        $this->assertTrue($this->pmssMessagesContain($logs, 'Dirty Frag modules still loaded after unload attempt'), 'expected residual module warning');
        $this->assertTrue(!is_file($dir.'/runtime/dirtyfrag-modules-loaded'), 'expected zero-use residual module to avoid runtime flag');
    }

    public function testRemovesStaleRuntimeFlagAfterSuccessfulEviction(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-dirtyfrag-cleanup-');
        @mkdir($dir.'/runtime', 0755, true);
        file_put_contents($dir.'/runtime/dirtyfrag-modules-loaded', "esp4\t1\n");

        $this->pmssWithEnv($this->dirtyFragEnv($dir, "Module                  Size  Used by\n"), function (): void {
            \pmssEnsureDirtyFragBlacklist(function (): void {
            }, function (): int {
                return 0;
            });
        });

        $this->assertTrue(!is_file($dir.'/runtime/dirtyfrag-modules-loaded'), 'expected stale runtime flag to be removed');
    }

    public function testWarnsWhenModuleInspectionSnapshotIsUnavailable(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-dirtyfrag-missing-snapshot-');
        $logs = [];
        $env = $this->dirtyFragEnv($dir, null);
        $env['PMSS_LSMOD_OUTPUT_PATH'] = $dir.'/missing-lsmod.txt';

        $this->pmssWithEnv($env, function () use (&$logs): void {
            \pmssEnsureDirtyFragBlacklist(function (string $message) use (&$logs): void { $logs[] = $message; }, function (): int {
                return 0;
            });
        });

        $this->assertTrue($this->pmssMessagesContain($logs, 'Unable to inspect Dirty Frag modules'), 'expected missing snapshot warning');
    }

    public function testSkipsAllMutationsInDryRun(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-dirtyfrag-dry-run-');
        $logs = [];
        $calls = [];
        $env = $this->dirtyFragEnv($dir, null);
        $env['PMSS_DRY_RUN'] = '1';

        $this->pmssWithEnv($env, function () use (&$logs, &$calls): void {
            \pmssEnsureDirtyFragBlacklist(
                function (string $message) use (&$logs): void { $logs[] = $message; },
                function (string $description, string $command) use (&$calls): int {
                    $calls[] = [$description, $command];
                    return 0;
                }
            );
        });

        $this->assertTrue($this->pmssMessagesContain($logs, 'Dirty Frag blacklist: skipping all mutations'), 'expected dry-run skip log');
        $this->assertTrue(!is_file($dir.'/modprobe.d/dirtyfrag.conf'), 'expected dry-run to skip file creation');
        $this->assertEquals([], $calls);
    }

    /**
     * @return array<string, string>
     */
    private function dirtyFragEnv(string $dir, ?string $lsmodOutput): array
    {
        $env = [
            'PMSS_DIRTYFRAG_MODPROBE_PATH' => $dir.'/modprobe.d/dirtyfrag.conf',
            'PMSS_DIRTYFRAG_FLAG_PATH' => $dir.'/runtime/dirtyfrag-modules-loaded',
        ];
        if ($lsmodOutput !== null) {
            $env['PMSS_LSMOD_OUTPUT_PATH'] = $this->pmssWriteTempFile('lsmod', $lsmodOutput);
        }

        return $env;
    }
}
