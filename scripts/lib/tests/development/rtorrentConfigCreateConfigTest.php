<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/rtorrentConfig.php';

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

    public function testCreateConfigRendersTemplateReplacements(): void
    {
        // createConfig() touches this real path when present; keep dev tests hermetic.
        if (is_readable('/etc/seedbox/config/localnet')) {
            throw new SkipTest('localnet config present on host; skipping rtorrentConfig render test');
        }

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
        if (is_readable('/etc/seedbox/config/localnet')) {
            throw new SkipTest('localnet config present on host; skipping rtorrentConfig memory guardrail test');
        }

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
        if (is_readable('/etc/seedbox/config/localnet')) {
            throw new SkipTest('localnet config present on host; skipping rtorrentConfig port default test');
        }

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

        ob_start();
        $result = $cfg->createConfig([
            'ram' => 500,
            'scgiPort' => 5000,
            'dhtPort' => 5001,
            'listenPort' => 5002,
            'pex' => 'auto',
            'dht' => 'yes',
        ]);
        $output = ob_get_clean();

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

    private function rtorrentConfigFixture(): \rtorrentConfig
    {
        return new \rtorrentConfig([
            'ramBlock' => 250,
            'peers' => ['minimum' => 1, 'maximum' => 2],
            'uploadSlots' => 1,
        ], "mem=##memoryMax\n");
    }
}
