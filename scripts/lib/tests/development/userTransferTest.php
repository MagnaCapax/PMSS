<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 3).'/lib/userTransfer.php';
require_once dirname(__DIR__, 3).'/lib/user/dockerContainerIds.php';
require_once dirname(__DIR__, 3).'/lib/user/billingIds.php';

/**
 * Hermetic tests for user transfer helpers.
 */
class UserTransferTest extends TestCase
{
    public function setUp(): void
    {
        $this->pmssTrackEnvKeys(['PMSS_HOME_DIR', 'PMSS_DRY_RUN', 'PMSS_LOG_DIR']);
    }

    public function testHostnameValidationAcceptsExpectedNames(): void
    {
        $this->assertHostnameValidity([
            'lt5-1-56-138anger-core.pulsedmedia.com',
            'le4-0-78-95wheatley.pulsedmedia.com',
            '185.148.1.138',
            'a.b',
            'abc123.example',
            'a-b.c-d',
        ], true, 'valid');
    }

    public function testHostnameValidationRejectsBadNames(): void
    {
        $this->assertHostnameValidity([
            '',
            ' host',
            'host name',
            'host..name',
            '.host.example',
            'host.example.',
            '-host.example',
            'host-.example',
            'host_underscore.example',
            'host;rm -rf /',
        ], false, 'invalid');
    }

    public function testParseCliHandlesSupportedForms(): void
    {
        foreach ([
            [['userTransfer.php', 'deefbox', 'lt5-1-56-138anger-core'], ['localUser' => 'deefbox', 'remoteUser' => 'deefbox', 'hostname' => 'lt5-1-56-138anger-core.pulsedmedia.com', 'suffixAppended' => true]],
            [['userTransfer.php', 'deefbox', 'remote01', 'example.com'], ['localUser' => 'deefbox', 'remoteUser' => 'remote01', 'hostname' => 'example.com', 'suffixAppended' => false, 'mainPasses' => 31, 'finalPasses' => 3, 'sleepMin' => 60, 'sleepMax' => 360, 'verifyThreshold' => 90, 'dryRun' => false, 'printPassword' => false]],
            [['userTransfer.php', 'DeefBox', 'example.com'], ['localUser' => 'deefbox', 'remoteUser' => 'deefbox']],
            [['userTransfer.php', '--', 'deefbox', 'example.com'], ['localUser' => 'deefbox', 'remoteUser' => 'deefbox', 'hostname' => 'example.com']],
            [['userTransfer.php', '--no-sleep', 'deefbox', 'example.com'], ['sleepMin' => 0, 'sleepMax' => 0]],
            [['userTransfer.php', '--verify-threshold=95', 'deefbox', 'example.com'], ['verifyThreshold' => 95]],
        ] as [$argv, $expected]) {
            $cfg = \pmssUserTransferParseCli($argv);
            foreach ($expected as $key => $value) {
                $this->assertSame($value, $cfg[$key], 'unexpected '.$key.' for '.implode(' ', $argv));
            }
        }
    }

    public function testParseCliRejectsInvalidInputs(): void
    {
        foreach ([
            [['userTransfer.php', 'deef_box', 'example.com'], 'Invalid username'],
            [['userTransfer.php', 'toolongname', 'example.com'], 'Invalid username'],
            [['userTransfer.php', 'deefbox', 'bad host'], 'Invalid hostname'],
            [['userTransfer.php', 'deefbox', 'example.com', '--main-passes'], 'requires a value'],
            [['userTransfer.php', '--sleep-min=abc', 'deefbox', 'example.com'], 'expected integer'],
            [['userTransfer.php', '--verify-threshold=101', 'deefbox', 'example.com'], 'verify-threshold'],
            [['userTransfer.php', '--sleep-min=10', '--sleep-max=5', 'deefbox', 'example.com'], 'sleep-max must be'],
            [['userTransfer.php', '--main-passes=501', 'deefbox', 'example.com'], 'main-passes'],
        ] as [$argv, $messageFragment]) {
            $this->assertThrowsRuntime(static function () use ($argv): void {
                \pmssUserTransferParseCli($argv);
            }, $messageFragment);
        }
    }

