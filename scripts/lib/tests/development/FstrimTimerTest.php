<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class FstrimTimerTest extends TestCase
{
    public function testTimerStateMatrix(): void
    {
        // An isolated process supplies a logmsg spy before the runtime is loaded.
        $source = <<<'PHP'
function logmsg(string $message): void { $GLOBALS['trimLogs'][] = $message; }
require_once %s;
putenv('PMSS_DRY_RUN=1');
$results = [];
foreach (['enabled', 'disabled', 'masked', '', 'not-found', null] as $state) {
    $GLOBALS['trimLogs'] = [];
    $GLOBALS['PMSS_PROFILE'] = [];
    ob_start();
    pmssEnsureFstrimTimerForState($state, 'test');
    ob_end_clean();
    $results[$state === null ? 'null' : $state] = [
        'commands' => array_column($GLOBALS['PMSS_PROFILE'], 'command'),
        'descriptions' => array_column($GLOBALS['PMSS_PROFILE'], 'description'),
        'logs' => $GLOBALS['trimLogs'],
    ];
}
echo json_encode($results);
PHP;
        $source = sprintf($source, var_export(dirname(__DIR__, 2).'/update/services/systemd.php', true));
        $result = $this->pmssExecShellCommand(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($source), [], '2>&1');
        $this->assertSame(0, $result['rc']);
        $states = json_decode($result['output'], true);
        $this->assertTrue(is_array($states), $result['output']);

        $empty = ['commands' => [], 'descriptions' => [], 'logs' => []];
        $this->assertSame($empty, $states['enabled']);
        $this->assertSame($empty, $states['null']);
        $this->assertSame([
            'commands' => [
                'systemctl enable --now fstrim.timer || true',
                'systemctl start --no-block fstrim.service || true',
            ],
            'descriptions' => [
                'Enabling weekly TRIM timer (test)',
                'Starting first TRIM run in the background (test)',
            ],
            'logs' => [],
        ], $states['disabled']);
        $this->assertSame(['commands' => [], 'descriptions' => [], 'logs' => ['[INFO] fstrim.timer is masked by the operator; left as is']], $states['masked']);
        $notShipped = ['commands' => [], 'descriptions' => [], 'logs' => ['[INFO] fstrim.timer is not shipped on this release; weekly TRIM skipped']];
        $this->assertSame($notShipped, $states['']);
        $this->assertSame($notShipped, $states['not-found']);
    }
}
