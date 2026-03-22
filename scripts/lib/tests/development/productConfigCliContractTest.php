<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class ProductConfigCliContractTest extends TestCase
{
    public function testProductConfigUsesSharedCliParserForWelcomeMessage(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../../productConfig.php');

        $this->assertTrue($source !== '', 'Expected to read scripts/productConfig.php');
        $this->assertStringContainsString("require_once __DIR__.'/lib/cli/optionParser.php';", $source);
        $this->assertStringContainsString("pmssParseCliTokens(\$argv ?? (\$_SERVER['argv'] ?? []), ['welcome-message'])", $source);
        $this->assertStringContainsString("pmssCliOption(\$parsed, 'welcome-message')", $source);
        $this->assertTrue(
            strpos($source, "strpos((string) \$argv[\$index], '--welcome-message=')") === false,
            'productConfig.php should not keep a manual --welcome-message scan'
        );
    }
}
