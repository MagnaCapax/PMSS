<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class CliWrapperDependencyGuardTest extends TestCase
{
    public function testCliWrappersGuardMissingTargetsBeforeRequire(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/util/setupSkelPermissions.php' => ['required' => ["if (!is_file(\$setupPermissions))", 'setupPermissions.php missing; aborting wrapper.', 'require $setupPermissions;']],
        ]);
    }
}
