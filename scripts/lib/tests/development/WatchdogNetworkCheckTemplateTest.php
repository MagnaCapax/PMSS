<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class WatchdogNetworkCheckTemplateTest extends TestCase
{
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
