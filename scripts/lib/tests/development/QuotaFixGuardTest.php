<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/services/quota.php';

class QuotaFixGuardTest extends TestCase
{
    public function testQuotaCommandRunCapturesExitCodeAndOutput(): void
    {
        $result = \pmssQuotaCommandRun('quotaoff -av', static function (string $command): array {
            return ['rc' => 5, 'stdout' => "stdout\n", 'stderr' => "stderr\n"];
        });

        $this->assertFalse($result['ok']);
        $this->assertSame(5, $result['rc']);
        $this->assertSame("stdout\nstderr\n", $result['output']);
    }

    public function testQuotaCommandRunRejectsEmptyCommands(): void
    {
        $result = \pmssQuotaCommandRun('   ', static function (string $command): array {
            return ['rc' => 0, 'stdout' => 'unexpected', 'stderr' => ''];
        });

        $this->assertFalse($result['ok']);
        $this->assertSame(1, $result['rc']);
        $this->assertSame('', $result['output']);
    }

    public function testQuotaFixUsesExitAwareRunnerForDestructiveCommands(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/util/quotaFix.php', [
            'pmssQuotaFixRunCommand(',
            "pmssQuotaFixRunCommand('[quotaFix] Disabling quotas for recalculation', 'quotaoff -av', true, \$exitCode);",
            "'quotacheck -avugmn'",
            "pmssQuotaFixRunCommand('[quotaFix] Re-enabling quotas', 'quotaon -av', true, \$exitCode);",
        ]);
        $this->pmssAssertRepoFileNotContainsString('scripts/util/quotaFix.php', 'shell_exec(');
    }

    public function testQuotaFixSkipsQuotacheckWhenQuotaoffFails(): void
    {
        $source = $this->pmssReadRepoFile('scripts/util/quotaFix.php');

        $this->assertOrderedStrings(
            [
                '$quotaOffResult = pmssQuotaFixRunCommand',
                "if (\$quotaOffResult['ok']) {",
                "pmssQuotaFixRunCommand(\n        '[quotaFix] Recalculating quota usage from disk",
                '[quotaFix] WARNING: skipping quotacheck because quotaoff failed',
                "pmssQuotaFixRunCommand('[quotaFix] Re-enabling quotas', 'quotaon -av', true, \$exitCode);",
            ],
            $source,
            'quotaFix missing safety guard: ',
            'quotaFix safety guard order changed near: '
        );
    }
}
