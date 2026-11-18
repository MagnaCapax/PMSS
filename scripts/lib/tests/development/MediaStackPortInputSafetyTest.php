<?php
/** Verify corrupt media-stack port observations without accessing account files or ports. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class MediaStackPortInputSafetyTest extends TestCase
{
    /** Intercept every observation and refuse accidental reservation or publication. */
    private function observe(array $cases): array
    {
        $script = <<<'PHP'
namespace MediaStackPortInputFixture;
function realpath($path) { $GLOBALS['events'][] = ['realpath', $path]; return $path; }
function filesize($path) {
    $GLOBALS['events'][] = ['filesize', $path];
    return $GLOBALS['content'] === null ? false : strlen($GLOBALS['content']);
}
function pmssReadRegularFileContents($path) {
    $GLOBALS['events'][] = ['read', $path]; return $GLOBALS['content'];
}
function is_dir($path) { $GLOBALS['events'][] = ['is_dir', $path]; return true; }
function is_link($path) { $GLOBALS['events'][] = ['is_link', $path]; return false; }
function pmssPortManagerAssignServicePort() { throw new \RuntimeException('Unexpected allocation'); }
function pmssReplaceAccountFileWithLegacyFallback() { throw new \RuntimeException('Unexpected publication'); }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/mediaStackPorts.php', 'MediaStackPortInputFixture');
        $script .= <<<'PHP'
set_error_handler(static function ($severity, $message) { throw new \RuntimeException($message); });
$results = [];
foreach (json_decode(getenv('PMSS_TEST_MEDIA_STACK_PORT_INPUT'), true) as [$operation, $home, $app, $content, $relative]) {
    $GLOBALS['events'] = []; $GLOBALS['content'] = $content;
    $definition = pmssMediaStackPortDefinitions()[$app];
    if ($relative !== null) $definition['path'] = $relative;
    $result = $operation === 'ensure' ? pmssMediaStackPortsEnsure('fixtur01', $home)
        : pmssMediaStackConfiguredPortRead($home, $definition);
    $results[] = [$result, $GLOBALS['events']];
}
echo json_encode($results);
PHP;
        return $this->pmssRunInlinePhpJson($script, [
            'PMSS_TEST_MEDIA_STACK_PORT_INPUT' => json_encode($cases),
        ]);
    }

    public function testMalformedPathsStopBeforeFilesystemOrAllocation(): void
    {
        $cases = [];
        foreach (["\0", "\0/fixture/fixtur01", "/fixture/\0fixtur01", "/fixture/fixtur01\0", "/fixture/\0/fixtur01/"] as $home) {
            $cases[] = ['read', $home, 'radarr', '<Port>23001</Port>', null];
            $cases[] = ['ensure', $home, 'radarr', null, null];
        }
        foreach (["\0", "\0config.xml", "config\0.xml", "config.xml\0", ".config/\0/config.xml"] as $relative) {
            $cases[] = ['read', '/fixture/fixtur01', 'radarr', '<Port>23001</Port>', $relative];
        }
        $results = $this->observe($cases);
        $this->assertSame(count($cases), count($results));
        foreach ($results as $index => $result) {
            $this->assertSame([$cases[$index][0] === 'ensure' ? [] : null, []], $result);
        }
    }

    public function testCorruptConfigurationsCannotCertifyAnOtherwiseValidPort(): void
    {
        $cases = [];
        foreach (['sabnzbd' => "port = 23001\n", 'radarr' => '<Port>23001</Port>', 'openclaw' => "23001\n"] as $app => $content) {
            foreach (["\0".$content, $content."\0", $content."\nother=\0", substr_replace($content, "\0", 2, 0)] as $corrupt) {
                $cases[] = ['read', '/fixture/fixtur01', $app, $corrupt, null];
            }
        }
        $results = $this->observe($cases);
        $this->assertSame(count($cases), count($results));
        foreach ($results as [$port, $events]) {
            $this->assertSame(null, $port);
            $this->assertSame(['realpath', 'realpath', 'filesize', 'read'], array_column($events, 0));
        }
    }

    public function testOrdinaryInputsKeepPortsAndObservationOrder(): void
    {
        $samples = [
            ['sabnzbd', "port = 23001\n", 23001], ['autobrr', "port = \"23002\"\n", 23002],
            ['radarr', '<Port>2000</Port>', 2000], ['radarr', '<Port>38000</Port>', 38000],
            ['jellyfin', '<InternalHttpPort>23003</InternalHttpPort>', 23003],
            ['openclaw', "23004\r\n", 23004], ['radarr', '<Port>1999</Port>', null],
            ['radarr', '<Port>38001</Port>', null], ['openclaw', 'invalid', null],
            ['radarr', '', null], ['radarr', null, null],
        ];
        $cases = [];
        foreach ($samples as [$app, $content]) $cases[] = ['read', '/fixture/fixtur01/', $app, $content, null];
        $results = $this->observe($cases);
        $this->assertSame(count($samples), count($results));
        foreach ($results as $index => [$port, $events]) {
            $this->assertSame($samples[$index][2], $port);
            $stages = ['realpath', 'realpath', 'filesize'];
            if ($samples[$index][1] !== null && $samples[$index][1] !== '') $stages[] = 'read';
            $this->assertSame($stages, array_column($events, 0));
            $this->assertSame('/fixture/fixtur01', $events[0][1]);
        }
    }
}