    public function testParseCliHelpReturnsStableUsageText(): void
    {
        $expected = "Usage:\n"
            ."  /scripts/util/userTransfer.php LOCAL_USERNAME REMOTE_HOSTNAME\n"
            ."  /scripts/util/userTransfer.php LOCAL_USERNAME REMOTE_USERNAME REMOTE_HOSTNAME\n\n"
            ."Options:\n"
            ."  --main-passes N     Number of passes for the main rsync (default 31)\n"
            ."  --final-passes N    Number of passes for the final rsync (default 3)\n"
            ."  --sleep-min N       Minimum sleep seconds between passes (default 60)\n"
            ."  --sleep-max N       Maximum sleep seconds between passes (default 360)\n"
            ."  --verify-threshold N Warn if local size is below N% of remote (default 90)\n"
            ."  --no-sleep          Disable sleeping between passes\n"
            ."  --dry-run           Log planned steps without executing commands\n"
            ."  --print-password    Print the supplied password at the end (unsafe)\n"
            ."  --help, -h          Show this help\n\n"
            ."Notes:\n"
            ."  - If REMOTE_HOSTNAME does not contain a dot, \".pulsedmedia.com\" is appended.\n"
            ."  - Password can be provided via env: PMSS_USER_TRANSFER_PASSWORD\n";

        try {
            \pmssUserTransferParseCli(['userTransfer.php', '--help']);
            $this->fail('expected help path to throw RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertEquals(0, $e->getCode());
            $this->assertEquals($expected, $e->getMessage());
            $this->assertStringContainsString('--print-password    Print the supplied password at the end (unsafe)', $e->getMessage());
        }
    }

    public function testParseCliAcceptsSplitLongOptionValuesAndFlags(): void
    {
        $cfg = \pmssUserTransferParseCli([
            'userTransfer.php',
            '--main-passes',
            '7',
            '--final-passes',
            '2',
            '--dry-run',
            '--print-password',
            'deefbox',
            'example.com',
        ]);

        $this->assertEquals(7, $cfg['mainPasses']);
        $this->assertEquals(2, $cfg['finalPasses']);
        $this->assertTrue($cfg['dryRun']);
        $this->assertTrue($cfg['printPassword']);
        $this->assertSame('33448dbf45fb18a2927929b8432652ed3eaaef39908fd9e27f8535de57377990', hash('sha256', json_encode($cfg)));
    }

    public function testGeneratedProbeScriptsContainExpectedFragments(): void
    {
        $cfg = $this->baseConfig();

        foreach ([
            [\pmssUserTransferBuildRsyncMain($cfg), ['rsync -aHAX --numeric-ids', ':/home/deefbox/', '/home/deefbox/', '.rtorrent.rc', '.qbittorrentPort', '.config/pmss-user.json', '.trafficDataIngress', '.trafficDataIngressLocal', '.resourceData', '*.pmss-tmp-*', '/.billingId', '/.billingServiceId', '/.billingClientId'], ['rsync -av' => 'expected no verbose per-file rsync logging', '--exclude={' => 'expected no brace-expanded excludes']],
            [\pmssUserTransferBuildRsyncFinal($cfg), ["rsync -aHAX --numeric-ids", "'-R'", ':/home/deefbox/./session', ':/home/deefbox/./www/public'], ['rsync -av' => 'expected no verbose per-file rsync logging', '{session' => 'expected no brace-expanded sources']],
            [\pmssUserTransferBuildAuthProbe($cfg), ['ssh -F /dev/null -o Compression=no', '-o NumberOfPasswordPrompts=1', "-l 'deefbox'", "'example.com'", "'/bin/true'"], []],
            [\pmssUserTransferBuildRemoteSizeProbe($this->baseConfig(['remoteUser' => 'remote01', 'verifyThreshold' => 90])), ['-o NumberOfPasswordPrompts=1', "'example.com'", '/home/remote01/', 'du -sbx -- "$home"', 'find "$home" -xdev -type f -printf .', 'printf "bytes=%s files=%s\\n"'], ['%p' => 'expected no path-printing stat probe output']],
        ] as [$script, $required, $forbidden]) {
            $this->assertStringContainsAndOmitsStrings($required, $forbidden, $script);
        }
    }

    public function testFinalPassExcludesSourceRtorrentLock(): void
    {
        foreach ([false, true] as $namespaceRsync) {
            $script = \pmssUserTransferBuildRsyncFinal($this->baseConfig(['namespaceRsync' => $namespaceRsync]));
            $this->assertStringContainsString('/session/rtorrent.lock', $script);
            $this->assertStringContainsString(':/home/deefbox/./session', $script);
        }
    }

    public function testAllRsyncPassesKeepExactlyOneFilesystemBoundary(): void
    {
        foreach ([false, true] as $namespaceRsync) {
            $cfg = $this->baseConfig(['namespaceRsync' => $namespaceRsync]);
            foreach ([\pmssUserTransferBuildRsyncMain($cfg), \pmssUserTransferBuildRsyncFinal($cfg)] as $script) {
                $this->assertSame(1, substr_count($script, '--one-file-system'));
                $this->assertStringContainsString('rsync -aHAX --numeric-ids --stats --one-file-system', $script);
            }
        }
    }

    public function testHomeStatsMountSummaryIsPrivateAndFailsSoft(): void
    {
        $root = $this->pmssMakeTempDir('pmss-transfer-mount-summary-');
        $home = $root.'/home';
        mkdir($home);
        file_put_contents($home.'/payload', 'owned');
        $mountinfo = $root.'/mountinfo';
        $base = "1 0 8:1 / / rw - ext4 /dev/example rw\n"
            ."2 1 8:2 / $home rw - ext4 /dev/home rw\n";
        $nested = "3 2 0:99 / $home/private\\040remote rw shared:1 - fuse.rclone private:remote rw\n"
            ."4 3 0:100 / $home/private\\040remote/nested rw - fuse.mergerfs private rw\n"
            ."5 2 8:2 / $home/same-device rw - ext4 /dev/home rw\n"
            ."6 1 0:101 / {$home}-sibling rw - tmpfs tmpfs rw\n";
        foreach ([[$base, 'nested_mounts=0'], [$base.$nested, 'nested_mounts=2'], ['', 'nested_mounts=unavailable'], [null, 'nested_mounts=unavailable']] as [$contents, $expected]) {
            if ($contents === null) {
                unlink($mountinfo);
            } else {
                file_put_contents($mountinfo, $contents);
            }
            $shell = str_replace('/proc/self/mountinfo', escapeshellarg($mountinfo), \pmssUserTransferBuildHomeStatsShell($home));
            $result = \pmssCommandCapture(\pmssBuildCommand('bash', ['-ec', $shell]));
            $this->assertSame(0, $result['rc'], $result['stderr']);
            $this->assertSame('', $result['stderr']);
            $this->assertSame(1, \pmssUserTransferParseHomeStats($result['stdout'])['files']);
            $this->assertStringContainsString($expected, $result['stdout']);
            $this->assertStringNotContainsString('private', $result['stdout']);
            $this->assertStringNotContainsString($home, $result['stdout']);
            if ($contents === $base.$nested) {
                $this->assertStringContainsString('mount_type_fuse.rclone=1', $result['stdout']);
                $this->assertStringContainsString('mount_type_fuse.mergerfs=1', $result['stdout']);
                $this->assertStringNotContainsString('mount_type_ext4', $result['stdout']);
            }
        }
    }

    public function testMountSummaryPersistsWithLocalAndExpectLineEndings(): void
    {
        $logDir = $this->pmssMakeTempDir('pmss-transfer-mount-log-');
        foreach (["\n", "\r\n"] as $ending) {
            $command = \pmssBuildCommand('printf', ['%s', 'bytes=5 files=1'.$ending
                .'nested_mounts=2 mount_type_tmpfs=2'.$ending]);
            list($stats, $output) = $this->pmssCaptureStdout(static function () use ($command): ?array {
                return \pmssUserTransferMeasureHomeStats('Measuring fixture home size', $command);
            }, ['PMSS_DRY_RUN' => '0', 'PMSS_LOG_DIR' => $logDir]);
            $this->assertSame(['bytes' => 5, 'files' => 1], $stats);
            $this->assertStringContainsString('[INFO] Measuring fixture home size: nested_mounts=2 mount_type_tmpfs=2', $output);
        }
    }

    /** Real mounts stay inside a disposable user/mount namespace and temporary fixture. */
    public function testOneFilesystemCopyAndStatsSkipNestedMounts(): void
    {
        $probe = \pmssCommandCapture('command -v rsync >/dev/null && unshare -Urnm true');
        if ($probe['rc'] !== 0) {
            throw new SkipTest('requires rsync and unprivileged user/mount namespaces');
        }
        $root = $this->pmssMakeTempDir('pmss-transfer-mount-copy-');
        $source = $root.'/source';
        $target = $root.'/target';
        $final = $root.'/final';
        $this->pmssEnsureDir($source.'/data/remote view');
        $this->pmssEnsureDir($source.'/.local/mounted');
        $this->pmssEnsureDir($source.'/bind');
        file_put_contents($source.'/data/payload', 'owned');
        $shell = 'set -eu; mount --make-rprivate /; '
            .'mount -t tmpfs tmpfs '.escapeshellarg($source.'/data/remote view').'; '
            .'mount -t tmpfs tmpfs '.escapeshellarg($source.'/.local/mounted').'; '
            .'head -c 131072 /dev/zero > '.escapeshellarg($source.'/data/remote view/remote').'; '
            .'printf remote > '.escapeshellarg($source.'/.local/mounted/remote').'; '
            .'mount --bind '.escapeshellarg($source.'/data').' '.escapeshellarg($source.'/bind').'; '
            .'rsync -aHAX --one-file-system '.escapeshellarg($source.'/').' '.escapeshellarg($target.'/').'; '
            .'rsync -aHAX --one-file-system -R '.escapeshellarg($source.'/./.local').' '.escapeshellarg($final.'/').'; '
            .\pmssUserTransferBuildHomeStatsShell($source);
        $result = \pmssCommandCapture(\pmssBuildCommand('unshare', ['-Urnm', 'bash', '-c', $shell]));
        $this->assertSame(0, $result['rc'], $result['stderr']);
        $this->assertSame('owned', file_get_contents($target.'/data/payload'));
        $this->assertSame('owned', file_get_contents($target.'/bind/payload'));
        foreach ([$target.'/data/remote view', $target.'/.local/mounted', $final.'/.local/mounted'] as $mount) {
            $this->assertSame(['.', '..'], scandir($mount));
        }
        $stats = \pmssUserTransferParseHomeStats($result['stdout']);
        $this->assertSame(2, $stats['files']);
        $this->assertTrue($stats['bytes'] < 131072, 'mounted payload enlarged the home size');
        $this->assertStringContainsString('nested_mounts=2 mount_type_tmpfs=2', $result['stdout']);
    }

    public function testCrossUsernamePayloadOwnershipCheckUsesTargetIds(): void
    {
        $command = \pmssUserTransferBuildPayloadOwnershipCheckCommand('/home/newuser/data', 1201, 1202);

        $this->assertStringContainsAllStrings([
            'newuser/data',
            '-not -type l',
            '-not -uid 1201 -o -not -gid 1202',
            'mismatch=$(',
            'find_rc=$?',
            'test -z "$mismatch"',
        ], $command);
        $this->assertStringNotContainsString('chown', $command);
    }

    public function testCrossUsernamePayloadAccessCheckRunsAsTargetUser(): void
    {
        $shell = \pmssUserTransferBuildPayloadAccessCheckShell('/home/newuser/data');

        $this->assertStringContainsAllStrings([
            "find '/home/newuser/data' -type f -print -quit",
            'if [ -n "$sample" ]; then test -r "$sample";',
            "else test -x '/home/newuser/data'; fi",
        ], $shell);
        $this->assertStringNotContainsString('sudo', $shell);
    }

    public function testCrossUsernamePayloadGateStaysAfterPermissionNormalisation(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/lib/userTransfer/postSetup.php',
            [
                "'Normalising user permissions'",
                'pmssUserTransferVerifyPayloadOwnership($localUser, $home);',
                'pmssUserTransferRequestRtorrentRestart',
            ],
            'Cross-username payload gate order is unsafe: '
        );
        $this->pmssAssertRepoFileContainsString(
            'scripts/lib/userTransfer/postSetup.php',
            'if ($remoteUser !== $localUser) {',
            'Cross-username payload gate lost its username guard: '
        );
    }

