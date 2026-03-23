<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../common/UserConfigCgroupCliTrait.php';

class UserCgroupCliWipeDryRunTest extends TestCase
{
    use UserConfigCgroupCliTrait;

    public function testWipeIsNoopUnderDryRun(): void
    {
        $out = $this->pmssRunUserConfigCgroupCli(['root', '--apply', '--dry-run', '--wipe']);
        $this->assertStringContainsString('(dry-run or no --apply; not changing system)', $out);
        $this->assertTrue(strpos($out, 'Reverting user slice') === false);
    }

    public function testDryRunPrintsPlannedWhenPropsPresent(): void
    {
        $out = $this->pmssRunUserConfigCgroupCli(['root', '--apply', '--dry-run', '--cpu-weight=123', '--io-weight=321']);
        $this->assertStringContainsString('Planned properties', $out);
        $this->assertStringContainsString('CPUWeight=123', $out);
        $this->assertStringContainsString('IOWeight=321', $out);
    }
}
