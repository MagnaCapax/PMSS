<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/user/UserValidator.php';

class UserValidatorTest extends TestCase
{
    public function testIsValidUsername(): void
    {
        foreach (['alice_01', 'bob-02', 'user_123'] as $username) {
            $this->assertTrue(\UserValidator::isValidUsername($username), $username);
        }

        foreach (['Admin', 'bad user', 'evil!', '../etc/passwd', 'user/../foo', "admin\x00", "user\u200Dname"] as $username) {
            $this->assertFalse(\UserValidator::isValidUsername($username), $username);
        }
    }

    public function testValidatePayloadRequiresFields(): void
    {
        $valid = [
            'ramMiB'       => 128,
            'rtorrentPort' => 5000,
            'quota'        => 20,
            'quotaBurst'   => 30,
        ];
        $this->assertTrue(\UserValidator::validatePayload($valid));
        unset($valid['quotaBurst']);
        $this->assertFalse(\UserValidator::validatePayload($valid));

        $legacy = [
            'rtorrentRam'  => 128,
            'rtorrentPort' => 5000,
            'quota'        => 20,
            'quotaBurst'   => 30,
        ];
        $this->assertTrue(\UserValidator::validatePayload($legacy));
    }

}
