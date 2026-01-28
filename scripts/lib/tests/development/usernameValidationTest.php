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
        $reserved = [
            'root',
            'daemon',
            'bin',
            'sys',
            'sync',
            'games',
            'man',
            'lp',
            'mail',
            'news',
            'uucp',
            'proxy',
            'www',
            'backup',
            'list',
            'irc',
            'gnats',
            'nobody',
            'sshd',
            'dbus',
            'rtkit',
            'avahi',
            'pulse',
            'ntp',
            'proftpd',
            'syslog',
            'polkitd',
            'uuidd',
        ];

        foreach ($reserved as $name) {
            $this->assertTrue(!\pmssValidateUsernameForCreate($name), 'Expected reserved username to be rejected: '.$name);
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
            '..',         // path-like
        ];
        foreach ($invalid as $name) {
            $this->assertTrue(!\pmssValidateUsername($name), 'Expected invalid username: '.$name);
        }
    }
}
