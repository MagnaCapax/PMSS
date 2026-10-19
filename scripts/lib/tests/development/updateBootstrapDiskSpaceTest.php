<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateBootstrapDiskSpaceTest extends TestCase
{
    public function testBootstrapAndPhase2DecisionsMatch(): void
    {
        $outputs = [null, '', '5767168 4096', '524288 4096', '10 0', 'bad', str_repeat('9', 400).' 4096'];
        $inputs = [
            ['/home', 0, 0.0, 22.0 * 1073741824],
            ['/home', 0, 0.0, 2.0 * 1073741824],
            ['/home', 0, 4.0 * 1073741824, null],
            ['/home', 1000, 0.0, 22.0 * 1073741824],
            ['/', 0, 0.0, 22.0 * 1073741824],
        ];
        $script = '$outputs = '.var_export($outputs, true).'; $inputs = '.var_export($inputs, true).';'
            .'echo json_encode([array_map("pmssRootUsableDiskBytes", $outputs), array_map(function ($row) {'
            .'return pmssDiskPreflightAvailableBytes(...$row); }, $inputs)], JSON_PRESERVE_ZERO_FRACTION);';
        $phase2 = $this->pmssRunRepoInlinePhpRequireJson('scripts/lib/update/diskSpace.php', $script);
        $bootstrap = [array_map('\\pmssRootUsableDiskBytes', $outputs), array_map(static function (array $row): float {
            return \pmssDiskPreflightAvailableBytes(...$row);
        }, $inputs)];
        $this->assertSame($bootstrap, $phase2);
    }

    public function testRootHomeUsesReservedBlocksWhenUserSpaceIsZero(): void
    {
        $rootUsable = \pmssRootUsableDiskBytes('5767168 4096');
        $this->assertSame(22.0 * 1073741824, $rootUsable);
        $this->assertTrue(\pmssDiskPreflightAvailableBytes('/home', 0, 0.0, $rootUsable) >= 3.0 * 1073741824);
    }

    public function testRootHomeFailsWhenReservedBlocksAreInsufficient(): void
    {
        $rootUsable = \pmssRootUsableDiskBytes('524288 4096');
        $available = \pmssDiskPreflightAvailableBytes('/home', 0, 0.0, $rootUsable);
        $this->assertSame(2.0 * 1073741824, $available);
        $this->assertTrue($available < 3.0 * 1073741824);
    }

    public function testMalformedStatFallsBackToUserAvailable(): void
    {
        foreach ([null, '', 'invalid', '10', '10 0', '-1 4096', '10 4096 extra', str_repeat('9', 400).' 4096'] as $output) {
            $this->assertSame(null, \pmssRootUsableDiskBytes($output));
            $this->assertSame(0.0, \pmssDiskPreflightAvailableBytes('/home', 0, 0.0, \pmssRootUsableDiskBytes($output)));
        }
    }

    public function testNonRootHomeUsesUserAvailable(): void
    {
        $this->assertSame(0.0, \pmssDiskPreflightAvailableBytes('/home', 1000, 0.0, 22.0 * 1073741824));
    }

    public function testRootFilesystemAlwaysUsesUserAvailable(): void
    {
        $this->assertSame(0.0, \pmssDiskPreflightAvailableBytes('/', 0, 0.0, 22.0 * 1073741824));
    }

    public function testRootHomeFallsBackWhenStatCommandFails(): void
    {
        $this->assertSame(4.0 * 1073741824, \pmssDiskPreflightAvailableBytes('/home', 0, 4.0 * 1073741824, null));
    }
}
