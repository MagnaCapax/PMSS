<?php
/**
 * CLI parsing for user transfers.
 *
 * @license GPL-3.0-only
 */
require_once __DIR__.'/../cli/optionParser.php';

/**
 * Parse argv and return a normalised configuration array.
 *
 * @throws RuntimeException on invalid input.
 */
function pmssUserTransferParseCli(array $argv): array
{
    $usageText = pmssCliHelpUsageOptions([
        '/scripts/util/userTransfer.php LOCAL_USERNAME REMOTE_HOSTNAME',
        '/scripts/util/userTransfer.php LOCAL_USERNAME REMOTE_USERNAME REMOTE_HOSTNAME',
    ], [
        ['--main-passes N', 'Number of passes for the main rsync (default 31)'],
        ['--final-passes N', 'Number of passes for the final rsync (default 3)'],
        ['--sleep-min N', 'Minimum sleep seconds between passes (default 60)'],
        ['--sleep-max N', 'Maximum sleep seconds between passes (default 360)'],
        ['--verify-threshold N ', 'Warn if local size is below N% of remote (default 90)'],
        ['--no-sleep', 'Disable sleeping between passes'],
        ['--dry-run', 'Log planned steps without executing commands'],
        ['--print-password', 'Print the supplied password at the end (unsafe)'],
        ['--help, -h', 'Show this help'],
    ], 20, [
        'If REMOTE_HOSTNAME does not contain a dot, ".pulsedmedia.com" is appended.',
        'Password can be provided via env: PMSS_USER_TRANSFER_PASSWORD',
    ], false);

    $optionSpecs = [
        'main-passes' => ['field' => 'mainPasses', 'default' => 31, 'expectsValue' => true, 'range' => [1, 500]],
        'final-passes' => ['field' => 'finalPasses', 'default' => 3, 'expectsValue' => true, 'range' => [1, 100]],
        'sleep-min' => ['field' => 'sleepMin', 'default' => 60, 'expectsValue' => true],
        'sleep-max' => ['field' => 'sleepMax', 'default' => 360, 'expectsValue' => true],
        'verify-threshold' => ['field' => 'verifyThreshold', 'default' => 90, 'expectsValue' => true, 'range' => [1, 100]],
        'help' => ['field' => 'help', 'default' => false, 'expectsValue' => false],
        'no-sleep' => ['field' => 'noSleep', 'default' => false, 'expectsValue' => false],
        'dry-run' => ['field' => 'dryRun', 'default' => false, 'expectsValue' => false],
        'print-password' => ['field' => 'printPassword', 'default' => false, 'expectsValue' => false],
    ];
    $options = array_column($optionSpecs, 'default', 'field');

    $valueOptions = array_keys(array_filter($optionSpecs, static function (array $spec): bool {
        return $spec['expectsValue'];
    }));
    $parsed = pmssParseCliTokens($argv, $valueOptions);
    $positionals = $parsed['arguments'];

    foreach ($parsed['options'] as $key => $value) {
        $spec = $key === 'h' ? $optionSpecs['help'] : ($optionSpecs[$key] ?? null);
        if ($spec === null) {
            throw new RuntimeException('Unknown option: '.(strlen((string) $key) === 1 ? '-' : '--').$key, 1);
        }
        if (!$spec['expectsValue']) {
            $options[$spec['field']] = true;
            continue;
        }
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('Option --'.$key.' requires a value', 1);
        }
        if (!ctype_digit($value)) {
            throw new RuntimeException('Invalid value for --'.$key.' (expected integer)', 1);
        }

        $options[$spec['field']] = (int) $value;
    }

    if ($options['help']) {
        throw new RuntimeException($usageText, 0);
    }

    if (($positionalCount = count($positionals)) < 2 || $positionalCount > 3) {
        throw new RuntimeException('Need arguments.'.PHP_EOL.$usageText, 1);
    }

    $localUser = pmssNormalizeUsername((string) $positionals[0]);
    $remoteUser = pmssNormalizeUsername((string) $positionals[$positionalCount === 3 ? 1 : 0]);
    $hostname = trim((string) $positionals[$positionalCount - 1]);

    // Usernames are used in file paths and ssh user arguments; keep strict.
    if (!pmssValidateUsername($localUser) || !pmssValidateUsername($remoteUser)) {
        throw new RuntimeException('Invalid username; expected /^[a-z][a-z0-9]{0,7}$/', 1);
    }

    $suffixAppended = false;
    if ($hostname !== '' && strpos($hostname, '.') === false) {
        $hostname .= '.pulsedmedia.com';
        $suffixAppended = true;
    }
    if (!pmssHostnameIsValid($hostname)) {
        throw new RuntimeException('Invalid hostname', 1);
    }

    foreach ($optionSpecs as $key => $spec) {
        if (!isset($spec['range'])) {
            continue;
        }
        $field = $spec['field'];
        $range = $spec['range'];
        if ($options[$field] < $range[0] || $options[$field] > $range[1]) {
            throw new RuntimeException(
                sprintf('Invalid --%s (expected %d..%d)', $key, $range[0], $range[1]),
                1
            );
        }
    }
    if ($options['sleepMin'] < 0 || $options['sleepMax'] < 0) {
        throw new RuntimeException('Invalid sleep values (expected non-negative integers)', 1);
    }
    if ($options['sleepMax'] < $options['sleepMin']) {
        throw new RuntimeException('Invalid sleep range (sleep-max must be >= sleep-min)', 1);
    }
    if ($options['noSleep']) {
        $options['sleepMin'] = $options['sleepMax'] = 0;
    }

    $cfg = [
        'localUser' => $localUser,
        'remoteUser' => $remoteUser,
        'hostname' => $hostname,
        'suffixAppended' => $suffixAppended,
    ];

    foreach (['mainPasses', 'finalPasses', 'sleepMin', 'sleepMax', 'verifyThreshold', 'dryRun', 'printPassword'] as $field) {
        $cfg[$field] = $options[$field];
    }

    return $cfg;
}
