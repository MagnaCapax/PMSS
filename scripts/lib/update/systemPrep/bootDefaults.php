<?php
/**
 * Boot-default convergence helpers for update-step2 system preparation.
 *
 * Keeps /proc hidepid and GRUB default convergence separate from the broader
 * system-prep runtime so callers keep one stable public entrypoint.
 *
 * @license GPL-3.0-only
 */

function pmssBootDefaultsRequiredGrubOptions(string $grubOption, ?array $extraGrubOptions): array
{
    $options = [];
    foreach (array_merge([$grubOption], is_array($extraGrubOptions) ? $extraGrubOptions : []) as $option) {
        $option = trim((string) $option);
        if ($option !== '') $options[] = $option;
    }
    return array_values(array_unique($options));
}

function pmssBootDefaultsRequiredGrubSettings(?array $extraGrubSettings): array
{
    $settings = [];
    foreach (is_array($extraGrubSettings) ? $extraGrubSettings : [] as $key => $value) {
        $key = trim((string) $key);
        if ($key !== '' && preg_match('/^[A-Z0-9_]+$/', $key) === 1) $settings[$key] = trim((string) $value);
    }
    return $settings;
}

function pmssBootDefaultsEnsureProcHidepid(string $fstabPath, callable $log): void
{
    $fstabChanged = false;
    $lines = pmssFstabLinesRead($fstabPath, $log, '/proc hidepid enforcement');
    if ($lines === null) return;

    $plan = pmssFstabMountOptionsEnsure($lines, '/proc', [], [], false, 'proc', ['hidepid=' => 'hidepid=2']);
    if ($plan === null) {
        $lines[] = "proc\t/proc\tproc\tdefaults,hidepid=2\t0\t0";
        $fstabChanged = true;
        $log('Added /proc mount with hidepid=2 to '.$fstabPath);
    } elseif ($plan['changed']) {
        $fstabChanged = true;
        $log('Updated /proc mount options in '.$fstabPath);
    }
    if (!$fstabChanged) return;

    pmssWriteManagedPathFileWithBackup($fstabPath, $lines, 'fstab', $log);
    runStep('Remounting /proc with hidepid=2', pmssBuildCommand('mount', ['-o', 'remount,hidepid=2', '/proc']));
}

function pmssBootDefaultsGrubValueUnquote(string $rawValue): string
{
    if (strlen($rawValue) < 2) return $rawValue;
    $quote = $rawValue[0];
    return (($quote === '"' || $quote === "'") && $rawValue[strlen($rawValue) - 1] === $quote) ? substr($rawValue, 1, -1) : $rawValue;
}

function pmssBootDefaultsEnsureGrubOptions(array &$lines, array $requiredGrubOptions, string $grubPath, callable $log): bool
{
    $changed = $found = false;
    foreach ($lines as $idx => $line) {
        if (!preg_match('/^(GRUB_CMDLINE_LINUX_DEFAULT|GRUB_CMDLINE_LINUX)=("|\')([^"\']*)("|\')/', $line, $match)) continue;
        $lineChanged = false;
        // Keep option placement backward-compatible while healing every managed line's quoting.
        if (!$found) {
            $found = true;
            $currentOptions = pmssNonEmptyStrings(preg_split('/\s+/', trim($match[3])) ?: []);
            foreach ($requiredGrubOptions as $option) {
                if (!in_array($option, $currentOptions, true)) $currentOptions[] = $option;
            }
            $updatedValue = implode(' ', $currentOptions);
            if ($updatedValue !== $match[3]) {
                $lines[$idx] = $match[1].'='.$match[2].$updatedValue.$match[2];
                $lineChanged = true;
                $log('Updated '.$match[1].' options in '.$grubPath);
            }
        }
        $lineToNormalize = $lines[$idx] ?? $line;
        if (preg_match('/^(GRUB_CMDLINE_LINUX_DEFAULT|GRUB_CMDLINE_LINUX)=("|\')([^"\']*)\2\2+$/', $lineToNormalize, $malformedMatch) === 1) {
            $lines[$idx] = $malformedMatch[1].'='.$malformedMatch[2].$malformedMatch[3].$malformedMatch[2];
            $lineChanged = true;
            $log('Normalized '.$malformedMatch[1].' quoting in '.$grubPath);
        }
        $changed = $lineChanged || $changed;
    }
    if ($found || $requiredGrubOptions === []) return $changed;

    $lines[] = 'GRUB_CMDLINE_LINUX_DEFAULT="'.implode(' ', $requiredGrubOptions).'"';
    $log('Added GRUB_CMDLINE_LINUX_DEFAULT to '.$grubPath);
    return true;
}

