<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/rtorrentConfig.php';
require_once dirname(__DIR__, 2).'/rtorrentPortReservationsReconcile.php';

class rtorrentConfigCreateConfigTest extends TestCase
{
    private function calculatePiecesMemory(int $ramMiB): int
    {
        $ramMiB = max(0, (int)$ramMiB);
        $gapMiB = (int) floor($ramMiB * 0.25);
        $gapMiB = max(250, min(1000, $gapMiB));
        $piecesMemoryMiB = $ramMiB - $gapMiB;
        if ($piecesMemoryMiB < 170) {
            $piecesMemoryMiB = 170;
        }
        return $piecesMemoryMiB;
    }

    private function skipIfLocalnetPresent(string $label): void
    {
        if (is_readable('/etc/seedbox/config/localnet')) {
            throw new SkipTest('localnet config present on host; skipping rtorrentConfig '.$label.' test');
        }
    }

    public function testCreateConfigRendersTemplateReplacements(): void
    {
        // createConfig() touches this real path when present; keep dev tests hermetic.
        $this->skipIfLocalnetPresent('render');

        $resourceConfig = [
            'ramBlock' => 250,
            'peers' => [
                'minimum' => 6,
                'maximum' => 32,
            ],
            'uploadSlots' => 7,
        ];

        $template = implode("\n", [
            'min=##minimumPeers',
            'max=##maximumPeers',
            'usg=##uploadSlotsGlobal',
            'us=##uploadSlots',
            '##uploadThrottleLine',
            'scgi=##scgiPort',
            'dht=##dhtPort',
            'listen=##listenPort',
            'pex=##pex',
            'dhtmode=##dht',
            'mem=##memoryMax',
            '',
        ]);

        $cfg = new \rtorrentConfig($resourceConfig, $template);
        $input = [
            'ram'        => 1000,
            'scgiPort'   => 5000,
            'dhtPort'    => 5001,
            'listenPort' => 5002,
            'pex'        => 'auto',
            'dht'        => 'yes',
            'uploadThrottle' => 1234,
        ];

        $result = $cfg->createConfig($input);
        $this->assertTrue(is_array($result));
        $this->assertTrue(isset($result['configFile']));
        $this->assertTrue(isset($result['config']));

        $blocks = round(($input['ram'] / $resourceConfig['ramBlock']), 2);
        $minimumPeers = ceil($resourceConfig['peers']['minimum'] * $blocks);
        $maximumPeers = floor($resourceConfig['peers']['maximum'] * $blocks);
        $uploadSlots = floor($resourceConfig['uploadSlots'] * $blocks);

        $expected = implode("\n", [
            'min='.$minimumPeers,
            'max='.$maximumPeers,
            'usg='.($uploadSlots * 6),
            'us='.$uploadSlots,
            'throttle.global_up.max_rate.set = '.$input['uploadThrottle'],
            'scgi='.$input['scgiPort'],
            'dht='.$input['dhtPort'],
            'listen='.$input['listenPort'],
            'pex='.$input['pex'],
            'dhtmode='.$input['dht'],
            'mem='.$this->calculatePiecesMemory((int)$input['ram']).'M',
            '',
        ]);

        $this->assertEquals($expected, (string) $result['configFile']);
        $this->assertEquals($input, $result['config']);

        $defaults = new \rtorrentConfig(['custom' => true], "min=##minimumPeers\nmax=##maximumPeers\nus=##uploadSlots\nusg=##uploadSlotsGlobal\n");
        $defaultResult = $defaults->createConfig($input);
        $this->assertEquals("min=24\nmax=128\nus=28\nusg=168\n", $defaultResult['configFile']);
    }

    public function testCreateConfigAppliesMemoryHeadroomGuardrails(): void
    {
        $this->skipIfLocalnetPresent('memory guardrail');

        $resourceConfig = [
            'ramBlock' => 250,
            'peers' => [
                'minimum' => 1,
                'maximum' => 2,
            ],
            'uploadSlots' => 1,
        ];
        $template = "mem=##memoryMax\n";
        $cfg = new \rtorrentConfig($resourceConfig, $template);

        $base = [
            'scgiPort'   => 5000,
            'dhtPort'    => 5001,
            'listenPort' => 5002,
            'pex'        => 'auto',
            'dht'        => 'yes',
        ];

        $cases = [
            ['ram' => 250,  'expected' => 170],
            ['ram' => 500,  'expected' => 250],
            ['ram' => 1000, 'expected' => 750],
            ['ram' => 2000, 'expected' => 1500],
            ['ram' => 8000, 'expected' => 7000],
        ];

        foreach ($cases as $case) {
            $input = $base;
            $input['ram'] = $case['ram'];
            $result = $cfg->createConfig($input);
            $this->assertEquals(
                'mem='.$case['expected'].'M'."\n",
                (string) $result['configFile'],
                'Unexpected pieces.memory.max for ram '.$case['ram']
            );
        }
    }

