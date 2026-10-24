<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class ProductConfigCliContractTest extends TestCase
{
    public function testProductConfigUsesSharedCliParserForWelcomeMessage(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/productConfig.php',
            [
                "require_once __DIR__.'/lib/cli/optionParser.php';",
                "pmssParseCliTokensOrHelp(pmssCliArgv(\$argv ?? null), \$usage, ['welcome-message'])",
                "pmssCliOption(\$parsed, 'welcome-message')",
            ]
        );
        $this->pmssAssertRepoFileNotContainsString('scripts/productConfig.php', "strpos((string) \$argv[\$index], '--welcome-message=')", 'productConfig.php should not keep a manual --welcome-message scan');
    }
}
