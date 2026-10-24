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

    public function testFreshInstallStopsWhenUpdateFails(): void
    {
        $this->assertStringContainsString(implode("\n\t", [
            'run_cmd /scripts/update.php "${UPDATE_ARGS[@]}"',
            'update_rc=$?',
            'if [ "$update_rc" -ne 0 ]; then',
            "\t".'log_error "Update failed (rc=${update_rc}); see /var/log/pmss/update.log"',
            "\t".'exit "$update_rc"',
            'fi',
            'run_cmd /scripts/util/setupRootCron.php',
        ]), $this->script);
    }

    public function testHomeMountPreflightPrecedesProvisioning(): void
    {
        $this->assertOrderedStrings([
            'existing_install=false',
            'existing_install=true',
            'preflight_checks() {',
            'if [ "$existing_install" != true ]; then',
            'PMSS_SKIP_HOME_MOUNT_CHECK',
            'mountpoint -q /home',
            "\npreflight_checks\nprint_summary",
            'run_cmd apt update',
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

    public function testBullseyeSecurityMovesToArchiveBeforeBootstrapPackages(): void
    {
        $this->assertOrderedStrings([
            'bootstrap_bullseye_security_archive /etc/os-release /etc/apt',
            'run_cmd apt update',
            'ensure_packages git rsync curl wget ca-certificates unzip php php-cli',
        ], $this->script);
    }

    public function testBullseyeSecurityRewriteTouchesOnlyBullseyeSecurityLines(): void
    {
        $root = $this->pmssMakeTempDir('pmss-install-bullseye-');
        mkdir($root.'/apt/sources.list.d', 0755, true);
        $original = implode("\n", [
            'deb http://deb.debian.org/debian bullseye main',
            'deb http://security.debian.org/debian-security bullseye-security main',
            'deb-src http://security.debian.org/debian-security/ bullseye-security main contrib',
            'deb [arch=amd64] https://deb.debian.org/debian-security bullseye-security main',
            '# deb http://security.debian.org/debian-security bullseye-security main',
            'deb http://deb.debian.org/debian bullseye-updates main',
        ])."\n";
        file_put_contents($root.'/apt/sources.list', $original);
        file_put_contents($root.'/apt/sources.list.d/extra.list', "deb http://security.debian.org/debian-security bullseye-security main\n");
        file_put_contents($root.'/target.list', "deb http://security.debian.org/debian-security bullseye-security main\n");
        symlink($root.'/target.list', $root.'/apt/sources.list.d/link.list');
        file_put_contents($root.'/bookworm', "VERSION_CODENAME=bookworm\n");
        file_put_contents($root.'/bullseye', "VERSION_CODENAME=bullseye\n");

        $script = $root.'/run.sh';
        file_put_contents($script, "log_info() { echo \"INFO \$*\"; }\nlog_warn() { echo \"WARN \$*\"; }\n"
            .$this->pmssExtractShellFunction($this->script, 'bootstrap_bullseye_security_archive')."\n"
            .'bootstrap_bullseye_security_archive "$1" "$2"'."\n");

        $run = function (string $release) use ($script, $root): array {
            return $this->pmssExecShellCommand('bash '.escapeshellarg($script).' '.escapeshellarg($root.'/'.$release).' '.escapeshellarg($root.'/apt'));
        };

        $result = $run('bookworm');
        $this->assertSame(0, $result['rc']);
        $this->assertSame($original, file_get_contents($root.'/apt/sources.list'), 'Other releases stay untouched');

        $result = $run('bullseye');
        $this->assertSame(0, $result['rc'], $result['output']);
        $this->assertSame(implode("\n", [
            'deb http://deb.debian.org/debian bullseye main',
            'deb http://archive.debian.org/debian-security bullseye-security main',
            'deb-src http://archive.debian.org/debian-security bullseye-security main contrib',
            'deb [arch=amd64] http://archive.debian.org/debian-security bullseye-security main',
            '# deb http://security.debian.org/debian-security bullseye-security main',
            'deb http://deb.debian.org/debian bullseye-updates main',
        ])."\n", file_get_contents($root.'/apt/sources.list'));
        $this->assertSame($original, file_get_contents($root.'/apt/sources.list.pmss-bootstrap.save'), 'Backup keeps the original');
        $this->assertSame("deb http://archive.debian.org/debian-security bullseye-security main\n", file_get_contents($root.'/apt/sources.list.d/extra.list'));
        $this->assertStringContainsString('security.debian.org', (string) file_get_contents($root.'/target.list'), 'Symlinked lists are not followed');

        $second = $run('bullseye');
        $this->assertSame(0, $second['rc']);
        $this->assertSame('', $second['output'], 'A second run changes nothing');
        $this->assertSame($original, file_get_contents($root.'/apt/sources.list.pmss-bootstrap.save'), 'Backup is not overwritten');
    }

    public function testSharedConvergenceRemainsInUpdateStep2(): void
    {
        $path = $this->pmssRepoPath('scripts/util/update-step2.php');
        $updateStep2 = file_get_contents($path);
        $this->assertTrue($updateStep2 !== false, 'Failed to read update-step2.php');

        $this->assertStringContainsAllStrings([
            "['Applying hostname configuration', 'pmssApplyHostnameConfig'",
            "['Configuring quota mounts', 'pmssConfigureQuotaMount'",
            "['Applying boot defaults', 'pmssEnsureBootDefaults'",
            "['Applying legacy sysctl baseline', 'pmssEnsureLegacySysctlBaseline'",
            "['Configuring root shell defaults', 'pmssConfigureRootShellDefaults'",
        ], $updateStep2);
    }
}