    public function testRtorrentRestartVerifiesOnlyAfterSuccessfulCommand(): void
    {
        $home = (string) getenv('PMSS_TEST_TEMP_ROOT').'/transfer-restart';
        mkdir($home, 0700);
        file_put_contents($home.'/.rtorrentRestart.php', '<?php');
        $commands = [];
        $runner = static function (string $description, string $command) use (&$commands): int {
            $commands[] = [$description, $command];
            return 7;
        };

        ob_start();
        \pmssUserTransferRunRtorrentRestart($home, 'alice', $runner);
        $failedOutput = (string) ob_get_clean();
        $this->assertEquals(1, count($commands));
        $this->assertStringContainsString('restart command failed; skipping reload verification', $failedOutput);
        $this->assertStringNotContainsString('verification skipped', $failedOutput);

        ob_start();
        \pmssUserTransferRunRtorrentRestart($home, 'alice', static function (): int { return 0; });
        $successOutput = (string) ob_get_clean();
        $this->assertStringContainsString('verification skipped: no session torrent files found', $successOutput);

        unlink($home.'/.rtorrentRestart.php');
        ob_start();
        \pmssUserTransferRunRtorrentRestart($home, 'alice', $runner);
        $missingOutput = (string) ob_get_clean();
        $this->assertEquals(1, count($commands));
        $this->assertStringContainsString('restart script missing or unsafe', $missingOutput);
    }

    public function testGeneratedScriptsKeepSharedSshFlagsAligned(): void
    {
        $cfg = $this->baseConfig();
        $sharedFlags = 'ssh -F /dev/null -o Compression=no -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no';

        foreach ([
            \pmssUserTransferBuildRsyncMain($cfg),
            \pmssUserTransferBuildRsyncFinal($cfg),
            \pmssUserTransferBuildAuthProbe($cfg),
            \pmssUserTransferBuildRemoteSizeProbe($cfg + ['verifyThreshold' => 90]),
        ] as $script) {
            $this->assertStringContainsString($sharedFlags, $script);
        }
    }

    public function testParseDuBytesHandlesReadableAndUnreadableOutput(): void
    {
        foreach ([
            "12345\t/home/deefbox/\n" => 12345,
            "bytes=12345 files=67\n" => 12345,
            "du: cannot access '/home/deefbox': Permission denied\n" => null,
        ] as $output => $expected) {
            $this->assertSame($expected, \pmssUserTransferParseDuBytes($output), $output);
        }
    }

    public function testParseHomeStatsHandlesAggregateOutput(): void
    {
        foreach ([
            "bytes=12345 files=67\n" => ['bytes' => 12345, 'files' => 67],
            "bytes=0 files=0\n" => ['bytes' => 0, 'files' => 0],
            "12345\t/home/deefbox/\n" => null,
            "bytes=12345\n" => null,
            "bytes=abc files=67\n" => null,
        ] as $output => $expected) {
            $this->assertSame($expected, \pmssUserTransferParseHomeStats($output), $output);
        }
    }

    public function testEvaluateCompletenessHandlesThresholdCases(): void
    {
        foreach ([
            [1000, 850, 90, ['remoteBytes' => 1000, 'localBytes' => 850, 'verifyThreshold' => 90, 'localPercent' => 85.0], 'below threshold'],
            [1000, 950, 90, null, 'healthy transfer'],
            [0, 0, 90, null, 'zero remote size'],
        ] as [$remoteBytes, $localBytes, $threshold, $expected, $label]) {
            $this->assertEquals($expected, \pmssUserTransferEvaluateCompleteness($remoteBytes, $localBytes, $threshold), 'unexpected result for '.$label);
        }
    }

    public function testBuildExpectWrapperUsesEnvPassword(): void
    {
        $script = \pmssUserTransferBuildExpectWrapper();

        $this->assertStringContainsString('env(PMSS_USER_TRANSFER_PASSWORD)', $script);
        $this->assertStringNotContainsString('send "{$', $script, 'expected password not embedded in script');
    }

    public function testGeneratedTransferScriptsMatchSnapshot(): void
    {
        $cfg = $this->baseConfig();
        $expectedScratchPaths = ['expect' => '/root/pmss-userTransfer-<generated>/transfer.expect', 'authProbe' => '/root/pmss-userTransfer-<generated>/auth-probe.sh', 'identityProbe' => '/root/pmss-userTransfer-<generated>/identity-probe.sh', 'namespaceProbe' => '/root/pmss-userTransfer-<generated>/namespace-probe.sh', 'mainScript' => '/root/pmss-userTransfer-<generated>/rsync-main.sh', 'finalScript' => '/root/pmss-userTransfer-<generated>/rsync-final.sh', 'remoteSizeScript' => '/root/pmss-userTransfer-<generated>/remote-size.sh', 'qbittorrentProbeScript' => '/root/pmss-userTransfer-<generated>/qbittorrent-categories.sh', 'dockerQuiesceScript' => '/root/pmss-userTransfer-<generated>/docker-quiesce.sh', 'dockerIds' => '/root/pmss-userTransfer-<generated>/docker-ids', 'dockerStartScript' => '/root/pmss-userTransfer-<generated>/docker-start.sh', 'qbittorrentConfig' => '/root/pmss-userTransfer-<generated>/qBittorrent.conf', 'qbittorrentCategories' => '/root/pmss-userTransfer-<generated>/categories.json'];
        $expectedPayloadKeys = ['expect', 'authProbe', 'identityProbe', 'namespaceProbe', 'mainScript', 'finalScript', 'remoteSizeScript', 'qbittorrentProbeScript', 'dockerQuiesceScript'];
        $expectedAuth = <<<'SNAP'
#!/bin/bash
set -e
ssh -F /dev/null -o Compression=no -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -o ConnectTimeout=20 -o NumberOfPasswordPrompts=1 -l 'deefbox' 'example.com' '/bin/true'
SNAP;

        $this->assertStringContainsAllStrings(['--numeric-ids', '--usermap=', '--groupmap=', 'unshare -U', 'env -u PMSS_USER_TRANSFER_PASSWORD', '/.billingId'], \pmssUserTransferBuildRsyncMain($cfg));
        $this->assertStringContainsAllStrings(['--numeric-ids', '-R', 'unshare -U', '/.billingClientId'], \pmssUserTransferBuildRsyncFinal($cfg));
        $this->assertEquals($expectedAuth."\n", \pmssUserTransferBuildAuthProbe($cfg));
        $this->assertSame($expectedScratchPaths, \pmssUserTransferScratchPaths('/root/pmss-userTransfer-<generated>/'));
        $this->assertSame($expectedPayloadKeys, array_keys(\pmssUserTransferScratchPayloads($cfg)));
        $this->assertStringContainsString('/etc/sub$kind', \pmssUserTransferScratchPayloads($cfg)['identityProbe']);
    }

    public function testDockerQuiesceProbeBoundsAndStopsSourceSet(): void
    {
        $script = \pmssUserTransferBuildDockerQuiesce($this->baseConfig(), '/root/scratch/docker-ids');
        $this->assertStringContainsAllStrings([
            "#!/bin/bash\nset -e\numask 077\n", '[ -S "$S" ]', 'command -v docker',
            'docker ps -q --no-trunc | head -n 500', 'docker stop -t 30 $ids',
            'printf "%s\n" "$ids"', "| head -c 65536 > '/root/scratch/docker-ids'",
        ], $script);
        $this->assertStringNotContainsString('pipefail', $script);
    }

