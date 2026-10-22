<?php
/** Traffic report CLI; model and output helpers retain the legacy include surface.
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/reportModel.php';
require_once __DIR__.'/reportDisplay.php';
require_once dirname(__DIR__).'/cli/optionParser.php';

function pmssShowTrafficMain(array $argv): int
{
    $parsed = pmssParseCliTokens($argv);
    $usage = pmssCliHelpUsageOptions('showTraffic.php [--json] [--show-missing] [--extended] [--sort=<mode>]', [
        ['--json', 'Emit JSON instead of human text output.'], ['--show-missing', 'Print missing stats usernames (text mode only).'],
        ['--extended', 'Show limit, percent, and rate units in text output.'], ['--sort=<mode>', 'Sort output by name, month, pct, or rate (default: name).'],
        ['--color', 'Force ANSI colors in extended text output.'], ['--no-color', 'Disable ANSI colors in extended text output.'],
    ]);
    if (pmssCliOptionPresent($parsed, 'help')) { echo $usage; return 0; }
    $sortOption = pmssCliOption($parsed, 'sort', null, null);
    if ($sortOption !== null && (!is_string($sortOption) || trim($sortOption) === '')) {
        return pmssCliReturnWithStderr("Error: --sort expects a value.\n", 2);
    }
    $sort = is_string($sortOption) ? strtolower(trim($sortOption)) : 'name';
    if (!in_array($sort, ['name', 'month', 'pct', 'rate'], true)) {
        fwrite(STDERR, "Error: invalid --sort value: {$sort}\n");
        echo $usage;
        return 2;
    }
    $asJson = pmssCliOptionPresent($parsed, 'json');
    $extended = pmssCliOptionPresent($parsed, 'extended');

    if (pmssCliRejectMutuallyExclusiveOptions($parsed, ['color', 'no-color'], "Error: --color and --no-color are mutually exclusive.\n")) return 2;

    $statsDir = pmssRuntimeDir().'/trafficStats';
    $users = pmssShowTrafficUsersLoad(dirname(__DIR__, 2).'/listUsers.php', $statsDir);
    if ($users === null) {
        return 1;
    }
    if (empty($users)) {
        echo "No users in this system!\n";
        return 0;
    }

    if (!$asJson) {
        echo $extended ? "Legend:\n\t USER: Traffic: Data Month / Limit  %  Stat  Bar        IN: Month  Ratio  DATARATES: Week MiB/s / Day MiB/s / Hour MiB/s / 15min MiB/s\n" : "Legend:\n\t USER: Traffic: Data Month / Week / Day  IN: Month  Ratio  DATARATES: Rate Week / Rate Day / Rate Hour / Rate 15min\n";
    }

    $report = pmssShowTrafficReportBuild($users, $statsDir);
    pmssShowTrafficRowsSort($report['rows'], $sort);
    if ($asJson) {
        return pmssJsonEmitPayload(pmssShowTrafficJsonPayload($report), 'Failed to encode traffic report JSON.');
    }
    // Color selection belongs to text output, after the JSON return.
    $term = getenv('TERM'); $noColorEnv = getenv('NO_COLOR');
    $useColor = $extended && (pmssCliOptionPresent($parsed, 'color') || (!pmssCliOptionPresent($parsed, 'no-color')
        && pmssStreamIsTty(STDOUT) && !in_array($term, [false, '', 'dumb'], true) && ($noColorEnv === false || $noColorEnv === '')));
    foreach ($report['rows'] as $row) { pmssShowTrafficPrintRow($row, $extended, $useColor); }
    pmssShowTrafficPrintSummary($report, $extended, pmssCliOptionPresent($parsed, 'show-missing'));
    return 0;
}
