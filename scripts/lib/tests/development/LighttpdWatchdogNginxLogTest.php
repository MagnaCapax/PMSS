<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/lighttpd/watchdogNginxLogReader.php';
require_once __DIR__.'/../common/TestCase.php';

class LighttpdWatchdogNginxLogTest extends TestCase
{
    private function nginxLine(int $port, int $status = 502, string $upstreamStatus = '502', string $headerTime = '-'): string
    {
        return '127.0.0.1 - - [23/Aug/2026:12:00:00 +0200] "GET /user-alice/ HTTP/1.1" '
            .$status.' 123 "-" "fixture" pmss_status="'.$status.'" pmss_upstream_addr="127.0.0.1:'.$port
            .'" pmss_upstream_status="'.$upstreamStatus.'" pmss_upstream_header_time="'.$headerTime.'"' . "\n";
    }

    private function failureEvent(int $port = 25000): array
    {
        return array('port' => $port, 'outcome' => 'failure');
    }

    public function testParserClassifiesNoHeader502AsTransportFailure(): void
    {
        $this->assertSame($this->failureEvent(), \pmssLighttpdWatchdogNginxEventParse($this->nginxLine(25000)));
    }

    public function testNginxTemplateEmitsTheParserSuffix(): void
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.nginx-conf');
        $this->assertStringContainsString('log_format pmss_combined', $template);
        $this->assertStringContainsString('pmss_status="$status"', $template);
        $this->assertStringContainsString('pmss_upstream_addr="$upstream_addr"', $template);
        $this->assertStringContainsString('pmss_upstream_status="$upstream_status"', $template);
        $this->assertStringContainsString('pmss_upstream_header_time="$upstream_header_time"', $template);
        $this->assertStringContainsString('access_log /var/log/nginx/access.log pmss_combined;', $template);
    }

    public function testParserTreatsApplication502WithHeaderAsHealthyHop(): void
    {
        $this->assertSame(
            array('port' => 25000, 'outcome' => 'healthy'),
            \pmssLighttpdWatchdogNginxEventParse($this->nginxLine(25000, 502, '502', '0.003'))
        );
    }

    public function testParserTreatsAuthenticatedResponseAsHealthyHop(): void
    {
        $this->assertSame(
            array('port' => 25000, 'outcome' => 'healthy'),
            \pmssLighttpdWatchdogNginxEventParse($this->nginxLine(25000, 401, '401', '0.001'))
        );
    }

    public function testParserIgnoresLegacyAndNonLoopbackLines(): void
    {
        $this->assertSame(null, \pmssLighttpdWatchdogNginxEventParse('legacy combined line'));
        $nonLoopback = str_replace('127.0.0.1:25000', '192.0.2.1:25000', $this->nginxLine(25000));
        $this->assertSame(null, \pmssLighttpdWatchdogNginxEventParse($nonLoopback));
    }

    public function testStateRequiresThreeFailureCyclesBeforeRestart(): void
    {
        $state = array();
        for ($cycle = 1; $cycle <= 3; $cycle++) {
            $result = \pmssLighttpdWatchdogNginxStateAdvance($state, [$this->failureEvent()], [25000 => 'alice']);
            $state = array('users' => $result['users']);
            $this->assertSame($cycle === 3 ? array('alice' => 'restart') : array(), $result['actions']);
        }
    }

    public function testMultipleFailuresInOneRunAdvanceOnlyOneCycle(): void
    {
        $result = \pmssLighttpdWatchdogNginxStateAdvance(
            array(),
            [$this->failureEvent(), $this->failureEvent(), $this->failureEvent()],
            [25000 => 'alice']
        );
        $this->assertSame(1, $result['users']['alice']['failureCycles']);
        $this->assertSame(array(), $result['actions']);
    }

    public function testHealthyResponseResetsFailureAndRecoveryStage(): void
    {
        $state = array('users' => array('alice' => array('failureCycles' => 2, 'recoveryStage' => 1)));
        $events = [
            $this->failureEvent(),
            array('port' => 25000, 'outcome' => 'healthy'),
        ];
        $result = \pmssLighttpdWatchdogNginxStateAdvance($state, $events, [25000 => 'alice']);
        $this->assertSame(array('failureCycles' => 0, 'recoveryStage' => 0), $result['users']['alice']);
        $this->assertSame(array(), $result['actions']);
    }

    public function testContinuedFailureAfterRestartEscalatesToReconfigure(): void
    {
        $state = array('users' => array('alice' => array('failureCycles' => 2, 'recoveryStage' => 1)));
        $result = \pmssLighttpdWatchdogNginxStateAdvance($state, [$this->failureEvent()], [25000 => 'alice']);
        $this->assertSame(array('alice' => 'reconfigure'), $result['actions']);
        $this->assertSame(2, $result['users']['alice']['recoveryStage']);
    }

    public function testExhaustedRecoveryDoesNotRepeatDestructiveActions(): void
    {
        $state = array('users' => array('alice' => array('failureCycles' => 2, 'recoveryStage' => 2)));
        $result = \pmssLighttpdWatchdogNginxStateAdvance($state, [$this->failureEvent()], [25000 => 'alice']);
        $this->assertSame(array(), $result['actions']);
        $this->assertSame(0, $result['users']['alice']['failureCycles']);
    }

    public function testIncrementalReaderBaselinesThenPersistsRestartDecision(): void
    {
        $logPath = $this->pmssMakeTempPath('lighttpd-nginx-log-', '.log');
        $statePath = $this->pmssMakeTempPath('lighttpd-nginx-state-', '.json');
        $usersByPort = [25000 => 'alice'];

        file_put_contents($logPath, $this->nginxLine(25000));
        $this->assertSame(array(), \pmssLighttpdWatchdogNginxActionsRead($logPath, $statePath, $usersByPort));
        $actions = array();
        for ($cycle = 1; $cycle <= 3; $cycle++) {
            file_put_contents($logPath, $this->nginxLine(25000), FILE_APPEND);
            $actions = \pmssLighttpdWatchdogNginxActionsRead($logPath, $statePath, $usersByPort);
        }
        $this->assertSame(array('alice' => 'restart'), $actions);
        $this->assertSame(array(), \pmssLighttpdWatchdogNginxActionsRead($logPath, $statePath, $usersByPort));
    }

    public function testIncrementalReaderRetriesAnIncompleteFinalLine(): void
    {
        $logPath = $this->pmssMakeTempPath('lighttpd-nginx-log-', '.log');
        $statePath = $this->pmssMakeTempPath('lighttpd-nginx-state-', '.json');
        file_put_contents($logPath, '');
        \pmssLighttpdWatchdogNginxActionsRead($logPath, $statePath, [25000 => 'alice']);

        file_put_contents($logPath, rtrim($this->nginxLine(25000), "\n"), FILE_APPEND);
        \pmssLighttpdWatchdogNginxActionsRead($logPath, $statePath, [25000 => 'alice']);
        file_put_contents($logPath, "\n", FILE_APPEND);
        \pmssLighttpdWatchdogNginxActionsRead($logPath, $statePath, [25000 => 'alice']);

        $state = json_decode((string) file_get_contents($statePath), true);
        $this->assertSame(1, $state['users']['alice']['failureCycles']);
    }

    public function testIncrementalReaderFailsSoftForUnsafeInputs(): void
    {
        $logPath = $this->pmssMakeTempPath('lighttpd-nginx-log-', '.log');
        file_put_contents($logPath, $this->nginxLine(25000));
        $target = $this->pmssMakeTempPath('lighttpd-nginx-target-', '.json');
        $link = $this->pmssMakeTempPath('lighttpd-nginx-link-', '.json');
        @unlink($link);
        if (!@symlink($target, $link)) {
            throw new SkipTest('symlink fixture unavailable');
        }

        $this->assertSame(array(), \pmssLighttpdWatchdogNginxActionsRead($logPath, $link, [25000 => 'alice']));
        $this->assertSame(array(), \pmssLighttpdWatchdogNginxActionsRead('/missing/nginx.log', $target, [25000 => 'alice']));
    }
}
