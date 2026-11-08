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

    public function testNonInteractivePreservesBootstrapConfiguration(): void
    {
        // Execute only argument parsing, editor selection and environment export, never provisioning.
        $flagsStart = strpos($this->script, '# Installer runtime flags,');
        $flagsEnd = strpos($this->script, "\nexisting_install=false");
        $editorStart = strpos($this->script, "\nrun_editor() {");
        $editorEnd = strpos($this->script, "\nparse_version_string() {");
        foreach ([$flagsStart, $flagsEnd, $editorStart, $editorEnd] as $offset) {
            $this->assertTrue($offset !== false, 'Installer probe boundary missing');
        }
        $probe = 'FORCE_NONINTERACTIVE=false; SCRIPTS_ONLY=false'."\n"
            .'log_info() { :; }; log_warn() { :; }; nano() { echo editor-opened; }'."\n"
            // Stale inherited intent must not disable default quota convergence.
            .'export PMSS_HOSTNAME=stale PMSS_SKIP_HOSTNAME=1 PMSS_QUOTA_MOUNT=/stale PMSS_SKIP_QUOTA=1'."\n"
            .substr($this->script, $flagsStart, $flagsEnd - $flagsStart)
            .substr($this->script, $editorStart, $editorEnd - $editorStart)
            ."\nexport_update_bootstrap_env\n"
            .'printf "hostname=%s skip-hostname=%s quota=%s skip-quota=%s\n" '
            .'"${PMSS_HOSTNAME-unset}" "${PMSS_SKIP_HOSTNAME-unset}" '
            .'"${PMSS_QUOTA_MOUNT-unset}" "${PMSS_SKIP_QUOTA-unset}"'."\n"
            .'run_editor /fixture; printf "editor-rc=%s\n" "$?"';

        foreach ([
            [[], 'unset', 'unset', 'unset', 'unset'],
            [['--hostname=fixture.example.invalid', '--quota-mount=/home'], 'fixture.example.invalid', 'unset', '/home', 'unset'],
            [['--hostname', 'fixture.example.invalid', '--quota-mount', '/home'], 'fixture.example.invalid', 'unset', '/home', 'unset'],
            [['--skip-hostname', '--skip-quota'], 'unset', '1', 'unset', '1'],
            [['--skip-hostname', '--hostname=fixture.example.invalid', '--skip-quota', '--quota-mount=/home'], 'fixture.example.invalid', 'unset', '/home', 'unset'],
            [['--hostname=fixture.example.invalid', '--skip-hostname', '--quota-mount=/home', '--skip-quota'], 'fixture.example.invalid', 'unset', '/home', 'unset'],
            [['--hostname=fixture.example.invalid', '--skip-quota'], 'fixture.example.invalid', 'unset', 'unset', '1'],
            [['--skip-hostname', '--quota-mount=/home'], 'unset', '1', '/home', 'unset'],
        ] as $case) {
            // Non-interactive must work before or after the other options.
            foreach ([array_merge(['--non-interactive'], $case[0]), array_merge($case[0], ['--non-interactive'])] as $arguments) {
                $result = $this->pmssExecArgvCommand(array_merge(['bash', '-c', $probe, '--'], $arguments));
                $this->assertSame(0, $result['rc'], $result['output']);
                $this->assertSame(sprintf(
                    "hostname=%s skip-hostname=%s quota=%s skip-quota=%s\neditor-rc=0",
                    $case[1], $case[2], $case[3], $case[4]
                ), $result['output'], implode(' ', $arguments));
            }
        }
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

    public function testFreshInstallHandoffExitStatusAndHelperExecution(): void
    {
        // Run only the final hand-off block, with every system command replaced by a recorder.
        $start = strrpos($this->script, "\n".'if [ "$RUN_UPDATE" = true ]; then');
        $this->assertTrue($start !== false, 'Fresh-install hand-off boundary missing');
        $probe = <<<'BASH'
fixture_rc="$1"; RUN_UPDATE="$2"; UPDATE_ARGS=(release)
log_step() { :; }
log_info() { :; }
log_error() { printf '%s\n' "$*"; }
export_update_bootstrap_env() { :; }
run_cmd() {
    printf 'command:%s\n' "$1"
    if [ "$1" = /scripts/update.php ]; then return "$fixture_rc"; fi
    return 0
}
BASH;
        $probe .= substr($this->script, $start);
        $update = 'command:/scripts/update.php';
        $success = $update."\n".implode("\n", [
            'command:/scripts/util/setupRootCron.php',
            'command:/scripts/util/setupPermissions.php',
            'command:/scripts/util/quotaFix.php',
            'command:/scripts/util/ftpConfig.php',
        ]);
        foreach ([[0, 'true'], [1, 'true'], [42, 'true'], [127, 'true'], [255, 'true'], [42, 'false']] as $case) {
            $result = $this->pmssExecArgvCommand(['bash', '-c', $probe, '--', (string) $case[0], $case[1]]);
            $expectedRc = $case[1] === 'true' ? $case[0] : 0;
            $expected = $case[1] === 'false' ? '' : ($expectedRc === 0 ? $success : $update."\n"
                .'Update failed (rc='.$expectedRc.'); see /var/log/pmss/update.log');
            $this->assertSame($expectedRc, $result['rc'], $result['output']);
            $this->assertSame($expected, $result['output']);
        }
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
        $this->assertStringContainsAndOmitsStrings([], [
            'install_sysctl'.'_defaults()',
            'install_configure_temp'.'_disk_backed_mount()',
            'install_root_shell'.'_defaults()',
            'update'.'_hostname()',
            'ensure_fstab'.'_options()',
            'ensure_proc'.'_hidepid()',
            'ensure_grub_cmdline'.'_option()',
            'mount -o remount,hidepid=2 /proc',
            'mount -o remount /home',
        ], $this->script);
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
        file_put_contents($root.'/bullseye', "PRETTY_NAME=\"Debian GNU/Linux 11 (bullseye)\"\nVERSION_CODENAME=\"bullseye\"\n");

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
        $this->assertStringContainsString('WARN Debian 11: '.$root.'/apt/sources.list.d/link.list is a symlink', $result['output']);

        $second = $run('bullseye');
        $this->assertSame(0, $second['rc']);
        $this->assertStringNotContainsString('INFO', $second['output'], 'A second run changes nothing');
        $this->assertSame($original, file_get_contents($root.'/apt/sources.list.pmss-bootstrap.save'), 'Backup is not overwritten');
    }

    public function testBusterArchiveRewriteRunsBeforeBootstrapPackages(): void
    {
        $this->assertOrderedStrings([
            'bootstrap_buster_archive /etc/os-release /etc/apt',
            'run_cmd apt update',
            'ensure_packages git rsync curl wget ca-certificates unzip php php-cli',
        ], $this->script);
    }

    public function testBusterRewriteMovesOnlyBusterDebianLinesToTheArchive(): void
    {
        $root = $this->pmssMakeTempDir('pmss-install-buster-');
        mkdir($root.'/apt/sources.list.d', 0755, true);
        $original = implode("\n", [
            'deb http://deb.debian.org/debian buster main',
            'deb-src http://deb.debian.org/debian/ buster main contrib',
            'deb http://ftp.fi.debian.org/debian buster-updates main',
            'deb http://security.debian.org/debian-security buster/updates main',
            'deb [arch=amd64] https://deb.debian.org/debian buster-backports main',
            '# deb http://deb.debian.org/debian buster main',
            'deb https://mediaarea.net/repo/deb/debian buster main',
            'deb http://deb.debian.org/debian bullseye main',
        ])."\n";
        file_put_contents($root.'/apt/sources.list', $original);
        file_put_contents($root.'/target.list', "deb http://deb.debian.org/debian buster main\n");
        symlink($root.'/target.list', $root.'/apt/sources.list.d/link.list');
        file_put_contents($root.'/bookworm', "VERSION_CODENAME=bookworm\n");
        file_put_contents($root.'/buster', "PRETTY_NAME=\"Debian GNU/Linux 10 (buster)\"\nVERSION_CODENAME=buster\n");

        $script = $root.'/run.sh';
        file_put_contents($script, "log_info() { echo \"INFO \$*\"; }\nlog_warn() { echo \"WARN \$*\"; }\n"
            .$this->pmssExtractShellFunction($this->script, 'bootstrap_buster_archive')."\n"
            .'bootstrap_buster_archive "$1" "$2"'."\n");

        $run = function (string $release) use ($script, $root): array {
            return $this->pmssExecShellCommand('bash '.escapeshellarg($script).' '.escapeshellarg($root.'/'.$release).' '.escapeshellarg($root.'/apt'));
        };

        $result = $run('bookworm');
        $this->assertSame(0, $result['rc']);
        $this->assertSame($original, file_get_contents($root.'/apt/sources.list'), 'Other releases stay untouched');

        $result = $run('buster');
        $this->assertSame(0, $result['rc'], $result['output']);
        $this->assertSame(implode("\n", [
            'deb http://archive.debian.org/debian buster main',
            'deb-src http://archive.debian.org/debian buster main contrib',
            'deb http://archive.debian.org/debian buster-updates main',
            'deb http://archive.debian.org/debian-security buster/updates main',
            'deb [arch=amd64] http://archive.debian.org/debian buster-backports main',
            '# deb http://deb.debian.org/debian buster main',
            'deb https://mediaarea.net/repo/deb/debian buster main',
            'deb http://deb.debian.org/debian bullseye main',
        ])."\n", file_get_contents($root.'/apt/sources.list'));
        $this->assertSame($original, file_get_contents($root.'/apt/sources.list.pmss-bootstrap.save'), 'Backup keeps the original');
        $this->assertStringContainsString('deb.debian.org', (string) file_get_contents($root.'/target.list'), 'Symlinked lists are not followed');
        $this->assertStringContainsString('WARN Debian 10: '.$root.'/apt/sources.list.d/link.list is a symlink', $result['output']);

        $second = $run('buster');
        $this->assertSame(0, $second['rc']);
        $this->assertStringNotContainsString('INFO', $second['output'], 'A second run changes nothing');
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
