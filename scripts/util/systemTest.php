#!/usr/bin/env php
<?php
/**
 * Utility script: system Test.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
declare(strict_types=1);

/**
 * PMSS system status probe.
 *
 * Aggregates non-destructive checks to highlight runtime readiness. Intended for
 * production hosts; development environments may report WARN for missing
 * packages.
 */

require_once __DIR__.'/../lib/cli/optionParser.php';
require_once __DIR__.'/../lib/systemStatus.php';

$parsed = pmssParseCliTokens($argv ?? ($_SERVER['argv'] ?? []));
$format = strtolower((string) pmssCliOption($parsed, 'output', 'o', 'text'));
$jsonFlag = pmssCliOption($parsed, 'json', 'j', false);
$prettyFlag = pmssCliOption($parsed, 'pretty', 'p', false);
$prettyFlag = $prettyFlag !== false && $prettyFlag !== null;
$format = ($jsonFlag === true || $format === 'json') ? 'json' : 'text';

$checks = pmssSystemStatusChecks();

$summary = pmssStatusSummary($checks);

if ($format === 'json') {
    echo pmssStatusJsonEncode(['checks' => $checks, 'summary' => $summary], $prettyFlag ? JSON_PRETTY_PRINT : 0).PHP_EOL;
    exit(0);
}

pmssRenderStatusText('PMSS System Check', $checks, $summary, true, 9);