function pmssBootDefaultsEnsureGrubSettings(array &$lines, array $requiredGrubSettings, string $grubPath, callable $log): bool
{
    $changed = false;
    foreach ($requiredGrubSettings as $settingKey => $settingValue) {
        $settingFound = false;
        $settingPrefix = $settingKey.'=';
        foreach ($lines as $lineIdx => $line) {
            if (strpos($line, $settingPrefix) !== 0) continue;
            $settingFound = true;
            if (pmssBootDefaultsGrubValueUnquote(trim(substr($line, strlen($settingPrefix)))) !== $settingValue) {
                $lines[$lineIdx] = $settingKey.'="'.$settingValue.'"';
                $changed = true;
                $log('Updated '.$settingKey.' in '.$grubPath);
            }
            break;
        }
        if ($settingFound) continue;
        $lines[] = $settingKey.'="'.$settingValue.'"';
        $changed = true;
        $log('Added '.$settingKey.' to '.$grubPath);
    }
    return $changed;
}

function pmssBootDefaultsApplyGrubChanges(bool $grubChanged, string $grubPath, string $grubOption, callable $log): void
{
    if (!$grubChanged) return;
    if (pmssCommandPath('update-grub') !== '') {
        runStep('Updating GRUB configuration', 'update-grub');
    } else {
        $log('[WARN] update-grub not available; run update-grub after editing '.$grubPath);
    }
    $cmdline = @file_get_contents('/proc/cmdline');
    if ($grubOption !== '' && $cmdline !== false && strpos($cmdline, $grubOption) === false) {
        $log('[WARN] '.$grubOption.' will apply after reboot');
    }
}

/** Ensure /proc hidepid=2 and legacy grub cmdline defaults stay applied. */
function pmssEnsureBootDefaults(
    ?callable $logger = null,
    ?string $fstabPath = null,
    ?string $grubPath = null,
    ?string $grubOption = null,
    ?array $extraGrubOptions = null,
    ?array $extraGrubSettings = null
): void {
    $log = $logger ?: 'logMessage';
    $fstabPath = $fstabPath ?? '/etc/fstab';
    $grubPath = $grubPath ?? '/etc/default/grub';
    $grubOption = trim((string) ($grubOption ?? 'systemd.unified_cgroup_hierarchy=0'));

    pmssBootDefaultsEnsureProcHidepid($fstabPath, $log);
    $grubChanged = false;
    if (is_readable($grubPath) && ($lines = file($grubPath, FILE_IGNORE_NEW_LINES)) !== false) {
        $grubChanged = pmssBootDefaultsEnsureGrubOptions($lines, pmssBootDefaultsRequiredGrubOptions($grubOption, $extraGrubOptions), $grubPath, $log);
        $grubChanged = pmssBootDefaultsEnsureGrubSettings($lines, pmssBootDefaultsRequiredGrubSettings($extraGrubSettings), $grubPath, $log) || $grubChanged;
        if ($grubChanged) pmssWriteManagedPathFileWithBackup($grubPath, $lines, 'grub', $log);
    } else {
        $log('[WARN] '.$grubPath.' not readable; skipping grub cmdline update');
    }
    pmssBootDefaultsApplyGrubChanges($grubChanged, $grubPath, $grubOption, $log);
}
