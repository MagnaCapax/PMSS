<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/qbittorrentWatchdog.php';

/** Foreign-owner evidence is hermetic and never authorizes service mutations. */
final class QbittorrentPortCollisionTest extends TestCase
{
    private function row(string $address = '0100007F', string $port = '5BA0', string $uid = '1002', string $state = '0A', string $inode = '123'): string
    {
        return "0: {$address}:{$port} 00000000:0000 {$state} 0 0 0 {$uid} 0 {$inode}\n";
    }

    public function testLoopbackAndWildcardForeignListenersAreDetected(): void
    {
        foreach (['0100007F', '00000000'] as $address) {
            $this->assertTrue(\pmssQbittorrentPortHasForeignListener($this->row($address), 23456, 1001));
            $this->assertFalse(\pmssQbittorrentPortHasForeignListener($this->row($address), 23456, 1002));
        }
    }

    public function testOtherInterfacesPortsAndSocketStatesAreNotCollisions(): void
    {
        foreach ([$this->row('0100000A'), $this->row('0100007F', '5BA1'),
            $this->row('0100007F', '5BA0', '1002', '01'),
            $this->row('0100007F', '5BA0', '1002', '06', '0')] as $table)
            $this->assertFalse(\pmssQbittorrentPortHasForeignListener($table, 23456, 1001));
    }

    public function testMalformedOrMissingOwnerAndSocketEvidenceIsIgnored(): void
    {
        foreach (['', "sl local_address rem_address st\n", '0: 0100007F:5BA0',
            $this->row('0100007F', 'ZZZZ'), $this->row('0100007F', '5BA0', '-1'),
            $this->row('0100007F', '5BA0', 'unknown'),
            $this->row('0100007F', '5BA0', '1002', '0A', '0'),
            $this->row('0100007F', '5BA0', '1002', '0A', 'unknown')] as $table)
            $this->assertFalse(\pmssQbittorrentPortHasForeignListener($table, 23456, 1001));
    }

    public function testPortAndAccountBoundariesRemainStrict(): void
    {
        foreach ([0, 80, 1023, 65536] as $port)
            $this->assertFalse(\pmssQbittorrentPortHasForeignListener($this->row(), $port, 1001));
        foreach ([-1, 0] as $uid)
            $this->assertFalse(\pmssQbittorrentPortHasForeignListener($this->row(), 23456, $uid));
        foreach ([1024 => '0400', 65535 => 'FFFF'] as $port => $hex)
            $this->assertTrue(\pmssQbittorrentPortHasForeignListener($this->row('0100007F', $hex), $port, 1001));
    }

    public function testOwnListenerDoesNotHideASeparateForeignListener(): void
    {
        $own = $this->row('0100007F', '5BA0', '1001');
        foreach ([$own.$this->row(), $this->row().$own] as $table)
            $this->assertTrue(\pmssQbittorrentPortHasForeignListener($table, 23456, 1001));
    }

    public function testObservationLogsOnceWithoutChangingThePortOrConfig(): void
    {
        $home = $this->pmssMakeTrackedHomeRoot('pmss-qbt-collision-').'/fixtur01';
        $portPath = $this->pmssWriteFile($home.'/.qbittorrentPort', "23456\n");
        $config = "[Preferences]\nWebUI\\Port=23456\nWebUI\\Password_PBKDF2=fixture-hash\n";
        $path = $this->pmssWriteFile($home.'/.config/qBittorrent/qBittorrent.conf', $config);
        $events = [];
        $ops = self::pmssMakeCallbacks(['account' => ['uid' => 1001], 'read' => $this->row()]) + [
            'log' => static function ($user, $port) use (&$events): void { $events[] = [$user, $port]; },
        ];
        $this->assertTrue(\pmssQbittorrentPortCollisionObserve('fixtur01', $home, $ops));
        $this->assertSame([['fixtur01', 23456]], $events);
        $this->assertSame("23456\n", file_get_contents($portPath));
        $this->assertSame($config, file_get_contents($path));
        foreach ([null, false, '', $this->row('0100007F', '5BA0', '1001')] as $table)
            $this->assertFalse(\pmssQbittorrentPortCollisionObserve('fixtur01', $home,
                self::pmssMakeCallbacks(['read' => $table]) + $ops));
        foreach ([false, [], ['uid' => 0], ['uid' => '1001']] as $account)
            $this->assertFalse(\pmssQbittorrentPortCollisionObserve('fixtur01', $home,
                self::pmssMakeCallbacks(['account' => $account]) + $ops));
        foreach (['', '80', '65536', 'invalid'] as $port) {
            $this->pmssWriteFile($portPath, $port);
            $this->assertFalse(\pmssQbittorrentPortCollisionObserve('fixtur01', $home, $ops));
        }
        $this->assertFalse(\pmssQbittorrentPortCollisionObserve('fixtur02', $home, $ops));
        $target = $this->pmssWriteFile($home.'/unmanaged-port', '23456');
        unlink($portPath);
        $this->pmssCreateSymlinkOrSkip($target, $portPath);
        $this->assertFalse(\pmssQbittorrentPortCollisionObserve('fixtur01', $home, $ops));
        $this->assertSame([['fixtur01', 23456]], $events);
    }

    public function testRunningWatchdogObservesWithoutReachingStoppedReconciliation(): void
    {
        $events = [];
        $this->assertTrue(\pmssQbittorrentWatchdogRunning('fixtur01', '/fixture/fixtur01', [
            'portCollision' => static function () use (&$events): bool { $events[] = 'collision'; return true; },
            'port' => static function (): void { throw new \RuntimeException('Collision authorized a stopped write'); },
            'throttle' => static function ($user, $running): bool { return $running; },
        ] + self::pmssMakeCallbacks(['portResolve' => 45678, 'proxy' => true, 'running' => true, 'portRepairNeeded' => false,
            'account' => true, 'auth' => false, 'managed' => null])));
        $this->assertSame(['collision'], $events);
    }

    public function testStoppedWatchdogLeavesObservationToTheExistingPortPass(): void
    {
        $this->assertFalse(\pmssQbittorrentWatchdogRunning('fixtur01', '/fixture/fixtur01', [
            'portCollision' => static function (): void { throw new \RuntimeException('Stopped account was observed twice'); },
            'throttle' => static function ($user, $running): bool { return $running; },
        ] + self::pmssMakeCallbacks(['portResolve' => 45678, 'proxy' => true, 'running' => false, 'account' => true,
            'port' => true, 'auth' => false, 'managed' => null])));
    }
}
