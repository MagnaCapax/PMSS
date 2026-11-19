<?php
/** Verify request timing in the nginx template preserves watchdog evidence. */
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/lighttpd/watchdogNginxLog.php';
require_once __DIR__.'/../common/TestCase.php';

class NginxRequestTimeLogTest extends TestCase
{
    public function testTemplateTimingPreservesOldAndNewLogClassification(): void
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.nginx-conf');
        $this->assertSame(1, preg_match('/log_format pmss_combined\s+(.+?);/s', $template, $format));
        preg_match_all("/'([^']*)'/", $format[1], $parts);
        $format = implode('', $parts[1]);
        // Read the actual template so moving timing after the anchored suffix fails.
        $this->assertSame(1, substr_count($format, 'pmss_request_time="$request_time" '));
        $this->assertStringContainsString('pmss_request_time="$request_time" pmss_status="$status"', $format);

        $cases = [
            [502, '502', '-', 'failure'],
            [502, '502', '0.003', 'healthy'],
            [401, '401', '0.001', 'healthy'],
            [200, '200', '0.020', 'healthy'],
            [504, '504', '-', null],
            [502, '-', '-', null],
        ];
        foreach ($cases as $case) {
            list($status, $upstreamStatus, $headerTime, $outcome) = $case;
            $expected = $outcome === null ? null : ['port' => 25000, 'outcome' => $outcome];
            foreach (['0.000', '0.001', '125.678'] as $requestTime) {
                $line = strtr($format, [
                    '$status' => (string) $status, '$upstream_addr' => '127.0.0.1:25000',
                    '$upstream_status' => $upstreamStatus, '$upstream_header_time' => $headerTime,
                    '$request_time' => $requestTime,
                ])."\n";
                $this->assertSame($expected, \pmssLighttpdWatchdogNginxEventParse($line));
                // Rotated logs keep their original format throughout rollout.
                $oldLine = str_replace('pmss_request_time="'.$requestTime.'" ', '', $line);
                $this->assertSame($expected, \pmssLighttpdWatchdogNginxEventParse($oldLine));
            }
        }
    }
}
