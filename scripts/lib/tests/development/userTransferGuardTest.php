<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserTransferGuardTest extends TestCase
{
    public function testUserTransferWrapperDelegatesToLibrary(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../../util/userTransfer.php');

        $this->assertStringContainsString('PMSS_LOG_FILE', $src, 'userTransfer should set PMSS_LOG_FILE');
        $this->assertStringContainsString("require_once __DIR__.'/../lib/userTransfer.php';", $src, 'userTransfer should require the shared implementation');
        $this->assertStringContainsString('pmssUserTransferMain', $src, 'userTransfer should call pmssUserTransferMain');
    }

    public function testUserTransferLibraryAvoidsEmbeddingPasswordInScripts(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../userTransfer.php');

        $this->assertStringContainsString('PMSS_USER_TRANSFER_PASSWORD', $src, 'expected password to be sourced from env');
        $this->assertTrue(strpos($src, 'send "{$args') === false, 'userTransfer must not embed password literals');
    }

    public function testUserTransferRequiresRoot(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../userTransfer.php');

        $this->assertStringContainsString('posix_geteuid', $src, 'userTransfer must check effective UID');
        $this->assertStringContainsString('must be run as root', $src, 'userTransfer must refuse non-root execution');
    }
}
