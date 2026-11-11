<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Faults in scripts/lib/runtime/commandPipes.php use memory streams, never child processes. */
class RuntimeCommandPipeCloseRetrySafetyTest extends TestCase
{
    public function testLiveCloseFailuresRetryOnceAndRetainTheFirstThrowable(): void
    {
        $script = <<<'PHP'
namespace CommandPipeCloseRetryFixture;
function fclose($handle) {
    $slot = array_search($handle, $GLOBALS['handles'], true);
    $attempt = $GLOBALS['attempts'][$slot]++;
    $GLOBALS['events'][] = $slot;
    if (!in_array($slot, $GLOBALS['slots'], true)) return \fclose($handle);
    $mode = $GLOBALS['mode'];
    // Some wrappers fail before closing; others close and then report a fault.
    if ($mode === 'always-throw' || ($attempt === 0 && in_array($mode, ['live-throw', 'retry-throw'], true))) {
        throw $GLOBALS['failures'][$slot][$attempt];
    }
    if ($attempt === 0 && in_array($mode, ['live-false', 'false-then-throw'], true)) return false;
    \fclose($handle);
    if ($mode === 'closed-throw' || ($attempt === 1 && in_array($mode, ['retry-throw', 'false-then-throw'], true))) {
        throw $GLOBALS['failures'][$slot][$attempt];
    }
    return $mode !== 'closed-false';
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/runtime/commandPipes.php', 'CommandPipeCloseRetryFixture');
        $script .= <<<'PHP'
$results = [];
foreach (json_decode(getenv('PMSS_TEST_PIPE_CLOSE_RETRY'), true) as [$mode, $slots, $errorFirst]) {
    $GLOBALS['mode'] = $mode; $GLOBALS['slots'] = $slots;
    $GLOBALS['handles'] = $GLOBALS['events'] = $GLOBALS['failures'] = [];
    $GLOBALS['attempts'] = [0, 0, 0];
    foreach (range(0, 2) as $slot) {
        $GLOBALS['handles'][] = \fopen('php://memory', 'w+');
        // Retry failures have a different identity and family from the initial fault.
        $GLOBALS['failures'][$slot] = $errorFirst
            ? [new \Error('first fault'), new \RuntimeException('retry fault')]
            : [new \RuntimeException('first fault'), new \Error('retry fault')];
    }
    $closed = \fopen('php://memory', 'w+');
    \fclose($closed);
    $context = stream_context_create();
    $caught = $result = null;
    try {
        $result = pmssProcessPipesClose([null, $GLOBALS['handles'][0], $closed,
            $GLOBALS['handles'][1], $context, false, $GLOBALS['handles'][2]]);
    } catch (\Throwable $error) { $caught = $error; }
    $expected = $slots === [] || in_array($mode, ['closed-false', 'live-false'], true)
        ? null : $GLOBALS['failures'][$slots[0]][$mode === 'false-then-throw' ? 1 : 0];
    $live = array_filter($GLOBALS['handles'], 'is_resource');
    $results[] = [$caught === $expected, count($live), $GLOBALS['events'], $result, is_resource($context)];
    // Observe persistent failures before releasing the fixture's remaining handles.
    foreach ($live as $handle) \fclose($handle);
}
echo json_encode($results);
PHP;
        $cases = [];
        foreach (['closed-false', 'closed-throw', 'live-false', 'live-throw', 'retry-throw',
            'always-throw', 'false-then-throw'] as $mode) {
            foreach ([[], [0], [1], [2], [0, 2], [0, 1, 2]] as $slots) {
                foreach ([false, true] as $errorFirst) $cases[] = [$mode, $slots, $errorFirst];
            }
        }
        $results = $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_PIPE_CLOSE_RETRY' => json_encode($cases)]);
        $this->assertSame(count($cases), count($results));
        foreach ($cases as $index => [$mode, $slots, $errorFirst]) {
            $events = [];
            foreach (range(0, 2) as $slot) {
                $events[] = $slot;
                if (in_array($slot, $slots, true) && !in_array($mode, ['closed-false', 'closed-throw'], true)) {
                    $events[] = $slot;
                }
            }
            $this->assertSame([true, $mode === 'always-throw' ? count($slots) : 0, $events, null, true],
                $results[$index], json_encode([$mode, $slots, $errorFirst]));
        }
    }
}
