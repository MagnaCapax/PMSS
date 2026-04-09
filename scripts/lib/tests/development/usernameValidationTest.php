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
            $this->assertTrue(\pmssUsernameIsValidForCreate($name), 'Expected create-valid username: '.$name);
        }

        $invalid = ['a', 'ab', '1user', 'user-name', 'User123', 'toolong89x'];
        foreach ($invalid as $name) {
            $this->assertTrue(!\pmssUsernameIsValidForCreate($name), 'Expected create-invalid username: '.$name);
        }
    }

    public function testCreateUsernamesRejectReservedNames(): void
    {
        foreach (\pmssReservedUsernames() as $name) {
            $this->assertTrue(!\pmssUsernameIsValidForCreate($name), 'Expected reserved username to be rejected: '.$name);
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

    public function testPasswdEntryLookupRequiresExactUserMatch(): void
    {
        $passwd = $this->pmssMakeTempDir('pmss-passwd-').'/passwd';
        file_put_contents($passwd, implode("\n", [
            'alice2:x:1000:1000::/home/alice2:/bin/bash',
            'alice:x:1001:1001::/home/alice:/bin/bash',
            '',
        ]));

        $entry = \pmssPasswdEntryLookup('alice', $passwd);

        $this->assertTrue(is_array($entry));
        $this->assertEquals('alice', $entry['name']);
        $this->assertEquals(1001, $entry['uid']);
        $this->assertEquals('/home/alice', $entry['dir']);
    }

    public function testPasswdEntryLookupRejectsInvalidUsernameAndSymlinkPath(): void
    {
        $root = $this->pmssMakeTempDir('pmss-passwd-link-');
        $passwd = $root.'/passwd';
        $link = $root.'/passwd.link';
        file_put_contents($passwd, "alice:x:1001:1001::/home/alice:/bin/bash\n");
        symlink($passwd, $link);

        $this->assertEquals(null, \pmssPasswdEntryLookup('../alice', $passwd));
        $this->assertEquals(null, \pmssPasswdEntryLookup('alice', $link));
    }

    public function testColonRecordFieldsLookupRequiresExactMatchAndMinimumFields(): void
    {
        $passwd = $this->pmssMakeTempDir('pmss-passwd-fields-').'/passwd';
        file_put_contents($passwd, implode("\n", [
            'alice2:x:1000:1000::/home/alice2:/bin/bash',
            'alice:x:1001:1001::/home/alice:/bin/bash',
            'broken:x',
            '',
        ]));

        $this->assertEquals(
            ['alice', 'x', '1001', '1001', '', '/home/alice', '/bin/bash'],
            \pmssColonRecordFieldsLookup($passwd, 'alice', 7, false)
        );
        $this->assertEquals(null, \pmssColonRecordFieldsLookup($passwd, 'ali', 7, false));
        $this->assertEquals(null, \pmssColonRecordFieldsLookup($passwd, 'broken', 7, false));
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
