<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class QuotaSnapshotTest extends TestCase
{
    public function testParserExtractsNumericRowsWithoutGraceColumns(): void
    {
        require_once dirname(__DIR__, 3).'/cron/quotaSnapshot.php';

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
        require_once dirname(__DIR__, 3).'/cron/quotaSnapshot.php';

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
        require_once dirname(__DIR__, 3).'/cron/quotaSnapshot.php';

        $rows = pmssQuotaSnapshotParseRepquotaUserRows([
            'root -- 1 2 3 4 5 6',
            'userA -- 1 2 3 4 5 6',
            '1000 -- 1 2 3 4 5 6',
        ]);

        $this->assertEquals([['1000', '1', '2', '3', '4', '5', '6']], $rows);
    }

    public function testRootCronSchedulesQuotaSnapshots(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $cron = (string) file_get_contents($repoRoot.'/etc/seedbox/config/root.cron');
        $this->assertTrue(strpos($cron, '/scripts/cron/quotaSnapshot.php') !== false, 'root.cron should schedule quotaSnapshot.php');
    }

    public function testLogrotateKeepsQuotaHistoryRootOnly(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $policy = (string) file_get_contents($repoRoot.'/etc/seedbox/config/template.logrotate.pmss');
        $this->assertTrue(strpos($policy, '/var/log/pmss/quota-daily.log') !== false, 'logrotate policy should include quota-daily.log');
        $this->assertTrue(strpos($policy, 'rotate 24') !== false, 'quota log should keep 24 rotations (monthly)');
        $this->assertTrue(strpos($policy, 'create 0600 root root') !== false, 'quota log should remain root-only');
    }
}
