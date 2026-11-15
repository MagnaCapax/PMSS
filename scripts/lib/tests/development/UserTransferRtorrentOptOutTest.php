<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/userTransfer/rtorrentRestart.php';
require_once dirname(__DIR__, 2).'/testing/filesystem.php';

/** GH#869: restart requests honour opt-out before marker writes or execution. */
class UserTransferRtorrentOptOutTest extends TestCase
{
    public function testOptOutStopsRealAndDryRunRequestsIncludingLinkedMarkers(): void
    {
        $cases = $expected = [];
        foreach (['empty', 'populated', 'link', 'dangling', 'directory-link', 'missing', 'directory'] as $kind) {
            $home = $this->pmssMakeUserWebHome('pmss-transfer-optout-', 'fixtur01');
            $marker = $home.'/.rtorrentDisable';
            if ($kind === 'empty' || $kind === 'populated') {
                $this->pmssWriteFile($marker, $kind === 'empty' ? '' : "disabled\n");
            } elseif ($kind === 'directory') {
                mkdir($marker);
            } elseif ($kind !== 'missing') {
                $target = $home.'/optout-target';
                if ($kind === 'link') $this->pmssWriteFile($target, '');
                if ($kind === 'directory-link') mkdir($target);
                symlink($target, $marker);
            }

            $disabled = !in_array($kind, ['missing', 'directory'], true);
            foreach ([false, true] as $dryRun) {
                foreach (['', '/'] as $suffix) {
                    $requestedHome = $home.$suffix;
                    $cases[] = [$requestedHome, $dryRun];
                    if ($disabled) {
                        $expected[] = [['log', '[INFO] Skipping rTorrent restart: user opted out via .rtorrentDisable']];
                        continue;
                    }
                    // Controls retain the existing request and immediate execution order.
                    $events = $dryRun
                        ? [['log', '[SKIP] Requesting rTorrent restart marker (dry run)']]
                        : [['marker', $requestedHome.'/www/.rtorrentRestart', 'fixtur01']];
                    $events[] = ['run', $requestedHome, 'fixtur01'];
                    $expected[] = $events;
                }
            }
            // An already queued request must also be left untouched while opted out.
            if ($disabled) $this->pmssWriteFile($home.'/www/.rtorrentRestart', 'pending');
        }

        // Export the real request function; replace every mutating operation with a recorder.
        $script = <<<'PHP'
namespace TransferRtorrentOptOutFixture;
function logMessage($message) { $GLOBALS['events'][] = ['log', $message]; }
function pmssEnvFlagEnabled($key) { return $GLOBALS['dryRun']; }
function pmssUserTransferIsPathWithinHome($path, $home) { return true; }
function pmssUserTransferCreateRestartMarker($marker, $user) {
    $GLOBALS['events'][] = ['marker', $marker, $user];
    return true;
}
function pmssUserTransferRunRtorrentRestart($home, $user) {
    $GLOBALS['events'][] = ['run', $home, $user];
}
function is_file($path) {
    // A symlink must short-circuit before any target-following file probe.
    if (\is_link($path)) throw new \RuntimeException('Unexpected symlink target probe');
    return \is_file($path);
}
PHP;
        $script .= \pmssTestingFunctionExport('pmssUserTransferRequestRtorrentRestart', 'TransferRtorrentOptOutFixture');
        $script .= <<<'PHP'
$rows = [];
foreach (json_decode(getenv('PMSS_TEST_TRANSFER_OPTOUT'), true) as [$home, $dryRun]) {
    $GLOBALS['events'] = []; $GLOBALS['dryRun'] = $dryRun;
    pmssUserTransferRequestRtorrentRestart($home, 'fixtur01');
    $rows[] = $GLOBALS['events'];
}
echo json_encode($rows);
PHP;
        $this->assertSame($expected, $this->pmssRunInlinePhpJson($script, [
            'PMSS_TEST_TRANSFER_OPTOUT' => json_encode($cases),
        ]));
        foreach ($cases as [$home]) {
            if (is_link($home.'/.rtorrentDisable') || is_file($home.'/.rtorrentDisable')) {
                $this->assertSame('pending', file_get_contents($home.'/www/.rtorrentRestart'));
            } else {
                $this->assertFalse(file_exists($home.'/www/.rtorrentRestart'));
            }
        }
    }
}
