<?php
/**
 * CLI parsing for user transfers.
 *
 * @license GPL-3.0-only
 */

function pmssUserTransferUsageText(): string
{
    return <<<TXT
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
}

/**
 * Parse argv and return a normalised configuration array.
 *
 * @throws RuntimeException on invalid input.
 */
function pmssUserTransferParseCli(array $argv): array
{
    // Parse options manually: optionParser treats long flags as value-taking when
    // followed by a positional token, which makes boolean flags fragile.
    $tokens = array_slice($argv, 1);
    $positionals = [];

    $mainPasses = 31;
    $finalPasses = 3;
    $sleepMin = 60;
    $sleepMax = 360;
    $noSleep = false;
    $dryRun = false;
    $printPassword = false;
    $help = false;

    $parseInt = static function (string $name, ?string $value): int {
        if ($value === null || $value === '') {
            throw new RuntimeException('Option --'.$name.' requires a value', 1);
        }
        if (!ctype_digit($value)) {
            throw new RuntimeException('Invalid value for --'.$name.' (expected integer)', 1);
        }
        return (int) $value;
    };

    for ($i = 0; $i < count($tokens); $i++) {
        $token = $tokens[$i];
        if ($token === '--') {
            $positionals = array_merge($positionals, array_slice($tokens, $i + 1));
            break;
        }

        if (substr($token, 0, 2) === '--') {
            $body = substr($token, 2);
            if ($body === '') {
                continue;
            }
            $key = $body;
            $value = null;
            if (strpos($body, '=') !== false) {
                [$key, $value] = explode('=', $body, 2);
            }

            if ($key === 'help') {
                $help = true;
                continue;
            }
            if ($key === 'no-sleep') {
                $noSleep = true;
                continue;
            }
            if ($key === 'dry-run') {
                $dryRun = true;
                continue;
            }
            if ($key === 'print-password') {
                $printPassword = true;
                continue;
            }

            if ($value === null) {
                $i++;
                $value = $tokens[$i] ?? null;
            }

            if ($key === 'main-passes') {
                $mainPasses = $parseInt('main-passes', $value);
                continue;
            }
            if ($key === 'final-passes') {
                $finalPasses = $parseInt('final-passes', $value);
                continue;
            }
            if ($key === 'sleep-min') {
                $sleepMin = $parseInt('sleep-min', $value);
                continue;
            }
            if ($key === 'sleep-max') {
                $sleepMax = $parseInt('sleep-max', $value);
                continue;
            }

            throw new RuntimeException('Unknown option: --'.$key, 1);
        }

        if (substr($token, 0, 1) === '-' && strlen($token) > 1) {
            $flags = substr($token, 1);
            if ($flags === 'h') {
                $help = true;
                continue;
            }
            throw new RuntimeException('Unknown option: '.$token, 1);
        }

        $positionals[] = $token;
    }

    if ($help) {
        throw new RuntimeException(pmssUserTransferUsageText(), 0);
    }

    if (count($positionals) !== 2 && count($positionals) !== 3) {
        throw new RuntimeException('Need arguments.'.PHP_EOL.pmssUserTransferUsageText(), 1);
    }

    $localUser = pmssNormalizeUsername((string) $positionals[0]);
    $remoteUser = $localUser;
    $hostname = '';
    if (count($positionals) === 2) {
        $hostname = trim((string) $positionals[1]);
    } else {
        $remoteUser = pmssNormalizeUsername((string) $positionals[1]);
        $hostname = trim((string) $positionals[2]);
    }

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

    if ($mainPasses < 1 || $mainPasses > 500) {
        throw new RuntimeException('Invalid --main-passes (expected 1..500)', 1);
    }
    if ($finalPasses < 1 || $finalPasses > 100) {
        throw new RuntimeException('Invalid --final-passes (expected 1..100)', 1);
    }
    if ($sleepMin < 0 || $sleepMax < 0) {
        throw new RuntimeException('Invalid sleep values (expected non-negative integers)', 1);
    }
    if ($sleepMax < $sleepMin) {
        throw new RuntimeException('Invalid sleep range (sleep-max must be >= sleep-min)', 1);
    }
    if ($noSleep) {
        $sleepMin = 0;
        $sleepMax = 0;
    }

    return [
        'localUser' => $localUser,
        'remoteUser' => $remoteUser,
        'hostname' => $hostname,
        'suffixAppended' => $suffixAppended,
        'mainPasses' => $mainPasses,
        'finalPasses' => $finalPasses,
        'sleepMin' => $sleepMin,
        'sleepMax' => $sleepMax,
        'dryRun' => $dryRun,
        'printPassword' => $printPassword,
    ];
}
