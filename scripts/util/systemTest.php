#!/usr/bin/env php
<?php
/** Utility script: system Test.
 * @license GPL-3.0-only
 * @author PMSS Team
 */
declare(strict_types=1);

require_once __DIR__.'/../lib/cli/optionParser.php';
require_once __DIR__.'/../lib/systemStatus.php';

$parsed = pmssParseCliTokens($argv ?? ($_SERVER['argv'] ?? []));
$format = strtolower((string) pmssCliOption($parsed, 'output', 'o', 'text'));
$jsonFlag = pmssCliOption($parsed, 'json', 'j', false);
$prettyFlag = pmssCliOption($parsed, 'pretty', 'p', false) !== false;
$format = ($jsonFlag === true || $format === 'json') ? 'json' : 'text';

$checks = pmssSystemStatusChecks();
$summary = pmssStatusSummary($checks);
exit(pmssStatusEmit($checks, 'PMSS System Check', $format === 'json', ['checks' => $checks, 'summary' => $summary], $summary, $prettyFlag ? JSON_PRETTY_PRINT : 0, true, 9));
