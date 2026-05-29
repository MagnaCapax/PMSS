<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class CliWrapperDependencyGuardTest extends TestCase
{
    public function testPmssStatsWrapperGuardsMissingLibraryBeforeRequire(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/pmss-stats.php', ["if (!is_file(\$pmssStatsLib))", 'pmss stats library missing; aborting.', 'require_once $pmssStatsLib;']);
    }

    public function testSetupSkelPermissionsWrapperGuardsRenamedEntrypoint(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/util/setupSkelPermissions.php', ["if (!is_file(\$setupPermissions))", 'setupPermissions.php missing; aborting wrapper.', 'require $setupPermissions;']);
    }
}
