<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class installBootstrapSafetyTest extends TestCase
{
    /** @var string */
    private $script;

    protected function setUp(): void
    {
        $path = $this->pmssRepoPath('install.sh');
        $script = file_get_contents($path);
        $this->assertTrue($script !== false, 'Failed to read install.sh');
        $this->script = $script;
    }

    public function testSnapshotCleanupAvoidsWildcardDelete(): void
    {
        $broadDeletePattern = 'rm -rf PMSS'.'*';
        $this->assertStringContainsString('cleanup_snapshot_workspace()', $this->script);
        $this->assertStringContainsString('/tmp/PMSS.tar.gz', $this->script);
        $this->assertStringNotContainsString($broadDeletePattern, $this->script);
    }

    public function testSnapshotTreeValidatedBeforeStaging(): void
    {
        $this->assertOrderedStrings([
            'validate_snapshot_tree PMSS || exit 1',
            'run_required rsync -a --ignore-missing-args PMSS/{var,scripts,etc} /',
        ], $this->script);
    }

    public function testCriticalSnapshotCommandsUseRequiredRunner(): void
    {
        $this->assertStringContainsAllStrings([
            'run_required git clone "$repository" PMSS',
            'run_required wget "https://api.github.com/repos/MagnaCapax/PMSS/tarball/${VERSION}" -O PMSS.tar.gz',
            'run_required tar -xzf PMSS.tar.gz -C PMSS --strip-components 1',
        ], $this->script);
    }

    public function testLatestReleaseResolutionCannotFallThroughEmpty(): void
    {
        $this->assertStringContainsString('Unable to resolve latest PMSS release tag', $this->script);
    }

    public function testBootstrapIntentIsPassedToUpdateStep2(): void
    {
        $this->assertStringContainsAllStrings([
            'export_update_bootstrap_env()',
            'unset PMSS_HOSTNAME PMSS_SKIP_HOSTNAME PMSS_QUOTA_MOUNT PMSS_SKIP_QUOTA',
            'export PMSS_HOSTNAME="$hostname_override"',
            'export PMSS_SKIP_HOSTNAME=1',
            'export PMSS_QUOTA_MOUNT="$quota_mountpoint"',
            'export PMSS_SKIP_QUOTA=1',
        ], $this->script);
        $this->assertOrderedStrings([
            'export_update_bootstrap_env',
            'run_cmd /scripts/update.php "${UPDATE_ARGS[@]}"',
        ], $this->script);
    }

    public function testInstallerDoesNotDuplicateSharedSystemPrepConvergence(): void
    {
        foreach ([
            'install_sysctl'.'_defaults()',
            'install_configure_temp'.'_disk_backed_mount()',
            'install_root_shell'.'_defaults()',
            'update'.'_hostname()',
            'ensure_fstab'.'_options()',
            'ensure_proc'.'_hidepid()',
            'ensure_grub_cmdline'.'_option()',
            'mount -o remount,hidepid=2 /proc',
            'mount -o remount /home',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $this->script);
        }
    }

    public function testSharedConvergenceRemainsInUpdateStep2(): void
    {
        $path = $this->pmssRepoPath('scripts/util/update-step2.php');
        $updateStep2 = file_get_contents($path);
        $this->assertTrue($updateStep2 !== false, 'Failed to read update-step2.php');

        $this->assertStringContainsAllStrings([
            "pmssRunProfiledCallable('Applying hostname configuration', 'pmssApplyHostnameConfig'",
            "pmssRunProfiledCallable('Configuring quota mounts', 'pmssConfigureQuotaMount'",
            "pmssRunProfiledCallable('Applying boot defaults', 'pmssEnsureBootDefaults'",
            "pmssRunProfiledCallable('Applying legacy sysctl baseline', 'pmssEnsureLegacySysctlBaseline'",
            "pmssRunProfiledCallable('Configuring root shell defaults', 'pmssConfigureRootShellDefaults'",
        ], $updateStep2);
    }
}