    public function testDockerStartProbeQuotesValidatedIds(): void
    {
        $id = str_repeat('a', 64);
        $script = \pmssUserTransferBuildDockerStartScript($this->baseConfig(), [$id]);
        $this->assertStringContainsString('docker start', $script);
        $this->assertStringContainsString("'{$id}'", $script);
        foreach ([';id', '$(id)', '`id`', strtoupper($id)] as $invalid) {
            $this->assertThrowsRuntime(static function () use ($invalid): void {
                \pmssUserTransferBuildDockerStartScript(['remoteUser' => 'user', 'hostname' => 'example.com'], [$invalid]);
            }, 'Invalid Docker container ID');
        }
    }

    public function testDockerIdParsingKeepsUniqueBoundedExactIds(): void
    {
        $a = str_repeat('a', 64);
        $b = str_repeat('b', 64);
        $raw = implode("\n", [$a, strtoupper($a), str_repeat('a', 63), str_repeat('a', 65), $a,
            ';id', '$(id)', '`id`', ' a ', "x\n y", $b]);
        $this->assertSame([$a, $b], \pmssUserTransferValidDockerIds($raw));
        $this->assertSame([$a], \pmssUserTransferValidDockerIds($raw, 1));
        $this->assertSame([], \pmssUserTransferValidDockerIds($raw, 0));
        $many = [];
        for ($i = 0; $i < 501; $i++) {
            $many[] = str_pad(dechex($i), 64, '0', STR_PAD_LEFT);
        }
        $this->assertSame(array_slice($many, 0, 500), \pmssUserTransferValidDockerIds(implode("\n", $many)));
    }

    public function testDockerFinalPassReturnCodes(): void
    {
        foreach ([0, 23, 24] as $rc) {
            $this->assertTrue(\pmssUserTransferDockerRcTransferred($rc));
        }
        foreach ([12, 255] as $rc) {
            $this->assertFalse(\pmssUserTransferDockerRcTransferred($rc));
        }
    }

    public function testDockerContainerStartArgumentsRejectInvalidOrOversizedSets(): void
    {
        $id = str_repeat('c', 64);
        $this->assertTrue(\pmssUserDockerContainerIdsValid([$id]));
        $this->assertTrue(\pmssUserDockerContainerIdsValid(array_fill(0, 500, $id)));
        foreach ([[], [$id, ';id'], [strtoupper($id)], array_fill(0, 501, $id)] as $invalid) {
            $this->assertFalse(\pmssUserDockerContainerIdsValid($invalid));
        }
    }

    public function testBuildQbittorrentCategoryProbeWritesRemoteMetadataToScratchFiles(): void
    {
        $script = \pmssUserTransferBuildQbittorrentCategoryProbe(
            $this->baseConfig(),
            '/root/scratch/qBittorrent.conf',
            '/root/scratch/categories.json'
        );

        $this->assertStringContainsAllStrings([
            "ssh -F /dev/null -o Compression=no",
            "-o NumberOfPasswordPrompts=1",
            "-l 'deefbox'",
            "'example.com'",
            '/home/deefbox/.config/qBittorrent/qBittorrent.conf',
            '/home/deefbox/.config/qBittorrent/categories.json',
            "> '/root/scratch/qBittorrent.conf'",
            "> '/root/scratch/categories.json'",
        ], $script);
    }

    public function testLegacyQbittorrentCategoriesExtractsQtVariantMap(): void
    {
        $variant = $this->pmssQtSettingsEscapedBytes($this->pmssQtVariantStringMap([
            'Movies' => '/home/olduser/data/Movies',
            'sonarr' => '',
        ]));
        $config = "[Preferences]\nSession\\Categories=@Variant(ignored)\n[BitTorrent]\nSession\\Categories=@Variant({$variant})\n";

        $categories = \pmssUserTransferQbittorrentLegacyCategoriesFromConfig($config, [
            'localUser' => 'newuser',
            'remoteUser' => 'olduser',
        ]);

        $this->assertEquals([
            'Movies' => ['save_path' => '/home/newuser/data/Movies'],
            'sonarr' => ['save_path' => ''],
        ], $categories);
    }

    public function testLegacyQbittorrentCategoriesRejectsMalformedVariant(): void
    {
        $this->assertEquals([], \pmssUserTransferQbittorrentLegacyCategoriesFromConfig(
            "[BitTorrent]\nSession\\Categories=@Variant(garbage)\n",
            $this->baseConfig()
        ));
    }

    public function testQbittorrentCategoriesMergeWritesJsonWithoutOverwritingExisting(): void
    {
        $target = $this->pmssMakeTempPath('pmss-userTransfer-qbit-categories-').'/categories.json';
        $added = \pmssUserTransferQbittorrentCategoriesMerge($target, [
            'Movies' => ['save_path' => '/existing'],
        ], [
            'Movies' => ['save_path' => '/source'],
            'Books' => ['savePath' => '/books'],
        ]);

        $decoded = json_decode((string) file_get_contents($target), true);

        $this->assertEquals(1, $added);
        $this->assertEquals('/existing', $decoded['Movies']['save_path']);
        $this->assertEquals('/books', $decoded['Books']['save_path']);
        $this->assertTrue(!isset($decoded['Books']['savePath']), 'expected API-style savePath key to be normalized');
    }

    public function testQbittorrentCategoriesJsonReadRejectsInvalidJson(): void
    {
        $path = $this->pmssMakeTempPath('pmss-userTransfer-qbit-invalid-');
        file_put_contents($path, '{not json');

        $this->assertSame(null, \pmssUserTransferQbittorrentCategoriesJsonRead($path));
    }

    public function testSharedRsyncCommandBuilderMatchesSnapshot(): void
    {
        $cfg = $this->baseConfig();
        $script = \pmssUserTransferBuildRsyncCommand($cfg, ['/home/deefbox/', '/home/deefbox/.local'], ['www', 'session']);
        $this->assertStringContainsAllStrings(['rsync -aHAX --numeric-ids', 'www', 'session', 'unshare -U', 'wait "$child"'], $script);
    }

    public function testNamespaceRsyncKeepsPassOptionsAndUsesContainerRelativeIds(): void
    {
        $cfg = $this->baseConfig(['namespaceRsync' => true]);
        $main = \pmssUserTransferBuildRsyncMain($cfg);
        $final = \pmssUserTransferBuildRsyncFinal($cfg);
        foreach ([$main, $final] as $script) {
            $this->assertStringContainsAllStrings([
                'rsync -aHAX --numeric-ids --stats',
                '--rsync-path=',
                'env -u PMSS_USER_TRANSFER_PASSWORD setpriv --reuid=1200 --regid=1200 --clear-groups',
                'unshare --map-auto --map-root-user rsync',
                '--filter=', '/.billingId', '/.billingServiceId', '/.billingClientId',
                'mount --make-rprivate /',
            ], $script);
            $this->assertStringNotContainsString('--usermap=', $script);
            $this->assertStringNotContainsString('--groupmap=', $script);
            $this->assertStringNotContainsString('unshare -U bash', $script);
        }
        $this->assertStringContainsAllStrings(['.config/qBittorrent/qBittorrent.conf', '.resourceData', '*.pmss-tmp-*', ':/home/deefbox/'], $main);
        $this->assertStringContainsAllStrings(["'-R'", ':/home/deefbox/./session', ':/home/deefbox/./www/public'], $final);
    }

