<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/rtorrentConfig.php';
require_once dirname(__DIR__, 2).'/rtorrentPortReservationsReconcile.php';

class rtorrentConfigCreateConfigTest extends TestCase
{
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
            'pex=##pex',
            'dhtmode=##dht',
            'mem=##memoryMax',
            '',
        ]);

        $cfg = new \rtorrentConfig($resourceConfig, $template);
        $input = [
            'ram'        => 1000,
            'scgiPort'   => 5000,
            'pex'        => 'auto',
            'dht'        => 'yes',
            'uploadThrottle' => 1234,
        ];

        $result = $cfg->createConfig($input);
        $this->assertTrue(is_array($result));
        $this->assertTrue(isset($result['configFile']));
        $this->assertTrue(isset($result['config']));

        // Freeze the public render result instead of repeating its sizing formula.
        $expected = "min=24\nmax=128\nusg=168\nus=28\nthrottle.global_up.max_rate.set = 1234\n"
            ."scgi=5000\npex=auto\ndhtmode=yes\nmem=750M\n";

        $this->assertEquals($expected, (string) $result['configFile']);
        $this->assertEquals($input, $result['config']);

        $defaults = new \rtorrentConfig(['custom' => true], "min=##minimumPeers\nmax=##maximumPeers\nus=##uploadSlots\nusg=##uploadSlotsGlobal\n");
        $defaultResult = $defaults->createConfig($input);
        $this->assertEquals("min=24\nmax=128\nus=28\nusg=168\n", $defaultResult['configFile']);
    }

    public function testCreateConfigDoesNotRenderUnsupportedPortTokens(): void
    {
        $this->skipIfLocalnetPresent('unsupported port tokens');
        $cfg = new \rtorrentConfig([
            'ramBlock' => 250,
            'peers' => ['minimum' => 1, 'maximum' => 2],
            'uploadSlots' => 1,
        ], "scgi=##scgiPort\ndht=##dhtPort\nlisten=##listenPort\n");

        $result = $cfg->createConfig(['ram' => 500, 'scgiPort' => 5000, 'dht' => 'auto', 'pex' => 'auto']);
        // The supported ##dht token still replaces its prefix in ##dhtPort.
        $this->assertSame("scgi=5000\ndht=autoPort\nlisten=##listenPort\n", $result['configFile']);
        $this->assertFalse(isset($result['config']['dhtPort']));
        $this->assertFalse(isset($result['config']['listenPort']));
    }

    public function testReservationSourcesKeepOnlyScgiOwnership(): void
    {
        $payload = ['rtorrentPort' => 4001, 'rtorrentDhtPort' => 24001, 'rtorrentListenPort' => 44001];
        $this->assertSame(
            ['ports' => ['scgi' => [4001 => true]], 'uncertain' => []],
            \pmssRtorrentPortReservationPayloadSource($payload)
        );
        $this->assertSame(
            ['ports' => [], 'uncertain' => ['scgi' => true]],
            \pmssRtorrentPortReservationPayloadSource(array_replace($payload, ['rtorrentPort' => 'invalid']))
        );

        $path = $this->pmssWriteFile(
            $this->pmssMakeTempFile('pmss-rtorrent-source-'),
            "network.scgi.open_port = 127.0.0.1:4002\ndht.port.set = invalid\nnetwork.port_range.set = 44001-44001\n"
        );
        $this->assertSame(
            ['ports' => ['scgi' => [4002 => true]], 'uncertain' => []],
            \pmssRtorrentPortReservationConfigSource($path)
        );
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
            'pex'        => 'auto',
            'dht'        => 'yes',
        ];

        $cases = [
            ['ram' => -1,   'expected' => 170],
            ['ram' => 0,    'expected' => 170],
            ['ram' => 250,  'expected' => 170],
            ['ram' => 420,  'expected' => 170],
            ['ram' => 421,  'expected' => 171],
            ['ram' => 500,  'expected' => 250],
            ['ram' => 1000, 'expected' => 750],
            ['ram' => 2000, 'expected' => 1500],
            ['ram' => 3999, 'expected' => 3000],
            ['ram' => 4000, 'expected' => 3000],
            ['ram' => 4001, 'expected' => 3001],
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

    public function testCreateConfigReservesOnlyMissingScgiPort(): void
    {
        $this->skipIfLocalnetPresent('port default');

        $cfg = new class([
            'ramBlock' => 250,
            'peers' => ['minimum' => 1, 'maximum' => 2],
            'uploadSlots' => 1,
        ], "scgi=##scgiPort\n") extends \rtorrentConfig {
            public $reservedTypes = [];

            protected function _configPortPrivate($type, $rangeStart = 2000, $rangeEnd = 65000)
            {
                $this->reservedTypes[] = $type;
                return 4001;
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

        $this->assertEquals([], $cfg->reservedTypes);
        $this->assertEquals("scgi=0\n", (string) $result['configFile']);
        $this->assertSame('', $result['config']['dhtPort']);
        $this->assertSame(null, $result['config']['listenPort']);
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

    public function testWriteConfigReportsOnlyCompleteWritesAsSuccess(): void
    {
        $expected = [
            'false' => [false, "prior\n"],
            'zero' => [false, "prior\n"],
            'short' => [false, "complete"],
            'over' => [false, "complete\n"],
            'complete' => [true, "complete\n"],
        ];

        foreach ($expected as $mode => $case) {
            $result = $this->writeConfigWithResultMode($mode);
            $this->assertSame($case[0], $result['result']);
            $this->assertSame(base64_encode($case[1]), $result['content']);
        }
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

    /** Run writeConfig() behind a namespaced file_put_contents() fault shim. */
    private function writeConfigWithResultMode(string $mode): array
    {
        $homeRoot = $this->pmssMakeTrackedHomeRoot('pmss-rtorrent-write-');
        $home = $this->pmssUserHomePath($homeRoot, 'dummy');
        $this->pmssEnsureDir($home);
        $path = $home.'/.rtorrent.rc';
        $this->pmssWriteFile($path, "prior\n");

        $script = <<<'PHP'
namespace RtorrentConfigWriteFixture;
function file_put_contents($path, $data, $flags = 0) {
    $mode = getenv('PMSS_TEST_WRITE_MODE');
    if ($mode === 'false') return false;
    if ($mode === 'zero') return 0;
    $written = \file_put_contents($path, $mode === 'short' ? substr($data, 0, -1) : $data, $flags);
    return $mode === 'over' ? strlen($data) + 1 : $written;
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/rtorrentConfig.php', 'RtorrentConfigWriteFixture');
        $script .= <<<'PHP'
$config = new rtorrentConfig(['ramBlock' => 250], 'template');
$result = $config->writeConfig('dummy', "complete\n");
$path = rtrim(getenv('PMSS_HOME_DIR'), '/').'/dummy/.rtorrent.rc';
echo json_encode(['result' => $result, 'content' => base64_encode((string) \file_get_contents($path))]);
PHP;
        return $this->pmssRunInlinePhpJson($script, [
            'PMSS_HOME_DIR' => $homeRoot,
            'PMSS_TEST_WRITE_MODE' => $mode,
        ]);
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

    public function testPortReservationRejectsUnsafeDirectoryBeforeFilesystemTouch(): void
    {
        $root = $this->pmssMakeTempDir('pmss-rtorrent-paths-');
        $target = $this->pmssEnsureDir($root.'/target', 0700);
        $link = $root.'/linked';
        $this->pmssCreateSymlinkOrSkip($target, $link);
        file_put_contents($root.'/occupied', 'preserved');

        foreach (['relative', $root.'/../other', $root."/nul\0byte", $link, $root.'/occupied'] as $base) {
            $this->assertThrows(\InvalidArgumentException::class, static function () use ($base): void {
                \pmssRtorrentPortReserve($base, 'scgi', 4000, 4000);
            }, 'reservation directory');
        }

        $this->assertFalse(file_exists($target.'/scgi'));
        $this->assertFalse(file_exists($root.'/other'));
        $this->assertSame('preserved', file_get_contents($root.'/occupied'));
    }

    public function testPortReservationSkipsOccupiedFilesAndCreatesExclusiveReservation(): void
    {
        $portRoot = $this->pmssMakeTempDir('pmss-rtorrent-ports-');
        @mkdir($portRoot.'/scgi', 0755, true);
        file_put_contents($portRoot.'/scgi/4000', '');
        $cfg = $this->rtorrentPortReservationFixture($portRoot);

        // This seed draws the occupied slot for all 16 random attempts, exercising fallback.
        srand(21860);
        $port = $cfg->reservePrivatePort('scgi', 4000, 4001);

        $this->assertEquals(4001, $port);
        $this->assertTrue(is_file($portRoot.'/scgi/4001'), 'expected reservation file to be created');
        $this->assertFalse(is_link($portRoot.'/scgi/4001'), 'reservation file must not be a symlink');
    }

    public function testPortReservationRefusesExhaustedOrSymlinkedRange(): void
    {
        $portRoot = $this->pmssMakeTempDir('pmss-rtorrent-ports-');
        @mkdir($portRoot.'/scgi', 0755, true);
        file_put_contents($portRoot.'/scgi/4000', '');
        file_put_contents($portRoot.'/scgi/4001', '');
        $cfg = $this->rtorrentPortReservationFixture($portRoot);

        $this->assertThrowsRuntime(static function () use ($cfg): void {
            $cfg->reservePrivatePort('scgi', 4000, 4001);
        }, 'No available rTorrent scgi port reservation slots');

        if (!@symlink($portRoot.'/missing', $portRoot.'/scgi/4002')) {
            throw new SkipTest('symlink fixtures unavailable');
        }

        $this->assertThrowsRuntime(static function () use ($cfg): void {
            $cfg->reservePrivatePort('scgi', 4002, 4002);
        }, 'No available rTorrent scgi port reservation slots');
        $this->assertTrue(is_link($portRoot.'/scgi/4002'), 'dangling symlink should remain untouched');
    }

    public function testCreateConfigFailedScgiReservationCreatesNoMarkers(): void
    {
        $portRoot = $this->pmssMakeTempDir('pmss-rtorrent-transaction-');
        $cfg = $this->rtorrentTransactionalFixture($portRoot, 'scgi');
        $this->assertThrowsRuntime(static function () use ($cfg): void {
            $cfg->createConfig(['ram' => 500, 'pex' => 'auto', 'dht' => 'auto']);
        }, 'forced scgi reservation failure');
        $this->assertFalse(file_exists($portRoot.'/scgi/4000'));
        $this->assertFalse(is_dir($portRoot.'/dht'));
        $this->assertFalse(is_dir($portRoot.'/listen'));
    }

    public function testCreateConfigKeepsSuccessfulTransactionalReservations(): void
    {
        $this->skipIfLocalnetPresent('reservation success');
        $portRoot = $this->pmssMakeTempDir('pmss-rtorrent-transaction-');
        $cfg = $this->rtorrentTransactionalFixture($portRoot, null);

        $result = $cfg->createConfig(['ram' => 500, 'pex' => 'auto', 'dht' => 'auto']);

        $this->assertEquals(4000, $result['config']['scgiPort']);
        $this->assertTrue(is_file($portRoot.'/scgi/4000'));
        $this->assertFalse(isset($result['config']['dhtPort']));
        $this->assertFalse(isset($result['config']['listenPort']));
        $this->assertFalse(is_dir($portRoot.'/dht'));
        $this->assertFalse(is_dir($portRoot.'/listen'));
    }

    public function testCreateConfigIgnoresFullUnusedPortPools(): void
    {
        $portRoot = $this->pmssMakeTempDir('pmss-rtorrent-full-ports-');
        foreach (['dht' => [24001, 44000], 'listen' => [44001, 64000]] as $type => $range) {
            $this->pmssEnsureDir($portRoot.'/'.$type);
            for ($port = $range[0]; $port <= $range[1]; $port++) {
                file_put_contents($portRoot.'/'.$type.'/'.$port, '');
            }
        }
        $cfg = $this->rtorrentPortReservationFixture($portRoot);

        $result = $cfg->createConfig(['ram' => 500, 'pex' => 'auto', 'dht' => 'auto']);

        $this->assertTrue(is_file($portRoot.'/scgi/'.$result['config']['scgiPort']));
        $this->assertSame(1, count(glob($portRoot.'/scgi/*')));
        $this->assertFalse(isset($result['config']['dhtPort']));
        $this->assertFalse(isset($result['config']['listenPort']));
        $this->assertSame(20000, count(glob($portRoot.'/dht/*')));
        $this->assertSame(20000, count(glob($portRoot.'/listen/*')));
    }

    public function testReservationReusableKeepsOnlyStoredPortsWithLiveMarkers(): void
    {
        $portRoot = $this->pmssMakeTempDir('pmss-rtorrent-reuse-');
        foreach (['scgi/4100', 'dht/24100', 'listen/44100'] as $relative) {
            $this->pmssWriteFile($portRoot.'/'.$relative, '');
        }
        $stored = ['rtorrentPort' => 4100, 'rtorrentDhtPort' => '24100', 'rtorrentListenPort' => 44100];

        // Legacy dht/listen values and markers do not affect scgi reuse.
        $this->assertEquals(['scgiPort' => 4100], \pmssRtorrentPortReservationReusable($stored, $portRoot));

        // Legacy marker removal cannot change scgi reuse.
        @unlink($portRoot.'/dht/24100');
        $this->assertEquals(['scgiPort' => 4100], \pmssRtorrentPortReservationReusable($stored, $portRoot));

        // New account (payload default rtorrentPort 0), missing keys, malformed and out-of-range values: nothing kept.
        $this->assertEquals([], \pmssRtorrentPortReservationReusable(['rtorrentPort' => 0], $portRoot));
        $this->assertEquals([], \pmssRtorrentPortReservationReusable([], $portRoot));
        $this->assertEquals([], \pmssRtorrentPortReservationReusable(['rtorrentPort' => '41x0', 'rtorrentListenPort' => [44100]], $portRoot));
        $this->pmssWriteFile($portRoot.'/scgi/24100', '');
        $this->assertEquals([], \pmssRtorrentPortReservationReusable(['rtorrentPort' => 24100, 'rtorrentListenPort' => 4100], $portRoot));

        // A directory or symlink in the marker slot is not a reservation.
        @mkdir($portRoot.'/scgi/4200', 0755, true);
        $this->assertEquals([], \pmssRtorrentPortReservationReusable(['rtorrentPort' => 4200], $portRoot));
        $this->assertEquals([], \pmssRtorrentPortReservationReusable(['rtorrentListenPort' => 44100], $portRoot));

        // An ordinary marker reached through a symlinked namespace is not ours.
        $otherRoot = $this->pmssMakeTempDir('pmss-rtorrent-other-');
        $redirectedRoot = $this->pmssMakeTempDir('pmss-rtorrent-redirected-');
        $this->pmssWriteFile($otherRoot.'/4100', '');
        $this->assertTrue(symlink($otherRoot, $redirectedRoot.'/scgi'));
        $this->assertEquals([], \pmssRtorrentPortReservationReusable($stored, $redirectedRoot));
    }

    public function testCreateConfigReconfigureKeepsReservedPortsWithoutNewMarkers(): void
    {
        $this->skipIfLocalnetPresent('reconfigure port reuse');
        $portRoot = $this->pmssMakeTempDir('pmss-rtorrent-reconfigure-');
        $cfg = $this->rtorrentTransactionalFixture($portRoot, null);
        $first = $cfg->createConfig(['ram' => 500, 'pex' => 'auto', 'dht' => 'auto']);
        $stored = [
            'rtorrentPort' => $first['config']['scgiPort'],
            'rtorrentDhtPort' => 24100,
            'rtorrentListenPort' => 44100,
        ];

        $again = $cfg->createConfig(\pmssRtorrentPortReservationReusable($stored, $portRoot) + ['ram' => 1000, 'pex' => 'auto', 'dht' => 'auto']);

        $this->assertEquals($first['config']['scgiPort'], $again['config']['scgiPort']);
        $this->assertFalse(isset($again['config']['dhtPort']));
        $this->assertFalse(isset($again['config']['listenPort']));
        $this->assertEquals(1, count(glob($portRoot.'/scgi/*')), 'reconfigure must not reserve a second scgi marker');
        $this->assertFalse(is_dir($portRoot.'/dht'));
        $this->assertFalse(is_dir($portRoot.'/listen'));
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
        $this->assertTrue(is_file($fixture['portsBase'].'/dht/24001'), 'legacy markers must remain inert');
        $this->assertTrue(is_file($fixture['portsBase'].'/dht/24002'));
        $this->assertTrue(is_file($fixture['portsBase'].'/listen/44001'));
        $this->assertTrue(is_file($fixture['portsBase'].'/listen/44002'));
    }

    public function testReservationReconcilerIgnoresLegacyPortsAndStaticRange(): void
    {
        $fixture = $this->rtorrentReconcileFixture();
        $this->pmssWriteFile($fixture['configRoot'].'/users/dummy.json', json_encode([
            'rtorrentPort' => 4000,
            'rtorrentDhtPort' => 'invalid',
            'rtorrentListenPort' => 'invalid',
        ]));
        $this->pmssWriteFile($fixture['homeRoot'].'/dummy/.rtorrent.rc', "network.scgi.open_port = 127.0.0.1:4000\ndht.port.set = invalid\nnetwork.port_range.set = 50000-60000\n");
        $this->pmssWriteFile($fixture['portsBase'].'/dht/24001', '');
        $this->pmssWriteFile($fixture['portsBase'].'/listen/44001', '');
        @touch($fixture['portsBase'].'/dht/24001', 1000);
        @touch($fixture['portsBase'].'/listen/44001', 1000);

        $result = \pmssRtorrentPortReservationsReconcile(
            ['dummy'], $fixture['homeRoot'], $fixture['configRoot'], $fixture['portsBase'], 10000, 3600, $fixture['lockPath']
        );

        $this->assertSame('ok', $result['status']);
        $this->assertSame('', $result['reason']);
        $this->assertSame(0, $result['removed']);
        $this->assertTrue(is_file($fixture['portsBase'].'/dht/24001'));
        $this->assertTrue(is_file($fixture['portsBase'].'/listen/44001'));
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
                $directory = $this->portRoot.'/'.$type;
                if (!is_dir($directory)) {
                    mkdir($directory, 0755, true);
                }
                file_put_contents($directory.'/'.$rangeStart, '');
                return $rangeStart;
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
