<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserTransferGuardTest extends TestCase
{
    public function testUserTransferWrapperDelegatesToLibrary(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/util/userTransfer.php',
            [
                'PMSS_LOG_FILE',
                "require_once __DIR__.'/../lib/userTransfer.php';",
                'pmssUserTransferMain',
            ],
            'userTransfer wrapper is missing: '
        );
    }

    public function testUserTransferLibraryAvoidsEmbeddingPasswordInScripts(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings(
            'scripts/lib/userTransfer/remoteScripts.php',
            ['PMSS_USER_TRANSFER_PASSWORD'],
            ['send "{$args' => 'userTransfer must not embed password literals']
        );
        $this->pmssAssertRepoFileContainsString(
            'scripts/lib/userTransfer/transferRuntime.php',
            'Remote user password:',
            'userTransfer runtime prompt is missing: '
        );
    }

    public function testUserTransferRequiresRoot(): void
    {
        $this->pmssAssertRepoFileContainsString('scripts/lib/userTransfer.php', 'must be run as root', 'userTransfer main flow must refuse non-root execution');
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/lib/userTransfer.php',
            ['posix_geteuid', 'pmssUserTransferParseCli'],
            'userTransfer main flow must contain: ',
            'userTransfer should refuse non-root execution before parsing CLI arguments: '
        );
    }

    public function testUserTransferLibraryLoadsSharedHelpersThroughTopLevelInclude(): void
    {
        require_once __DIR__.'/../../userTransfer.php';

        $this->assertTrue(\function_exists('pmssHostnameIsValid'), 'userTransfer should load hostname validation helper');
        $this->assertTrue(\function_exists('pmssUserTransferPostSetup'), 'userTransfer should load post-setup helper');
    }
}
