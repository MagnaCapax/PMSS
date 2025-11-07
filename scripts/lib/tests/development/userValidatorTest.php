<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/user/UserValidator.php';

class UserValidatorTest extends TestCase
{
    public function testIsValidUsername(): void
    {
        $this->assertTrue(\UserValidator::isValidUsername('alice_01'));
        $this->assertTrue(\UserValidator::isValidUsername('bob-02'));
        $this->assertTrue(!\UserValidator::isValidUsername('bad user'));
        $this->assertTrue(!\UserValidator::isValidUsername('evil!'));
    }

    public function testValidatePayloadRequiresFields(): void
    {
        $valid = [
            'rtorrentRam'  => 128,
            'rtorrentPort' => 5000,
            'quota'        => 20,
            'quotaBurst'   => 30,
        ];
        $this->assertTrue(\UserValidator::validatePayload($valid));
        unset($valid['quotaBurst']);
        $this->assertTrue(!\UserValidator::validatePayload($valid));
    }

    public function testNormalisedPayloadSortsKeys(): void
    {
        $raw = ['b' => 2, 'a' => 1];
        $normalised = \UserValidator::normalisedPayload($raw);
        $this->assertEquals(['a' => 1, 'b' => 2], $normalised);
    }
}
