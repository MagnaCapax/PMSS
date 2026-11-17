<?php
/** Verify combined port-scan completion across namespace refusals and throwables. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class PortManagerScanCompletionSafetyTest extends TestCase
{
    public function testCompletionRequiresBothNamespaceScansToFinish(): void
    {
        $script = <<<'PHP'
namespace PortManagerScanCompletionFixture;
// Every host boundary is synthetic; no directory, reservation or socket is opened.
function boundary($stage) {
    $GLOBALS['events'][] = $stage;
    $GLOBALS['observed'][] = $GLOBALS['complete'];
    if (count($GLOBALS['events']) === $GLOBALS['failAt']) throw $GLOBALS['failure'];
}
function is_dir($path) {
    $kind = basename($path);
    boundary('root-'.$kind);
    return $GLOBALS['mode'] !== 'missing-'.$kind;
}
function is_link($path) { boundary('link-'.basename($path)); return false; }
function pmssPathExistsOrLink($path) { boundary('exists-'.basename($path)); return false; }
function glob($pattern) {
    $kind = strpos($pattern, '/fixture/legacy/') === 0 ? 'legacy' : 'managed';
    boundary('glob-'.$kind);
    if ($GLOBALS['mode'] === 'failed-'.$kind) return false;
    if ($GLOBALS['mode'] === 'empty') return [];
    return $kind === 'legacy' ? ['/fixture/legacy/scgi/26000', '/fixture/legacy/scgi/28000']
        : ['/fixture/managed/a', '/fixture/managed/b', '/fixture/managed/c'];
}
function pmssNetworkPortParseDigits($raw) { boundary('decode-legacy-'.$raw); return (int) $raw; }
function pmssPortManagerReadAssignedPort($path) {
    boundary('decode-managed-'.basename($path));
    return ['a' => 24000, 'b' => 26000, 'c' => null][basename($path)];
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/portManager/state.php', 'PortManagerScanCompletionFixture');
        $script .= <<<'PHP'
$cases = [];
foreach (['normal', 'empty', 'missing-legacy', 'missing-managed', 'failed-legacy', 'failed-managed'] as $mode) {
    $cases[] = [$mode, 0, null];
}
// Cover each root check, recheck, glob and decoder in both namespaces.
foreach (['RuntimeException', 'Error'] as $class) {
    foreach ([1, 3, 4, 6, 7, 8, 10, 11, 13, 14, 15] as $failAt) {
        $cases[] = ['normal', $failAt, $class];
    }
}
$results = [];
foreach ($cases as [$mode, $failAt, $class]) {
    // Reusing true models a caller that already completed an earlier scan.
    $GLOBALS['complete'] = true;
    $GLOBALS['mode'] = $mode; $GLOBALS['failAt'] = $failAt;
    $GLOBALS['events'] = []; $GLOBALS['observed'] = [];
    $GLOBALS['failure'] = $class === null ? null : new $class('fixture scan fault');
    $ports = $caught = null;
    try { $ports = pmssPortManagerUsedPorts('/fixture/managed/', '/fixture/legacy/', $GLOBALS['complete']); }
    catch (\Throwable $error) { $caught = $error; }
    $results[] = [$mode, $failAt, $class, $ports, $GLOBALS['complete'],
        $caught === $GLOBALS['failure'], $GLOBALS['events'], $GLOBALS['observed']];
}
echo json_encode($results);
PHP;
        $results = $this->pmssRunInlinePhpJson($script);
        $legacyEvents = ['root-legacy', 'link-legacy', 'glob-legacy', 'root-legacy', 'link-legacy',
            'decode-legacy-26000', 'decode-legacy-28000'];
        $managedEvents = ['root-managed', 'link-managed', 'glob-managed', 'root-managed', 'link-managed',
            'decode-managed-a', 'decode-managed-b', 'decode-managed-c'];
        $allEvents = array_merge($legacyEvents, $managedEvents);
        // Numeric JSON keys are decoded back into integer port keys by the shared runner.
        $expected = [
            'normal' => [[26000 => true, 28000 => true, 24000 => true], true, $allEvents],
            'empty' => [[], true, array_merge(array_slice($legacyEvents, 0, 5), array_slice($managedEvents, 0, 5))],
            'missing-legacy' => [[24000 => true, 26000 => true], true,
                array_merge(['root-legacy', 'exists-legacy'], $managedEvents)],
            'missing-managed' => [[26000 => true, 28000 => true], false,
                array_merge($legacyEvents, ['root-managed'])],
            'failed-legacy' => [[24000 => true, 26000 => true], false,
                array_merge(array_slice($legacyEvents, 0, 3), $managedEvents)],
            'failed-managed' => [[26000 => true, 28000 => true], false,
                array_merge($legacyEvents, array_slice($managedEvents, 0, 3))],
        ];
        $this->assertSame(28, count($results));
        foreach ($results as [$mode, $failAt, $class, $ports, $complete, $sameFailure, $events, $observed]) {
            $label = $mode.' '.$class.' '.$failAt;
            $this->assertTrue($sameFailure, $label);
            // No callback may observe stale success, even while the second scan runs.
            $this->assertSame(array_fill(0, count($events), false), $observed, $label);
            if ($failAt > 0) {
                $this->assertSame(null, $ports, $label);
                $this->assertFalse($complete, $label);
                $this->assertSame(array_slice($allEvents, 0, $failAt), $events, $label);
            } else {
                $this->assertSame($expected[$mode], [$ports, $complete, $events], $label);
            }
        }
    }
}