    public function testNamespaceProbeFailureKeepsExistingRsyncMapping(): void
    {
        $probe = \pmssUserTransferBuildNamespaceProbe($this->baseConfig());
        $this->assertStringContainsAllStrings([
            'ssh -F /dev/null', "-l 'deefbox'", "'example.com'",
            "'unshare --map-auto --map-root-user true'", '>/dev/null 2>&1',
        ], $probe);
        foreach ([[1, 0], [0, 1], [127, 0], [0, 255], [1, 1]] as [$localRc, $remoteRc]) {
            $this->assertFalse(\pmssUserTransferNamespaceRsyncAvailable($localRc, $remoteRc));
            $cfg = $this->baseConfig(['namespaceRsync' => \pmssUserTransferNamespaceRsyncAvailable($localRc, $remoteRc)]);
            $script = \pmssUserTransferBuildRsyncFinal($cfg);
            $this->assertStringContainsAllStrings(['--numeric-ids', '--usermap=', '--groupmap=', 'unshare -U bash', 'wait "$child"', "'-R'"], $script);
            $this->assertStringNotContainsString('--rsync-path=', $script);
        }
        $this->assertTrue(\pmssUserTransferNamespaceRsyncAvailable(0, 0));
    }

    public function testSourceIdentityAndSubordinateMapsRejectUnsafeInputs(): void
    {
        $frame = static function (string $body): string {
            return "password: \nPMSS_TRANSFER_IDENTITY_BEGIN ".$body."PMSS_TRANSFER_IDENTITY_END\nConnection closed.\n";
        };
        $identity = \pmssUserTransferParseIdentity($frame("uid=2200 gid=2300\nsubuid=110000:2\nsubgid=120000:2\n"));
        $this->assertSame([110000, 2], $identity['subuid'][0]);
        $this->assertSame("0 3000 1\n110000 200000 2\n", \pmssUserTransferIdMap($identity['subuid'], [[200000, 2]], 3000));
        foreach (["", "uid=0 gid=0\n", "uid=2200 gid=2300\nsubuid=110000:0\n",
            "uid=2200 gid=2300\nsubuid=110000:2\nsubuid=110001:2\n",
            "uid=2200 gid=2300\nsubuid=110000:2\ninvalid\n",
            "uid=110000 gid=2300\nsubuid=110000:2\n",
            "uid=18446744073709551615 gid=2300\n"] as $invalid) {
            $this->assertThrowsRuntime(static function () use ($invalid, $frame): void {
                \pmssUserTransferParseIdentity($invalid === '' ? '' : $frame($invalid));
            }, 'Source');
        }
        $this->assertThrowsRuntime(static function () use ($identity): void {
            \pmssUserTransferIdMap($identity['subuid'], [[200000, 1]], 3000);
        }, 'too small');
        $this->assertThrowsRuntime(static function () use ($identity): void {
            \pmssUserTransferIdMap($identity['subuid'], [[200000, 2], [200001, 2]], 3000);
        }, 'overlaps');
    }

    /** Root-only local rehearsal: no network or host account mutation. */
    public function testNamespacedReceiverPreservesPayloadAndBillingFallback(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            throw new SkipTest('requires root for user-namespace ownership mapping');
        }
        $account = posix_getpwnam('nobody');
        if (!is_array($account) || !is_executable('/usr/bin/unshare') || !is_executable('/usr/bin/setpriv')) {
            throw new SkipTest('receiver prerequisites unavailable');
        }
        $root = $this->pmssMakeTempPath('pmss-userTransfer-namespace-');
        mkdir($root, 0755);
        $source = $root.'/source';
        $target = $root.'/target';
        $outside = $root.'/outside';
        mkdir($source, 0755);
        mkdir($source.'/data', 0755);
        mkdir($target, 0755);
        mkdir($outside, 0755);
        chmod($root, 0755);
        chmod($source, 0755);
        chmod($source.'/data', 0755);
        chown($source, $account['uid']);
        chgrp($source, $account['gid']);
        chown($source.'/data', $account['uid']);
        chgrp($source.'/data', $account['gid']);
        chown($outside, $account['uid']);
        chgrp($outside, $account['gid']);
        chown($target, $account['uid']);
        chgrp($target, $account['gid']);
        file_put_contents($target.'/.billingId', "98765\n");
        chmod($target.'/.billingId', 0640);
        foreach (['.billingId', '.billingServiceId', '.billingClientId'] as $marker) {
            file_put_contents($source.'/'.$marker, "1\n");
        }
        file_put_contents($source.'/data/payload', 'payload');
        chown($source.'/data/payload', $account['uid']);
        chgrp($source.'/data/payload', $account['gid']);
        link($source.'/data/payload', $source.'/data/hardlink');
        file_put_contents($source.'/data/subordinate', 'subordinate');
        chown($source.'/data/subordinate', 100000);
        chgrp($source.'/data/subordinate', 100000);
        chmod($source.'/data/subordinate', 0644);
        file_put_contents($source.'/data/setuid', 'not executable code');
        chown($source.'/data/setuid', $account['uid']);
        chgrp($source.'/data/setuid', $account['gid']);
        chmod($source.'/data/setuid', 04755);
        $hasFakeSuper = is_executable('/usr/bin/setfattr') && is_executable('/usr/bin/getfattr');
        if ($hasFakeSuper) {
            exec('setfattr -n '.escapeshellarg('user.rsync.%stat').' -v '.escapeshellarg('0:0:04755')
                .' '.escapeshellarg($source.'/data/setuid'), $ignored, $xattrRc);
            $this->assertSame(0, $xattrRc, 'fake-super fixture xattr could not be set');
        }
        symlink($outside, $target.'/escape');
        mkdir($source.'/escape');
        chmod($source.'/escape', 0755);
        chown($source.'/escape', $account['uid']);
        chgrp($source.'/escape', $account['gid']);
        file_put_contents($source.'/escape/blocked', 'must stay inside target');
        chown($source.'/escape/blocked', $account['uid']);
        chgrp($source.'/escape/blocked', $account['gid']);
        $cfg = ['receiver' => ['uid' => $account['uid'], 'gid' => $account['gid'], 'home' => $target,
            'uidMap' => "0 {$account['uid']} 1\n110000 100000 1\n",
            'gidMap' => "0 {$account['gid']} 1\n120000 100000 1\n",
            'sourceUid' => 2200, 'sourceGid' => 2300]];
        $rsync = 'rsync -aHAX --numeric-ids --usermap=2200:0 --groupmap=2300:0 '
            .'--filter='.escapeshellarg('-x user.rsync.*').' --filter='.escapeshellarg('-x! user.*').' '
            .'--exclude=/.billingId --exclude=/.billingServiceId --exclude=/.billingClientId '
            .escapeshellarg($source.'/').' '.escapeshellarg($target.'/');
        $script = \pmssUserTransferBuildReceiver($cfg, $rsync);
        $output = [];
        exec('bash -c '.escapeshellarg("set -e\n".$script).' 2>&1', $output, $rc);
        $this->assertSame(0, $rc, implode("\n", $output));
        $this->assertSame('payload', file_get_contents($target.'/data/payload'));
        $this->assertSame(fileinode($target.'/data/payload'), fileinode($target.'/data/hardlink'));
        $this->assertSame($account['uid'], fileowner($target.'/data/payload'));
        $this->assertSame(100000, fileowner($target.'/data/subordinate'));
        $this->assertSame(100000, filegroup($target.'/data/subordinate'));
        $this->assertSame(0, fileowner($target.'/.billingId'));
        $this->assertSame(98765, \pmssUserBillingServiceIdRead($target, true));
        $this->assertFalse(file_exists($target.'/.billingServiceId'));
        $this->assertFalse(file_exists($target.'/.billingClientId'));
        $this->assertTrue(fileowner($target.'/data/setuid') !== 0);
        if ($hasFakeSuper) {
            exec('getfattr -n '.escapeshellarg('user.rsync.%stat').' '.escapeshellarg($target.'/data/setuid').' 2>/dev/null', $ignored, $xattrRc);
            $this->assertTrue($xattrRc !== 0, 'fake-super metadata must not land on the target');
        }
        $this->assertFalse(file_exists($outside.'/blocked'));
        $escape = 'touch '.escapeshellarg($outside.'/escaped');
        exec('bash -c '.escapeshellarg("set -e\n".\pmssUserTransferBuildReceiver($cfg, $escape)).' 2>&1', $output, $escapeRc);
        $this->assertTrue($escapeRc !== 0, 'receiver mount view must refuse writes outside the home');
        $this->assertFalse(file_exists($outside.'/escaped'));
        $noPassword = 'bash -c '.escapeshellarg('test -z "${PMSS_USER_TRANSFER_PASSWORD+x}"');
        exec('PMSS_USER_TRANSFER_PASSWORD=secret bash -c '
            .escapeshellarg("set -e\n".\pmssUserTransferBuildReceiver($cfg, $noPassword)).' 2>&1', $output, $passwordRc);
        $this->assertSame(0, $passwordRc, 'account-owned receiver inherited the password');
        $link = 'ln '.escapeshellarg($target.'/.billingId').' '.escapeshellarg($target.'/data/cross-tenant-link');
        exec('bash -c '.escapeshellarg("set -e\n".\pmssUserTransferBuildReceiver($cfg, $link)).' 2>&1', $output, $linkRc);
        $this->assertTrue($linkRc !== 0, 'namespace receiver must not hardlink a foreign-owned file');

