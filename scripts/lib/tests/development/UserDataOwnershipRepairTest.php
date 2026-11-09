<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/dataOwnershipRepair.php';

/** Explicit data repair stays confined to safe payload entries, even with hostile names. */
class UserDataOwnershipRepairTest extends TestCase
{
    protected function pmssTempDirFixtureArguments(): array { return ['tempDir', 'pmss-data-ownership-']; }

    private function home(): string
    {
        $home = $this->tempDir.'/fixtur01';
        $this->pmssEnsureDir($home.'/data/nested');
        return $home;
    }

    /** Exercise the real find selection, substituting a harmless fixture-only action. */
    private function select(string $home, int $uid, array $ranges = []): void
    {
        $command = \pmssUserDataOwnershipRepairCommand($home, $uid, 100001, $ranges);
        $this->assertTrue(is_string($command));
        $action = '-execdir /usr/bin/chown -h '.escapeshellarg($uid.':100001').' {} +';
        $this->assertStringContainsString($action, $command);
        $command = str_replace($action, '-execdir /usr/bin/touch -h -d @1 {} +', $command);
        $this->assertSame(0, $this->pmssExecShellCommand($command)['rc']);
        clearstatcache();
    }

    public function testMismatchSelectionIsDataOnlyAndDoesNotFollowLinks(): void
    {
        $home = $this->home();
        $outside = $this->tempDir.'/outside';
        $this->pmssWriteFile($outside, 'kept');
        $this->pmssWriteFile($home.'/watch', 'kept');
        $files = ['ordinary', '-option', "quote' name", "line\nbreak", 'nested/payload'];
        foreach ($files as $name) $this->pmssWriteFile($home.'/data/'.$name, 'payload');
        $this->pmssCreateSymlinkOrSkip($outside, $home.'/data/link');
        $this->pmssCreateSymlinkOrSkip($this->tempDir, $home.'/data/directory-link');
        $this->assertTrue(link($outside, $home.'/data/hard-link'));
        $before = [filemtime($outside), filemtime($home.'/watch'), filemtime($home)];

        $this->select($home, posix_geteuid() + 100001);

        foreach ($files as $name) $this->assertSame(1, filemtime($home.'/data/'.$name), $name);
        $this->assertSame(1, filemtime($home.'/data/nested'));
        $this->assertSame(1, filemtime($home.'/data'));
        $this->assertSame($before, [filemtime($outside), filemtime($home.'/watch'), filemtime($home)]);
        $this->assertTrue(is_link($home.'/data/link') && is_link($home.'/data/directory-link'));
        $this->assertSame('kept', file_get_contents($outside));
    }

    public function testPrimaryAndSubordinateOwnersAreNotSelected(): void
    {
        $home = $this->home();
        $path = $home.'/data/nested/payload';
        $this->pmssWriteFile($path, 'kept');
        $before = filemtime($path);
        $currentUid = posix_geteuid();
        if ($currentUid === 0) {
            $currentUid = 100001;
            foreach ([$home.'/data', $home.'/data/nested', $path] as $entry) {
                $this->assertTrue(chown($entry, $currentUid));
            }
        }
        $this->select($home, $currentUid);
        $this->assertSame($before, filemtime($path));
        // A one-ID interval proves both find bounds include the allocated owner.
        $this->select($home, $currentUid + 100001, [[$currentUid, 1]]);
        $this->assertSame($before, filemtime($path));
    }

    public function testMissingRedirectedAndMalformedTreesAreRefused(): void
    {
        $home = $this->home();
        $this->pmssCreateSymlinkOrSkip($home, $this->tempDir.'/linked-home');
        $other = $this->pmssEnsureDir($this->tempDir.'/other');
        $this->pmssCreateSymlinkOrSkip($home.'/data', $other.'/data');
        $regular = $this->pmssEnsureDir($this->tempDir.'/regular');
        $this->pmssWriteFile($regular.'/data', 'not a directory');
        foreach (['', '/', 'relative', $home.'/../fixtur01', $home."\0", $home.'//',
            $this->tempDir.'/missing', $this->tempDir.'/linked-home', $other, $regular] as $path) {
            $this->assertSame(null, \pmssUserDataOwnershipRepairCommand($path, 100001, 100001), $path);
        }
        foreach ([[0, 100001], [-1, 100001], [100001, 0], [100001, -1]] as [$uid, $gid]) {
            $this->assertSame(null, \pmssUserDataOwnershipRepairCommand($home, $uid, $gid));
        }
    }

    public function testRootCanRepairExistingRootOwnedPayloadAndRepeatSafely(): void
    {
        if (posix_geteuid() !== 0) throw new SkipTest('fixture ownership changes require root');
        $home = $this->home();
        $path = $home.'/data/nested/payload';
        $this->pmssWriteFile($path, 'kept');
        chmod($path, 0750);
        $command = \pmssUserDataOwnershipRepairCommand($home, 100001, 100001);
        $this->assertSame(0, $this->pmssExecShellCommand($command)['rc']);
        clearstatcache();
        foreach ([$home.'/data', $home.'/data/nested', $path] as $entry) {
            $this->assertSame(100001, fileowner($entry));
            $this->assertSame(100001, filegroup($entry));
        }
        $this->assertSame(0750, fileperms($path) & 0777);
        $this->assertSame('kept', file_get_contents($path));
        $before = filemtime($path);
        $this->select($home, 100001);
        $this->assertSame($before, filemtime($path), 'healthy rerun selects nothing');
    }

    public function testCommandKeepsFilesystemAndEnvironmentGuards(): void
    {
        $command = \pmssUserDataOwnershipRepairCommand($this->home(), 100001, 100002);
        $this->assertStringContainsString('/usr/bin/env PATH=/usr/sbin:/usr/bin:/sbin:/bin find ', $command);
        $this->assertStringContainsString('-xdev -not -type l \\( ! -type f -o -links 1 \\)', $command);
        $this->assertStringContainsString("-execdir /usr/bin/chown -h '100001:100002' {} +", $command);
        $this->assertStringNotContainsString('chown -R', $command);
    }

    public function testCliRejectsMalformedAndReservedRequestsBeforeAccountWork(): void
    {
        foreach ([[], ['tool'], ['tool', '../x'], ['tool', ''], ['tool', 'root'],
            ['tool', "fixtur01\n"], ['tool', 'fixtur01', 'extra']] as $argv) {
            $code = 'require '.var_export($this->pmssRepoPath('scripts/util/userDataOwnershipRepair.php'), true).'; '
                .'exit(pmssUserDataOwnershipRepairCli('.var_export($argv, true).'));';
            $run = $this->pmssExecInlinePhpWithTempStderr($code);
            $this->assertSame(1, $run['result']['rc']);
            $this->assertStringContainsString('Usage: userDataOwnershipRepair.php', file_get_contents($run['stderrPath']));
        }
        $this->pmssAssertRepoFileContainsAllStrings('scripts/util/userDataOwnershipRepair.php', [
            "posix_geteuid() !== 0", "pmssPasswdEntryPositiveUid(\$account)",
            "(\$account['dir'] ?? '') !== \$home", "@fileowner(\$home) !== \$uid",
            "pmssSubordinateIdRanges(\$user, \$uid, 'uid')", "pmssEnvFlagEnabled('PMSS_DRY_RUN')",
            "'pmss-userPermissions-'.\$user.'.lock'", "return runStep('Repairing data ownership', \$command)",
            'fclose($lock)',
        ]);
    }
}
