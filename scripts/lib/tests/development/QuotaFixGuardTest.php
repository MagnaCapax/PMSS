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
            "\$quotaCheckCommands = pmssQuotaFixCheckCommands(\$quotaOffResult['ok']);",
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

    public function testHomeQuotaRecalculationNeededUnlessLastRunCompletedAndEveryTypeIsOn(): void
    {
        $cases = [
            ['usrjquota=aquota.user,grpjquota=aquota.group,jqfmt=vfsv1', 2, true, false],
            ['usrjquota=aquota.user,grpjquota=aquota.group,jqfmt=vfsv1', 2, false, true],
            ['usrjquota=aquota.user,grpjquota=aquota.group,jqfmt=vfsv1', 1, true, true],
            ['usrjquota=aquota.user,grpjquota=aquota.group,jqfmt=vfsv1', 0, true, true],
            ['usrquota', 1, true, false],
            ['defaults', 0, true, true],
        ];
        foreach ($cases as [$options, $enabled, $stamped, $expected]) {
            ['dir' => $dir, 'fstab' => $fstab] = $this->pmssMountFixtureCreate('pmss-quota-recalc-', "UUID=abc /srv ext4 usrquota 0 0\nUUID=def /home ext4 {$options} 0 0\n");
            $stamp = $dir.'/quota-recalculation.complete';
            if ($stamped) {
                file_put_contents($stamp, "2026-10-22T00:00:00+00:00\n");
            }
            $commands = [];
            $logs = [];
            $needed = \pmssQuotaHomeRecalculationNeeded(
                static function (string $command) use ($enabled, &$commands): array {
                    $commands[] = $command;
                    return ['rc' => $enabled, 'stdout' => '', 'stderr' => ''];
                },
                $this->pmssMakeArrayLogger($logs),
                $fstab,
                $stamp
            );
            $label = $options.' with '.$enabled.' on, stamp '.($stamped ? 'present' : 'missing');
            $this->assertSame($expected, $needed, $label);
            $this->assertSame(!$stamped || $options === 'defaults' ? [] : ['quotaon -p /home'], $commands, $label);
            $this->assertSame([], $logs, $label);
        }

        ['dir' => $dir] = $this->pmssMountFixtureCreate('pmss-quota-recalc-', '');
        file_put_contents($dir.'/stamp', "x\n");
        $logs = [];
        $this->assertTrue(\pmssQuotaHomeRecalculationNeeded(static function (): array {
            return ['rc' => 2, 'stdout' => '', 'stderr' => ''];
        }, $this->pmssMakeArrayLogger($logs), $dir.'/missing/fstab', $dir.'/stamp'));
        $this->assertSame(1, count($logs));
    }

    public function testUpdateDecidesRecalculationBeforeRemountAndQuotaFixStampsOnlyAfterVerify(): void
    {
        $this->pmssAssertRepoFileContract('scripts/util/update-step2.php', [
            'ordered' => [[
                'needles' => [
                    "\$pmssQuotaRecalculationNeeded = pmssQuotaHomeRecalculationNeeded(null, 'logmsg');",
                    "['Configuring quota mounts', 'pmssConfigureQuotaMount', ['logmsg']],",
                    "if (\$pmssQuotaRecalculationNeeded) {",
                    "runStep('Recalculating quota integrity', 'php /scripts/util/quotaFix.php');",
                ],
                'missingPrefix' => 'update-step2 missing quota recalculation gate: ',
                'orderPrefix' => 'update-step2 quota recalculation gate order changed near: ',
            ]],
        ]);
        $this->pmssAssertRepoFileContract('scripts/util/quotaFix.php', [
            'ordered' => [[
                'needles' => [
                    "\$quotaFixStamp = pmssQuotaRecalculationStampPath();",
                    "!@unlink(\$quotaFixStamp)",
                    "'quotaoff -av'",
                    "'quotaon -av'",
                    "\$quotaCounts = pmssQuotaHomeEnforcementWarn();",
                    "@file_put_contents(\$quotaFixStamp",
                ],
                'missingPrefix' => 'quotaFix missing completion stamp guard: ',
                'orderPrefix' => 'quotaFix completion stamp order changed near: ',
            ]],
        ]);
    }

    public function testQuotaFixSkipsQuotacheckWhenQuotaoffFails(): void
    {
        $this->pmssAssertRepoFileContract('scripts/util/quotaFix.php', [
            'ordered' => [[
                'needles' => [
                    '$quotaOffResult = pmssQuotaFixRunCommand',
                    "\$quotaCheckCommands = pmssQuotaFixCheckCommands(\$quotaOffResult['ok']);",
                    "if (\$quotaCheckCommands !== []) {",
                    "pmssRemoveStaleQuotaCheckFiles('/home');",
                    "foreach (\$quotaCheckCommands as \$quotaCheckCommand) {",
                    "pmssQuotaFixRunCommand(\n            '[quotaFix] Recalculating quota usage from disk",
                    '[quotaFix] WARNING: skipping quotacheck because quotaoff failed',
                    "pmssQuotaFixRunCommand('[quotaFix] Re-enabling quotas', 'quotaon -av', true, \$exitCode);",
                ],
                'missingPrefix' => 'quotaFix missing safety guard: ',
                'orderPrefix' => 'quotaFix safety guard order changed near: ',
            ]],
        ]);
    }

    /** Run pmssQuotaFixCheckCommands against a fixture fstab, quota files and a stubbed quotaon -p. */
    private function quotaFixCheckCommandsRun(bool $quotaOffOk, string $options, array $state, array $files = []): array
    {
        ['dir' => $dir, 'fstab' => $fstab] = $this->pmssMountFixtureCreate('pmss-quota-check-', "UUID=abc /srv ext4 usrquota 0 0\nUUID=def /home ext4 {$options} 0 0\n");
        foreach ($files as $name => $content) {
            file_put_contents($dir.'/'.$name, $content);
        }
        $commands = [];
        $logs = [];
        $checks = \pmssQuotaFixCheckCommands($quotaOffOk, static function (string $command) use ($state, &$commands): array {
            $commands[] = $command;
            return $state;
        }, $this->pmssMakeArrayLogger($logs), $fstab, $dir);

        return ['checks' => $checks, 'commands' => $commands, 'logs' => $logs];
    }

    public function testQuotaFixCheckCommandsKeepTheNormalRecountAfterQuotaoffSucceeds(): void
    {
        $zero = ['aquota.user' => str_repeat("\0", 64), 'aquota.group' => str_repeat("\0", 64)];
        foreach (['usrjquota=aquota.user,grpjquota=aquota.group,jqfmt=vfsv1', 'defaults'] as $options) {
            $run = $this->quotaFixCheckCommandsRun(true, $options, ['rc' => 2, 'stdout' => '', 'stderr' => ''], $zero);
            $this->assertSame(['quotacheck -avugmn'], $run['checks'], $options);
            $this->assertSame([], $run['commands'], $options);
            $this->assertSame([], $run['logs'], $options);
        }
    }

    public function testQuotaFixCheckCommandsRecoverHomeWhenQuotaoffFailsAndEveryDeclaredTypeIsOff(): void
    {
        $journaled = 'usrjquota=aquota.user,grpjquota=aquota.group,jqfmt=vfsv1';
        $off = ['rc' => 0, 'stdout' => "group quota on /home (/dev/md1) is off\nuser quota on /home (/dev/md1) is off\nproject quota on /home (/dev/md1) is off\n", 'stderr' => ''];
        $valid = ['user' => pack('VV', 0xd9c01f11, 1).str_repeat("\0", 56), 'group' => pack('VV', 0xd9c01927, 1).str_repeat("\0", 56)];
        $cases = [
            'both zero-filled' => [['aquota.user' => str_repeat("\0", 64), 'aquota.group' => str_repeat("\0", 64)], ['quotacheck -vumncb /home', 'quotacheck -vgmncb /home']],
            'both empty' => [['aquota.user' => '', 'aquota.group' => ''], ['quotacheck -vumncb /home', 'quotacheck -vgmncb /home']],
            'user zeroed, group readable' => [['aquota.user' => str_repeat("\0", 64), 'aquota.group' => $valid['group']], ['quotacheck -vumncb /home', 'quotacheck -vgmn /home']],
            'both readable' => [['aquota.user' => $valid['user'], 'aquota.group' => $valid['group']], ['quotacheck -vumn /home', 'quotacheck -vgmn /home']],
            'magics swapped' => [['aquota.user' => $valid['group'], 'aquota.group' => $valid['user']], ['quotacheck -vumncb /home', 'quotacheck -vgmncb /home']],
            'unknown version' => [['aquota.user' => pack('VV', 0xd9c01f11, 2), 'aquota.group' => pack('VV', 0xd9c01927, 0)], ['quotacheck -vumncb /home', 'quotacheck -vgmn /home']],
            'files missing' => [[], ['quotacheck -vumn /home', 'quotacheck -vgmn /home']],
        ];
        foreach ($cases as $label => [$files, $expected]) {
            $run = $this->quotaFixCheckCommandsRun(false, $journaled, $off, $files);
            $this->assertSame($expected, $run['checks'], $label);
            $this->assertSame(['quotaon -p /home'], $run['commands'], $label);
            $this->assertSame([], $run['logs'], $label);
        }

        $userOnly = $this->quotaFixCheckCommandsRun(false, 'usrjquota=aquota.user,jqfmt=vfsv1', ['rc' => 0, 'stdout' => "user quota on /home (/dev/md1) is off\n", 'stderr' => ''], ['aquota.user' => str_repeat("\0", 8)]);
        $this->assertSame(['quotacheck -vumncb /home'], $userOnly['checks']);
    }

    public function testQuotaFixCheckCommandsSkipRecoveryUnlessEveryDeclaredTypeIsVerifiablyOff(): void
    {
        $journaled = 'usrjquota=aquota.user,grpjquota=aquota.group,jqfmt=vfsv1';
        $zero = ['aquota.user' => str_repeat("\0", 64), 'aquota.group' => str_repeat("\0", 64)];
        $cases = [
            'group still on' => [$journaled, ['rc' => 1, 'stdout' => "group quota on /home (/dev/md1) is on\nuser quota on /home (/dev/md1) is off\n", 'stderr' => '']],
            'both still on' => [$journaled, ['rc' => 2, 'stdout' => "group quota on /home (/dev/md1) is on\nuser quota on /home (/dev/md1) is on\n", 'stderr' => '']],
            'rc says off but no state lines' => [$journaled, ['rc' => 0, 'stdout' => '', 'stderr' => '']],
            'rc says off but group line missing' => [$journaled, ['rc' => 0, 'stdout' => "user quota on /home (/dev/md1) is off\n", 'stderr' => '']],
            'off lines for another mount' => [$journaled, ['rc' => 0, 'stdout' => "group quota on /srv (/dev/md2) is off\nuser quota on /srv (/dev/md2) is off\n", 'stderr' => '']],
            'off lines but non-zero rc' => [$journaled, ['rc' => 1, 'stdout' => "group quota on /home (/dev/md1) is off\nuser quota on /home (/dev/md1) is off\n", 'stderr' => "quotaon: error\n"]],
            'localized output' => [$journaled, ['rc' => 0, 'stdout' => "Benutzer Quota auf /home (/dev/md1) ist aus\n", 'stderr' => '']],
            'runner returned garbage' => [$journaled, ['stdout' => 'x']],
        ];
        foreach ($cases as $label => [$options, $state]) {
            $run = $this->quotaFixCheckCommandsRun(false, $options, $state, $zero);
            $this->assertSame([], $run['checks'], $label);
            $this->assertSame(['quotaon -p /home'], $run['commands'], $label);
        }

        $none = $this->quotaFixCheckCommandsRun(false, 'defaults', ['rc' => 0, 'stdout' => '', 'stderr' => ''], $zero);
        $this->assertSame([], $none['checks']);
        $this->assertSame([], $none['commands']);

        ['dir' => $dir] = $this->pmssMountFixtureCreate('pmss-quota-check-', '');
        $commands = [];
        $logs = [];
        $this->assertSame([], \pmssQuotaFixCheckCommands(false, static function (string $command) use (&$commands): array {
            $commands[] = $command;
            return ['rc' => 0, 'stdout' => "user quota on /home (/dev/md1) is off\n", 'stderr' => ''];
        }, $this->pmssMakeArrayLogger($logs), $dir.'/missing/fstab', $dir));
        $this->assertSame([], $commands);
        $this->assertSame(1, count($logs));
    }

    public function testQuotaFileHeaderInvalidOnlyForAnExistingFileQuotacheckRejects(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-quota-header-');
        $cases = [
            ['user', pack('VV', 0xd9c01f11, 1), false],
            ['user', pack('VV', 0xd9c01f11, 0), false],
            ['group', pack('VV', 0xd9c01927, 1).'tail', false],
            ['user', str_repeat("\0", 8), true],
            ['group', pack('VV', 0xd9c01f11, 1), true],
            ['user', pack('NN', 0xd9c01f11, 1), true],
            ['user', pack('VV', 0xd9c01f11, 2), true],
            ['user', pack('V', 0xd9c01f11), true],
            ['group', '', true],
            ['project', str_repeat("\0", 8), false],
        ];
        foreach ($cases as $index => [$type, $content, $expected]) {
            $path = $this->pmssWriteFile($dir.'/case'.$index, $content);
            $this->assertSame($expected, \pmssQuotaFileHeaderInvalid($path, $type), 'case '.$index);
        }
        $this->assertFalse(\pmssQuotaFileHeaderInvalid($dir.'/missing', 'user'));
        $this->assertFalse(\pmssQuotaFileHeaderInvalid($dir, 'user'));
    }
}
