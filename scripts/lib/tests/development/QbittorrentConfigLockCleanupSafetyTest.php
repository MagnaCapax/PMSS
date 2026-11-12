<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/qbittorrentStoppedConfig.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/qbittorrentStoppedConfig.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/qbittorrentResolverLaunch.php';

/** Exercise account operation-lock ownership without real accounts, files or processes. */
class QbittorrentConfigLockCleanupSafetyTest extends TestCase
{
    public function testCleanupPreservesReturnsOrderingAndThrowablePrecedence(): void
    {
        $script = <<<'PHP'
namespace QbittorrentLockFixture;
function fault($stage) {
    if (isset($GLOBALS['faults'][$stage])) throw $GLOBALS['faults'][$stage];
}
function is_dir($path) { return true; }
function is_link($path) { return substr($path, -8) === '/default'; }
function file_exists($path) { return in_array($GLOBALS['case'], ['refused', 'notice'], true); }
function pmssQbittorrentFileSafe($path) { return true; }
function pmssQbittorrentFrontendFileSafe($path) { return true; }
function notice($home, $message) {
    $GLOBALS['events'][] = 'notice';
    $GLOBALS['notices'][] = $message;
    fault('notice');
}
function pmssQbittorrentFrontendNotice($home, $message) { notice($home, $message); }
function pmssQbittorrentFrontendStartRefused($home, $message) { notice($home, $message); return false; }
function fopen($path, $mode) {
    $GLOBALS['events'][] = 'open';
    $GLOBALS['opened'] = [$path, $mode];
    return $GLOBALS['case'] === 'open-false' ? false : ($GLOBALS['handle'] = \fopen('php://memory', 'w+'));
}
function flock($handle, $operation) {
    $stage = $operation === LOCK_UN ? 'unlock' : 'lock';
    $GLOBALS['events'][] = $stage;
    fault($stage);
    if ($stage === 'unlock') return $GLOBALS['case'] !== 'unlock-false';
    return $GLOBALS['case'] !== 'busy' && ($GLOBALS['case'] !== 'notice' || $GLOBALS['target'] === 'resolver')
        && ($GLOBALS['case'] !== 'retry' || ++$GLOBALS['attempt'] > 1);
}
function fclose($handle) {
    $GLOBALS['events'][] = 'close';
    \fclose($handle);
    fault('close');
    return $GLOBALS['case'] !== 'close-false';
}
function resolve($home, $client) {
    $GLOBALS['events'][] = 'resolve';
    fault('resolve');
    return $GLOBALS['case'] === 'missing' ? null : '/fixture/qbittorrent-nox';
}
function pmssQbittorrentFrontendStart($home, $launch, $ops) {
    $GLOBALS['events'][] = 'start';
    fault('start');
    return $launch();
}
PHP;
        foreach (['pmssQbittorrentConfigLocked', 'pmssQbittorrentFrontendConfigLocked', 'pmssTorrentClientResolverLaunch'] as $name) {
            // Dependencies are stubbed; retain each function's actual lock and launch control flow.
            $script .= \pmssTestingFunctionExport($name, 'QbittorrentLockFixture', [
                "require_once __DIR__.'/configureTorrentClients.php';" => '',
            ]);
        }
        $script .= <<<'PHP'
$results = [];
$cases = ['ok' => [], 'false' => [], 'open-false' => [], 'busy' => [], 'unlock-false' => [], 'close-false' => [],
    'lock' => ['lock'], 'work' => ['work'], 'unlock' => ['unlock'], 'close' => ['close'],
    'work-unlock' => ['work', 'unlock'], 'unlock-close' => ['unlock', 'close'], 'work-close' => ['work', 'close'],
    'notice' => ['notice'], 'retry' => [], 'refused' => [], 'missing' => [], 'resolve' => ['resolve'],
    'start' => ['start'], 'held' => [], 'held-work' => ['work']];
foreach (['operator', 'customer', 'resolver'] as $target) {
    foreach ($cases as $case => $stages) {
        if ($case === 'retry' && $target !== 'operator') continue;
        if (in_array($case, ['refused', 'missing', 'resolve', 'start', 'held', 'held-work'], true) && $target !== 'resolver') continue;
        foreach (['RuntimeException', 'Error'] as $kind) {
            $GLOBALS['target'] = $target; $GLOBALS['case'] = $case;
            $GLOBALS['events'] = []; $GLOBALS['opened'] = []; $GLOBALS['notices'] = [];
            $GLOBALS['handle'] = null; $GLOBALS['faults'] = []; $GLOBALS['attempt'] = 0;
            foreach ($stages as $stage) $GLOBALS['faults'][$stage] = new $kind($stage.' fault');
            $work = static function (): bool {
                $GLOBALS['events'][] = 'work'; fault('work'); return $GLOBALS['case'] !== 'false';
            };
            $result = $caught = null;
            try {
                if ($target === 'operator') $result = pmssQbittorrentConfigLocked('/fixture/home', $work, [
                    'notice' => __NAMESPACE__.'\notice', 'attempts' => $case === 'retry' ? 2 : 1,
                    'pause' => static function (): void { $GLOBALS['events'][] = 'pause'; },
                ]);
                elseif ($target === 'customer') $result = pmssQbittorrentFrontendConfigLocked('/fixture/home', $work);
                else $result = pmssTorrentClientResolverLaunch('/fixture/home', 'qbittorrent', strpos($case, 'held') === 0,
                    ['resolve' => __NAMESPACE__.'\resolve', 'launch' => $work]);
            } catch (\Throwable $error) { $caught = $error; }
            $live = is_resource($GLOBALS['handle']);
            if ($live) \fclose($GLOBALS['handle']);
            $expectedFailure = $stages === [] ? null : end($GLOBALS['faults']);
            $results[] = [$target, $case, $result, $caught === $expectedFailure, $live,
                $GLOBALS['events'], $GLOBALS['opened'], $GLOBALS['notices']];
        }
    }
}
echo json_encode($results);
PHP;
        $results = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(98, count($results));
        foreach ($results as [$target, $case, $result, $sameFailure, $live, $events, $opened, $notices]) {
            $held = strpos($case, 'held') === 0;
            $expected = $held ? [] : ['open'];
            $throws = in_array($case, ['lock', 'work', 'unlock', 'close', 'work-unlock', 'unlock-close', 'work-close',
                'notice', 'resolve', 'start', 'held-work'], true);
            if ($case === 'open-false') $expected[] = 'notice';
            else {
                if (!$held) $expected[] = 'lock';
                if ($case === 'retry') $expected = array_merge($expected, ['pause', 'lock']);
                if (in_array($case, ['notice', 'refused', 'missing'], true) || ($case === 'busy' && $target !== 'resolver')) {
                    if ($case === 'missing') $expected[] = 'resolve';
                    $expected[] = 'notice';
                } elseif (!in_array($case, ['lock', 'busy'], true)) {
                    if ($target === 'resolver') {
                        $expected[] = 'resolve';
                        if ($case !== 'resolve') $expected[] = 'start';
                    }
                    if (!in_array($case, ['resolve', 'start'], true)) $expected[] = 'work';
                }
                if (!$held) {
                    if ($target !== 'resolver' || $case !== 'busy') $expected[] = 'unlock';
                    $expected[] = 'close';
                }
            }
            $expectedResult = $throws ? null : (!in_array($case, ['false', 'open-false', 'refused', 'missing'], true)
                && ($case !== 'busy' || $target === 'resolver'));
            $label = $target.':'.$case;
            $this->assertSame([$expectedResult, true, false, $expected], [$result, $sameFailure, $live, $events], $label);
            $this->assertSame($held ? [] : ['/fixture/home/.config/pmss/torrentClients/qbittorrent.lock', 'ce'], $opened, $label);
            $this->assertSame(in_array('notice', $expected, true) ? 1 : 0, count($notices), $label);
        }
    }
}