        // The attacker replaces a directory only after the receiver has started.
        mkdir($target.'/swap', 0755);
        chown($target.'/swap', $account['uid']);
        $swapShell = 'touch '.escapeshellarg($target.'/.ready')
            .'; while [ ! -L '.escapeshellarg($target.'/swap').' ]; do sleep 0.01; done; '
            .'touch '.escapeshellarg($target.'/swap/escaped');
        $swapCommand = 'bash -c '.escapeshellarg($swapShell);
        $process = proc_open('bash -c '.escapeshellarg("set -e\n".\pmssUserTransferBuildReceiver($cfg, $swapCommand)),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertTrue(is_resource($process), 'receiver process must start');
        for ($i = 0; $i < 500 && !file_exists($target.'/.ready'); $i++) {
            usleep(10000);
        }
        $this->assertTrue(file_exists($target.'/.ready'), 'receiver did not reach the swap point');
        $this->assertTrue(rename($target.'/swap', $target.'/parked'), 'attacker rename failed');
        $this->assertTrue(symlink($outside, $target.'/swap'), 'attacker symlink failed');
        $swapOutput = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertTrue(proc_close($process) !== 0, (string) $swapOutput);
        $this->assertFalse(file_exists($outside.'/escaped'));
    }

    public function testRewriteBencodedHomePathsHandlesRewriteNoopAndMalformedInput(): void
    {
        $oldPath = '/home/olduser/data/movie';
        $rewritePayload = 'd9:directory'.strlen($oldPath).':'.$oldPath.'4:name4:teste';
        $newPath = '/home/new/data/movie';
        $otherPath = '/home/another/data';
        $noopPayload = 'd9:directory'.strlen($otherPath).':'.$otherPath.'e';
        $malformed = 'd9:directory999:/home/olduser/datae';

        foreach ([
            'rewrite' => [$rewritePayload, 'olduser', 'new', 1, 'd9:directory'.strlen($newPath).':'.$newPath.'4:name4:teste'],
            'noop' => [$noopPayload, 'olduser', 'newuser', 0, $noopPayload],
            'malformed' => [$malformed, 'olduser', 'newuser', 0, null],
        ] as $label => [$payload, $oldUser, $newUser, $expectedReplacements, $expected]) {
            $replacements = 0;
            $rewritten = \pmssUserTransferRewriteBencodedHomePaths($payload, $oldUser, $newUser, $replacements);

            $this->assertEquals($expectedReplacements, $replacements, $label);
            if ($expected === null) {
                $this->assertTrue($rewritten === null, 'expected malformed payload to be rejected');
            } else {
                $this->assertEquals($expected, $rewritten, $label);
            }
        }
    }

    public function testRewriteRtorrentSessionPathsUpdatesSessionFilesForUserRename(): void
    {
        $home = $this->pmssMakeUserHomeTree('pmss-userTransfer-session-rewrite-', 'session', 'home/newuser');
        $sessionDir = $home.'/session';

        $sessionFile = $sessionDir.'/test.torrent.rtorrent';
        $oldPath = '/home/olduser/data/movie';
        $payload = 'd9:directory'.strlen($oldPath).':'.$oldPath.'e';
        file_put_contents($sessionFile, $payload);

        \pmssUserTransferRewriteRtorrentSessionPaths([
            'localUser' => 'newuser',
            'remoteUser' => 'olduser',
        ], $home);

        $updated = (string) file_get_contents($sessionFile);
        $newPath = '/home/newuser/data/movie';
        $expected = 'd9:directory'.strlen($newPath).':'.$newPath.'e';

        $this->assertEquals($expected, $updated);
    }

    public function testRewriteSessionPreservesImportedInodeAndExactBencodedBytes(): void
    {
        $home = $this->pmssMakeUserHomeTree('pmss-session-inode-', 'session', 'home/newuser');
        $path = $home.'/session/one.rtorrent';
        $localUser = posix_geteuid() === 65534 ? 'root' : 'nobody';
        $localAccount = posix_getpwnam($localUser);
        $this->assertTrue(is_array($localAccount));
        $old = '/home/olduser/data/a';
        $payload = 'd9:directory'.strlen($old).':'.$old.'4:note4:keep' . 'e';
        file_put_contents($path, $payload);
        $before = lstat($path);
        $this->assertFalse($before['uid'] === $localAccount['uid'], 'fixture must exercise foreign imported ownership');
        \pmssUserTransferRewriteRtorrentSessionPaths(['localUser' => $localUser, 'remoteUser' => 'olduser'], $home);
        $new = '/home/'.$localUser.'/data/a';
        $this->assertSame('d9:directory'.strlen($new).':'.$new.'4:note4:keepe', file_get_contents($path));
        $after = lstat($path);
        $this->assertSame($before['ino'], $after['ino']);
        $this->assertSame($before['uid'], $after['uid']);
    }

    public function testRewriteSessionRefusesLinkedFinalAndParentWithoutTouchingOutside(): void
    {
        $base = $this->pmssMakeTempDir('pmss-session-linked-');
        $home = $base.'/home';
        $this->pmssEnsureDir($home.'/session');
        $outside = $base.'/outside.rtorrent';
        $payload = 'd9:directory20:/home/olduser/data/ae';
        file_put_contents($outside, $payload);
        file_put_contents($home.'/session/legitimate.rtorrent', $payload);
        symlink($outside, $home.'/session/linked.rtorrent');
        \pmssUserTransferRewriteRtorrentSessionPaths(['localUser' => 'newuser', 'remoteUser' => 'olduser'], $home);
        $this->assertSame($payload, file_get_contents($outside));
        $this->assertStringContainsString('/home/newuser/', file_get_contents($home.'/session/legitimate.rtorrent'));
        unlink($home.'/session/linked.rtorrent');
        rename($home.'/session', $home.'/saved-session');
        symlink($base, $home.'/session');
        \pmssUserTransferRewriteRtorrentSessionPaths(['localUser' => 'newuser', 'remoteUser' => 'olduser'], $home);
        $this->assertSame($payload, file_get_contents($outside));
        $this->assertTrue(is_file($home.'/saved-session/legitimate.rtorrent'));
    }

    public function testRewriteRtorrentSessionPathsReportsWhenNothingNeedsRewrite(): void
    {
        $base = $this->pmssMakeTempDir('pmss-userTransfer-session-nochange-');
        $home = $base.'/home/newuser';
        $sessionDir = $home.'/session';
        $logDir = $base.'/logs';
        $this->pmssEnsureDir($sessionDir);
        $this->pmssEnsureDir($logDir);

        $path = '/home/another/data/movie';
        file_put_contents($sessionDir.'/test.torrent.rtorrent', 'd9:directory'.strlen($path).':'.$path.'e');

        list(, $output) = $this->pmssCaptureStdout(function () use ($home): void {
            \pmssUserTransferRewriteRtorrentSessionPaths([
                'localUser' => 'newuser',
                'remoteUser' => 'olduser',
            ], $home);
        }, ['PMSS_LOG_DIR' => $logDir]);

        $this->assertStringContainsString('[INFO] rTorrent session rewrite found no /home path references to update', $output);
    }

