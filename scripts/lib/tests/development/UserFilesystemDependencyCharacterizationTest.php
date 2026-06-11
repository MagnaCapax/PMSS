<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/identity.php';

class UserFilesystemDependencyCharacterizationTest extends TestCase
{
    public function testFilesystemHelperDependsOnFocusedUserModules(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings(
            'scripts/lib/user/userFilesystem.php',
            [
                "require_once __DIR__.'/selection.php';",
                'pmssRequireCliUsername($rawUsername, $action, $errorFormat, $logMessage);',
                'pmssListManagedUsers($command)',
                'pmssUserAccountLookup($name)',
            ],
            [
                "require_once dirname(__DIR__).'/userLifecycle.php';" => 'userFilesystem.php should not reload the lifecycle facade',
            ]
        );
    }

    public function testCliUsernameNormalizationLivesWithIdentityHelpers(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/lib/user/identity.php',
            [
                'function pmssRequireCliUsername(',
                'pmssUserLifecycleContextLogStatusMessage($action, \'validate\', $normalized, \'ERR\', $logMessage);',
            ],
            'CLI username normalization should live with identity helpers: '
        );
        $this->pmssAssertRepoFileNotContainsString(
            'scripts/lib/userLifecycle.php',
            'function pmssRequireCliUsername(',
            'userLifecycle.php should compose focused modules instead of owning identity validation'
        );

        $this->assertSame('alice', \pmssRequireCliUsername(' Alice ', 'test', 'Invalid username %s'));
    }
}
