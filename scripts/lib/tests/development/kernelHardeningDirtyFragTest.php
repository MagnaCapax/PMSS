<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/KernelHardeningTestCase.php';
require_once dirname(__DIR__, 2).'/update/kernelHardening.php';

class KernelHardeningDirtyFragTest extends KernelHardeningTestCase
{
    public function testWritesBlacklistAndRunsUnloadStep(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-dirtyfrag-write-');
        $logs = [];
        $calls = [];

        $this->pmssRunKernelHardeningWithCalls(
            '\pmssEnsureDirtyFragBlacklist',
            $this->dirtyFragEnv($dir, "Module                  Size  Used by\n"),
            $logs,
            $calls
        );

        $this->assertEquals(\pmssDirtyFragBlacklistBody(), file_get_contents($dir.'/modprobe.d/dirtyfrag.conf'));
        $this->pmssAssertMessagesContain($logs, 'Updated '.$dir.'/modprobe.d/dirtyfrag.conf', 'expected blacklist update log');
        $this->assertEquals('Unloading Dirty Frag modules (esp4_offload esp6_offload xfrm_interface esp4 esp6 rxrpc ipcomp ipcomp6 espintcp)', $calls[0][0]);
        $this->assertTrue(!is_file($dir.'/runtime/dirtyfrag-modules-loaded'), 'expected runtime flag to stay absent');
    }

    public function testCreatesRuntimeFlagWhenResidualModulesRemainPinned(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-dirtyfrag-flag-');
        $logs = [];

        $snapshot = "Module                  Size  Used by\nesp4                   20480  2\nrxrpc                  45056  1\n";
        $this->pmssRunKernelHardeningNoop('\pmssEnsureDirtyFragBlacklist', $this->dirtyFragEnv($dir, $snapshot), $logs);

        $flagPath = $dir.'/runtime/dirtyfrag-modules-loaded';
        $this->assertTrue(is_file($flagPath), 'expected runtime flag for pinned modules');
        $this->assertEquals("esp4\t2\nrxrpc\t1\n", file_get_contents($flagPath));
        $this->pmssAssertMessagesContain($logs, 'Dirty Frag runtime flag written', 'expected runtime flag log');
    }

    public function testWarnsWithoutFlagWhenResidualModulesAreNotPinned(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-dirtyfrag-zero-use-');
        $logs = [];

        $snapshot = "Module                  Size  Used by\nesp6                   20480  0\n";
        $this->pmssRunKernelHardeningNoop('\pmssEnsureDirtyFragBlacklist', $this->dirtyFragEnv($dir, $snapshot), $logs);

        $this->pmssAssertMessagesContain($logs, 'Dirty Frag modules still loaded after unload attempt', 'expected residual module warning');
        $this->assertTrue(!is_file($dir.'/runtime/dirtyfrag-modules-loaded'), 'expected zero-use residual module to avoid runtime flag');
    }

    public function testRemovesStaleRuntimeFlagAfterSuccessfulEviction(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-dirtyfrag-cleanup-');
        $this->pmssWriteFile($dir.'/runtime/dirtyfrag-modules-loaded', "esp4\t1\n");

        $logs = [];
        $this->pmssRunKernelHardeningNoop('\pmssEnsureDirtyFragBlacklist', $this->dirtyFragEnv($dir, "Module                  Size  Used by\n"), $logs);

        $this->assertTrue(!is_file($dir.'/runtime/dirtyfrag-modules-loaded'), 'expected stale runtime flag to be removed');
    }

    public function testWarnsWhenModuleInspectionSnapshotIsUnavailable(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-dirtyfrag-missing-snapshot-');
        $logs = [];
        $env = $this->dirtyFragEnv($dir, null);
        $env['PMSS_LSMOD_OUTPUT_PATH'] = $dir.'/missing-lsmod.txt';

        $this->pmssRunKernelHardeningNoop('\pmssEnsureDirtyFragBlacklist', $env, $logs);

        $this->pmssAssertMessagesContain($logs, 'Unable to inspect Dirty Frag modules', 'expected missing snapshot warning');
    }

    public function testSkipsAllMutationsInDryRun(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-dirtyfrag-dry-run-');
        $logs = [];
        $calls = [];
        $env = $this->dirtyFragEnv($dir, null);
        $env['PMSS_DRY_RUN'] = '1';

        $this->pmssRunKernelHardeningWithCalls('\pmssEnsureDirtyFragBlacklist', $env, $logs, $calls);

        $this->pmssAssertMessagesContain($logs, 'Dirty Frag blacklist: skipping all mutations', 'expected dry-run skip log');
        $this->assertTrue(!is_file($dir.'/modprobe.d/dirtyfrag.conf'), 'expected dry-run to skip file creation');
        $this->assertEquals([], $calls);
    }

