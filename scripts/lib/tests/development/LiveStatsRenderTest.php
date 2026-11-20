<?php
/** Render live-statistics labels and legends using synthetic customer data only. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class LiveStatsRenderTest extends TestCase
{
    public function testTrendCopyWithKnownUnknownAndMissingSamples(): void
    {
        $load = $this->pmssInlinePhpLibraryInNamespace('etc/skel/www/statsLive.php', 'LiveStatsRenderFixture');
        $results = $this->pmssRunInlinePhpJson(<<<'PHP'
namespace LiveStatsRenderFixture;
// Intercept identity and ring reads so the renderer never observes the host.
function function_exists($name) { return $name === 'posix_getpwuid' || \function_exists($name); }
function posix_geteuid() { return 1000; }
function posix_getpwuid($uid) { return ['name' => 'fixtur01']; }
function is_link($path) { return false; }
function is_file($path) { return isset($GLOBALS['rings'][$path]); }
function file_get_contents($path) { return json_encode(['samples' => $GLOBALS['rings'][$path]]); }
PHP
            .$load.<<<'PHP'
$results = [];
foreach (['known', 'unknown', 'old-ring', 'no-own-samples', 'collecting'] as $case) {
    $node = $mine = [];
    foreach ([1000, 1300] as $t) {
        $sample = ['t' => $t, 'dt' => 300, 'cb' => 300, 'ct' => 600,
            'mu' => 500, 'mt' => 1000, 'tx' => 3750000, 'rx' => 1875000];
        if ($case !== 'old-ring') {
            $sample += ['tx_capacity' => $case === 'unknown' ? 0 : 1000,
                'rx_capacity' => $case === 'unknown' ? 0 : 1000];
        }
        $node[] = $sample;
        $mine[] = ['t' => $t, 'cpu' => 60, 'mem' => 100, 'tx' => 375000, 'rx' => 0];
    }
    $GLOBALS['rings'] = $case === 'collecting' ? [] : ['/var/lib/pmss/public/node-live.json' => $node];
    if ($case !== 'no-own-samples') $GLOBALS['rings']['/var/lib/pmss/live/fixtur01.json'] = $mine;
    ob_start();
    try { pmssStatsLiveRender(); $results[$case] = ob_get_contents(); }
    finally { ob_end_clean(); }
}
echo json_encode($results);
PHP
        );
        foreach ($results as $case => $html) {
            $this->assertStringNotContainsString('24 h trend', $html);
            if ($case === 'collecting') {
                $this->assertStringContainsString('Live statistics are being collected.', $html);
                $this->assertStringNotContainsString('<table', $html);
                continue;
            }
            $this->assertStringContainsAllStrings(['<th>Last 24 h</th>', '<th>CPU</th>', '<th>RAM</th>',
                '<th>Network out</th>', '<th>Network in</th>',
                'Thin lines show the node total and your own usage for each row over the last 24 hours.',
                'node</span> = whole server', 'you</span> = your own usage.'], $html);
            // Unknown network capacity still hides just the network lines.
            $this->assertSame(in_array($case, ['unknown', 'old-ring'], true) ? 4 : 8,
                substr_count($html, '<polyline'));
        }
    }
}
