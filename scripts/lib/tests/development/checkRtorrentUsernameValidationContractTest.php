<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class checkRtorrentUsernameValidationContractTest extends TestCase
{
    private function loadSource(): string
    {
        return (string) file_get_contents(__DIR__.'/../../../cron/checkRtorrent.php');
    }

    public function testSharedUsernameValidatorRemainsPreferred(): void
    {
        $src = $this->loadSource();

        $this->assertStringContainsString("function_exists('pmssValidateUsername')", $src);
        $this->assertStringContainsString('pmssValidateUsername($user)', $src);
    }

    public function testLegacyRegexFallbackRemainsAvailable(): void
    {
        $src = $this->loadSource();

        $this->assertStringContainsString('/^[a-z][a-z0-9]{0,7}$/', $src);
    }
}
