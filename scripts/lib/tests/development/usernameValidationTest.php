<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/lib/userLifecycle.php';

class UsernameValidationTest extends TestCase
{
    public function testValidUsernamesPass(): void
    {
        $valid = ['a', 'abc', 'user123', 'abcdefg8'];
        foreach ($valid as $name) {
            $this->assertTrue(\pmssValidateUsername($name), 'Expected valid username: '.$name);
        }
    }

    public function testCreateUsernamesRequireMinLengthThree(): void
    {
        $valid = ['abc', 'user123', 'abcdefg8'];
        foreach ($valid as $name) {
            $this->assertTrue(\pmssValidateUsernameForCreate($name), 'Expected create-valid username: '.$name);
        }

        $invalid = ['a', 'ab', '1user', 'user-name', 'User123', 'toolong89x'];
        foreach ($invalid as $name) {
            $this->assertTrue(!\pmssValidateUsernameForCreate($name), 'Expected create-invalid username: '.$name);
        }
    }

    public function testCreateUsernamesRejectReservedNames(): void
    {
        foreach (\pmssReservedUsernames() as $name) {
            $this->assertTrue(!\pmssValidateUsernameForCreate($name), 'Expected reserved username to be rejected: '.$name);
        }
    }

    public function testReservedListIncludesHighRiskNames(): void
    {
        $mustInclude = [
            'root', 'www', 'nginx', 'mysql', 'postgres', 'redis', 'mongodb', 'apache', 'docker',
            'messagebus', 'chrony', 'openvpn', 'seedbox', 'rtorrent', 'deluge', 'qbittorrent',
            'lighttpd', 'rutorrent', 'srvadmin', 'srvapi', 'pmcseed', 'pmcdn', 'srvmgmt',
        ];
        foreach ($mustInclude as $name) {
            $this->assertTrue(\pmssUsernameIsReserved($name), 'Expected reserved username to be listed: '.$name);
        }
    }

    public function testCreateValidationErrorClassifiesInvalidInputs(): void
    {
        $cases = [
            ['seedbox@anyemail.com', 'email_not_allowed'],
            ['1user', 'invalid_format'],
            ['ab', 'too_short'],
            ['seedbox', 'reserved'],
        ];

        foreach ($cases as $case) {
            $error = \pmssUsernameCreateValidationError($case[0]);
            $this->assertTrue(is_array($error), 'Expected error payload for username: '.$case[0]);
            $this->assertEquals($case[1], $error['code'], 'Unexpected error code for username: '.$case[0]);
        }

        $this->assertEquals(null, \pmssUsernameCreateValidationError('abc123'));
    }

    public function testUsernameNormalizeIfValidReturnsCanonicalUsernames(): void
    {
        $cases = [
            [' User1 ', 'user1'],
            ['abc123', 'abc123'],
            ['user-name', null],
            ['', null],
        ];

        foreach ($cases as $case) {
            $this->assertEquals($case[1], \pmssUsernameNormalizeIfValid($case[0]));
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
            'User123',    // uppercase not allowed
            "user\n",     // trailing newline
            '..',         // path-like
        ];
        foreach ($invalid as $name) {
            $this->assertTrue(!\pmssValidateUsername($name), 'Expected invalid username: '.$name);
        }
    }
}
