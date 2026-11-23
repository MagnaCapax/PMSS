<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/quota/mountOptions.php';

class QuotaFstabRepairTest extends TestCase
{
    private function quotaFstabRepairRun(string $content): array
    {
        ['fstab' => $fstab] = $this->pmssMountFixtureCreate('pmss-quota-repair-', $content);
        $messages = $this->pmssArrayLoggerMessages(function (callable $logger) use ($fstab): void {
            \pmssEnsureQuotaOptions('/home', null, $logger, $fstab);
        });

        return [(string) file_get_contents($fstab), $messages];
    }

    public function testMalformedQuotaValueIsReplacedNotDuplicated(): void
    {
        $control = chr(3);
        [$updated, $messages] = $this->quotaFstabRepairRun(
            "UUID=abc /home ext4 defaults,nofail,noatime,usrjquota=aquota.user,grpjquota=aquota.gro{$control},jqfmt=vfsv1 0 0\n"
        );

        $this->assertStringContainsString('grpjquota=aquota.group', $updated);
        $this->assertFalse(strpos($updated, 'aquota.gro'.$control) !== false, 'expected malformed group quota option removed');
        $this->assertEquals(1, substr_count($updated, 'grpjquota='), 'expected one group quota option');
        $this->pmssAssertMessagesContain($messages, 'Updated quota options', 'expected update log');
    }

    public function testDuplicateQuotaKeysCollapseToCanonicalValues(): void
    {
        [$updated] = $this->quotaFstabRepairRun(
            "UUID=abc /home ext4 defaults,usrjquota=bad.user,usrjquota=aquota.user,grpjquota=bad.group,grpjquota=aquota.group,jqfmt=bad,jqfmt=vfsv1 0 0\n"
        );

        $this->assertEquals(1, substr_count($updated, 'usrjquota='));
        $this->assertEquals(1, substr_count($updated, 'grpjquota='));
        $this->assertEquals(1, substr_count($updated, 'jqfmt='));
        $this->assertStringContainsAllStrings(['usrjquota=aquota.user', 'grpjquota=aquota.group', 'jqfmt=vfsv1'], $updated);
    }

    public function testNonExt4QuotaAndJournalPoliciesLeaveFstabUntouched(): void
    {
        foreach (['xfs', 'btrfs', 'ext2', 'ext3', 'auto'] as $fsType) {
            foreach (['/home', '/srv'] as $mount) {
                // An unrelated ext4 entry must not make the target eligible.
                $original = "# quota fixture\nUUID=root / ext4 defaults 0 1\n"
                    .'UUID=data  '.$mount.'  '.$fsType."  defaults,noatime,nofail 0 0\n";
                ['fstab' => $fstab] = $this->pmssMountFixtureCreate('pmss-quota-type-', $original);
                $warning = '[WARN] '.$mount.' is '.$fsType
                    .'; PMSS quota options are ext4-only, so per-user quota is not enforced on this host';
                foreach (['quota', 'journal'] as $policy) {
                    $messages = $this->pmssArrayLoggerMessages(function (callable $logger) use ($mount, $fstab, $policy): void {
                        if ($policy === 'quota') {
                            \pmssEnsureQuotaOptions($mount, null, $logger, $fstab);
                        } else {
                            \pmssEnsureJournalCommitOption($mount, 60, $logger, $fstab);
                        }
                    });
                    $this->assertSame([$warning], $messages);
                    $this->assertSame($original, file_get_contents($fstab));
                    $this->assertSame([], glob($fstab.'.pmss-backup-*') ?: []);
                }
            }
        }
    }

    public function testNonExt4NofailPolicyStillConverges(): void
    {
        foreach (['xfs', 'btrfs'] as $fsType) {
            $original = 'UUID=data /home '.$fsType." defaults,noatime 0 0\n";
            ['fstab' => $fstab] = $this->pmssMountFixtureCreate('pmss-quota-nofail-', $original);
            $apply = function (callable $logger) use ($fstab): void {
                \pmssEnsureMountNofailOption('/home', $logger, $fstab);
            };
            $messages = $this->pmssArrayLoggerMessages($apply);
            $this->assertSame('[WARN] Added nofail option for /home (added nofail)', $messages[0]);
            $expected = "UUID=data\t/home\t".$fsType."\tdefaults,noatime,nofail\t0\t0\n";
            $this->assertSame($expected, file_get_contents($fstab));
            $this->assertSame(['[SKIP] nofail option already present for /home'], $this->pmssArrayLoggerMessages($apply));
            $this->assertSame($expected, file_get_contents($fstab));
            $backups = glob($fstab.'.pmss-backup-*') ?: [];
            $this->assertSame(1, count($backups));
            $this->assertSame($original, file_get_contents($backups[0]));
        }
    }

    public function testExt4QuotaAndJournalPoliciesStillConverge(): void
    {
        $other = "UUID=other /srv xfs defaults 0 0\n";
        ['fstab' => $fstab] = $this->pmssMountFixtureCreate('pmss-quota-ext4-',
            "UUID=data /home ext4 defaults,noatime,commit=5 0 0\n".$other);
        $apply = function (callable $logger) use ($fstab): void {
            \pmssEnsureQuotaOptions('/home', null, $logger, $fstab);
            \pmssEnsureJournalCommitOption('/home', 60, $logger, $fstab);
        };
        $this->pmssArrayLoggerMessages($apply);
        $expected = "UUID=data\t/home\text4\tdefaults,noatime,commit=60,"
            ."usrjquota=aquota.user,grpjquota=aquota.group,jqfmt=vfsv1\t0\t0\n".$other;
        $this->assertSame($expected, file_get_contents($fstab));
        $this->assertSame(['[SKIP] Quota options already present for /home',
            '[SKIP] Journal commit option already present for /home'], $this->pmssArrayLoggerMessages($apply));
        $this->assertSame($expected, file_get_contents($fstab));
    }
}
