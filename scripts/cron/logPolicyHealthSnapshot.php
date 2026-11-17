#!/usr/bin/env php
<?php
/**
 * Alert-only snapshot of log-policy drift and stale PMSS content.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/logPolicyHealth.php';
require_once __DIR__.'/../lib/log.php';
require_once __DIR__.'/../lib/cli/optionParser.php';

/** Append one observation; warning findings do not trigger any remediation. */
function pmssLogPolicyHealthSnapshotMain(array $argv): int
{
    pmssPrepareCliEntrypoint(true);
    $usage = "Usage: logPolicyHealthSnapshot.php [--json <path>]\n"
        ."Default JSONL output: /var/log/pmss/log-policy-health.jsonl\n";
    if (($parsed = pmssParseCliTokensOrHelp($argv, $usage)) === null) {
        return 0;
    }
    $path = pmssCliOptionString($parsed, 'json', null, '/var/log/pmss/log-policy-health.jsonl')
        ?? '/var/log/pmss/log-policy-health.jsonl';
    $error = null;
    if (!pmssLogWriteDirectoryPrepare(dirname($path), 0755, $error) || !pmssLogWritePathIsSafe($path)) {
        return pmssCliReturnWithStderr("Refusing unavailable or unsafe log-policy health output\n");
    }
    if (!pmssJsonLineAppend($path, pmssLogPolicyHealthCollect())) {
        return pmssCliReturnWithStderr("Failed to append log-policy health snapshot\n");
    }
    return 0;
}

pmssRunCliEntrypointWithArgv(__FILE__, 'pmssLogPolicyHealthSnapshotMain');
