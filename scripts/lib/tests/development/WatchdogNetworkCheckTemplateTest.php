<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class WatchdogNetworkCheckTemplateTest extends TestCase
{
    public function testWatchdogLoadThresholdsAreExplicit(): void
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.watchdog.conf');
        $this->assertStringContainsAllStrings([
            'max-load-1 = 300',
            'max-load-5 = 225',
            'max-load-15 = 150',
        ], $template);
    }

    public function testNetworkCheckRequiresAllTargetsToFail(): void
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.watchdog.network-check.sh');

        $this->assertStringContainsAllStrings([
            'DEFAULT_GATEWAYS=$(ip -4 route show default',
            'EXTERNAL_TARGETS="1.1.1.1 8.8.8.8"',
            'TARGETS="$DEFAULT_GATEWAYS $EXTERNAL_TARGETS"',
            'for ip in $TARGETS; do',
            'if ping -c "$PING_COUNT" -W "$PING_TIMEOUT" "$ip" >/dev/null 2>&1; then',
            'exit 0',
        ], $template);
    }

    public function testNetworkCheckPreservesFailureEvidence(): void
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.watchdog.network-check.sh');

        // The check can end in a reboot and /run is tmpfs, so the evidence has to reach
        // syslog to survive the decision it makes. (Refs #916, Refs #669)
        $this->assertStringContainsAllStrings([
            'logger -t "$LOG_TAG"',
            'failure window opened',
            'SUSTAINED FAILURE',
            'recovered on ${ip}',
            'ping-binary-missing',
        ], $template);
    }

    public function testNetworkCheckRateLimitsItsOwnLogging(): void
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.watchdog.network-check.sh');

        // The daemon runs this every 10s against an 1800s window: without a rate limit
        // a single window would write 180 lines into syslog.
        $this->assertStringContainsAllStrings([
            'LOG_INTERVAL=60',
            'log_due() {',
            'if log_due "$now"; then',
        ], $template);
    }

    public function testNetworkCheckGatesSustainedEvidenceUntilRecovery(): void
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.watchdog.network-check.sh');

        $this->assertStringContainsAllStrings([
            'SUSTAINED_FILE="/run/pmss-watchdog-net-sustained"',
            'if [ ! -f "$SUSTAINED_FILE" ]; then',
            'echo "$now" >"$SUSTAINED_FILE" 2>/dev/null || true',
            'elif log_due "$now"; then',
            'rm -f "$FAIL_FILE" "$LOG_STATE" "$SUSTAINED_FILE" 2>/dev/null || true',
        ], $template);
        $gate = strpos($template, 'if [ ! -f "$SUSTAINED_FILE" ]; then');
        $boundary = strpos($template, 'log_line "SUSTAINED FAILURE');
        $periodic = strpos($template, 'elif log_due "$now"; then');
        $failure = strpos($template, 'exit 1');
        $this->assertTrue($gate < $boundary && $boundary < $periodic && $periodic < $failure,
            'The full sustained block must be gated while every sustained check still exits 1.');
    }

    public function testNetworkCheckWritesRotatedEvidenceBeforeSyslog(): void
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.watchdog.network-check.sh');

        $this->assertStringContainsString('EVIDENCE_LOG="/var/log/pmss/check', $template);
        $fileWrite = strpos($template, '>>"$EVIDENCE_LOG" 2>/dev/null || true');
        $syslogWrite = strpos($template, 'logger -t "$LOG_TAG"');
        $this->assertTrue($fileWrite !== false && $syslogWrite !== false && $fileWrite < $syslogWrite,
            'The rotated file must be the first evidence sink.');
    }

    public function testNetworkCheckCapturesLocalDiscriminators(): void
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.watchdog.network-check.sh');

        $this->assertStringContainsAllStrings([
            '/proc/pressure/io',
            '/proc/pressure/cpu',
            '$1 == "some" {sub(/^avg10=/, "", $2)',
            'psi_io_some_avg10=${io_pressure:-?}',
            'psi_cpu_some_avg10=${cpu_pressure:-?}',
            '/sys/class/net/$iface/statistics/rx_bytes',
            '/sys/class/net/$iface/statistics/tx_bytes',
            'read -r PROBE_START _ </proc/uptime',
            'read -r PROBE_END _ </proc/uptime',
            'probe=${probe_seconds}s',
            'command -v ping >/dev/null 2>&1 || return 0',
        ], $template);
    }

    public function testNetworkCheckKeepsDateOffTheHealthyPath(): void
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.watchdog.network-check.sh');
        $ping = strpos($template, 'if ping -c "$PING_COUNT" -W "$PING_TIMEOUT" "$ip" >/dev/null 2>&1; then');
        $recovery = strpos($template, 'if [ -n "$first" ]; then', $ping);
        $date = strpos($template, 'now=$(date +%s)');
        $this->assertTrue($ping !== false && $recovery !== false && $date > $recovery);
        $this->assertStringNotContainsString('date', substr($template, $ping, $recovery - $ping));
        $this->assertStringContainsString("now=\$(date +%s)\nprobe_finished\n\nif [ -n \"\$first\" ]; then", $template);
    }

    public function testNetworkCheckBudgetsEachEvidenceCollector(): void
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.watchdog.network-check.sh');
        $this->assertStringContainsAllStrings([
            'EVIDENCE_BUDGET=20',
            "host_snapshot() {\n\tif ! within_budget; then",
            "for ip in \$TARGETS; do\n\t\tif ! within_budget; then",
            'snapshot=skipped runtime=${runtime}s probe=${probe_seconds}s',
            'budget-exhausted',
            "if within_budget; then\n\t\t\t\troute=",
            'rm -f "$SUSTAINED_FILE" 2>/dev/null || true',
        ], $template);
    }

    public function testNetworkCheckFailureWindowAndRecoveryInShell(): void
    {
        $root = $this->networkFixture();
        // A stale sustained marker must not suppress the next window's evidence.
        file_put_contents($root.'/pmss-watchdog-net-sustained', 'stale');
        $this->assertNetworkRun($root, 245, 6);
        $this->assertFalse(file_exists($root.'/pmss-watchdog-net-sustained'));
        file_put_contents($root.'/pmss-watchdog-net-fail', (string) (time() - 1900));
        foreach ([6, 3, 3] as $calls) {
            $this->assertNetworkRun($root, 1, $calls);
        }
        $log = file_get_contents($root.'/checkNetworkWatchdog.log');
        $this->assertSame(1, substr_count($log, 'SUSTAINED FAILURE'));
        file_put_contents($root.'/ping-exit', '0');
        $this->assertNetworkRun($root, 0, 1);
        foreach (['fail', 'lastlog', 'sustained'] as $marker) {
            $this->assertFalse(file_exists($root.'/pmss-watchdog-net-'.$marker));
        }
        // With no window open, success must not call date or write evidence.
        unlink($root.'/date-calls');
        $before = file_get_contents($root.'/checkNetworkWatchdog.log');
        $this->assertNetworkRun($root, 0, 1);
        $this->assertFalse(file_exists($root.'/date-calls'));
        $this->assertSame($before, file_get_contents($root.'/checkNetworkWatchdog.log'));
    }

    public function testNetworkCheckStopsEvidenceAtBudgetBoundary(): void
    {
        $root = $this->networkFixture();
        // The probe crosses the budget; only the original three pings may run.
        file_put_contents($root.'/stop-after', '3');
        file_put_contents($root.'/pmss-watchdog-net-fail', (string) (time() - 1900));
        $this->assertNetworkRun($root, 1, 3);
        $log = file_get_contents($root.'/checkNetworkWatchdog.log');
        $this->assertStringContainsString('snapshot=skipped runtime=20s probe=20s', $log);
        $this->assertSame(1, substr_count($log, 'budget-exhausted'));
        // Only target discovery may call ip once the budget has expired.
        $this->assertSame("ip\n", file_get_contents($root.'/ip-calls'));
    }

    public function testNetworkCheckChecksBudgetBetweenDiagnosticTargets(): void
    {
        $root = $this->networkFixture();
        file_put_contents($root.'/stop-after', '4');
        $this->assertNetworkRun($root, 245, 4);
        $log = file_get_contents($root.'/checkNetworkWatchdog.log');
        $this->assertSame(1, substr_count($log, 'budget-exhausted'));
    }

    public function testNetworkCheckSkipsEvidenceWithInvalidOrMissingUptime(): void
    {
        foreach (['invalid', ''] as $uptime) {
            $root = $this->networkFixture();
            file_put_contents($root.'/uptime', $uptime);
            if ($uptime === '') {
                unlink($root.'/uptime');
            }
            file_put_contents($root.'/pmss-watchdog-net-fail', 'invalid');
            $this->assertNetworkRun($root, 245, 3);
            $log = file_get_contents($root.'/checkNetworkWatchdog.log');
            $this->assertStringContainsString('snapshot=skipped runtime=?s probe=?s', $log);
            $this->assertSame(1, substr_count($log, 'budget-exhausted'));
        }
    }

    /** Sandbox all writes and commands that could contact the network or syslog. */
    private function networkFixture(): string
    {
        $root = $this->pmssMakeTempDir('pmss-watchdog-network-');
        mkdir($root.'/bin');
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.watchdog.network-check.sh');
        file_put_contents($root.'/check.sh', str_replace(
            ['/run/', '/var/log/pmss', '/proc/uptime'], [$root.'/', $root, $root.'/uptime'], $template
        ));
        foreach (['uptime' => "100.00 0\n", 'ping-exit' => '1', 'stop-after' => '0'] as $file => $value) {
            file_put_contents($root.'/'.$file, $value);
        }
        $prefix = "#!/bin/sh\nroot=".escapeshellarg($root)."\n";
        $this->pmssWriteExecutableFile($root.'/bin/ping', $prefix.<<<'SH'
read -r count <"$root/ping-calls"
count=$((count + 1))
echo "$count" >"$root/ping-calls"
read -r stop_after <"$root/stop-after"
if [ "$count" -eq "$stop_after" ]; then echo '120.00 0' >"$root/uptime"; fi
read -r status <"$root/ping-exit"
echo 'stub ping'
exit "$status"
SH
        );
        $this->pmssWriteExecutableFile($root.'/bin/ip', $prefix
            ."echo ip >>\"\$root/ip-calls\"\necho 'default via 192.0.2.1 dev fixture0'\n");
        $this->pmssWriteExecutableFile($root.'/bin/logger', "#!/bin/sh\nexit 0\n");
        $this->pmssWriteExecutableFile($root.'/bin/date', $prefix
            ."echo date >>\"\$root/date-calls\"\nexec /bin/date \"\$@\"\n");
        return $root;
    }

    /** Run the real template under sh, preserving its watchdog exit status. */
    private function assertNetworkRun(string $root, int $expectedExit, int $expectedPings): void
    {
        file_put_contents($root.'/ping-calls', '0');
        $command = 'PATH='.escapeshellarg($root.'/bin:/usr/bin:/bin').' sh '.escapeshellarg($root.'/check.sh').' 2>&1';
        $result = $this->pmssExecShellCommand($command, [], '');
        $this->assertSame($expectedExit, $result['rc'], $result['output']);
        $this->assertSame($expectedPings, (int) file_get_contents($root.'/ping-calls'));
    }

    public function testNetworkCheckKeepsTransientExitUntilThreshold(): void
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.watchdog.network-check.sh');

        $thresholdFailure = strpos($template, 'if [ "$elapsed" -ge "$FAIL_THRESHOLD" ]; then');
        $failureExit = strpos($template, 'exit 1');
        $transientExit = strrpos($template, 'exit 245');

        $this->assertTrue($thresholdFailure !== false, 'Network check must keep the sustained-failure threshold.');
        $this->assertTrue($failureExit !== false, 'Network check must still fail after the sustained threshold.');
        $this->assertTrue($transientExit !== false, 'Network check must keep watchdog EDONTKNOW exit 245.');
        $this->assertTrue($thresholdFailure < $failureExit, 'Watchdog failure must stay behind the threshold.');
        $this->assertTrue($failureExit < $transientExit, 'Transient exit must remain the default before threshold failure.');
    }
}
