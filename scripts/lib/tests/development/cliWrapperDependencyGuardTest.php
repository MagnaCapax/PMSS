<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class CliWrapperDependencyGuardTest extends TestCase
{
    public function testPmssStatsWrapperGuardsMissingLibraryBeforeRequire(): void
    {
        $source = $this->pmssReadRepoFile('scripts/pmss-stats.php');

        $this->assertStringContainsString("if (!is_file(\$pmssStatsLib))", $source);
        $this->assertStringContainsString('pmss stats library missing; aborting.', $source);
        $this->assertStringContainsString('require_once $pmssStatsLib;', $source);
    }

    public function testSetupSkelPermissionsWrapperGuardsRenamedEntrypoint(): void
    {
        $source = $this->pmssReadRepoFile('scripts/util/setupSkelPermissions.php');

        $this->assertStringContainsString("if (!is_file(\$setupPermissions))", $source);
        $this->assertStringContainsString('setupPermissions.php missing; aborting wrapper.', $source);
        $this->assertStringContainsString('require $setupPermissions;', $source);
    }
}
