<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class welcomeQuotaMissingWarningTest extends TestCase
{
    public function testQuotaMissingWarningGuardUsesOnlyQuotaLimitFields(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/welcome.php');

        $this->assertStringContainsString('if ($hardLimit == 0 || $totalSpace == 0)', $source);
        $this->pmssAssertStringNotContainsString('$freeSpace == 0', $source);
        $this->pmssAssertStringNotContainsString('|| $usedBytes == 0', $source);
    }
}