    public function testCreateConfigKeepsLegacyPortDefaultRules(): void
    {
        $this->skipIfLocalnetPresent('port default');

        $cfg = new class([
            'ramBlock' => 250,
            'peers' => ['minimum' => 1, 'maximum' => 2],
            'uploadSlots' => 1,
        ], "scgi=##scgiPort\ndht=##dhtPort\nlisten=##listenPort\n") extends \rtorrentConfig {
            public $reservedTypes = [];

            protected function _configPortPrivate($type, $rangeStart = 2000, $rangeEnd = 65000)
            {
                $this->reservedTypes[] = $type;
                return ['scgi' => 4001, 'dht' => 24002, 'listen' => 44002][$type];
            }
        };

        $result = $cfg->createConfig([
            'ram' => 500,
            'scgiPort' => 0,
            'dhtPort' => '',
            'listenPort' => null,
            'pex' => 'auto',
            'dht' => 'yes',
        ]);

        $this->assertEquals(['dht', 'listen'], $cfg->reservedTypes);
        $this->assertEquals("scgi=0\ndht=24002\nlisten=44002\n", (string) $result['configFile']);
    }

    public function testCreateConfigKeepsRenderingSilent(): void
    {
        $cfg = new \rtorrentConfig([
            'ramBlock' => 250,
            'peers' => ['minimum' => 1, 'maximum' => 2],
            'uploadSlots' => 1,
        ], "mem=##memoryMax\n");

        list($result, $output) = $this->pmssCaptureStdout(function () use ($cfg): array {
            return $cfg->createConfig([
                'ram' => 500,
                'scgiPort' => 5000,
                'dhtPort' => 5001,
                'listenPort' => 5002,
                'pex' => 'auto',
                'dht' => 'yes',
            ]);
        });

        $this->assertEquals('', $output);
        $this->assertEquals("mem=250M\n", (string) $result['configFile']);
    }

    public function testWriteConfigUsesValidatedHomeRoot(): void
    {
        $homeRoot = $this->pmssMakeTrackedHomeRoot('pmss-rtorrent-home-');
        $this->pmssEnsureDir($this->pmssUserHomePath($homeRoot, 'dummy'));
        $cfg = $this->rtorrentConfigFixture();
        $content = "directory.default.set = /home/dummy/data\n";

        $this->assertTrue($cfg->writeConfig('dummy', $content));
        $path = $this->pmssUserHomePath($homeRoot, 'dummy', '.rtorrent.rc');
        $this->assertEquals($content, (string) file_get_contents($path));
        $this->assertEquals(['directory.default.set' => '/home/dummy/data'], $cfg->readUserConfig('dummy'));
        $this->assertSame(null, $cfg->idempotentConfig('dummy', $content));
        $this->assertTrue($cfg->idempotentConfig('dummy', "directory.default.set = /home/dummy/other\n"));
    }

    public function testWriteConfigRejectsUnsafeUsernames(): void
    {
        $this->pmssMakeTrackedHomeRoot('pmss-rtorrent-home-');
        $cfg = $this->rtorrentConfigFixture();

        foreach (['BadName', '../root', 'user/name', 'dummy;rm'] as $username) {
            try {
                $cfg->writeConfig($username, "x = y\n");
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('valid PMSS username', $exception->getMessage());
                continue;
            }
            $this->fail('Expected invalid username to be rejected: '.$username);
        }
    }

    public function testWriteConfigRefusesMissingOrSymlinkedHome(): void
    {
        $homeRoot = $this->pmssMakeTrackedHomeRoot('pmss-rtorrent-home-');
        $cfg = $this->rtorrentConfigFixture();

        $this->assertFalse($cfg->writeConfig('dummy', "x = y\n"));

        $target = $this->pmssMakeTempDir('pmss-rtorrent-target-');
        if (!@symlink($target, $this->pmssUserHomePath($homeRoot, 'dummy'))) {
            throw new SkipTest('symlink fixtures unavailable');
        }
        $this->assertFalse($cfg->writeConfig('dummy', "x = y\n"));
        $this->assertFalse(file_exists($target.'/.rtorrent.rc'));
    }

