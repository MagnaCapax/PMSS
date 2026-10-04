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

    public function testQuotaCommandRunRejectsNulBeforeRunner(): void
    {
        foreach (["\0quotaoff -av", "quotaoff\0 -av", "quotaoff -av\0"] as $command) {
            $called = false;
            $result = \pmssQuotaCommandRun($command, static function () use (&$called): array {
                $called = true;
                return ['rc' => 0, 'stdout' => 'unexpected', 'stderr' => ''];
            });

            $this->assertFalse($called);
            $this->assertSame(['ok' => false, 'rc' => 1, 'output' => ''], $result);
        }
    }

    public function testQuotaFixUsesExitAwareRunnerForDestructiveCommands(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/util/quotaFix.php', [
            'pmssQuotaFixRunCommand(',
            "pmssQuotaFixRunCommand('[quotaFix] Disabling quotas for recalculation', 'quotaoff -av', true, \$exitCode);",
            "'quotacheck -avugmn'",
            "pmssQuotaFixRunCommand('[quotaFix] Re-enabling quotas', 'quotaon -av', true, \$exitCode);",
            "pmssQuotaFixRunCommand('[quotaFix] Verifying quota enforcement state:', 'quotaon -ap', false, \$exitCode, true);",
        ]);
        $this->pmssAssertRepoFileNotContainsString('scripts/util/quotaFix.php', 'shell_exec(');
    }

    public function testQuotaFixQueryExitCodeDoesNotWarnButCriticalFailureStillDoes(): void
    {
        $logs = [];
        $exitCode = 0;

        ob_start();
        $queryResult = \pmssQuotaFixRunCommand(
            '[quotaFix] Verifying quota enforcement state:',
            'quotaon -ap',
            false,
            $exitCode,
            true,
            static function (string $command): array {
                return ['rc' => 2, 'stdout' => "quota state\n", 'stderr' => ''];
            },
            $this->pmssMakeArrayLogger($logs)
        );
        $criticalResult = \pmssQuotaFixRunCommand(
            '[quotaFix] Re-enabling quotas',
            'quotaon -av',
            true,
            $exitCode,
            false,
            static function (string $command): array {
                return ['rc' => 5, 'stdout' => '', 'stderr' => "quotaon failed\n"];
            },
            $this->pmssMakeArrayLogger($logs)
        );
        $output = ob_get_clean();

        $this->assertSame(2, $queryResult['rc']);
        $this->assertSame(5, $criticalResult['rc']);
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString("quota state\nquotaon failed\n", $output);

        $logText = implode("\n", $logs);
        $this->assertStringContainsString('[quotaFix] WARNING: command failed (rc=5): quotaon -av', $logText);
        $this->assertStringNotContainsString('[quotaFix] WARNING: command failed (rc=2): quotaon -ap', $logText);
    }

    public function testHomeQuotaEnforcementWarnsOnlyOnDeclaredShortfall(): void
    {
        $cases = [
            ['usrjquota=aquota.user,grpjquota=aquota.group', 2, []],
            ['usrjquota=aquota.user,grpjquota=aquota.group', 0, ['[quotaFix] WARNING: /home declares 2 quota type(s) in fstab but 0 are on']],
            ['usrquota,grpquota', 1, ['[quotaFix] WARNING: /home declares 2 quota type(s) in fstab but 1 are on']],
            ['usrquota,usrjquota=aquota.user', 1, []],
            ['defaults', 0, []],
        ];
        foreach ($cases as [$options, $enabled, $expected]) {
            ['fstab' => $fstab] = $this->pmssMountFixtureCreate('pmss-quota-verify-', "UUID=abc /srv ext4 usrquota 0 0\nUUID=def /home ext4 {$options} 0 0\n");
            $commands = [];
            $logs = [];
            \pmssQuotaHomeEnforcementWarn(
                static function (string $command) use ($enabled, &$commands): array {
                    $commands[] = $command;
                    return ['rc' => $enabled, 'stdout' => '', 'stderr' => ''];
                },
                $this->pmssMakeArrayLogger($logs),
                $fstab
            );
            $this->assertSame($expected, $logs);
            $this->assertSame($options === 'defaults' ? [] : ['quotaon -p /home'], $commands);
        }
    }

    public function testQuotaFixSkipsQuotacheckWhenQuotaoffFails(): void
    {
        $this->pmssAssertRepoFileContract('scripts/util/quotaFix.php', [
            'ordered' => [[
                'needles' => [
                    '$quotaOffResult = pmssQuotaFixRunCommand',
                    "if (\$quotaOffResult['ok']) {",
                    "pmssQuotaFixRunCommand(\n        '[quotaFix] Recalculating quota usage from disk",
                    '[quotaFix] WARNING: skipping quotacheck because quotaoff failed',
                    "pmssQuotaFixRunCommand('[quotaFix] Re-enabling quotas', 'quotaon -av', true, \$exitCode);",
                ],
                'missingPrefix' => 'quotaFix missing safety guard: ',
                'orderPrefix' => 'quotaFix safety guard order changed near: ',
            ]],
        ]);
    }
}
