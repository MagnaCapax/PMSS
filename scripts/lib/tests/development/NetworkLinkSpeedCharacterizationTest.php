<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Lock probe precedence and bytes without reading sysfs or launching ethtool. */
class NetworkLinkSpeedCharacterizationTest extends TestCase
{
    public function testConsumersUseTheSharedInterfaceBoundary(): void
    {
        foreach ([
            'scripts/cron/liveStatsLog.php' => "pmssNetworkInterfaceNameNormalize((string) (\$network['interface'] ?? 'eth0'))",
            'scripts/lib/update/apps/vnstat.php' => 'pmssNetworkInterfaceNameNormalize((string) $link)',
        ] as $path => $normalization) {
            $this->pmssAssertRepoFileContainsAllStrings($path, [$normalization]);
        }
    }

    public function testConfiguredCapsKeepLegacyCastsAndUnknownSpeedDefaults(): void
    {
        $script = <<<'PHP'
namespace NetworkCapFixture;
function networkLoadConfig() { return $GLOBALS['config']; }
function getDetectedLinkSpeed($iface) { $GLOBALS['calls'][] = $iface; return $GLOBALS['physical']; }
$GLOBALS['config'] = ['interface' => 'probe0', 'speed' => 42];
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/networkInfo.php', 'NetworkCapFixture');
        $script .= <<<'PHP'
$included = [$link, $linkSpeed];
$results = [];
foreach ([[['speed' => 0], 10000], [['speed' => -1], 10000],
    [['speed' => false], 10000], [['speed' => '1234'], 10000],
    [['speed' => 'unknown'], 10000], [['speed' => null], 2500], [[], 2500], [[], 0]] as [$config, $physical]) {
    $GLOBALS['config'] = ['interface' => 'probe0'] + $config;
    $GLOBALS['physical'] = $physical;
    $GLOBALS['calls'] = [];
    $results[] = [getLinkSpeed('probe0'), $GLOBALS['calls']];
}
echo json_encode(['included' => $included, 'results' => $results]);
PHP;
        $this->assertSame(['included' => ['probe0', 42], 'results' => [
            [0, []], [-1, []], [0, []], [1234, []], [0, []],
            [2500, ['probe0']], [2500, ['probe0']], [1000, ['probe0']],
        ]], $this->pmssRunInlinePhpJson($script));
    }

    public function testPhysicalObservationHasOneOrderedFallbackAndNoIncludeTimeProbes(): void
    {
        $script = <<<'PHP'
namespace NetworkLinkSpeedFixture;
function getenv($key) {
    if ($key !== 'PMSS_NETWORK_SYS_CLASS_NET_DIR') throw new \RuntimeException('unexpected environment key');
    return $GLOBALS['override'];
}
function file_get_contents($path) {
    $GLOBALS['calls'][] = ['sysfs', $path];
    return $GLOBALS['sysfs'];
}
function shell_exec($command) {
    $GLOBALS['calls'][] = ['ethtool', $command];
    return 'Speed: 2500Mb/s';
}
$GLOBALS['calls'] = [];
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/network/linkSpeed.php', 'NetworkLinkSpeedFixture');
        $script .= <<<'PHP'
$included = $GLOBALS['calls'];
$results = [];
foreach ([
    [false, '10000', ' eth0 '], [false, '-1', 'eth0'], [false, '0', 'eth0'],
    [false, false, 'eth0'], [false, (string) PHP_INT_MAX.'0', 'eth0'],
    ['/fixture/net/', '-1', 'eth0'], ['/fixture/net/', false, 'eth0'],
    ['/fixture/net/', '001000', 'eth0'], ['/fixture/net/', '0', 'eth0'],
    ['', false, 'eth0'], [" \t", false, 'eth0'], ["/fixture\0/net", false, 'eth0'],
    [false, '10000', "eth0\0"], [false, '10000', 'bad!'],
] as [$override, $sysfs, $iface]) {
    $GLOBALS['override'] = $override;
    $GLOBALS['sysfs'] = $sysfs;
    $GLOBALS['calls'] = [];
    $results[] = [getDetectedLinkSpeed($iface), $GLOBALS['calls']];
}
echo json_encode(['included' => $included, 'results' => $results]);
PHP;
        $sysfs = ['sysfs', '/sys/class/net/eth0/speed'];
        $fixture = ['sysfs', '/fixture/net/eth0/speed'];
        $fallback = [$sysfs, ['ethtool', "/sbin/ethtool 'eth0' 2>/dev/null"]];
        $this->assertSame(['included' => [], 'results' => [
            [10000, [$sysfs]], [2500, $fallback], [2500, $fallback],
            [2500, $fallback], [2500, $fallback],
            [0, [$fixture]], [0, [$fixture]], [1000, [$fixture]], [0, [$fixture]],
            [2500, $fallback], [2500, $fallback], [2500, $fallback],
            [0, []], [0, []],
        ]], $this->pmssRunInlinePhpJson($script));
    }
}
