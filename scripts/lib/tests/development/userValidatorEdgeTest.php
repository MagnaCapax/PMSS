<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/UserValidator.php';

class UserValidatorEdgeTest extends TestCase
{
    public function testRejectsTraversalPatterns(): void
    {
        $this->assertTrue(!\UserValidator::isValidUsername('../etc/passwd'));
        $this->assertTrue(!\UserValidator::isValidUsername('user/../foo'));
    }

    public function testRejectsUnicodeConfusables(): void
    {
        $this->assertTrue(!\UserValidator::isValidUsername("admin\x00"));
        $this->assertTrue(!\UserValidator::isValidUsername("user\u200Dname"));
    }

    public function testAcceptsWhitelistedCharacters(): void
    {
        $this->assertTrue(\UserValidator::isValidUsername('user_123'));
    }
}