    public function testPortReservationRejectsUnsafeTypeBeforeFilesystemTouch(): void
    {
        $portRoot = $this->pmssMakeTempPath('pmss-rtorrent-ports-');
        $cfg = $this->rtorrentPortReservationFixture($portRoot);

        foreach (['', '../scgi', 'scgi/evil', 'Scgi', str_repeat('a', 33)] as $type) {
            $this->assertThrows(\InvalidArgumentException::class, static function () use ($cfg, $type): void {
                $cfg->reservePrivatePort($type, 4000, 4001);
            }, 'reservation type');
        }

        $this->assertFalse(file_exists($portRoot), 'unsafe types must not create reservation directories');
    }

    public function testPortReservationRejectsInvalidRangesBeforeFilesystemTouch(): void
    {
        $portRoot = $this->pmssMakeTempPath('pmss-rtorrent-ports-');
        $cfg = $this->rtorrentPortReservationFixture($portRoot);
        $cases = [
            [0, 1],
            [1, 0],
            [65536, 65537],
            ['abc', 4000],
            [4000, 'abc'],
        ];

        foreach ($cases as $case) {
            $this->assertThrows(\InvalidArgumentException::class, static function () use ($cfg, $case): void {
                $cfg->reservePrivatePort('scgi', $case[0], $case[1]);
            }, 'reservation range');
        }

        $this->assertFalse(file_exists($portRoot), 'invalid ranges must not create reservation directories');
    }

    public function testPortReservationSkipsOccupiedFilesAndCreatesExclusiveReservation(): void
    {
        $portRoot = $this->pmssMakeTempDir('pmss-rtorrent-ports-');
        @mkdir($portRoot.'/scgi', 0755, true);
        file_put_contents($portRoot.'/scgi/4000', '');
        $cfg = $this->rtorrentPortReservationFixture($portRoot);

        $port = $cfg->reservePrivatePort('scgi', 4000, 4001);

        $this->assertEquals(4001, $port);
        $this->assertTrue(is_file($portRoot.'/scgi/4001'), 'expected reservation file to be created');
        $this->assertFalse(is_link($portRoot.'/scgi/4001'), 'reservation file must not be a symlink');
    }

    public function testPortReservationRefusesExhaustedOrSymlinkedRange(): void
    {
        $portRoot = $this->pmssMakeTempDir('pmss-rtorrent-ports-');
        @mkdir($portRoot.'/dht', 0755, true);
        file_put_contents($portRoot.'/dht/24001', '');
        file_put_contents($portRoot.'/dht/24002', '');
        $cfg = $this->rtorrentPortReservationFixture($portRoot);

        $this->assertThrowsRuntime(static function () use ($cfg): void {
            $cfg->reservePrivatePort('dht', 24001, 24002);
        }, 'No available rTorrent dht port reservation slots');

        @mkdir($portRoot.'/listen', 0755, true);
        if (!@symlink($portRoot.'/missing', $portRoot.'/listen/44001')) {
            throw new SkipTest('symlink fixtures unavailable');
        }

        $this->assertThrowsRuntime(static function () use ($cfg): void {
            $cfg->reservePrivatePort('listen', 44001, 44001);
        }, 'No available rTorrent listen port reservation slots');
        $this->assertTrue(is_link($portRoot.'/listen/44001'), 'dangling symlink should remain untouched');
    }

    public function testCreateConfigRollsBackEveryPartialReservation(): void
    {
        $this->skipIfLocalnetPresent('reservation rollback');

        foreach (['dht', 'listen'] as $failType) {
            $portRoot = $this->pmssMakeTempDir('pmss-rtorrent-transaction-');
            $cfg = $this->rtorrentTransactionalFixture($portRoot, $failType);
            $this->assertThrowsRuntime(static function () use ($cfg): void {
                $cfg->createConfig(['ram' => 500, 'pex' => 'auto', 'dht' => 'auto']);
            }, 'forced '.$failType.' reservation failure');

            $this->assertFalse(file_exists($portRoot.'/scgi/4000'), 'scgi marker must unwind after '.$failType.' failure');
            $this->assertFalse(file_exists($portRoot.'/dht/24001'), 'dht marker must unwind after '.$failType.' failure');
        }
    }

