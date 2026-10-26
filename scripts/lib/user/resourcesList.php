<?php
/**
 * User resource listing helpers.
 *
 * Keeps the CLI entrypoint thin while centralising formatting and data
 * assembly rules in one testable module.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../cli/optionParser.php';
require_once __DIR__.'/resourcesListModel.php';
require_once __DIR__.'/resourcesListDisplay.php';

/** Main CLI entrypoint for resource listing. */
function pmssUserResourcesListMain(array $argv): int
{
    $useColor = pmssCliHelpSupportsColor();
    $usage = pmssCliHelpSectionText([
        'Usage' => ['  /scripts/util/userResourcesList.php [--brief|--full] [--json|--jsonl]'],
        'Options' => [
            pmssCliHelpLine('--brief', 'Show the compact RAM/CPU/IO table.'.pmssCliHelpDim(' (default)', $useColor)),
            pmssCliHelpLine('--full', 'Include disk quota, traffic, TasksMax, and suspension columns.'),
            pmssCliHelpLine('--json', 'Emit one JSON array instead of text output.'),
            pmssCliHelpLine('--jsonl', 'Emit one JSON object per line for pipelines.'),
            pmssCliHelpLine('-h, --help', 'Show this help and exit.'),
        ],
        'Examples' => [
            '  /scripts/util/userResourcesList.php --full',
            '  /scripts/util/userResourcesList.php --json',
        ],
        'Notes' => [
            '  - Run as root when querying real slice data; --help works without root.',
            '  - --brief and --full are mutually exclusive.',
        ],
    ], $useColor)."\n";
    if (($parsed = pmssParseCliTokensOrHelp($argv, $usage)) === null) return 0;
    if (posix_getuid() !== 0) {
        return pmssCliReturnWithStderr("Error: This script must be run as root to query systemd slices.\n", 1);
    }
    $outputMode = pmssCliOption($parsed, 'jsonl') ? 'jsonl' : (pmssCliOption($parsed, 'json') ? 'json' : 'text');
    $fullMode = (bool) pmssCliOption($parsed, 'full');
    if (pmssCliRejectMutuallyExclusiveOptions($parsed, ['brief', 'full'], "Error: choose either --brief or --full (not both).\n", 'truthy')) return 1;
    $columns = pmssUserResourcesListColumns([], $fullMode ? 'full' : 'brief');
    $format = implode(' ', array_map(static function (array $column): string { return '%-'.$column[1].'s'; }, $columns))."\n";
    $users = pmssListManagedUsers('/scripts/listUsers.php');
    if ($users === []) {
        echo ['json' => "[]\n", 'jsonl' => '', 'text' => "No users found.\n"][$outputMode];
        return 0;
    }
    if ($outputMode === 'text') {
        printf($format, ...array_column($columns, 0));
        echo str_repeat('-', $fullMode ? 180 : 120)."\n";
    }
    $allData = [];
    $sliceKeys = ['MemoryHigh', 'MemoryMax', 'CPUWeight', 'IOWeight', 'CPUQuotaPerSecUSec', 'CPUQuotaPeriodUSec', 'CPUQuota', 'IOReadBandwidthMax', 'IOWriteBandwidthMax', 'IOReadIOPSMax', 'IOWriteIOPSMax', 'TasksMax'];
    $store = new UserConfigStore();
    foreach ($users as $user) {
        if (($info = pmssUserAccountLookup($user)) === null) {
            continue;
        }
        $resourceData = pmssUserResourcesListEntryBuild($user, $info, pmssReadSystemdProperties('user-'.$info['uid'].'.slice', $sliceKeys), $store->get($user));
        if ($outputMode === 'jsonl') {
            if (pmssJsonEmitPayload($resourceData, 'Failed to encode user resources JSON line.') !== 0) return 1;
        } elseif ($outputMode === 'json') {
            $allData[] = $resourceData;
        } else {
            printf($format, ...pmssUserResourcesListRowBuild($resourceData, $fullMode ? 'full' : 'brief'));
        }
    }
    return $outputMode === 'json' ? pmssJsonEmitPayload($allData, 'Failed to encode user resources JSON.', JSON_PRETTY_PRINT) : 0;
}