    public function testRequestRtorrentRestartSkipsWhenUserOptedOut(): void
    {
        $home = $this->pmssMakeUserWebHome('pmss-userTransfer-rtorrent-optout-', 'newuser');
        $this->pmssWriteFile($home.'/.rtorrentDisable', '');
        $logDir = $this->pmssEnsureDir($this->pmssMakeTempDir('pmss-userTransfer-optout-logs-'));

        list(, $output) = $this->pmssCaptureStdout(function () use ($home): void {
            \pmssUserTransferRequestRtorrentRestart($home, 'newuser');
        }, ['PMSS_DRY_RUN' => '1', 'PMSS_LOG_DIR' => $logDir]);

        // The opt-out is honoured: the skip is logged and no restart marker is requested.
        $this->assertStringContainsString('[INFO] Skipping rTorrent restart: user opted out via .rtorrentDisable', $output);
        $this->assertStringNotContainsString('Requesting rTorrent restart marker', $output);
    }

    public function testRequestRtorrentRestartRequestsMarkerWithoutOptOut(): void
    {
        $home = $this->pmssMakeUserWebHome('pmss-userTransfer-rtorrent-restart-', 'newuser');
        $logDir = $this->pmssEnsureDir($this->pmssMakeTempDir('pmss-userTransfer-restart-logs-'));

        list(, $output) = $this->pmssCaptureStdout(function () use ($home): void {
            \pmssUserTransferRequestRtorrentRestart($home, 'newuser');
        }, ['PMSS_DRY_RUN' => '1', 'PMSS_LOG_DIR' => $logDir]);

        // Control: without the marker the restart is requested as before.
        $this->assertStringContainsString('Requesting rTorrent restart marker', $output);
        $this->assertStringNotContainsString('user opted out via .rtorrentDisable', $output);
    }

    public function testRutorrentShareRenameMovesExactDirectoryAsAccount(): void
    {
        $account = posix_getpwuid(posix_geteuid());
        $user = $account['name'];
        $home = $this->pmssMakeTempDir('pmss-share-rename-');
        $parent = $home.'/www/rutorrent/share/users';
        $this->pmssEnsureDir($parent.'/remote');
        file_put_contents($parent.'/remote/payload', "customer bytes\0remain");
        $inode = lstat($parent.'/remote')['ino'];
        \pmssUserTransferRenameRutorrentShare($home, 'remote', $user);
        $this->assertFalse(file_exists($parent.'/remote'));
        $this->assertSame("customer bytes\0remain", file_get_contents($parent.'/'.$user.'/payload'));
        $this->assertSame($inode, lstat($parent.'/'.$user)['ino']);
    }

    public function testRutorrentShareRenameRefusesLinkedSourceAndDestination(): void
    {
        $account = posix_getpwuid(posix_geteuid());
        $user = $account['name'];
        $base = $this->pmssMakeTempDir('pmss-share-linked-');
        $home = $base.'/home';
        $parent = $home.'/www/rutorrent/share/users';
        $outside = $base.'/outside';
        $this->pmssEnsureDir($parent);
        $this->pmssEnsureDir($outside);
        file_put_contents($outside.'/payload', 'outside bytes');
        symlink($outside, $parent.'/remote');
        \pmssUserTransferRenameRutorrentShare($home, 'remote', $user);
        $this->assertSame('outside bytes', file_get_contents($outside.'/payload'));
        $this->assertTrue(is_link($parent.'/remote'));
        unlink($parent.'/remote');
        $this->pmssEnsureDir($parent.'/remote');
        file_put_contents($parent.'/remote/payload', 'tenant bytes');
        symlink($outside, $parent.'/'.$user);
        \pmssUserTransferRenameRutorrentShare($home, 'remote', $user);
        $this->assertSame('tenant bytes', file_get_contents($parent.'/remote/payload'));
        $this->assertSame('outside bytes', file_get_contents($outside.'/payload'));
    }

    public function testRutorrentShareRenameFollowsOnlyManagedDurableShareLayout(): void
    {
        $account = posix_getpwuid(posix_geteuid());
        $user = $account['name'];
        $base = $this->pmssMakeTempDir('pmss-share-managed-');
        $home = $base.'/home';
        $share = $home.'/.local/share/pmss/rutorrent/share';
        $this->pmssWriteFile($share.'/users/remote/payload', "durable\0bytes");
        $this->pmssEnsureDir($home.'/www/rutorrent');
        symlink('../../.local/share/pmss/rutorrent/share', $home.'/www/rutorrent/share');
        \pmssUserTransferRenameRutorrentShare($home, 'remote', $user);
        $this->assertSame("durable\0bytes", file_get_contents($share.'/users/'.$user.'/payload'));
        $this->assertFalse(file_exists($share.'/users/remote'));
        $this->assertTrue(is_link($home.'/www/rutorrent/share'));
        $outside = $base.'/outside';
        $this->pmssWriteFile($outside.'/users/remote/payload', 'outside');
        unlink($home.'/www/rutorrent/share');
        symlink($outside, $home.'/www/rutorrent/share');
        \pmssUserTransferRenameRutorrentShare($home, 'remote', $user);
        $this->assertSame('outside', file_get_contents($outside.'/users/remote/payload'));
        $this->assertSame("durable\0bytes", file_get_contents($share.'/users/'.$user.'/payload'));
    }

    public function testRtorrentRestartScriptUsesLiveUserProcessFallback(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('etc/skel/.rtorrentRestart.php', [
            'function pmssRtorrentRestartPidIsRtorrent(int $pid, int $uid): bool',
            'ps -p \' . (int) $pid . \' -o euid=,comm=',
            'pgrep -u \' . (int) $uid . " -x \'rtorrent( main)?\'"',
            "preg_match('/^rtorrent( main)?$/', \$m[2]) === 1",
            'rtorrent lock pid is stale or not rtorrent',
            'pmssRtorrentRestartSignalPids($rtorrentPids, 15)',
        ], 'Missing robust rTorrent restart fragment: ');

        $this->pmssAssertRepoFileNotContainsStrings('etc/skel/.rtorrentRestart.php', [
            "system('kill ' . \$pid);",
            "system('kill -9 ' . \$pid);",
        ], 'Restart script must not blindly kill the lock PID: ');
    }

    public function testRestartMarkerRejectsLinkedEntries(): void
    {
        $base = $this->pmssMakeTempDir('pmss-userTransfer-marker-');
        $logDir = $base.'/logs';
        $this->pmssEnsureDir($base.'/www');
        $this->pmssEnsureDir($logDir);
        $user = (string) (posix_getpwuid(posix_geteuid())['name'] ?? '');
        $marker = $base.'/www/.rtorrentRestart';
        $create = function () use ($marker, $user, $logDir): bool {
            $created = false;
            $this->pmssCaptureStdout(function () use ($marker, $user, &$created): void {
                $created = \pmssUserTransferCreateRestartMarker($marker, $user);
            }, ['PMSS_LOG_DIR' => $logDir]);
            clearstatcache();
            return $created;
        };

        // A plain path: the marker is created as an empty regular file.
        $this->assertTrue($create(), 'marker must be created on a clean path');
        $this->assertTrue(is_file($marker) && !is_link($marker), 'marker must be a regular file');
        $this->assertSame(0, filesize($marker), 'marker must be empty');
        $this->assertSame(posix_geteuid(), fileowner($marker));

        file_put_contents($marker, 'existing');
        $this->assertTrue($create(), 'an existing marker still requests a restart');
        $this->assertSame('existing', file_get_contents($marker), 'existing marker bytes must remain intact');
        unlink($marker);

        // A symlink to an existing file: refused, and the target is never touched.
        $target = $base.'/root-owned-target';
        file_put_contents($target, 'secret');
        $targetMtime = filemtime($target);
        symlink($target, $marker);
        $this->assertFalse($create(), 'a planted symlink must be refused');
        $this->assertSame('secret', file_get_contents($target), 'symlink target must be untouched');
        $this->assertSame($targetMtime, filemtime($target), 'symlink target mtime must be untouched');

        // A dangling symlink: refused, and the target is never created.
        unlink($marker);
        $absent = $base.'/should-not-exist';
        symlink($absent, $marker);
        $this->assertFalse($create(), 'a dangling symlink must be refused');
        $this->assertFalse(file_exists($absent), 'dangling symlink target must not be created');
    }

