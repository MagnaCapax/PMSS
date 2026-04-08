#!/usr/bin/env php
<?php
/** Utility script: component Status.
 * @license GPL-3.0-only
 * @author PMSS Team
 */
declare(strict_types=1);

require_once __DIR__.'/../lib/cli/optionParser.php';
require_once __DIR__.'/../lib/systemStatus.php';

$parsed = pmssParseCliTokens($argv ?? ($_SERVER['argv'] ?? []));
$wantJson = pmssCliOption($parsed, 'json', null, false) !== false;
$results = pmssComponentStatusChecks();
exit(pmssStatusEmit($results, 'PMSS Component Status', $wantJson, ['generated_at' => date('c'), 'results' => $results], null, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES, false, 8, false));
