<?php
/** Profiled PHP invocation and classified failure dispatch; loaded by profile.php.
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * Run non-shell orchestration work with profiling metadata.
 *
 * Wrapper keeps phase-2 profiling complete even when a step is pure PHP and
 * does not invoke runStep() directly.
 *
 * @param callable $step
 * @return mixed
 */
function pmssRunProfiledStep(string $description, callable $step)
{
    $started = microtime(true);
    logmsg('[START] '.$description.' :: [callable]');
    $status = 'ERR';
    try {
        $result = $step();
        $status = 'OK';
        return $result;
    } finally {
        // Keep both outcomes on the same profiling path, including thrown steps.
        pmssLogStatus($status, $description, $status === 'OK' ? 0 : 1, microtime(true) - $started);
    }
}

/**
 * Profile a direct function/method invocation with optional arguments.
 *
 * @param callable $callable
 * @param array<int, mixed> $arguments
 * @return mixed
 */
function pmssRunProfiledCallable(string $description, callable $callable, array $arguments = [], string $classification = '')
{
    try {
        return pmssRunProfiledStep($description, static function () use ($callable, $arguments) { return $callable(...$arguments); });
    } catch (\Throwable $throwable) {
        if ($classification === '') {
            throw $throwable;
        }
        $reason = get_class($throwable).($throwable->getMessage() !== '' ? ': '.$throwable->getMessage() : '');
        pmssUpdateStep2HandleClassifiedFailure($description, $classification, 1, $reason);
    }
}

/**
 * Run a table of profiled callables in order.
 *
 * @param array<int,array{0:string,1:callable,2?:array<int,mixed>,3?:string}> $steps
 */
function pmssRunProfiledCallableBatch(array $steps): void
{
    foreach ($steps as $step) {
        pmssRunProfiledCallable((string) $step[0], $step[1],
            is_array($step[2] ?? null) ? $step[2] : [], (string) ($step[3] ?? ''));
    }
}
