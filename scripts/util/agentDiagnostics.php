#!/usr/bin/env php
<?php
/**
 * Utility script: agent diagnostics snapshot.
 *
 * Aggregates read-only PMSS diagnostics into one CLI report.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
declare(strict_types=1);

require_once __DIR__.'/../lib/agentDiagnostics.php';

exit(pmssAgentDiagnosticsMain($argv ?? ($_SERVER['argv'] ?? [])));

