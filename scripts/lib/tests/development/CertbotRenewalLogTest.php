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
        $this->assertSame('ok', \pmssCertbotRenewalSummaryFromLog($log."\nAll renewals succeeded.\ntrailing noise")['status']);
        $this->assertSame('noop', \pmssCertbotRenewalSummaryFromLog($log."\nNo renewals were attempted.\n")['status']);
    }

    public function testFileSummaryKeepsWholeAndTailReadResults(): void
    {
        $path = $this->pmssMakeTempDir('pmss-renewal-log-').'/renewal.log';
        $log = "All renewals failed.\nold detail\nNo renewals were attempted.\n";
        file_put_contents($path, $log);
        foreach ([strlen($log) + 1, strlen($log), strlen($log) - 1, 30, 1] as $maxBytes) {
            $expected = \pmssCertbotRenewalSummaryFromLog(substr($log, -$maxBytes));
            $this->assertSame($expected, \pmssCertbotRenewalSummaryFromFile($path, $maxBytes));
        }
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

    public function testTailReadFailuresCloseStreamAndPreserveExceptions(): void
    {
        // Intercept only the failing boundaries; open, seek and close real temp files.
        $script = <<<'PHP'
namespace CertbotTailFixture;
function fseek($handle, $offset) {
    $GLOBALS['handle'] = $handle;
    if ($GLOBALS['mode'] === 'seek-false') return -1;
    if (strpos($GLOBALS['mode'], 'seek-') === 0) throw $GLOBALS['failure'];
    return \fseek($handle, $offset);
}
function stream_get_contents($handle, $length = -1) {
    $GLOBALS['length'] = $length;
    if ($GLOBALS['mode'] === 'read-false') return false;
    if (strpos($GLOBALS['mode'], 'read-') === 0) throw $GLOBALS['failure'];
    if ($GLOBALS['mode'] === 'growth') {
        \file_put_contents($GLOBALS['path'], "All renewals failed.\n", FILE_APPEND);
    }
    return \stream_get_contents($handle, $length);
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/certbotRenewal.php', 'CertbotTailFixture');
        $script .= <<<'PHP'
[$GLOBALS['path'], $GLOBALS['mode']] = json_decode(getenv('PMSS_TEST_CERTBOT_TAIL'), true);
$GLOBALS['failure'] = strpos($GLOBALS['mode'], 'error') !== false
    ? new \Error('injected failure') : new \RuntimeException('injected failure');
$summary = null;
$sameException = false;
try {
    $summary = pmssCertbotRenewalSummaryFromFile($GLOBALS['path'], 30);
} catch (\Throwable $error) {
    $sameException = $error === $GLOBALS['failure'];
}
echo json_encode([$summary, $sameException, !is_resource($GLOBALS['handle']), $GLOBALS['length'] ?? null]);
PHP;
        $path = $this->pmssMakeTempFile('pmss-certbot-tail-');
        $log = str_repeat("old log line\n", 10)."No renewals were attempted.\n";
        foreach (['normal', 'growth', 'seek-false', 'seek-exception', 'seek-error',
            'read-false', 'read-exception', 'read-error'] as $mode) {
            file_put_contents($path, $log);
            $throws = strpos($mode, 'exception') !== false || strpos($mode, 'error') !== false;
            $expected = $throws ? null : \pmssCertbotRenewalSummaryFromLog(
                $mode === 'seek-false' || $mode === 'read-false' ? '' : substr($log, -30)
            );
            $this->assertSame([$expected, $throws, true, strpos($mode, 'seek-') === 0 ? null : 30],
                $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_CERTBOT_TAIL' => json_encode([$path, $mode])]));
        }
    }
}
