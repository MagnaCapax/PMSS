<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/qbittorrentThrottleAccount.php';
require_once __DIR__.'/../../user/credentialProcess.php';

/** Verify the provisioning primitive and stopped writer against account-owned fixture files. */
final class QbittorrentProvisionOwnershipTest extends TestCase
{
    /** Prepare a real account-owned fixture home without changing its passwd entry. */
    private function accountHome(): array
    {
        $root = $this->pmssMakeTrackedHomeRoot('pmss-qbt-provision-owner-');
        chmod($root, 0755);
        $account = posix_geteuid() === 0 ? posix_getpwnam('nobody') : posix_getpwuid(posix_geteuid());
        if (!is_array($account)) throw new SkipTest('Fixture account unavailable');
        $home = $this->pmssEnsureDir($root.'/'.$account['name']);
        $dir = $this->pmssEnsureDir($home.'/.config/qBittorrent');
        if (posix_geteuid() === 0) foreach ([$home, $home.'/.config', $dir] as $path) {
            chown($path, $account['uid']); chgrp($path, $account['gid']);
        }
        return [$account, $home, $dir.'/qBittorrent.conf'];
    }

    /** The child loads trusted libraries before changing identity, matching the writer. */
    private function applyAsAccount(array $account, string $home, ?int $cap, string $build): bool
    {
        if (posix_geteuid() === 0) {
            $code = 'require_once '.var_export(dirname(__DIR__, 2).'/user/qbittorrentThrottleAccount.php', true).';'
                .'$a=posix_getpwnam($argv[1]);'
                .'if (!posix_initgroups($argv[1],$a["gid"]) || !posix_setgid($a["gid"]) || !posix_setuid($a["uid"])) exit(1);'
                .'$r=pmssQbittorrentThrottleAccount($argv[2],$argv[3] === "" ? null : (int)$argv[3],true,['
                .'"build"=>$argv[4],"accountPids"=>static function(){return [];},"restore"=>static function(){return true;}]);'
                .'exit($r === null ? 1 : ($r ? 2 : 0));';
            $output = [];
            $status = \pmssCredentialProcessRun(\pmssBuildCommand(PHP_BINARY,
                ['-r', $code, $account['name'], $home, $cap === null ? '' : (string) $cap, $build]), null, $output, false, 5);
            $this->assertTrue(in_array($status, [0, 2], true), implode("\n", $output));
            return $status === 2;
        }
        $result = \pmssQbittorrentThrottleAccount($home, $cap, true, ['build' => $build,
            'accountPids' => static function (): array { return []; }, 'restore' => static function (): bool { return true; }]);
        $this->assertTrue(is_bool($result));
        return $result;
    }

    public function testProvisionedConfigIsAccountOwnedAndAcceptsTheStoppedThrottle(): void
    {
        list($account, $home, $path) = $this->accountHome();
        $this->assertTrue(\pmssWriteUserFile($path, "[BitTorrent]\nSession\\GlobalUPSpeedLimit=0\n", $account['name'], 0600));
        clearstatcache(true, $path);
        $this->assertSame($account['uid'], fileowner($path));
        $this->assertTrue($this->applyAsAccount($account, $home, 500, '5.2.4'));
        clearstatcache(true, $path);
        $this->assertSame($account['uid'], fileowner($path));
        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertSame('500', \pmssQbittorrentConfigValues(file_get_contents($path))['BitTorrent']['Session\\GlobalUPSpeedLimit']);
        // The top-level provisioning script has no ops seam; tie this behavior test to its actual primitive.
        $this->pmssAssertRepoFileContainsString('scripts/util/userConfig.php',
            "pmssWriteUserFile(\$qbittorrentConfigFile, \$qbittorrentConfig, \$user['name'], 0600)");
    }

    public function testTemplateCapIsRecordedEvenWhenConvergenceIsANoopAndCanThenBeRemoved(): void
    {
        list($account, $home, $path) = $this->accountHome();
        $config = "[Preferences]\nConnection\\GlobalUPLimit=500\n";
        $this->assertTrue(\pmssWriteUserFile($path, $config, $account['name'], 0600));
        $this->assertTrue(\pmssQbittorrentThrottleSeedRecord($account['name'], $home, 500));
        $state = $home.'/.config/pmss/torrentClients/qbittorrent.throttleApplied';
        clearstatcache(true, $state);
        $this->assertSame("500\n", file_get_contents($state));
        $this->assertSame($account['uid'], fileowner($state));
        $this->assertSame(0600, fileperms($state) & 0777);
        $this->assertFalse($this->applyAsAccount($account, $home, 500, '4.3.9'));
        $this->assertSame($config, file_get_contents($path));
        $this->assertTrue($this->applyAsAccount($account, $home, null, '4.3.9'));
        clearstatcache(true, $state);
        $this->assertFalse(file_exists($state));
        $this->assertStringNotContainsString('GlobalUPLimit', file_get_contents($path));
    }

    public function testUnlimitedTemplateDoesNotCreateAnAppliedRecord(): void
    {
        $home = $this->pmssMakeTrackedHomeRoot('pmss-qbt-provision-no-cap-').'/alice';
        foreach ([0, -1] as $cap) $this->assertTrue(\pmssQbittorrentThrottleSeedRecord('alice', $home, $cap));
        $this->assertFalse(file_exists($home));
    }
}
