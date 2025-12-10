<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/lib/userLifecycle.php';

class UsernameValidationTest extends TestCase
{
    public function testValidUsernamesPass(): void
    {
        $valid = ['a', 'abc', 'User123', 'abcdefg8'];
        foreach ($valid as $name) {
            $this->assertTrue(\pmssValidateUsername($name), 'Expected valid username: '.$name);
        }
    }

    public function testInvalidUsernamesFail(): void
    {
        $invalid = [
            '',
            '1user',      // starts with digit
            'user-name',  // dash not allowed
            'user.name',  // dot not allowed
            'user name',  // space
            'toolong89x', // longer than 8 chars
            'slash/user', // slash
            '..',         // path-like
        ];
        foreach ($invalid as $name) {
            $this->assertTrue(!\pmssValidateUsername($name), 'Expected invalid username: '.$name);
        }
    }
}