    public function testWarnsWhenKernelBuildsDirtyFragModulesIn(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-dirtyfrag-built-in-');
        $logs = [];
        // Built-in modules never show in lsmod: the snapshot is empty, as on a real built-in kernel.
        $env = $this->dirtyFragEnv($dir, "Module                  Size  Used by\n", "CONFIG_INET_ESP=y\n");

        [, $stdout] = $this->pmssCaptureStdout(function () use ($env, &$logs): void {
            $this->pmssRunKernelHardeningNoop('\pmssEnsureDirtyFragBlacklist', $env, $logs);
        });

        $this->pmssAssertMessagesContain($logs, 'Dirty Frag modules (esp4, esp6, rxrpc', 'expected built-in warning via custom logger');
        $this->pmssAssertMessagesContain($logs, 'built into this kernel', 'expected built-in wording');
        $this->assertStringContainsString((string) \PMSS_KERNEL_HARDENING['dirtyfrag']['builtin_warning'], $stdout, 'expected pmssLogStatus warning');
    }

    public function testWarnsForEachBlacklistedModuleSymbolBuiltIn(): void
    {
        $symbols = ['CONFIG_INET_ESP', 'CONFIG_INET6_ESP', 'CONFIG_AF_RXRPC', 'CONFIG_INET_IPCOMP', 'CONFIG_INET6_IPCOMP', 'CONFIG_XFRM_USER', 'CONFIG_INET_ESPINTCP', 'CONFIG_INET_ESP_OFFLOAD', 'CONFIG_INET6_ESP_OFFLOAD', 'CONFIG_XFRM_INTERFACE'];
        foreach ($symbols as $symbol) {
            $dir = $this->pmssMakeTempDir('pmss-dirtyfrag-symbol-');
            $logs = [];
            // Every other symbol stays loadable; only this one is compiled in.
            $config = implode("\n", array_map(static function (string $other) use ($symbol): string {
                return $other.($other === $symbol ? '=y' : '=m');
            }, $symbols))."\n";
            $env = $this->dirtyFragEnv($dir, "Module                  Size  Used by\n", $config);

            $this->pmssCaptureStdout(function () use ($env, &$logs): void {
                $this->pmssRunKernelHardeningNoop('\pmssEnsureDirtyFragBlacklist', $env, $logs);
            });

            $this->pmssAssertMessagesContain($logs, 'built into this kernel', 'expected built-in warning for '.$symbol);
        }
    }

    public function testStaysQuietWhenDirtyFragModulesAreLoadableOrConfigIsMissing(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-dirtyfrag-loadable-');
        $logs = [];
        $env = $this->dirtyFragEnv($dir, "Module                  Size  Used by\n", "CONFIG_INET_ESP=m\nCONFIG_AF_RXRPC=m\n# CONFIG_INET_ESPINTCP is not set\n");
        $this->pmssRunKernelHardeningNoop('\pmssEnsureDirtyFragBlacklist', $env, $logs);
        $this->assertFalse($this->pmssMessagesContain($logs, 'built into this kernel'), 'loadable modules must not warn');

        $logs = [];
        $env = $this->dirtyFragEnv($dir, "Module                  Size  Used by\n", null);
        $env['PMSS_DIRTYFRAG_KERNEL_CONFIG_PATH'] = $dir.'/no-such-kernel-config';
        $this->pmssRunKernelHardeningNoop('\pmssEnsureDirtyFragBlacklist', $env, $logs);
        $this->assertFalse($this->pmssMessagesContain($logs, 'built into this kernel'), 'an unreadable kernel config must not warn');
    }

    public function testBuiltInWarningCoexistsWithPinnedRuntimeFlag(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-dirtyfrag-built-in-flag-');
        $logs = [];
        $snapshot = "Module                  Size  Used by\nxfrm_user              20480  3\n";
        $env = $this->dirtyFragEnv($dir, $snapshot, "CONFIG_AF_RXRPC=y\n");

        $this->pmssCaptureStdout(function () use ($env, &$logs): void {
            $this->pmssRunKernelHardeningNoop('\pmssEnsureDirtyFragBlacklist', $env, $logs);
        });

        $this->pmssAssertMessagesContain($logs, 'built into this kernel', 'expected built-in warning when a loadable module is also pinned');
        $this->assertTrue(is_file($dir.'/runtime/dirtyfrag-modules-loaded'), 'expected the pinned-module runtime flag to be kept');
    }

    /**
     * @return array<string, string>
     */
    private function dirtyFragEnv(string $dir, ?string $lsmodOutput, ?string $kernelConfig = "CONFIG_INET_ESP=m\n"): array
    {
        $env = [
            'PMSS_DIRTYFRAG_MODPROBE_PATH' => $dir.'/modprobe.d/dirtyfrag.conf',
            'PMSS_DIRTYFRAG_FLAG_PATH' => $dir.'/runtime/dirtyfrag-modules-loaded',
        ];
        if ($kernelConfig !== null) {
            $env['PMSS_DIRTYFRAG_KERNEL_CONFIG_PATH'] = $this->pmssWriteTempFile('dirtyfrag-kernel-config', $kernelConfig);
        }
        if ($lsmodOutput !== null) {
            $env['PMSS_LSMOD_OUTPUT_PATH'] = $this->pmssWriteTempFile('lsmod', $lsmodOutput);
        }

        return $env;
    }

}
