<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class TerminateUserConfirmTest extends TestCase
{
    public function testTerminateUserConfirmationLoopHandlesEof(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../../terminateUser.php');
        $this->assertTrue(strpos($src, 'confirmation input unavailable (EOF)') !== false, 'terminateUser.php should abort when STDIN is EOF');
        $this->assertTrue(strpos($src, 'Unable to read confirmation input (EOF)') !== false, 'terminateUser.php should log non-interactive confirmation failure');
    }
}
