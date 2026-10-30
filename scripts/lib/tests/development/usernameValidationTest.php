<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/lib/user/identity.php';

class UsernameValidationTest extends TestCase
{
    public function testValidUsernamesPass(): void
    {
        foreach (['a', 'abc', 'user123', 'abcdefg8'] as $name) {
            $this->assertTrue(\pmssValidateUsername($name), 'Expected valid username: '.$name);
        }
    }

    public function testCreateUsernamesRequireMinLengthThree(): void
    {
        foreach (['abc', 'user123', 'abcdefg8'] as $name) {
            $this->assertTrue(\pmssUsernameIsValidForCreate($name), 'Expected create-valid username: '.$name);
        }

        foreach (['a', 'ab', '1user', 'user-name', 'User123', 'toolong89x'] as $name) {
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
        foreach ([
            'seedbox@anyemail.com' => 'email_not_allowed',
            '1user' => 'invalid_format',
            'ab' => 'too_short',
            'seedbox' => 'reserved',
        ] as $name => $code) {
            $error = \pmssUsernameCreateValidationError($name);
            $this->assertTrue(is_array($error), 'Expected error payload for username: '.$name);
            $this->assertEquals($code, $error['code'], 'Unexpected error code for username: '.$name);
        }

        $this->assertEquals(null, \pmssUsernameCreateValidationError('abc123'));
    }

    public function testCreateValidationBooleanMatchesClassifierForCanonicalNames(): void
    {
        foreach (array('abc' => true, 'user123' => true, 'a' => false, 'ab' => false, '1user' => false, 'seedbox' => false, 'toolong89x' => false) as $name => $expected) {
            $classifierAllowsCreate = \pmssUsernameCreateValidationError($name) === null;
            $this->assertEquals($expected, \pmssUsernameIsValidForCreate($name), 'Unexpected boolean result for '.$name);
            $this->assertEquals($expected, $classifierAllowsCreate, 'Unexpected classifier result for '.$name);
        }
    }

    public function testUsernameNormalizeIfValidReturnsCanonicalUsernames(): void
    {
        foreach ([' User1 ' => 'user1', 'abc123' => 'abc123', 'user-name' => null, '' => null] as $name => $expected) {
            $this->assertEquals($expected, \pmssUsernameNormalizeIfValid($name));
        }
    }

    public function testPasswdEntryLookupRequiresExactUserMatch(): void
    {
        $passwd = $this->pmssWriteFile($this->pmssMakeTempDir('pmss-passwd-').'/passwd', implode("\n", [
            'fixtur012:x:1000:1000::/home/fixtur012:/bin/bash',
            'fixtur01:x:1001:1001::/home/fixtur01:/bin/bash',
            '',
        ]));

        $entry = \pmssPasswdEntryLookup('fixtur01', $passwd);

        $this->assertTrue(is_array($entry));
        $this->assertEquals('fixtur01', $entry['name']);
        $this->assertEquals(1001, $entry['uid']);
        $this->assertEquals('/home/fixtur01', $entry['dir']);
    }

    public function testPasswdEntryLookupRejectsInvalidUsernameAndSymlinkPath(): void
    {
        $root = $this->pmssMakeTempDir('pmss-passwd-link-');
        $passwd = $root.'/passwd';
        $link = $root.'/passwd.link';
        $this->pmssWriteFile($passwd, "fixtur01:x:1001:1001::/home/fixtur01:/bin/bash\n");
        $this->pmssCreateSymlinkOrSkip($passwd, $link);

        $this->assertEquals(null, \pmssPasswdEntryLookup('../fixtur01', $passwd));
        $this->assertEquals(null, \pmssPasswdEntryLookup('fixtur01', $link));
    }

    public function testColonRecordFieldsLookupRequiresExactMatchAndMinimumFields(): void
    {
        $passwd = $this->pmssWriteFile($this->pmssMakeTempDir('pmss-passwd-fields-').'/passwd', implode("\n", [
            'fixtur012:x:1000:1000::/home/fixtur012:/bin/bash',
            'fixtur01:x:1001:1001::/home/fixtur01:/bin/bash',
            'broken:x',
            '',
        ]));

        $this->assertEquals(
            ['fixtur01', 'x', '1001', '1001', '', '/home/fixtur01', '/bin/bash'],
            \pmssColonRecordFieldsLookup($passwd, 'fixtur01', 7, false)
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

    public function testTestAccountPrefixIsReservedForCreateUnlessMarked(): void
    {
        $this->pmssWithEnv(['PMSS_TEST_ACCOUNT' => null], function (): void {
            foreach (['qatst1a2', 'qatst', 'QATST9'] as $name) {
                $error = \pmssUsernameCreateValidationError($name);
                $this->assertTrue(is_array($error) && $error['code'] === 'reserved', 'Expected the test-account prefix to be refused: '.$name);
                $this->assertTrue(!\pmssUsernameIsValidForCreate($name), 'Expected create to refuse: '.$name);
            }
            $this->assertEquals(null, \pmssUsernameCreateValidationError('qatbob'));
            // The exact-name list is untouched: other readers of it (per-user log gating) see no change.
            $this->assertTrue(!\pmssUsernameIsReserved('qatst1a2'), 'The prefix is not an exact reserved name');
            putenv('PMSS_TEST_ACCOUNT=0');
            $this->assertTrue(is_array(\pmssUsernameCreateValidationError('qatst1a2')), 'Only the value 1 marks a test account');
            putenv('PMSS_TEST_ACCOUNT=1');
            $this->assertEquals(null, \pmssUsernameCreateValidationError('qatst1a2'));
            $this->assertTrue(\pmssUsernameIsValidForCreate('qatst1a2'), 'A marked create may use the prefix');
            $this->assertEquals('reserved', \pmssUsernameCreateValidationError('seedbox')['code'] ?? null);
        });
    }
}
