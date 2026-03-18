<?php
/**
 * CLI parsing for user transfers.
 *
 * @license GPL-3.0-only
 */

/**
 * Parse argv and return a normalised configuration array.
 *
 * @throws RuntimeException on invalid input.
 */
function pmssUserTransferParseCli(array $argv): array
{
    $usageText = <<<TXT
Usage:
  /scripts/util/userTransfer.php LOCAL_USERNAME REMOTE_HOSTNAME
  /scripts/util/userTransfer.php LOCAL_USERNAME REMOTE_USERNAME REMOTE_HOSTNAME

Options:
  --main-passes N     Number of passes for the main rsync (default 31)
  --final-passes N    Number of passes for the final rsync (default 3)
  --sleep-min N       Minimum sleep seconds between passes (default 60)
  --sleep-max N       Maximum sleep seconds between passes (default 360)
  --no-sleep          Disable sleeping between passes
  --dry-run           Log planned steps without executing commands
  --print-password    Print the supplied password at the end (unsafe)
  --help, -h          Show this help

Notes:
  - If REMOTE_HOSTNAME does not contain a dot, ".pulsedmedia.com" is appended.
  - Password can be provided via env: PMSS_USER_TRANSFER_PASSWORD

TXT;

    // Parse options manually: optionParser treats long flags as value-taking when
    // followed by a positional token, which makes boolean flags fragile.
    $tokens = array_slice($argv, 1);
    $positionals = [];
    $options = [
        'mainPasses' => 31,
        'finalPasses' => 3,
        'sleepMin' => 60,
        'sleepMax' => 360,
        'noSleep' => false,
        'dryRun' => false,
        'printPassword' => false,
        'help' => false,
    ];
    $flagOptions = [
        'help' => 'help',
        'no-sleep' => 'noSleep',
        'dry-run' => 'dryRun',
        'print-password' => 'printPassword',
    ];
    $integerOptions = [
        'main-passes' => 'mainPasses',
        'final-passes' => 'finalPasses',
        'sleep-min' => 'sleepMin',
        'sleep-max' => 'sleepMax',
    ];

    $parseInt = static function (string $name, ?string $value): int {
        if ($value === null || $value === '') {
            throw new RuntimeException('Option --'.$name.' requires a value', 1);
        }
        if (!ctype_digit($value)) {
            throw new RuntimeException('Invalid value for --'.$name.' (expected integer)', 1);
        }
        return (int) $value;
    };

    for ($i = 0, $tokenCount = count($tokens); $i < $tokenCount; $i++) {
        $token = $tokens[$i];
        if ($token === '--') {
            $positionals = array_merge($positionals, array_slice($tokens, $i + 1));
            break;
        }

        if (substr($token, 0, 2) === '--') {
            [$key, $value] = array_pad(explode('=', substr($token, 2), 2), 2, null);

            if (isset($flagOptions[$key])) {
                $options[$flagOptions[$key]] = true;
                continue;
            }

            if (!isset($integerOptions[$key])) {
                throw new RuntimeException('Unknown option: --'.$key, 1);
            }

            if ($value === null) {
                $value = $tokens[++$i] ?? null;
            }

            $options[$integerOptions[$key]] = $parseInt($key, $value);
            continue;
        }

        if (substr($token, 0, 1) === '-' && strlen($token) > 1) {
            if (substr($token, 1) === 'h') {
                $options['help'] = true;
                continue;
            }
            throw new RuntimeException('Unknown option: '.$token, 1);
        }

        $positionals[] = $token;
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
    if (!pmssUserTransferHostnameIsValid($hostname)) {
        throw new RuntimeException('Invalid hostname', 1);
    }

    if ($options['mainPasses'] < 1 || $options['mainPasses'] > 500) {
        throw new RuntimeException('Invalid --main-passes (expected 1..500)', 1);
    }
    if ($options['finalPasses'] < 1 || $options['finalPasses'] > 100) {
        throw new RuntimeException('Invalid --final-passes (expected 1..100)', 1);
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

    return [
        'localUser' => $localUser,
        'remoteUser' => $remoteUser,
        'hostname' => $hostname,
        'suffixAppended' => $suffixAppended,
        'mainPasses' => $options['mainPasses'],
        'finalPasses' => $options['finalPasses'],
        'sleepMin' => $options['sleepMin'],
        'sleepMax' => $options['sleepMax'],
        'dryRun' => $options['dryRun'],
        'printPassword' => $options['printPassword'],
    ];
}

/**
 * Validate a hostname (or IPv4 address) without permitting shell metacharacters.
 */
function pmssUserTransferHostnameIsValid(string $hostname): bool
{
    if ($hostname === '' || preg_match('/\\s/', $hostname) || strlen($hostname) > 253) {
        return false;
    }

    // Accept IPv4 literals to support direct node IP transfers.
    if (filter_var($hostname, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        return true;
    }

    // Allow hostname labels separated by dots.
    if (
        !preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{0,252}$/', $hostname)
        || strpos($hostname, '..') !== false
        || $hostname[0] === '.'
        || substr($hostname, -1) === '.'
    ) {
        return false;
    }

    foreach (explode('.', $hostname) as $label) {
        if ($label === '' || strlen($label) > 63 || $label[0] === '-' || substr($label, -1) === '-') {
            return false;
        }
    }
    return true;
}
