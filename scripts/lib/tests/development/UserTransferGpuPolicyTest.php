<?php
/** Verify transfer GPU deny convergence precedes service restarts without host operations. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/userTransfer/postSetup.php';

class UserTransferGpuPolicyTest extends TestCase
{
    public function testNormalTransfersRepairDeniesBeforeServicesAndRecoverySkipsSetup(): void
    {
        // Export only the controller; every filesystem, command and service boundary is intercepted.
        $script = <<<'PHP'
namespace TransferGpuFixture;
function pmssUserTransferRewriteRtorrentSessionPaths(...$args) {}
function pmssUserTransferPreserveQbittorrentCategories(...$args) {}
function pmssTrafficLimitHomeArtifactReconcile(...$args) {}
function pmssBuildCommand(...$args) { return 'fixture command'; }
function runStep($label, $command) { $GLOBALS['events'][] = 'permissions'; return 0; }
function pmssEnsureIntelRenderNodeDenies($logger) {
    $GLOBALS['events'][] = ['denies', $logger];
    if ($GLOBALS['throw']) throw new \RuntimeException('deny boundary');
}
function pmssTorrentClientsPostSetupConverge(...$args) { $GLOBALS['events'][] = 'clients'; }
function pmssUserTransferRenameRutorrentShare(...$args) { $GLOBALS['events'][] = 'rename'; }
function pmssUserTransferVerifyPayloadOwnership(...$args) { $GLOBALS['events'][] = 'ownership'; }
function pmssUserTransferRequestRtorrentRestart(...$args) { $GLOBALS['events'][] = 'rtorrent'; }
function pmssUserTransferRequestQbittorrentRestart(...$args) { $GLOBALS['events'][] = 'qbittorrent'; }
function pmssUserTransferVerifyCompleteness(...$args) { $GLOBALS['events'][] = 'completeness'; }
PHP;
        $script .= \pmssTestingFunctionExport('pmssUserTransferPostSetup', 'TransferGpuFixture');
        $script .= <<<'PHP'
$results = [];
foreach ([
    ['fixtur01', [], false], ['fixtur02', [], false], ['fixtur02', ['into' => null], false],
    ['fixtur01', ['into' => 'old-copy'], false], ['fixtur02', ['into' => '0'], false],
    ['fixtur01', [], true], ['fixtur02', [], true],
] as [$remote, $options, $throw]) {
    $GLOBALS['events'] = [];
    $GLOBALS['throw'] = $throw;
    $error = null;
    try {
        pmssUserTransferPostSetup($options + ['localUser' => 'fixtur01', 'remoteUser' => $remote],
            '/fixture/home', array_fill_keys(['expect', 'qbittorrentProbeScript', 'qbittorrentConfig',
                'qbittorrentCategories', 'remoteSizeScript'], '/fixture/scratch'));
    } catch (\RuntimeException $exception) {
        $error = $exception->getMessage();
    }
    $results[] = [$GLOBALS['events'], $error];
}
echo json_encode($results);
PHP;
        $start = ['permissions', ['denies', 'logMessage']];
        $finish = ['rtorrent', 'qbittorrent', 'completeness'];
        $normal = array_merge($start, ['clients'], $finish);
        $renamed = array_merge($start, ['clients', 'rename', 'ownership'], $finish);
        $this->assertSame([
            [$normal, null], [$renamed, null], [$renamed, null], [[], null], [[], null],
            [$start, 'deny boundary'], [$start, 'deny boundary'],
        ], $this->pmssRunInlinePhpJson($script));
    }

    public function testPostSetupConvergesBeforeTargetContainersStart(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/lib/userTransfer.php', [
            'pmssUserTransferPostSetup($cfg, $home, $scratchPaths)',
            "runStep('Starting rootless Docker for '", "runStep('Starting transferred containers'",
        ]);
    }
}