    public function testUserTransferRtorrentRestartVerificationHelpers(): void
    {
        $home = $this->pmssMakeTempDir('pmss-userTransfer-rtorrent-verify-');
        $this->pmssEnsureDir($home.'/session');
        file_put_contents($home.'/session/a.torrent', 'a');
        file_put_contents($home.'/session/b.torrent', 'b');

        $this->assertSame(2, \pmssUserTransferRtorrentSessionTorrentCount($home));

        $calls = 0;
        $count = \pmssUserTransferWaitForRtorrentDownloadListCount($home, 0, static function () use (&$calls): array {
            $calls++;
            return ['hash-a', 'hash-b'];
        });

        $this->assertSame(2, $count);
        $this->assertSame(1, $calls);
        $this->assertSame(null, \pmssUserTransferRtorrentDownloadListCount($home, static function () {
            return false;
        }));
    }

    public function testIsPathWithinHomeAcceptsRealChildPaths(): void
    {
        $home = $this->pmssMakeUserWebHome('pmss-userTransfer-path-within-', 'testuser');
        $this->pmssWriteFile($home.'/www/index.html', 'x');

        $this->assertTrue(\pmssUserTransferIsPathWithinHome($home.'/www', $home), 'expected www dir within home');
        $this->assertTrue(\pmssUserTransferIsPathWithinHome($home.'/www/index.html', $home), 'expected file within home');
    }

    public function testIsPathWithinHomeRejectsSymlinkEscapes(): void
    {
        $base = $this->pmssMakeTempDir('pmss-userTransfer-path-escape-');
        $home = $base.'/home/testuser';
        $outside = $base.'/outside';
        $this->pmssEnsureDir($home);
        $this->pmssEnsureDir($outside);
        file_put_contents($outside.'/secret', 'x');

        if (!function_exists('symlink') || @symlink($outside, $home.'/escape') === false) {
            throw new SkipTest('symlink not supported in this environment');
        }

        $this->assertTrue(
            !\pmssUserTransferIsPathWithinHome($home.'/escape/secret', $home),
            'expected symlink target to be rejected as outside home'
        );
    }

    public function testUserTransferHomePathsStillResolveFromPmssHomeDirEnv(): void
    {
        $helper = $this->pmssReadRepoFile('scripts/lib/userTransfer/localUserSafety.php');

        $needle = "pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home')";
        $this->assertStringContainsString($needle, $helper, 'userTransfer safety checks must honour PMSS_HOME_DIR');
    }

    public function testWriteFilePersistsPayloadAndMode(): void
    {
        $path = $this->pmssMakeTempPath('pmss-userTransfer-write-');
        \pmssUserTransferWriteFile($path, 'payload', 0600);

        $this->assertEquals('payload', (string) file_get_contents($path));
        $this->assertEquals(0600, fileperms($path) & 0777);
    }

    public function testWriteFileThrowsWhenParentDirectoryIsMissing(): void
    {
        $path = $this->pmssMakeTempPath('pmss-userTransfer-missing-').'/payload';

        $this->assertThrowsRuntime(static function () use ($path): void {
            \pmssUserTransferWriteFile($path, 'payload', 0600);
        }, 'Failed writing: ');
    }

    public function testWriteFileRejectsSymlinkTarget(): void
    {
        $base = $this->pmssMakeTempDir('pmss-userTransfer-symlink-');
        $realTarget = $base.'/real';
        $target = $base.'/link';
        file_put_contents($realTarget, 'original');
        symlink($realTarget, $target);

        $this->assertThrowsRuntime(static function () use ($target): void {
            \pmssUserTransferWriteFile($target, 'payload', 0600);
        }, 'Failed writing: ');

        $this->assertEquals('original', (string) file_get_contents($realTarget));
    }

    public function testScratchCleanupRejectsPathsOutsideScratchRoot(): void
    {
        $base = $this->pmssMakeTempDir('pmss-userTransfer-cleanup-');
        $scratch = $base.'/scratch';
        $outside = $base.'/outside';
        $logDir = $base.'/logs';
        $this->pmssEnsureDir($scratch);
        $this->pmssEnsureDir($logDir);
        file_put_contents($scratch.'/transfer.expect', 'scratch');
        file_put_contents($outside, 'keep');

        $this->pmssWithEnv(['PMSS_LOG_DIR' => $logDir], function () use ($scratch, $outside): void {
            \pmssUserTransferScratchCleanup($scratch, [
                $scratch.'/transfer.expect',
                $scratch.'/../outside',
                $outside,
                '',
                '/',
            ]);
        });

        $this->assertFalse(file_exists($scratch.'/transfer.expect'), 'expected scratch file cleanup');
        $this->assertFalse(is_dir($scratch), 'expected empty scratch root cleanup');
        $this->assertEquals('keep', (string) file_get_contents($outside), 'outside file must not be unlinked');
    }

    public function testSleepReturnsImmediatelyWhenNoLiveDelayIsAllowed(): void
    {
        foreach ([[1, 1, true, 'dry-run'], [0, 0, false, 'non-positive max']] as [$min, $max, $dryRun, $label]) {
            putenv($dryRun ? 'PMSS_DRY_RUN=1' : 'PMSS_DRY_RUN');
            $started = microtime(true);
            \pmssUserTransferSleep($min, $max, 'Unit test');
            $this->assertTrue((microtime(true) - $started) < 0.2, 'expected '.$label.' sleep to return immediately');
        }
    }

    private function baseConfig(array $overrides = []): array
    {
        return array_replace(['localUser' => 'deefbox', 'remoteUser' => 'deefbox', 'hostname' => 'example.com',
            'receiver' => ['uid' => 1200, 'gid' => 1200, 'home' => '/home/deefbox', 'uidMap' => "0 1200 1\n100000 200000 65536\n",
                'gidMap' => "0 1200 1\n100000 200000 65536\n", 'sourceUid' => 1300, 'sourceGid' => 1300]], $overrides);
    }

    private function assertHostnameValidity(array $hostnames, bool $expected, string $label): void
    {
        foreach ($hostnames as $hostname) {
            $this->assertSame($expected, \pmssHostnameIsValid($hostname), 'expected '.$label.' hostname: '.$hostname);
        }
    }

    private function pmssQtVariantStringMap(array $values): string
    {
        $bytes = pack('N', 8).pack('N', count($values));
        foreach ($values as $key => $value) {
            $bytes .= $this->pmssQtString((string) $key);
            $bytes .= pack('N', 10);
            $bytes .= $this->pmssQtString((string) $value);
        }
        return $bytes;
    }

    private function pmssQtString(string $value): string
    {
        $raw = '';
        for ($i = 0; $i < strlen($value); $i++) {
            $raw .= "\0".$value[$i];
        }
        return pack('N', strlen($raw)).$raw;
    }

    private function pmssQtSettingsEscapedBytes(string $bytes): string
    {
        $escaped = '';
        $escapes = array(0 => '\0', 10 => '\n');
        for ($i = 0; $i < strlen($bytes); $i++) {
            $ord = ord($bytes[$i]);
            if (isset($escapes[$ord])) {
                $escaped .= $escapes[$ord];
            } elseif ($ord >= 32 && $ord <= 126 && $bytes[$i] !== '\\') {
                $escaped .= $bytes[$i];
            } else {
                $escaped .= '\x'.str_pad(dechex($ord), 2, '0', STR_PAD_LEFT);
            }
        }
        return $escaped;
    }
}
