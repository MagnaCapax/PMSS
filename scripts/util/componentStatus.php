#!/usr/bin/env php
<?php
/**
 * Utility script: component Status.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
declare(strict_types=1);

require_once __DIR__.'/../lib/cli/optionParser.php';
require_once __DIR__.'/../lib/systemStatus.php';

/**
 * PMSS component status reporter.
 *
 * Examines key binaries and configuration paths to summarise runtime health.
 * Supports machine-readable output via `--json`.
 */

$parsed = pmssParseCliTokens($argv ?? ($_SERVER['argv'] ?? []));
$wantJson = pmssCliOption($parsed, 'json', null, false) !== false;
$results = pmssComponentStatusChecks();
$summary = pmssStatusSummary($results);

if ($wantJson) {
    echo pmssStatusJsonEncode(['generated_at' => date('c'), 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

pmssRenderStatusText('PMSS Component Status', $results, $summary, false, 8, false);
