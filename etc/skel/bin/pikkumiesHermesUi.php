<?php
/** Account-owned Pikkumies Hermes CLI. */
require_once __DIR__.'/pikkumiesHermesRuntime.php';
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $home = pmssAgentHome();
    $paths = pmssPikkumiesHermesPaths($home);
    $verb = $argv[1] ?? '';
    if ($verb === 'rotate-session') exit(pmssPikkumiesHermesRotate($paths) ? 0 : 1);
    if ($verb === 'ensure') exit(pmssPikkumiesHermesEnsure($paths) ? 0 : 1);
    if ($verb === 'stop-simplified') { pmssPikkumiesSimplifiedStop($paths); exit(0); }
    if ($verb === 'ready') exit(pmssPikkumiesHermesReady($paths) ? 0 : 1);
    if ($verb === 'start') exit(pmssPikkumiesHermesStart($paths, (string) ($argv[2] ?? '')) ? 0 : 1);
    exit(2);
}
