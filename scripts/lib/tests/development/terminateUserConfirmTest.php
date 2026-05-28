<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class TerminateUserConfirmTest extends TestCase
{
    public function testTerminateUserConfirmationLoopHandlesEof(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/terminateUser.php',
            ['confirmation input unavailable (EOF)', 'Unable to read confirmation input (EOF)'],
            'terminateUser.php should handle EOF confirmation: '
        );
    }
}
