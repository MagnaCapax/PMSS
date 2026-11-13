<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/quota/mount.php';

/** Pending journal intervals are observations only; mount operations stay with the updater. */
class QuotaJournalCommitTest extends TestCase
{
    public function testPendingWarningUsesTheConfiguredIntervalAndExactLiveMount(): void
    {
        $mounts = $this->pmssMakeTempFile('pmss-journal-mounts-');
        foreach ([
            ['/home', 'rw,commit=60', 'rw', true],
            ['/home', 'rw,commit=60', 'rw,commit=5', true],
            ['/home', 'rw,commit=60', 'rw,commit=600', true],
            ['/home', 'rw,commit=60', 'rw,xcommit=60', true],
            ['/home', 'rw,commit=60', 'rw,commit=60', false],
            ['/srv/data', 'rw,commit=30', 'rw,commit=60', true],
            ['/srv/data', 'rw,commit=30', 'rw,commit=30', false],
        ] as [$mount, $configured, $live, $pending]) {
            // Other mounts and commented entries must never satisfy the live interval check.
            $content = "fixture /other ext4 rw,commit=60 0 0\n"
                ."# fixture {$mount} ext4 rw,commit=60 0 0\n"
                ."fixture {$mount} ext4 {$live} 0 0\n";
            $this->pmssWriteFile($mounts, $content);
            $expected = $pending ? ['[WARN] Journal commit option '.substr($configured, 3).' for '.$mount
                .' is pending until the next mount/boot (journaled quota is active)'] : [];
            $this->assertSame($expected, $this->journalMessages($mount, $configured, $mounts));
            $this->assertSame($expected, $this->journalMessages($mount, $configured, $mounts));
            $this->assertSame($content, file_get_contents($mounts));
        }
    }

    public function testUnavailableOrMissingLiveMountIsReportedAsUnverified(): void
    {
        $mounts = $this->pmssMakeTempFile('pmss-journal-mounts-');
        foreach (['', "fixture /home-backup ext4 rw,commit=60 0 0\n", "malformed\n"] as $content) {
            $this->pmssWriteFile($mounts, $content);
            $this->assertUnverified($mounts);
        }
        $this->assertUnverified($this->pmssMakeTempPath('pmss-journal-missing-'));
        $this->assertUnverified($this->pmssMakeTempDir('pmss-journal-directory-'));
    }

    public function testNoConfiguredIntervalDoesNotClaimAPendingChange(): void
    {
        $missing = $this->pmssMakeTempPath('pmss-journal-missing-');
        foreach (['', 'rw', 'commit=', 'xcommit=60', 'commit=60x', 'commit=0', 'commit=-1'] as $configured) {
            $this->assertSame([], $this->journalMessages('/home', $configured, $missing));
        }
    }

    private function assertUnverified(string $mounts): void
    {
        $this->assertSame(['[WARN] Cannot verify live journal commit option for /home; commit=60'
            .' in fstab may be pending until the next mount/boot'], $this->journalMessages('/home', 'rw,commit=60', $mounts));
    }

    private function journalMessages(string $mount, string $configured, string $mounts): array
    {
        return $this->pmssArrayLoggerMessages(function (callable $logger) use ($mount, $configured, $mounts): void {
            $this->pmssWithEnv(['PMSS_PROC_MOUNTS_PATH' => $mounts], static function () use ($mount, $configured, $logger): void {
                \pmssWarnPendingJournalCommit($mount, $configured, $logger);
            });
        });
    }
}