    public function testCreateConfigKeepsSuccessfulTransactionalReservations(): void
    {
        $this->skipIfLocalnetPresent('reservation success');
        $portRoot = $this->pmssMakeTempDir('pmss-rtorrent-transaction-');
        $cfg = $this->rtorrentTransactionalFixture($portRoot, null);

        $result = $cfg->createConfig(['ram' => 500, 'pex' => 'auto', 'dht' => 'auto']);

        $this->assertEquals(4000, $result['config']['scgiPort']);
        $this->assertTrue(is_file($portRoot.'/scgi/4000'));
        $this->assertTrue(is_file($portRoot.'/dht/24001'));
        $this->assertTrue(is_file($portRoot.'/listen/44001'));
    }

    public function testReservationReconcilerKeepsReferencesRecentAndUnsafeMarkers(): void
    {
        $fixture = $this->rtorrentReconcileFixture();
        $this->pmssWriteFile($fixture['configRoot'].'/users/dummy.json', json_encode([
            'rtorrentPort' => 4000,
            'rtorrentDhtPort' => 24001,
            'rtorrentListenPort' => 44001,
        ]));
        $this->pmssWriteFile($fixture['homeRoot'].'/dummy/.rtorrent.rc', "network.scgi.open_port = 127.0.0.1:4001\ndht.port.set = 24002\nnetwork.port_range.set = 44002-44002\n");
        foreach ([
            'scgi/4000', 'scgi/4001', 'scgi/4002', 'scgi/4003',
            'dht/24001', 'dht/24002', 'listen/44001', 'listen/44002',
        ] as $relative) {
            $this->pmssWriteFile($fixture['portsBase'].'/'.$relative, '');
            @touch($fixture['portsBase'].'/'.$relative, 1000);
        }
        @touch($fixture['portsBase'].'/scgi/4003', 9900);
        if (!@symlink($fixture['portsBase'].'/missing', $fixture['portsBase'].'/scgi/4004')) {
            throw new SkipTest('symlink fixtures unavailable');
        }

        $result = \pmssRtorrentPortReservationsReconcile(
            ['dummy'],
            $fixture['homeRoot'],
            $fixture['configRoot'],
            $fixture['portsBase'],
            10000,
            3600,
            $fixture['lockPath']
        );

        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['removed']);
        $this->assertFalse(file_exists($fixture['portsBase'].'/scgi/4002'), 'old unreferenced marker must be reclaimed');
        $this->assertTrue(is_file($fixture['portsBase'].'/scgi/4003'), 'recent marker must survive publication grace');
        $this->assertTrue(is_link($fixture['portsBase'].'/scgi/4004'), 'unsafe marker must remain untouched');
        $this->assertTrue(is_file($fixture['portsBase'].'/listen/44002'), 'rendered config reference must be retained');
    }

    public function testReservationReconcilerSkipsIncompleteOwnershipSources(): void
    {
        $fixture = $this->rtorrentReconcileFixture();
        $this->pmssWriteFile($fixture['configRoot'].'/users/dummy.json', json_encode(['rtorrentPort' => 4000]));
        $this->pmssWriteFile($fixture['portsBase'].'/dht/24001', '');
        @touch($fixture['portsBase'].'/dht/24001', 1000);

        $result = \pmssRtorrentPortReservationsReconcile(
            ['dummy'], $fixture['homeRoot'], $fixture['configRoot'], $fixture['portsBase'], 10000, 3600, $fixture['lockPath']
        );

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('uncertain_dht_ownership', $result['reason']);
        $this->assertTrue(is_file($fixture['portsBase'].'/dht/24001'), 'uncertainty must retain every marker');
    }

    public function testReservationReconcilerSkipsMalformedStoredConfig(): void
    {
        $fixture = $this->rtorrentReconcileFixture();
        $this->pmssWriteFile($fixture['configRoot'].'/users/dummy.json', '{malformed');
        $this->pmssWriteFile($fixture['homeRoot'].'/dummy/.rtorrent.rc', "network.scgi.open_port = 127.0.0.1:4000\ndht.port.set = 24001\nnetwork.port_range.set = 44001-44001\n");
        $this->pmssWriteFile($fixture['portsBase'].'/scgi/4002', '');
        @touch($fixture['portsBase'].'/scgi/4002', 1000);

        $result = \pmssRtorrentPortReservationsReconcile(
            ['dummy'], $fixture['homeRoot'], $fixture['configRoot'], $fixture['portsBase'], 10000, 3600, $fixture['lockPath']
        );

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('uncertain_scgi_ownership', $result['reason']);
        $this->assertTrue(is_file($fixture['portsBase'].'/scgi/4002'));
    }

    public function testReservationReconcilerSkipsWhenItsLockIsBusy(): void
    {
        $fixture = $this->rtorrentReconcileFixture();
        $this->pmssWriteFile($fixture['portsBase'].'/scgi/4002', '');
        $busy = false;
        $lock = \pmssLockFileAcquire($fixture['lockPath'], false, 'c', true, true, $busy);
        $this->assertTrue(is_resource($lock));
        try {
            $result = \pmssRtorrentPortReservationsReconcile(
                [], $fixture['homeRoot'], $fixture['configRoot'], $fixture['portsBase'], 10000, 0, $fixture['lockPath']
            );
            $this->assertSame('skipped', $result['status']);
            $this->assertSame('lock_busy', $result['reason']);
            $this->assertTrue(is_file($fixture['portsBase'].'/scgi/4002'));
        } finally {
            \pmssLockHandleRelease($lock);
        }
    }

    public function testReservationReconcilerIsScheduledByRootCron(): void
    {
        $this->pmssAssertRepoFileContainsString(
            'etc/seedbox/config/root.cron',
            '/scripts/cron/rtorrentPortReservationsReconcile.php',
            'root.cron should schedule legacy rTorrent reservation reconciliation'
        );
    }

    private function rtorrentConfigFixture(): \rtorrentConfig
    {
        return new \rtorrentConfig([
            'ramBlock' => 250,
            'peers' => ['minimum' => 1, 'maximum' => 2],
            'uploadSlots' => 1,
        ], "mem=##memoryMax\n");
    }

    private function rtorrentPortReservationFixture(string $portRoot)
    {
        return new class($portRoot, [
            'ramBlock' => 250,
            'peers' => ['minimum' => 1, 'maximum' => 2],
            'uploadSlots' => 1,
        ], "mem=##memoryMax\n") extends \rtorrentConfig {
            private $portRoot;

            public function __construct(string $portRoot, array $resourceConfig, string $template)
            {
                $this->portRoot = $portRoot;
                parent::__construct($resourceConfig, $template);
            }

            protected function portReservationBaseDir(): string
            {
                return $this->portRoot;
            }

            public function reservePrivatePort($type, $rangeStart, $rangeEnd): int
            {
                return $this->_configPortPrivate($type, $rangeStart, $rangeEnd);
            }
        };
    }

    private function rtorrentTransactionalFixture(string $portRoot, ?string $failType)
    {
        return new class($portRoot, $failType, [
            'ramBlock' => 250,
            'peers' => ['minimum' => 1, 'maximum' => 2],
            'uploadSlots' => 1,
        ], "mem=##memoryMax\n") extends \rtorrentConfig {
            private $portRoot;
            private $failType;

            public function __construct(string $portRoot, ?string $failType, array $resourceConfig, string $template)
            {
                $this->portRoot = $portRoot;
                $this->failType = $failType;
                parent::__construct($resourceConfig, $template);
            }

            protected function portReservationBaseDir(): string
            {
                return $this->portRoot;
            }

            protected function portReservationLockPath(): string
            {
                return $this->portRoot.'/reservation.lock';
            }

            protected function _configPortPrivate($type, $rangeStart = 2000, $rangeEnd = 65000)
            {
                if ($type === $this->failType) {
                    throw new \RuntimeException('forced '.$type.' reservation failure');
                }
                $this->pmssEnsureMarkerDirectory($type);
                file_put_contents($this->portRoot.'/'.$type.'/'.$rangeStart, '');
                return $rangeStart;
            }

            private function pmssEnsureMarkerDirectory(string $type): void
            {
                if (!is_dir($this->portRoot.'/'.$type)) {
                    mkdir($this->portRoot.'/'.$type, 0755, true);
                }
            }
        };
    }

    /** @return array{homeRoot:string,configRoot:string,portsBase:string,lockPath:string} */
    private function rtorrentReconcileFixture(): array
    {
        $root = $this->pmssMakeTempDir('pmss-rtorrent-reconcile-');
        $homeRoot = $root.'/home';
        $configRoot = $root.'/etc/seedbox/config';
        $portsBase = $root.'/var/lib/pmss/ports';
        $this->pmssEnsureDir($homeRoot.'/dummy');
        $this->pmssEnsureDir($configRoot.'/users');
        $this->pmssEnsureDir($portsBase);
        return array(
            'homeRoot' => $homeRoot,
            'configRoot' => $configRoot,
            'portsBase' => $portsBase,
            'lockPath' => $root.'/run/lock/rtorrent-reconcile.lock',
        );
    }
}
