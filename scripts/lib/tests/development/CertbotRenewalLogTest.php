<?php
/**
 * Certbot renewal log parsing tests.
 */

namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/certbotRenewal.php';

class CertbotRenewalLogTest extends TestCase
{
    public function testParsesSuccessSummary(): void
    {
        $log = implode("\n", [
            '2026-01-28 10:00:00,000:INFO:certbot.main:Starting renew',
            '2026-01-28 10:00:05,000:INFO:certbot.main:Congratulations, all renewals succeeded. The following certs have been renewed:',
            '2026-01-28 10:00:05,100:INFO:certbot.main:  /etc/letsencrypt/live/example/fullchain.pem (success)',
        ]);

        $summary = \pmssCertbotRenewalSummaryFromLog($log);
        $this->assertEquals('ok', $summary['status']);
        $this->assertTrue(is_int($summary['event_ts']) && $summary['event_ts'] > 0, 'Expected event_ts to be an int timestamp');
        $this->assertStringContainsString('all renewals succeeded', strtolower($summary['event']));
        $this->assertEquals('', $summary['error_excerpt']);
    }

    public function testParsesNoopSummary(): void
    {
        $log = implode("\n", [
            '2026-01-28 12:00:00,000:INFO:certbot.main:Starting renew',
            '2026-01-28 12:00:01,000:INFO:certbot.main:No renewals were attempted.',
        ]);

        $summary = \pmssCertbotRenewalSummaryFromLog($log);
        $this->assertEquals('noop', $summary['status']);
        $this->assertTrue(is_int($summary['event_ts']) && $summary['event_ts'] > 0, 'Expected event_ts to be an int timestamp');
    }

    public function testParsesFailureSummaryAndCapturesExcerpt(): void
    {
        $log = implode("\n", [
            '2026-01-28 14:00:00,000:INFO:certbot.main:Starting renew',
            '2026-01-28 14:00:01,000:ERROR:certbot.renewal:All renewals failed. The following certs could not be renewed:',
            '  /etc/letsencrypt/live/example/fullchain.pem (failure)',
            '    some error detail line',
            '2026-01-28 14:00:02,000:INFO:certbot.main:Next run',
        ]);

        $summary = \pmssCertbotRenewalSummaryFromLog($log, 10);
        $this->assertEquals('fail', $summary['status']);
        $this->assertTrue(is_int($summary['event_ts']) && $summary['event_ts'] > 0, 'Expected event_ts to be an int timestamp');
        $this->assertStringContainsString('all renewals failed', strtolower($summary['event']));
        $this->assertStringContainsString('/etc/letsencrypt/live/example/fullchain.pem', $summary['error_excerpt']);
        $this->assertStringContainsString('some error detail line', $summary['error_excerpt']);
        $this->assertTrue(strpos($summary['error_excerpt'], 'Next run') === false, 'Excerpt should stop before the next timestamped entry');
    }

    public function testLastMarkerWinsWhenMultipleRunsExist(): void
    {
        $log = implode("\n", [
            '2026-01-27 10:00:00,000:INFO:certbot.main:No renewals were attempted.',
            '2026-01-28 10:00:00,000:ERROR:certbot.renewal:All renewals failed. The following certs could not be renewed:',
        ]);

        $summary = \pmssCertbotRenewalSummaryFromLog($log);
        $this->assertEquals('fail', $summary['status']);
    }

    public function testUnknownWhenNoMarkersExist(): void
    {
        $log = implode("\n", [
            '2026-01-28 10:00:00,000:INFO:certbot.main:Starting something',
            '2026-01-28 10:00:01,000:INFO:certbot.main:Doing work',
        ]);

        $summary = \pmssCertbotRenewalSummaryFromLog($log);
        $this->assertEquals('unknown', $summary['status']);
        $this->assertTrue($summary['event_ts'] === null);
    }
}
