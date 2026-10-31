<?php
/** In-memory process and clock observations; never starts a command or sleeps. */
namespace RuntimeProcessCharacterization;

function pmssCommandProcessGroupWrap($command, $timeout) { return $command; }
function proc_open($command, $descriptor, &$pipes, $cwd = null, $env = null) {
    $GLOBALS['trace'][] = ['open', $descriptor === []];
    foreach ($descriptor as $index => $unused) $pipes[$index] = fopen('php://memory', 'w+');
    return fopen('php://memory', 'w+');
}
function get_resource_type($resource) { return 'process'; }
function stream_set_blocking($stream, $mode) { return true; }
function proc_get_status($process) {
    $GLOBALS['trace'][] = ['status'];
    return array_shift($GLOBALS['case']['statuses']);
}
function microtime($asFloat) { return array_shift($GLOBALS['case']['times']); }
function usleep($delay) { $GLOBALS['trace'][] = ['sleep', $delay]; }
function proc_close($process) {
    $GLOBALS['trace'][] = ['close'];
    fclose($process);
    return -1;
}
function pmssProcessPipesClose($pipes) { foreach ($pipes as $pipe) fclose($pipe); }
function pmssCommandOutputPipesDrain($pipes, ...$unused) {
    $GLOBALS['trace'][] = ['drain'];
    return ['stdout' => 'out', 'stderr' => 'err', 'timed_out' => $GLOBALS['case']['drain_timeout']];
}
function pmssCommandTimeoutClose($process, ...$unused) {
    $GLOBALS['trace'][] = ['timeout'];
    fclose($process);
    return 124;
}

// The parent supplies the real production source in this namespace after these stubs.
