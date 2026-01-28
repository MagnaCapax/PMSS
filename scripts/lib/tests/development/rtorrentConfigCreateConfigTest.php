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
}
