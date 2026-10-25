<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class NetworkInfoCharacterizationTest extends TestCase
{
    public function testCommandProbesRejectMalformedOutputWithoutChangingValidCommands(): void
    {
        // Stub every shell call so malformed names can never reach a real probe.
        $script = <<<'PHP'
namespace NetworkProbeFixture;
function networkLoadConfig() { return $GLOBALS['config']; }
function shell_exec($command) { $GLOBALS['commands'][] = $command; return $GLOBALS['raw']; }
$GLOBALS['commands'] = [];
$GLOBALS['config'] = ['interface' => 'fixture0', 'speed' => 1000];
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/networkInfo.php', 'NetworkProbeFixture');
        $script .= <<<'PHP'
$results = [];
foreach (['', 'Speed: Unknown!', 'Speed: -1Mb/s', 'Speed: 0Mb/s',
    'Speed: '.PHP_INT_MAX.'0Mb/s', 'Speed: 000'.PHP_INT_MAX.'0Mb/s',
    'Speed: 001000Mb/s', 'Speed: '.PHP_INT_MAX.'Mb/s'] as $raw) {
    $GLOBALS['raw'] = $raw;
    $results[] = pmssNetworkProbeEthtoolLinkSpeed(' fixture0 ');
}
$before = count($GLOBALS['commands']);
foreach (["\0fixture0", "fixture0\0", "fix\0ture0", " \0fixture0\t", "fixture0\0\n"] as $name) {
    $results[] = pmssNetworkProbeEthtoolLinkSpeed($name);
}
$rejectedCalls = count($GLOBALS['commands']) - $before;
$speedCommands = $GLOBALS['commands'];
$GLOBALS['commands'] = $GLOBALS['config'] = [];
$routes = [];
foreach (["\0route9", "route9\0", "rou\0te9", " \0route9\t", "route9\0\n", " \troute9\n"] as $raw) {
    $GLOBALS['raw'] = $raw;
    $routes[] = detectPrimaryInterface();
}
echo json_encode(['speeds' => $results, 'commands' => $speedCommands,
    'rejectedCalls' => $rejectedCalls, 'routes' => $routes, 'routeCommands' => $GLOBALS['commands']]);
PHP;
        $result = $this->pmssRunInlinePhpJson($script);
        $this->assertSame([0, 0, 0, 0, 0, 0, 1000, PHP_INT_MAX, 0, 0, 0, 0, 0], $result['speeds']);
        $this->assertSame(0, $result['rejectedCalls']);
        $this->assertSame(array_fill(0, 8, "/sbin/ethtool 'fixture0' 2>/dev/null"), $result['commands']);
        $this->assertSame(['eth0', 'eth0', 'eth0', 'eth0', 'eth0', 'route9'], $result['routes']);
        $this->assertSame(array_fill(0, 6, "/sbin/ip route | awk '/default/ {print \$5; exit}'"), $result['routeCommands']);
    }

    public function testGlobalsStayAlignedWithHelperResults(): void
    {
        $result = $this->pmssRunRepoInlinePhpRequireJson('scripts/lib/networkInfo.php', 'echo json_encode(['
            .'"link" => $link,'
            .'"linkSpeed" => $linkSpeed,'
            .'"detect" => detectPrimaryInterface(),'
            .'"speed" => getLinkSpeed(detectPrimaryInterface()),'
            .']);', [], '2>/dev/null', true);

        $this->assertSame($result['detect'], $result['link']);
        $this->assertSame($result['speed'], $result['linkSpeed']);
    }

    public function testConfigProbeDelegatesToSharedHelper(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings(
            'scripts/lib/networkInfo.php',
            ["require_once __DIR__.'/network/config.php';", 'networkLoadConfig()'],
            ['/etc/seedbox/config/network']
        );
    }

    public function testSetupNetworkKeepsConfiguredCapAndWarnsOnPhysicalSpeedDrift(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings(
            'scripts/lib/network/setup.php',
            [
                '$detectedLinkSpeed = getDetectedLinkSpeed($interface);',
                'detected %s link speed %dMbit exceeds configured FireQOS speed %dMbit; keeping configured cap',
            ]
        );
        $this->pmssAssertRepoFileContainsAndOmitsStrings(
            'scripts/util/setupNetwork.php',
            [
                '$renderedIptables = networkIptablesRenderCommandSets($commands[\'filter\'], $commands[\'nat\'], $replacements);',
                '$fireqosNetworkConfig[\'interface\'] = $interface;',
                '$fireqosNetworkConfig[\'speed\'] = $linkSpeed;',
            ],
            [
                '$networkConfig + [\'interface\' => $interface, \'speed\' => $networkConfig[\'speed\'] ?? $linkSpeed]',
            ]
        );
    }
}
