<?php
/** Locale, timezone and MOTD baseline convergence; loaded by systemPrep.php. @license GPL-3.0-only */

/** Confirm the entire locale configuration was written before reporting success. */
function pmssLocaleGenWriteComplete(string $path, string $content, ?callable $writer = null): bool
{
    return pmssFileWriteComplete($path, $content, 0, $writer);
}

/**
 * Make sure essential locale assets exist before other services start.
 */
function pmssEnsureLocaleBaseline(): void
{
    // LANG and LC_TIME share one fixed baseline locale.
    $locale = 'en_US.UTF-8';
    $enabled = false;
    $gen = @file_get_contents('/etc/locale.gen');
    if (is_string($gen)) {
        foreach (pmssConfigTextLines($gen) as $trim) {
            if (stripos($trim, $locale.' UTF-8') === 0) {
                $enabled = true;
                break;
            }
        }
    }
    if (!$enabled) {
        $line = $locale.' UTF-8';
        if ($gen === false) {
            // Best effort: create file with the required locale line
            if (pmssLocaleGenWriteComplete('/etc/locale.gen', $line."\n")) {
                logMessage('[WARN] /etc/locale.gen missing; created with '.$line);
            } else {
                logMessage('[WARN] Unable to create /etc/locale.gen with '.$line);
            }
        } elseif (strpos($gen, $line) === false) {
            // Append the desired locale line if not present at all
            if (!pmssLocaleGenWriteComplete('/etc/locale.gen', rtrim($gen, "\r\n")."\n".$line."\n")) {
                logMessage('[WARN] Unable to append '.$line.' to /etc/locale.gen');
            } else {
                logMessage('Appended '.$line.' to /etc/locale.gen');
            }
        } else {
            // Un-comment the existing line if commented out
            runStep('Enabling '.$locale.' in /etc/locale.gen',
                "sed -i 's/^# *".$locale." UTF-8/".$locale." UTF-8/' /etc/locale.gen");
        }
    }

    $out = [];
    @exec('locale -a 2>/dev/null', $out);
    // Both libc spellings are accepted after the shared whitespace normalization.
    $available = array_map('strtolower', iterator_to_array(pmssConfigLines($out, [])));
    if (!$enabled || array_intersect([strtolower($locale), strtolower(str_replace('UTF-8', 'utf8', $locale))], $available) === []) {
        runStep('Generating '.$locale.' locale', 'locale-gen '.$locale);
    } else {
        logMessage('[SKIP] '.$locale.' already generated');
    }

    // Last assignment wins, case-insensitively; quoted values remain literal.
    $defaults = [];
    foreach (pmssConfigAssignments(pmssConfigTextLines((string) @file_get_contents('/etc/default/locale'))) as $key => $value) {
        $defaults[strtoupper($key)] = trim($value);
    }
    if (($defaults['LANG'] ?? null) !== $locale || ($defaults['LC_TIME'] ?? null) !== $locale) {
        runStep(
            'Setting default system locale',
            'update-locale LANG='.$locale.' LC_TIME='.$locale
        );
    } else {
        logMessage('[SKIP] Default system locale already set to '.$locale.' (LC_TIME='.$locale.')');
    }

    // Ensure system timezone matches the Finland/Helsinki baseline.
    $tz = trim((string) @file_get_contents('/etc/timezone'));
    if ($tz !== 'Europe/Helsinki') {
        runStep(
            'Setting system timezone to Europe/Helsinki',
            "timedatectl set-timezone Europe/Helsinki 2>/dev/null || (ln -sf /usr/share/zoneinfo/Europe/Helsinki /etc/localtime && echo 'Europe/Helsinki' > /etc/timezone)"
        );
    } else {
        logMessage('[SKIP] System timezone already set to Europe/Helsinki');
    }

    require_once dirname(__DIR__, 3).'/motd/Generator.php';
    \Motd::motdGenerate();
}
