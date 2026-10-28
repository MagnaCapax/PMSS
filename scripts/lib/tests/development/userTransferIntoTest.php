<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 3).'/lib/userTransfer.php';

/** Recovery mode keeps the live account's existing files and setup intact. */
class UserTransferIntoTest extends TestCase
{
    public function testIntoAcceptsOnlyPlainComponentsInBothOptionForms(): void
    {
        $this->assertFalse(array_key_exists('into', \pmssUserTransferParseCli(['transfer', 'target', 'example.com'])));
        foreach (['old-copy', 'backup.2026', '_old', '.recovery', '0', '-old', 'A_Z-9.'] as $dir) {
            foreach ([['--into', $dir], ['--into='.$dir]] as $option) {
                $cfg = \pmssUserTransferParseCli(array_merge(['transfer', 'target', 'example.com'], $option));
                $this->assertSame($dir, $cfg['into']);
            }
        }
    }

    public function testIntoRejectsMissingTraversalShellAndWhitespaceValues(): void
    {
        foreach (['', '.', '..', '../old', 'old/next', '/old', 'old/', 'a b', "a\tb", "old\n", "a\0b", 'a\\b',
            '$(id)', '`id`', 'a;b', 'a:b', 'a*b', 'é'] as $dir) {
            $this->assertThrowsRuntime(static function () use ($dir): void {
                \pmssUserTransferParseCli(['transfer', 'target', 'example.com', '--into='.$dir]);
            }, '[A-Za-z0-9._-]+');
        }
        $this->assertThrowsRuntime(static function (): void {
            \pmssUserTransferParseCli(['transfer', 'target', 'example.com', '--into']);
        }, '[A-Za-z0-9._-]+');
    }

    public function testBothRsyncPassesChangeOnlyTheRecoveryDestinationAndOption(): void
    {
        foreach ([false, true] as $namespace) {
            $cfg = $this->config('/home/target');
            $cfg['namespaceRsync'] = $namespace;
            foreach (['pmssUserTransferBuildRsyncMain', 'pmssUserTransferBuildRsyncFinal'] as $builder) {
                $normal = $builder($cfg);
                $recovery = $builder($cfg + ['into' => 'old-copy']);
                $this->assertStringNotContainsString('--ignore-existing', $normal);
                $this->assertStringNotContainsString('/home/target/old-copy/', $normal);
                $this->assertStringContainsString('/home/target/old-copy/', $recovery);
                $this->assertStringContainsString('--ignore-existing', $recovery);
                $this->assertStringNotContainsString('--compare-dest', $normal);
                // Absolute on purpose: a relative basis path breaks delta transfers (rsync rc 23, update discarded).
                $this->assertStringContainsString('--compare-dest=/home/target/', $recovery);
                $this->assertStringNotContainsString('--compare-dest=..', $recovery);
                $this->assertStringContainsString('O_NOFOLLOW', $recovery);
                $this->assertStringContainsString('setpriv --reuid=', $recovery);
            }
        }
    }

    public function testDestinationCreatesAndReusesOnlyAccountOwnedDirectories(): void
    {
        $home = $this->pmssMakeTempDir('pmss-transfer-into-');
        foreach (['old', '.old', '0'] as $dir) {
            $cfg = $this->config($home) + ['into' => $dir];
            $command = \pmssBuildCommand('sh', ['-c', 'printf recovered > new-file', 'receiver', '/home/target/'.$dir.'/']);
            for ($pass = 0; $pass < 2; ++$pass) {
                $result = $this->pmssExecShellCommand(\pmssUserTransferBuildIntoReceiver($cfg, $command));
                $this->assertSame(0, $result['rc'], $result['output']);
                $this->assertSame('recovered', file_get_contents($home.'/'.$dir.'/new-file'));
                $this->assertSame(posix_geteuid(), fileowner($home.'/'.$dir));
            }
        }
    }

    public function testDestinationRefusesSymlinksDanglingLinksFilesAndForeignOwners(): void
    {
        $home = $this->pmssMakeTempDir('pmss-transfer-into-refuse-');
        $outside = $this->pmssMakeTempDir('pmss-transfer-into-outside-');
        symlink($outside, $home.'/link');
        symlink($home.'/missing', $home.'/dangling');
        file_put_contents($home.'/file', 'live bytes');
        foreach (['link', 'dangling', 'file'] as $dir) {
            $cfg = $this->config($home) + ['into' => $dir];
            $result = $this->pmssExecShellCommand(\pmssUserTransferBuildIntoReceiver($cfg, 'touch must-not-run'));
            $this->assertTrue($result['rc'] !== 0, $dir.' must be refused');
            $this->assertStringContainsString('real directory owned by the account', $result['output']);
        }
        $this->assertSame('live bytes', file_get_contents($home.'/file'));
        $this->assertFalse(file_exists($outside.'/must-not-run'));
        $this->assertFalse(file_exists($home.'/missing'));
        if (posix_geteuid() !== fileowner('/tmp')) {
            $cfg = $this->config('/') + ['into' => 'tmp'];
            $result = $this->pmssExecShellCommand(\pmssUserTransferBuildIntoReceiver($cfg, 'touch must-not-run'));
            $this->assertTrue($result['rc'] !== 0, 'foreign-owned directory must be refused');
        }
    }

