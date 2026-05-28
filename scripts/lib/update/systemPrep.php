<?php
/**
 * Base system preparation helpers executed during update-step2.
 *
 * This remains the historical include path for system preparation while also
 * carrying the small baseline helpers that are only consumed through this
 * surface.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/logging.php';
require_once __DIR__.'/fstab.php';
require_once __DIR__.'/managedPath.php';
require_once __DIR__.'/runtime/commands.php';
require_once __DIR__.'/runtime/processes.php';
require_once __DIR__.'/../runtime.php';
require_once __DIR__.'/systemPrep/hostEnvironment.php';
require_once __DIR__.'/systemPrep/bootDefaults.php';
require_once __DIR__.'/systemPrep/sysctlTuning.php';

require_once __DIR__.'/systemPrep/systemdSlicesEnsure.php';

/**
 * Recreate the PMSS-owned hardware-aware sysctl baseline.
 */
function pmssEnsureLegacySysctlBaseline(?callable $logger = null, ?string $targetOverride = null, bool $reload = true, ?string $modulesLoadOverride = null): void
{
    $log             = $logger ?: 'logMessage';
    $target          = $targetOverride ?? '/etc/sysctl.d/99-pmss.conf';
    $modulesLoadPath = $modulesLoadOverride ?? '/etc/modules-load.d/pmss-bbr.conf';
    $sysctlWriteOk   = false;
    $overridePath    = pmssResolvePathFromEnv('PMSS_SYSCTL_OVERRIDES_PATH', '/etc/sysctl.d/90-pmss-overrides.conf');
    // Persist TCP BBR module loading across reboots.
    $modulesContent = "# PMSS: enable TCP BBR\ntcp_bbr\n";

    // /sys block tuning is handled by the boot-time tuning service; sysctl only covers /proc/sys.
    $profile = pmssSysctlProfileDetect();
    $overrideKeys = pmssSysctlOverridesParse($overridePath);
    $groupedSettings = pmssSysctlSettingsFilterOverrides(pmssSysctlSettingsBuild($profile), $overrideKeys);
    $content = pmssSysctlConfigRender($groupedSettings);
    $existingSettings = pmssSysctlFileParse($target);
    $changes = pmssSysctlChangesDescribe($existingSettings, $groupedSettings);

    // Check if file needs updating
    $existing = @file_get_contents($target);
    $sysctlUpToDate = $existing !== false && trim($existing) === trim($content);
    if ($sysctlUpToDate) {
        $sysctlWriteOk = true;
        $log('[SKIP] Legacy sysctl defaults already present and up to date');
    } else {
        pmssDirEnsureExists(dirname($target), 0755);
        if (@file_put_contents($target, $content.PHP_EOL) === false) {
            $log('[WARN] Unable to write legacy sysctl defaults at '.$target);
        } else {
            $sysctlWriteOk = true;
        }
    }

    pmssSysctlSummaryWrite($logger, $profile, $groupedSettings, $overrideKeys, $changes);

    $modulesExisting = @file_get_contents($modulesLoadPath);
    $modulesUpToDate = $modulesExisting !== false && trim($modulesExisting) === trim($modulesContent);
    if ($modulesUpToDate) {
        $log('[SKIP] TCP BBR modules-load configuration already present and up to date');
    } else {
        pmssDirEnsureExists(dirname($modulesLoadPath), 0755);
        if (@file_put_contents($modulesLoadPath, $modulesContent) === false) {
            $log('[WARN] Unable to write TCP BBR modules-load configuration at '.$modulesLoadPath);
        } else {
            $log('Refreshed TCP BBR modules-load configuration at '.$modulesLoadPath);
        }
    }

    if ($sysctlUpToDate || !$sysctlWriteOk) {
        return;
    }

    $reload ? runStep('Reloading sysctl configuration', 'sysctl --system') : $log('[SKIP] sysctl reload disabled');
    $log('Refreshed legacy sysctl defaults at '.$target);
}

/**
 * Keep /tmp disk-backed on Debian 13+ by masking the systemd tmpfs unit.
 */
