<?php
/** In-memory activation dependencies: no downloads, extraction, moves, or cleanup. */
namespace ArrActivationCharacterization;

$GLOBALS['trace'] = [];
$GLOBALS['scenario'] = '';
$GLOBALS['moved'] = false;

function pmssCreatePrivateTempDir($prefix) {
    $GLOBALS['trace'][] = ['workspace', $prefix];
    return $GLOBALS['scenario'] === 'workspace' ? null : '/fixture/work';
}
function runCommand($command) {
    $GLOBALS['trace'][] = ['command', $command];
    $phase = strpos($command, 'curl ') === 0 ? 'download' : (strpos($command, 'umask ') === 0 ? 'extract' : 'move');
    if ($GLOBALS['scenario'] === $phase) return 1;
    if ($phase === 'move') $GLOBALS['moved'] = true;
    return 0;
}
function is_file($path) { return $GLOBALS['scenario'] !== 'archive'; }
function is_dir($path) {
    if ($path === '/fixture/work/Package') return $GLOBALS['scenario'] !== 'directory';
    if ($path === '/fixture') return $GLOBALS['scenario'] !== 'parent';
    return $GLOBALS['scenario'] !== 'absent' && !($GLOBALS['moved'] && $GLOBALS['scenario'] === 'unpublished');
}
function pmssArrReleaseActivationPathsAreSafe($work, $prefix, $extract, $install) {
    $GLOBALS['trace'][] = ['guard', $work, $prefix, $extract, $install];
    return $GLOBALS['scenario'] !== 'guard';
}
function pmssRootOwnedTreeRemove($path) {
    $GLOBALS['trace'][] = ['remove', $path];
    return $GLOBALS['scenario'] !== 'remove';
}
function pmssRemovePrivateTempDir($path, $prefix, $description, $logger, $runner) {
    $GLOBALS['trace'][] = ['cleanup', $path, $prefix, $description];
    // Exercise the supplied adapter too, without executing its shell command.
    return $runner($description, 'fixture-cleanup');
}
