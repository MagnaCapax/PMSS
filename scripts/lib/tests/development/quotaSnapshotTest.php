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
        $this->pmssAssertRepoFileContainsString('etc/seedbox/config/root.cron', '/scripts/cron/quotaSnapshot.php', 'root.cron should schedule quotaSnapshot.php');
    }

    public function testLogrotateKeepsQuotaHistoryRootOnly(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'etc/seedbox/config/template.logrotate.pmss',
            ['/var/log/pmss/quota-daily.log', 'rotate 120', 'compress', 'delaycompress', 'create 0600 root root'],
            'logrotate policy is missing: '
        );
    }
}
