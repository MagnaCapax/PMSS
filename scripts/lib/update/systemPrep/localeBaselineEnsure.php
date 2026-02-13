<?php
/**
 * Locale and timezone baseline setup for update-step2 system preparation.
 *
 * @license GPL-3.0-only
 */

require_once dirname(__DIR__).'/runtime/commands.php';
require_once dirname(__DIR__, 2).'/runtime.php';

    /**
     * Make sure essential locale assets exist before other services start.
     */
    function pmssEnsureLocaleBaseline(): void
    {
        $langLocale = 'en_US.UTF-8';
        $timeLocale = 'en_US.UTF-8';

        // Deduplicate locales to avoid redundant file reads and locale-gen calls.
        foreach (array_unique([$langLocale, $timeLocale]) as $locale) {
            $enabled = false;
            $gen = @file_get_contents('/etc/locale.gen');
            if (is_string($gen)) {
                foreach (preg_split('/\r?\n/', $gen) as $line) {
                    $trim = trim($line);
                    if ($trim === '' || $trim[0] === '#') {
                        continue;
                    }
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
                    @file_put_contents('/etc/locale.gen', $line."\n");
                    logMessage('[WARN] /etc/locale.gen missing; created with '.$line);
                } else {
                    if (strpos($gen, $line) === false) {
                        // Append the desired locale line if not present at all
                        if (@file_put_contents('/etc/locale.gen', rtrim($gen, "\r\n")."\n".$line."\n") === false) {
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
            }

            $out = [];
            @exec('locale -a 2>/dev/null', $out);
            $has = false;
            if (!empty($out)) {
                $needle1 = strtolower($locale);
                $needle2 = strtolower(str_replace('UTF-8', 'utf8', $locale));
                foreach ($out as $line) {
                    $val = strtolower(trim((string) $line));
                    if ($val === $needle1 || $val === $needle2) {
                        $has = true;
                        break;
                    }
                }
            }
            if (!$has || !$enabled) {
                runStep('Generating '.$locale.' locale', 'locale-gen '.$locale);
            } else {
                logMessage('[SKIP] '.$locale.' already generated');
            }
        }

        $defaultMatches = false;
        $data = @file_get_contents('/etc/default/locale');
        if (is_string($data)) {
            $lang = null;
            $lcTime = null;
            foreach (preg_split('/\r?\n/', $data) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                if (stripos($line, 'LANG=') === 0) {
                    $lang = trim(substr($line, 5));
                }
                if (stripos($line, 'LC_TIME=') === 0) {
                    $lcTime = trim(substr($line, 8));
                }
            }
            $defaultMatches = ($lang === $langLocale && $lcTime === $timeLocale);
        }
        if (!$defaultMatches) {
            runStep(
                'Setting default system locale',
                'update-locale LANG='.$langLocale.' LC_TIME='.$timeLocale
            );
        } else {
            logMessage('[SKIP] Default system locale already set to '.$langLocale.' (LC_TIME='.$timeLocale.')');
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

        require_once dirname(__DIR__).'/../motd/Generator.php';
        \Motd::motdGenerate();
    }
