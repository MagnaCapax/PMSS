<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/qbittorrentThrottleWatchdog.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/qbittorrentStart.php';

/** Exercise the real customer reader under account permissions, without a reader stub. */
final class QbittorrentThrottleUnreadableTest extends TestCase
{
    public function testUnreadablePolicySurvivesCustomerStartAndTheNextWatchdogCycle(): void
    {
        if (posix_geteuid() !== 0) throw new SkipTest('Root QA is needed for a root-owned unreadable policy fixture');
        $account = posix_getpwnam('nobody');
        if (!is_array($account)) throw new SkipTest('Unprivileged QA fixture account unavailable');
        $root = $this->pmssMakeTrackedHomeRoot('pmss-qbt-unreadable-cap-');
        $home = $root.'/'.$account['name'];
        $config = "[Meta]\nMigrationVersion=8\n[BitTorrent]\nSession\\GlobalUPSpeedLimit=500\n";
        $path = $this->pmssWriteFile($home.'/.config/qBittorrent/qBittorrent.conf', $config);
        $state = $this->pmssWriteFile($home.'/.config/pmss/torrentClients/qbittorrent.throttleApplied', "500\n");
        // Model the canonical root-only policy with an actual permission-denied open.
        $policy = $this->pmssWriteFile($home.'/.torrentThrottle', "500\n");
        chmod($policy, 0000);
        clearstatcache(true, $policy);
        $this->assertSame(0, fileowner($policy));
        $this->assertSame(0000, fileperms($policy) & 0777);
        foreach ([$home, $home.'/.config', dirname($path), $home.'/.config/pmss', dirname($state), $path, $state] as $entry) {
            chown($entry, $account['uid']); chgrp($entry, $account['gid']);
        }
        chmod($path, 0600); chmod($state, 0600);
        // Load only trusted code before dropping identity; the actual reader must fail to open.
        $code = 'require_once '.var_export(dirname(__DIR__, 4).'/etc/skel/www/qbittorrentStart.php', true).';'.<<<'PHP'
$account = posix_getpwnam($argv[1]);
if (!posix_initgroups($argv[1], $account['gid']) || !posix_setgid($account['gid'])
    || !posix_setuid($account['uid'])) exit(1);
$home = $argv[2];
if (lstat($home.'/.torrentThrottle') === false || @file_get_contents($home.'/.torrentThrottle') !== false
    || pmssQbittorrentFrontendThrottleRead($home) !== null) exit(2);
$path = $home.'/.config/qBittorrent/qBittorrent.conf';
$state = $home.'/.config/pmss/torrentClients/qbittorrent.throttleApplied';
$before = file_get_contents($path);
$record = file_get_contents($state);
$launches = 0;
$ok = pmssQbittorrentFrontendStart($home, static function () use (&$launches): bool { $launches++; return true; }, [
    'build' => '5.2.4', 'accountPids' => static function (): array { return []; },
    'restore' => static function (): bool { return true; }, 'check' => static function (): bool { return true; },
]);
exit($ok && $launches === 1 && file_get_contents($path) === $before && file_get_contents($state) === $record ? 0 : 3);
PHP;
        $output = [];
        $this->assertSame(0, \pmssCredentialProcessRun(\pmssBuildCommand(PHP_BINARY,
            ['-r', $code, $account['name'], $home]), null, $output, false, 5), implode("\n", $output));
        $this->assertSame($config, file_get_contents($path));
        $this->assertSame("500\n", file_get_contents($state));
        $this->assertTrue(\pmssQbittorrentThrottleWatchdog($account['name'], $home, true, [
            'account' => static function ($user, $home, $cap, $apply): ?bool {
                if ($cap !== 500 || $apply) throw new \RuntimeException('Unexpected throttle removal or stopped write');
                return \pmssQbittorrentThrottleAccount($home, $cap, false, ['build' => '5.2.4']);
            },
            'stop' => static function (): void { throw new \RuntimeException('Unreadable customer policy caused a restart'); },
        ]));
        $this->assertSame($config, file_get_contents($path));
        $this->assertSame("500\n", file_get_contents($state));
    }

    public function testAbsentOrNonpositiveCustomerPolicyNeverRemovesAnAppliedCap(): void
    {
        $home = $this->pmssMakeTrackedHomeRoot('pmss-qbt-customer-cap-removal-').'/alice';
        $config = "[BitTorrent]\nSession\\GlobalUPSpeedLimit=500\n";
        $path = $this->pmssWriteFile($home.'/.config/qBittorrent/qBittorrent.conf', $config);
        $state = $this->pmssWriteFile($home.'/.config/pmss/torrentClients/qbittorrent.throttleApplied', "500\n");
        $unreachable = static function (): void { throw new \RuntimeException('Customer removal reached stopped configuration work'); };
        $this->assertTrue(\pmssQbittorrentFrontendThrottleStart($home, ['accountPids' => $unreachable, 'write' => $unreachable]));
        foreach ([null, 0, -1] as $cap) foreach ([false, true] as $apply) {
            $this->assertFalse(\pmssQbittorrentFrontendThrottleAccount($home, $cap, $apply,
                ['accountPids' => $unreachable, 'restore' => $unreachable, 'write' => $unreachable]));
        }
        $this->assertSame($config, file_get_contents($path));
        $this->assertSame("500\n", file_get_contents($state));
    }
}
