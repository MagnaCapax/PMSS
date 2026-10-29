<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/services/systemd.php';

class SystemdServiceBoundariesTest extends TestCase
{
    public function testUnboundPropertyBytesAndLastValuePrecedence(): void
    {
        // These are legacy parser contracts, including numeric-prefix casts and raw keys.
        foreach ([
            ["ActiveState=failed\nActiveState=active", false],
            ["ActiveState=active\nActiveState=failed", true],
            [" ActiveState=failed", false],
            ["ActiveState =failed", false],
            ["ActiveState= \tfailed\r\n", true],
            ["ActiveState=failed\0", true],
            ["ActiveState=failed=again", false],
            ["ActiveState=inactive\nNRestarts=2suffix\nResult=exit-code", true],
            ["ActiveState=inactive\nNRestarts=2\nResult=exit=code", true],
            ["ActiveState=inactive\nNRestarts=-1\nResult=exit-code", false],
            ["ActiveState=inactive\nNRestarts=2", false],
            ["ActiveState=inactive\nNRestarts=2\nResult=", true],
            ["ActiveState=inactive\nNRestarts=2\nResult=exit-code\nResult=success", false],
            ["ActiveState=inactive\rNRestarts=2\rResult=exit-code", false],
            ["=failed\nActiveState\nNRestarts=2\nResult=exit-code", false],
        ] as [$show, $expected]) {
            $this->assertSame($expected, \pmssUnboundShowIndicatesFailure($show), bin2hex($show));
        }
    }

    public function testCronQuotaBoundariesKeepTheDropinPayload(): void
    {
        // The four-thread payload already has a fixed SHA-256 snapshot in updateServicesRuntimeTest.
        $reference = \pmssCronRestartDropinContent(4);
        foreach ([PHP_INT_MIN, -10, 0, 1, 2, 3, 8, 64] as $threads) {
            $quota = $threads < 2 ? '' : 'CPUQuota='.(($threads - 1) * 100)."%\n";
            $this->assertSame(str_replace("CPUQuota=300%\n", $quota, $reference), \pmssCronRestartDropinContent($threads));
        }
    }

    public function testSpoolAllowlistKeepsTrailingSlashNormalization(): void
    {
        foreach (['input', 'msglog', 'db'] as $leaf) {
            $path = '/var/spool/exim4/'.$leaf;
            foreach (['', '/', '///'] as $suffix) {
                $this->assertSame("find '".$path."' -xdev -type f -delete 2>/dev/null || true", \pmssEximSpoolCleanupCommand($path.$suffix));
            }
        }
        foreach (['', '/', '/var/spool/exim4/Input', '/var/spool/exim4/input/.',
            '/var/spool/exim4/input//child', '/var/spool/exim4/input'."\0/", " /var/spool/exim4/input"] as $path) {
            $this->assertSame(null, \pmssEximSpoolCleanupCommand($path), bin2hex($path));
        }
    }

    public function testTimerUnrecognizedStatesStillEnableBeforeFirstTrim(): void
    {
        // Child-local log spies keep this matrix independent of runner include order.
        $source = <<<'PHP'
function logmsg(string $message): void { $GLOBALS['logs'][] = $message; }
require_once %s;
putenv('PMSS_DRY_RUN=1');
$results = [];
foreach (['static', 'enabled-runtime', 'masked-runtime', '0', 'Enabled', ' masked '] as $state) {
    $GLOBALS['logs'] = $GLOBALS['PMSS_PROFILE'] = [];
    ob_start();
    pmssEnsureFstrimTimerForState($state, 'boundary');
    ob_end_clean();
    $results[] = [array_column($GLOBALS['PMSS_PROFILE'], 'command'), array_column($GLOBALS['PMSS_PROFILE'], 'description'), $GLOBALS['logs']];
}
echo json_encode($results);
PHP;
        $result = $this->pmssExecInlinePhp(sprintf($source, var_export(dirname(__DIR__, 2).'/update/services/systemd.php', true)));
        $this->assertSame(0, $result['rc'], $result['output']);
        $rows = $this->pmssDecodeJsonArray($result['output'], $result['output']);
        $this->assertSame(6, count($rows));
        foreach ($rows as $row) {
            $this->assertSame([
                ['systemctl enable --now fstrim.timer || true', 'systemctl start --no-block fstrim.service || true'],
                ['Enabling weekly TRIM timer (boundary)', 'Starting first TRIM run in the background (boundary)'], [],
            ], $row);
        }
    }

    public function testServicePolicyCompletesBeforePackageAndSpoolCleanup(): void
    {
        $this->pmssResetRuntimeProfile();
        $this->pmssWithEnv(['PMSS_DRY_RUN' => '1'], static function (): void {
            \pmssStopDisableMaskSeedboxSystemServices();
        });
        $commands = $this->pmssProfileCommands();
        $this->assertSame(86, count($commands));
        $this->assertSame([
            "systemctl stop 'lighttpd' || true", "systemctl disable 'lighttpd' || true", "systemctl mask 'lighttpd' || true",
        ], array_slice($commands, 0, 3));
        $this->assertSame([
            "systemctl stop 'qbittorrent-nox' || true", "systemctl disable 'qbittorrent-nox' || true", "systemctl mask 'qbittorrent-nox' || true",
            \aptCmd('purge -y exim4 exim4-base exim4-config exim4-daemon-light'), \aptCmd('autoremove -y'),
            "find '/var/spool/exim4/input' -xdev -type f -delete 2>/dev/null || true",
            "find '/var/spool/exim4/msglog' -xdev -type f -delete 2>/dev/null || true",
            "find '/var/spool/exim4/db' -xdev -type f -delete 2>/dev/null || true",
        ], array_slice($commands, -8));
    }
}
