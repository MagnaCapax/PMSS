<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class RecreateUserSafetyGuardTest extends TestCase
{
    public function testRecreateUserSafetyGuardsRemainInPlace(): void
    {
        $this->pmssAssertRepoFileContract('scripts/recreateUser.php', ['required' => [
                "pmssRequireSafeRecreateUserPath(\$homeDir, 'home');",
                "pmssRequireSafeRecreateUserPath(\$backupDir, 'backup');",
                'Refusing to operate on symlinked',
                '$realHome = realpath($homeDir);',
                'Refusing to operate on unexpected home path',
                '!@mkdir($dir, 0755, true) && !is_dir($dir)',
                'Unable to create required directory',
                '$stat = @stat($homeDir);',
                'Validation failed: unable to stat homeDir',
            ]]);
    }
}
