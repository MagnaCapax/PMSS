<?php
/** Verify block-device topology input guards without probing the host filesystem. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class BlockDeviceLeavesInputSafetyTest extends TestCase
{
    /** Intercept filesystem operations and both callbacks in the topology walker. */
    private function walk(string $root, string $device = 'sda', string $mode = 'leaf'): array
    {
        $script = <<<'PHP'
namespace BlockDeviceLeavesInputFixture;
function is_dir($path) {
    $GLOBALS['events'][] = ['is_dir', $path];
    return $GLOBALS['mode'] !== 'missing';
}
function is_file($path) {
    $GLOBALS['events'][] = ['is_file', $path];
    return false;
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/blockDeviceLeaves.php', 'BlockDeviceLeavesInputFixture');
        $script .= <<<'PHP'
[$root, $device, $GLOBALS['mode']] = json_decode(getenv('PMSS_TEST_BLOCK_LEAVES_INPUT'), true);
$GLOBALS['events'] = [];
$globber = static function ($pattern) {
    $GLOBALS['events'][] = ['glob', $pattern];
    return $GLOBALS['mode'] === 'failed' ? false : [];
};
$visit = static function ($name) { $GLOBALS['events'][] = ['visit', $name]; };
$result = pmssBlockDeviceLeaves($device, $root, $globber, $visit);
echo json_encode([$result, $GLOBALS['events']]);
PHP;
        return $this->pmssRunInlinePhpJson($script, [
            'PMSS_TEST_BLOCK_LEAVES_INPUT' => json_encode([$root, $device, $mode]),
        ]);
    }

    public function testMalformedRootsStopBeforeProbesAndCallbacks(): void
    {
        foreach (['', "\0", "\0/fixture/sys/block", "/fixture/sys/\0block", "/fixture/sys/block\0"] as $root) {
            $this->assertSame([['leaves' => [], 'complete' => false], []], $this->walk($root));
        }
    }

    public function testValidRootsKeepLiteralPathBytesAndProbeOrder(): void
    {
        // Relative roots and whitespace remain supported; this guard does not normalize paths.
        foreach (['/fixture/sys/block', 'fixture/sys/block', '0', '/fixture/sys block', '/fixture/sys/block/'] as $root) {
            $path = $root.'/sda';
            $this->assertSame([['leaves' => ['sda'], 'complete' => true], [
                ['visit', 'sda'], ['is_dir', $path], ['glob', $path.'/slaves/*'], ['is_file', $path.'/partition'],
            ]], $this->walk($root));
        }
    }

    public function testMalformedDevicesKeepTheirExistingEarlyRefusal(): void
    {
        foreach (['', '.', '..', 'disk/name', "disk\nname", "sda\0"] as $device) {
            $this->assertSame([['leaves' => [], 'complete' => false], []], $this->walk('/fixture/sys/block', $device));
        }
    }

    public function testFailedAndMissingTopologyRemainIncomplete(): void
    {
        foreach (['failed' => '/slaves/*', 'missing' => ''] as $mode => $suffix) {
            $pattern = $mode === 'missing' ? '/fixture/sys/block/*/sda/partition' : '/fixture/sys/block/sda'.$suffix;
            $this->assertSame([['leaves' => [], 'complete' => false], [
                ['visit', 'sda'], ['is_dir', '/fixture/sys/block/sda'], ['glob', $pattern],
            ]], $this->walk('/fixture/sys/block', 'sda', $mode));
        }
    }
}