function pmssConfigureTempDiskBackedMount(?callable $logger = null, ?int $distroVersion = null): void
{
    $log = $logger ?: 'logMessage';
    if ($distroVersion === null && function_exists('pmssDetectDistro')) {
        $detected = pmssDetectDistro();
        $distroVersion = isset($detected['version']) ? (int) $detected['version'] : 0;
    }

    if ((int) $distroVersion < 13) {
        $log('[SKIP] Leaving /tmp mount policy unchanged before Debian 13');
        return;
    }

    if (pmssCommandPath('systemctl') === '') {
        $log('[WARN] systemctl unavailable; unable to mask tmp.mount');
        return;
    }

    $rc = runStep('Masking systemd tmp.mount to keep /tmp disk-backed', 'systemctl mask tmp.mount');
    if ($rc !== 0) {
        $log('[WARN] Failed to mask tmp.mount; /tmp may stay tmpfs-backed until corrected');
        return;
    }

    $log('Masked tmp.mount to keep /tmp disk-backed');
}

/**
 * Install and enable the PMSS boot tuning script + systemd unit.
 */
function pmssEnsureBootTuning(?callable $logger = null, ?string $scriptTarget = null, ?string $serviceTarget = null): void
{
    $log = $logger ?: 'logMessage';
    $cfgDir = pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config');
    $scriptTemplate = $cfgDir.'/template.pmss-boot-tuning.sh';
    $serviceTemplate = $cfgDir.'/template.systemd.pmss-boot-tuning.service';

    foreach ([$scriptTemplate => 'Boot tuning script template', $serviceTemplate => 'Boot tuning service template'] as $template => $label) {
        if (!is_file($template)) {
            $log('[SKIP] '.$label.' missing: '.$template);
            return;
        }
    }

    $scriptTarget = $scriptTarget ?? '/usr/local/sbin/pmss-boot-tuning.sh';
    $serviceTarget = $serviceTarget ?? '/etc/systemd/system/pmss-boot-tuning.service';

    $scriptRaw = @file_get_contents($scriptTemplate);
    if ($scriptRaw === false) {
        $log('[WARN] Unable to read boot tuning script template: '.$scriptTemplate);
        return;
    }

    $serviceRaw = @file_get_contents($serviceTemplate);
    if ($serviceRaw === false) {
        $log('[WARN] Unable to read boot tuning service template: '.$serviceTemplate);
        return;
    }
    $serviceRaw = str_replace('%%PMSS_BOOT_TUNING_SCRIPT%%', $scriptTarget, $serviceRaw);

    // Write files only when content changes to keep the run idempotent.
    foreach ([
        [$scriptTarget, $scriptRaw, 0755, 'Boot tuning script'],
        [$serviceTarget, $serviceRaw, 0644, 'Boot tuning service'],
    ] as $targetSpec) {
        [$path, $content, $mode, $label] = $targetSpec;
        $existing = @file_get_contents($path);
        if ($existing !== false && $existing === $content) {
            $log('[SKIP] '.$label.' already present and up to date');
            continue;
        }

        $dir = dirname($path);
        if (!pmssDirEnsureExists($dir, 0755)) {
            $log('[WARN] Unable to create '.$label.' directory: '.$dir);
            continue;
        }

        if (!pmssWriteManagedPathFile($path, $content, $label, $log, null, null, $mode, '[WARN] Unable to install '.$label.' at '.$path)) {
            continue;
        }

        $log('Installed '.$label.' at '.$path);
    }

    if (($skipReason = pmssSystemdActionSkipReason(null, true, true)) !== '') {
        pmssLogStatus('SKIP', 'Enabling PMSS boot tuning service ('.$skipReason.')');
        return;
    }

    runStep('Reloading systemd unit files (PMSS boot tuning)', 'systemctl daemon-reload || true');
    runStep('Enabling PMSS boot tuning service', 'systemctl enable pmss-boot-tuning.service || true');
    runStep('Starting PMSS boot tuning service', 'systemctl start pmss-boot-tuning.service || true');
}

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
                $trim = pmssConfigLineTrimmed($line);
                if ($trim === '') continue;
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

/**
 * Ensure root shell defaults mirror the historical installer behaviour.
 */
function pmssConfigureRootShellDefaults(?callable $logger = null): void
{
    $log    = $logger ?: 'logMessage';
    $bashrc = '/root/.bashrc';
    $lines = file_exists($bashrc) ? (file($bashrc, FILE_IGNORE_NEW_LINES) ?: []) : [];

    $defaults = [
        "alias ls='ls --color=auto'",
        'PATH=$PATH:/scripts',
    ];
    if (($missing = array_diff($defaults, $lines)) === []) {
        $log('[SKIP] Root shell defaults already configured');
        return;
    }

    @file_put_contents($bashrc, implode(PHP_EOL, array_merge($lines, $missing)).PHP_EOL);
    $log('Appended root shell defaults: '.implode(', ', $missing));
}
