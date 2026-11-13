<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/cron/quotaSnapshot.php';

class QuotaSnapshotTest extends TestCase
{
    public function testParserExtractsNumericRowsWithoutGraceColumns(): void
    {
        $rows = pmssQuotaSnapshotParseRepquotaUserRows([
            '*** Report for user quotas on device /dev/vda1',
            'Block grace time: 7days; Inode grace time: 7days',
            '                        Block limits                File limits',
            'User            used    soft    hard  grace    used  soft  hard  grace',
            '----------------------------------------------------------------------',
            '1001 -- 7630869144 23805820928 29757276160 171384 11351500 14189375',
            '1002 +- 7925371096 30534533120 38168166400 19200 14560000 18200000',
        ]);

        $this->assertEquals(
            [
                ['1001', '7630869144', '23805820928', '29757276160', '171384', '11351500', '14189375'],
                ['1002', '7925371096', '30534533120', '38168166400', '19200', '14560000', '18200000'],
            ],
            $rows
        );
    }

    public function testParserAcceptsHashPrefixedUidsAndIgnoresGraceTokens(): void
    {
        $rows = pmssQuotaSnapshotParseRepquotaUserRows([
            '#1001 -- 100 200 300 7days 10 20 30 -',
            '#1002 ++ 0 0 0 - 0 0 0 -',
        ]);

        $this->assertEquals(
            [
                ['1001', '100', '200', '300', '10', '20', '30'],
                ['1002', '0', '0', '0', '0', '0', '0'],
            ],
            $rows
        );
    }

    public function testParserSkipsNonNumericUsers(): void
    {
        $rows = pmssQuotaSnapshotParseRepquotaUserRows([
            'root -- 1 2 3 4 5 6',
            'userA -- 1 2 3 4 5 6',
            '1000 -- 1 2 3 4 5 6',
        ]);

        $this->assertEquals([['1000', '1', '2', '3', '4', '5', '6']], $rows);
    }

    public function testRootCronSchedulesQuotaSnapshots(): void
    {
        $this->pmssAssertRepoFileContainsString('etc/seedbox/config/root.cron', '/scripts/cron/quotaSnapshot.php >> /var/log/pmss/quotaSnapshot.log 2>&1', 'root.cron should capture quota alerts');
    }

    public function testJournaledQuotaDeclarationUsesOnlyTargetMount(): void
    {
        $fstab = $this->pmssMakeTempFile('pmss-quota-snapshot-fstab-');
        foreach ([
            ["UUID=a /srv ext4 usrjquota=aquota.user 0 0\nUUID=b /home ext4 defaults 0 0\n", false],
            ["# UUID=a /home ext4 usrjquota=aquota.user 0 0\n", false],
            ["UUID=a /home ext4 usrquota,grpquota 0 0\n", false],
            ["UUID=a /home ext4 usrjquota=aquota.user 0 0\n", true],
            ["UUID=a /home ext4 grpjquota=aquota.group 0 0\n", true],
        ] as [$contents, $expected]) {
            $this->pmssWriteFile($fstab, $contents);
            $this->assertSame($expected, \pmssQuotaSnapshotMountDeclaresJournaledQuota('/home', $fstab));
        }
        $this->assertFalse(\pmssQuotaSnapshotMountDeclaresJournaledQuota('/home', $fstab.'.missing'));
    }

    public function testLogrotateKeepsQuotaHistoryRootOnly(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'etc/seedbox/config/template.logrotate.pmss',
            ['/var/log/pmss/quota-daily.log /var/log/pmss/quotaSnapshot.log', 'rotate 120', 'compress', 'delaycompress', 'create 0600 root root'],
            'logrotate policy is missing: '
        );
    }

    public function testRepquotaFailuresAlertOnlyForDeclaredJournaledMounts(): void
    {
        $fstab = $this->pmssMakeTempFile('pmss-quota-alert-fstab-');
        foreach ([
            ['usrjquota=aquota.user', 1, [], true],
            ['grpjquota=aquota.group', 2, ['quota unavailable'], true],
            ['usrjquota=aquota.user,grpjquota=aquota.group', 127, [], true],
            ['usrquota,grpquota', 1, [], false],
            ['defaults', 1, [], false],
            ['usrjquota=aquota.user', 0, [], false],
            ['usrjquota=aquota.user', 0, ['1001 -- 1 2 3 4 5 6'], false],
        ] as [$options, $rc, $output, $alert]) {
            $this->pmssWriteFile($fstab, 'UUID=fixture /home ext4 '.$options." 0 0\n");
            // Run the real cron callback with in-memory logging and a simulated repquota.
            $source = $this->pmssReadRepoFile('scripts/cron/quotaSnapshot.php');
            $source = substr($source, strpos($source, '<?php') + 5);
            $source = preg_replace('/^require_once [^\n]+;\n/m', '', $source);
            $script = <<<'PHP'
namespace QuotaSnapshotAlertFixture;
use function pmssSnapshotWriteWarn, pmssSnapshotWriteLine, pmssSnapshotWarnToken,
    pmssQuotaSnapshotMountDeclaresJournaledQuota, pmssQuotaSnapshotParseRepquotaUserRows,
    pmssCliReturnWithStderr;
function pmssCommandPath($binary) { return '/fixture/'.$binary; }
function pmssRunCliEntrypoint($file, $main) {}
function exec($command, &$output, &$rc) {
    $GLOBALS['commands'][] = $command;
    $output = $GLOBALS['report']['output'];
    $rc = $GLOBALS['report']['rc'];
}
function pmssRunSnapshotLogTask($file, $envKey, $defaultLog, $callback) {
    $handle = fopen('php://temp', 'w+');
    try {
        $rc = $callback($handle, '2026-01-01T00:00:00');
        rewind($handle);
        $GLOBALS['log'] = stream_get_contents($handle);
        return $rc;
    } finally {
        fclose($handle);
    }
}
PHP;
            $script .= 'require_once '.var_export($this->pmssRepoPath('scripts/lib/quotaSnapshotCron.php'), true).';';
            $script .= $source;
            $script .= '$GLOBALS["commands"] = []; $GLOBALS["report"] = '.var_export(['rc' => $rc, 'output' => $output], true).';';
            $script .= '$rc = pmssQuotaSnapshotRun('.var_export($fstab, true).');';
            $script .= 'echo json_encode(["rc" => $rc, "commands" => $GLOBALS["commands"], "log" => $GLOBALS["log"]]);';
            $capture = $this->pmssExecInlinePhpWithTempStderr($script, ['PMSS_QUOTA_SNAPSHOT_MOUNT' => '/home']);
            $this->assertSame(0, $capture['result']['rc']);
            $result = $this->pmssDecodeJsonArray($capture['result']['output']);
            $this->assertSame($alert ? 1 : 0, $result['rc']);
            $this->assertSame(['/fixture/repquota -u -n '.escapeshellarg('/home').' 2>&1'], $result['commands']);
            $this->assertSame($alert ? '###PMSS_QUOTA_ALERT repquota_failed mount=home rc='.$rc.PHP_EOL : '',
                (string) file_get_contents($capture['stderrPath']));
            // Failed reports retain timestamped evidence even when no alert is warranted.
            if ($rc !== 0) {
                $this->assertStringContainsString('2026-01-01T00:00:00 WARN repquota_failed rc='.$rc.' mount=/home', $result['log']);
            } elseif ($output === []) {
                $this->assertStringContainsString('WARN repquota_no_rows', $result['log']);
            } else {
                $this->assertSame("2026-01-01T00:00:00 1001 1 2 3 4 5 6\n", $result['log']);
            }
        }
    }
}
