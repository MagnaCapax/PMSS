<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/services/bootstrap.php';
require_once dirname(__DIR__, 2).'/quotaSnapshotCron.php';

/** Characterize the quota policies shared by bootstrap, maintenance and snapshots. */
class QuotaPolicyCharacterizationTest extends TestCase
{
    public function testDeclaredTypesAndSnapshotPolicyKeepTheirDifferentMeanings(): void
    {
        $fstab = $this->pmssMakeTempFile('pmss-quota-policy-');
        foreach ([
            ['defaults', [], false],
            ['usrquota', ['user'], false],
            ['grpquota=off', ['group'], false],
            ['grpquota,usrquota,usrquota', ['user', 'group'], false],
            ['usrjquota=', ['user'], true],
            ['grpjquota=aquota.group,usrquota', ['user', 'group'], true],
            ['jqfmt=vfsv1', [], false],
            ['xusrquota,usrquotax,grpquotax,usrjquota', [], false],
            ['usrquota=,grpjquota=aquota.group', ['user', 'group'], true],
            ['usrquota, grpjquota=aquota.group', ['user'], false],
        ] as [$options, $types, $journaled]) {
            // The first matching row wins, and quota declarations on other mounts do not count.
            file_put_contents($fstab, "# x /home ext4 usrjquota=aquota.user 0 0\n"
                ."x /srv ext4 usrjquota=aquota.user 0 0\n"
                .'x /home ext4 '.$options." 0 0\nx /home ext4 usrjquota=aquota.user 0 0\n");
            $messages = [];
            $this->assertSame($types, \pmssQuotaHomeDeclaredTypes($this->pmssMakeArrayLogger($messages), $fstab, 'policy check.'));
            $this->assertSame($journaled, \pmssQuotaSnapshotMountDeclaresJournaledQuota('/home', $fstab));
            $this->assertSame([], $messages);
        }
        file_put_contents($fstab, "x /srv ext4 usrquota 0 0\n");
        $this->assertSame(null, \pmssQuotaHomeDeclaredTypes(static function (): void {}, $fstab, 'policy check.'));
        $this->assertFalse(\pmssQuotaSnapshotMountDeclaresJournaledQuota('/home', $fstab));
    }

    public function testMissingAndUnsafeFstabKeepCallerWarnings(): void
    {
        $missing = $this->pmssMakeTempPath('pmss-quota-missing-');
        foreach ([$missing, $missing."\0", ''] as $path) {
            $messages = [];
            $this->assertSame(null, \pmssQuotaHomeDeclaredTypes($this->pmssMakeArrayLogger($messages), $path, 'policy check.'));
            $expected = $path === '' || strpos($path, "\0") !== false
                ? '[WARN] Unsafe fstab path; skipping policy check.'
                : '[WARN] '.$path.' is not a regular file; skipping policy check.';
            $this->assertSame([$expected], $messages);
            $this->assertFalse(\pmssQuotaSnapshotMountDeclaresJournaledQuota('/home', $path));
        }
    }

    public function testCountsSkipQueriesWithoutDeclarationsAndKeepExitCounts(): void
    {
        $fstab = $this->pmssMakeTempFile('pmss-quota-counts-');
        foreach ([['defaults', 0], ['usrquota', 1], ['grpquota,usrquota', 2]] as [$options, $declared]) {
            file_put_contents($fstab, 'x /home ext4 '.$options." 0 0\n");
            foreach ([0, 1, 2, 127] as $enabled) {
                $commands = [];
                $counts = \pmssQuotaHomeEnforcementCounts(static function (string $command) use (&$commands, $enabled): array {
                    $commands[] = $command;
                    return ['rc' => $enabled];
                }, static function (): void {}, $fstab, 'count check.');
                $this->assertSame(['declared' => $declared, 'enabled' => $declared === 0 ? 0 : $enabled], $counts);
                $this->assertSame($declared === 0 ? [] : ['quotaon -p /home'], $commands);
            }
        }
    }

    public function testCommandNormalizationPreservesResultAndRunnerBoundaries(): void
    {
        foreach (['', '  ', "\0quotaon", "quotaon\0", " \0 "] as $command) {
            $this->assertSame(['ok' => false, 'rc' => 1, 'output' => ''], \pmssQuotaCommandRun($command, static function (): array {
                throw new \RuntimeException('Invalid command reached runner');
            }));
        }
        foreach ([null, false, 'bad', [], ['rc' => '2', 'stdout' => 'out', 'stderr' => 'err'],
            ['rc' => 0, 'stdout' => 9, 'stderr' => false], ['rc' => 'bad'], ['rc' => '0.5']] as $result) {
            $commands = [];
            $actual = \pmssQuotaCommandRun('  quotaon -p /home  ', static function (string $command) use (&$commands, $result) {
                $commands[] = $command;
                return $result;
            });
            $expectedRc = is_array($result) && is_numeric($result['rc'] ?? null) ? (int) $result['rc'] : 1;
            $this->assertSame(['ok' => $expectedRc === 0, 'rc' => $expectedRc, 'output' => ($result['stdout'] ?? null) === 'out' ? 'outerr' : ''], $actual);
            $this->assertSame(['quotaon -p /home'], $commands);
        }
    }

    public function testReportingKeepsQueryWarningsCriticalStatusAndOutputBytes(): void
    {
        foreach ([0, 2] as $rc) {
            foreach ([false, true] as $query) {
                foreach ([false, true] as $critical) {
                    $messages = [];
                    $exitCode = 7;
                    [$result, $output] = $this->pmssCaptureStdout(function () use ($rc, $query, $critical, &$messages, &$exitCode): array {
                        return \pmssQuotaFixRunCommand('description', 'quotaon -p /home', $critical, $exitCode, $query,
                            static function () use ($rc): array { return ['rc' => $rc, 'stdout' => "out\n", 'stderr' => "err\n"]; },
                            $this->pmssMakeArrayLogger($messages));
                    });
                    $this->assertSame(['ok' => $rc === 0, 'rc' => $rc, 'output' => "out\nerr\n"], $result);
                    $this->assertSame("out\nerr\n", $output);
                    $this->assertSame($rc !== 0 && $critical ? 1 : 7, $exitCode);
                    $expected = ['description'];
                    if ($rc !== 0 && !$query) $expected[] = '[quotaFix] WARNING: command failed (rc=2): quotaon -p /home';
                    $this->assertSame($expected, $messages);
                }
            }
        }
    }
}