    public function testDestinationRemainsPinnedAfterItsNameIsReplaced(): void
    {
        $home = $this->pmssMakeTempDir('pmss-transfer-into-swap-');
        $outside = $this->pmssMakeTempDir('pmss-transfer-into-swap-outside-');
        $script = 'mv ../old ../pinned && ln -s '.escapeshellarg($outside).' ../old && printf safe > new-file';
        $command = \pmssBuildCommand('sh', ['-c', $script, 'receiver', '/home/target/old/']);
        $cfg = $this->config($home) + ['into' => 'old'];
        $result = $this->pmssExecShellCommand(\pmssUserTransferBuildIntoReceiver($cfg, $command));
        $this->assertSame(0, $result['rc'], $result['output']);
        $this->assertSame('safe', file_get_contents($home.'/pinned/new-file'));
        $this->assertFalse(file_exists($outside.'/new-file'));
    }

    public function testRecoveryRsyncPreservesExistingFilesAndAddsMissingFiles(): void
    {
        if ($this->pmssExecShellCommand('command -v rsync')['rc'] !== 0) throw new SkipTest('requires rsync');
        $home = $this->pmssMakeTempDir('pmss-transfer-into-rsync-');
        $source = $this->pmssMakeTempDir('pmss-transfer-into-source-');
        $this->pmssWriteFile($home.'/old/existing', 'live bytes');
        $this->pmssWriteFile($home.'/existing', 'live home bytes');
        $this->pmssWriteFile($source.'/existing', 'old bytes');
        $this->pmssWriteFile($source.'/missing', 'recovered bytes');
        $inode = fileinode($home.'/old/existing');
        $command = \pmssBuildCommand('rsync', ['-a', '--ignore-existing', $source.'/', '/home/target/old/']);
        $cfg = $this->config($home) + ['into' => 'old'];
        $result = $this->pmssExecShellCommand(\pmssUserTransferBuildIntoReceiver($cfg, $command));
        $this->assertSame(0, $result['rc'], $result['output']);
        $this->assertSame('live bytes', file_get_contents($home.'/old/existing'));
        $this->assertSame($inode, fileinode($home.'/old/existing'));
        $this->assertSame('live home bytes', file_get_contents($home.'/existing'));
        $this->assertSame('recovered bytes', file_get_contents($home.'/old/missing'));
        $this->assertFalse(file_exists($home.'/missing'));
    }

    public function testRecoveryNeverHandsServicesOverOnTheLiveAccount(): void
    {
        $cfg = $this->config('/home/target');
        $this->assertTrue(\pmssUserTransferRunsHandOver($cfg));
        $this->assertFalse(\pmssUserTransferRunsHandOver($cfg + ['into' => 'old-copy']));
        // With no hold taken, a failed or interrupted recovery leaves the live account's Eternals untouched.
        $plan = \pmssUserTransferEternalCleanupPlan(false, false, false);
        $this->assertFalse($plan['holdTarget']);
        $this->assertFalse($plan['releaseTarget']);
        $this->assertFalse($plan['releaseSource']);
    }

    public function testPostSetupRunsOnlyWithoutInto(): void
    {
        $home = $this->pmssMakeTempDir('pmss-transfer-into-setup-');
        $this->pmssWriteFile($home.'/.trafficLimit', '100');
        $this->pmssWriteFile($home.'/.config/qBittorrent/categories.json', '{"live":{"save_path":"/live"}}');
        $cfg = $this->config($home);
        foreach ([null, 'old', '0'] as $into) {
            [, $output] = $this->pmssCaptureStdout(static function () use ($cfg, $into, $home): void {
                \pmssUserTransferPostSetup($cfg + ['into' => $into], $home, \pmssUserTransferScratchPaths($home.'/scratch'));
            }, ['PMSS_DRY_RUN' => '1', 'PMSS_LOG_DIR' => $home]);
            if ($into === null) $this->assertStringContainsString('Normalising user permissions', $output);
            else $this->assertSame('', $output);
        }
        $this->assertSame('100', file_get_contents($home.'/.trafficLimit'));
    }

    private function config(string $home): array
    {
        return ['localUser' => 'target', 'remoteUser' => 'target', 'hostname' => 'example.com',
            'verifyThreshold' => 90, 'receiver' => ['uid' => posix_geteuid(), 'gid' => posix_getegid(),
                'home' => $home, 'sourceUid' => 1300, 'sourceGid' => 1300,
                'uidMap' => '0 '.posix_geteuid().' 1', 'gidMap' => '0 '.posix_getegid().' 1']];
    }
}
