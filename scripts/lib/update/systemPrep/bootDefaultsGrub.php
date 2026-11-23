<?php
/**
 * GRUB setting and BPF-LSM boot-default convergence helpers.
 *
 * @license GPL-3.0-only
 */

/** Strip one matching quote pair without interpreting shell syntax. */
function pmssBootDefaultsGrubValueUnquote(string $rawValue): string
{
    if (strlen($rawValue) < 2) return $rawValue;
    $quote = $rawValue[0];
    return (($quote === '"' || $quote === "'") && $rawValue[strlen($rawValue) - 1] === $quote) ? substr($rawValue, 1, -1) : $rawValue;
}

/** Converge explicit GRUB settings while retaining unrelated assignments. */
function pmssBootDefaultsEnsureGrubSettings(array &$lines, array $requiredGrubSettings, string $grubPath, callable $log): bool
{
    $changed = false;
    foreach ($requiredGrubSettings as $settingKey => $settingValue) {
        $settingPrefix = $settingKey.'=';
        foreach ($lines as $lineIdx => $line) {
            if (strpos($line, $settingPrefix) !== 0) continue;
            if (pmssBootDefaultsGrubValueUnquote(trim(substr($line, strlen($settingPrefix)))) !== $settingValue) {
                $lines[$lineIdx] = $settingKey.'="'.$settingValue.'"';
                $changed = true;
                $log('Updated '.$settingKey.' in '.$grubPath);
            }
            continue 2;
        }
        $lines[] = $settingKey.'="'.$settingValue.'"';
        $changed = true;
        $log('Added '.$settingKey.' to '.$grubPath);
    }
    return $changed;
}

/** Add BPF to the built-in LSM defaults without losing explicit boot selections. */
function pmssBootDefaultsEnsureBpfLsm(
    array &$lines,
    string $grubPath,
    callable $log,
    ?string $kernelConfigPath = null,
    ?string $lsmPath = null
): bool {
    $lsmPath = $lsmPath ?? pmssResolvePathFromEnv('PMSS_SECURITY_LSM_PATH', '/sys/kernel/security/lsm');
    if ($lsmPath === '' || strpos($lsmPath, "\0") !== false) return false;
    $active = @file_get_contents($lsmPath);
    if (!is_string($active) || strpos($active, "\0") !== false
        || preg_match('/^[a-z0-9_]+(?:,[a-z0-9_]+)*$/D', trim($active)) !== 1
        || in_array('bpf', explode(',', trim($active)), true)) return false;

    $kernelConfigPath = $kernelConfigPath ?? '/boot/config-'.php_uname('r');
    if ($kernelConfigPath === '' || strpos($kernelConfigPath, "\0") !== false) return false;
    $config = @file_get_contents($kernelConfigPath);
    // Use build-time names: active integrity modules can be reported as ima/evm.
    if (!is_string($config) || strpos($config, "\0") !== false || preg_match('/^CONFIG_BPF_LSM=y$/m', $config) !== 1
        || preg_match('/^CONFIG_LSM="([a-z0-9_]+(?:,[a-z0-9_]+)*)"$/m', $config, $match) !== 1) return false;
    $defaults = explode(',', $match[1]);
    $selected = $legacy = [];
    $updated = $lines;
    $defaultIndex = null;
    $defaultQuote = '"';
    $defaultOptions = [];
    foreach ($lines as $index => $line) {
        if (preg_match('/^GRUB_CMDLINE_LINUX(?:_DEFAULT)?=/', $line) !== 1) continue;
        // Do not rewrite shell expressions or malformed assignments as literal lists.
        if (preg_match('/^(GRUB_CMDLINE_LINUX(?:_DEFAULT)?)=(["\'])([^"\']*)\2$/D', $line, $match) !== 1) return false;
        if (strpos($line, "\0") !== false || strpbrk($match[3], '$`\\') !== false) return false;
        $options = [];
        foreach (pmssConfigLineColumns($match[3], 0, []) as $option) {
            if (strpos($option, 'lsm=') === 0) {
                if (preg_match('/^lsm=([a-z0-9_]+(?:,[a-z0-9_]+)*)$/D', $option, $lsm) !== 1) return false;
                $selected[$match[1]] = array_merge(explode(',', $lsm[1]), $selected[$match[1]] ?? []);
            } else {
                $options[] = $option;
                if (preg_match('/^security=([a-z0-9_]+)$/D', $option, $security) === 1) {
                    $legacy[$match[1]] = array_merge([$security[1]], $legacy[$match[1]] ?? []);
                }
            }
        }
        if ($match[1] === 'GRUB_CMDLINE_LINUX_DEFAULT') {
            $defaultIndex = $index;
            $defaultQuote = $match[2];
            $defaultOptions = $options;
        }
        if (count($options) !== count(pmssConfigLineColumns($match[3], 0, []))) {
            $updated[$index] = $match[1].'='.$match[2].implode(' ', $options).$match[2];
        }
    }
    // An explicit order wins over build defaults, including legacy major-LSM choice.
    $selected = array_merge($selected['GRUB_CMDLINE_LINUX_DEFAULT'] ?? [], $selected['GRUB_CMDLINE_LINUX'] ?? []);
    $legacy = array_merge($legacy['GRUB_CMDLINE_LINUX_DEFAULT'] ?? [], $legacy['GRUB_CMDLINE_LINUX'] ?? []);
    $selected = array_values(array_unique(array_merge($selected ?: $legacy, $defaults, ['bpf'])));
    $defaultOptions[] = 'lsm='.implode(',', $selected);
    $updated[$defaultIndex ?? count($updated)] = 'GRUB_CMDLINE_LINUX_DEFAULT='.$defaultQuote.implode(' ', $defaultOptions).$defaultQuote;
    if ($updated === $lines) return false;
    $lines = $updated;
    $log('Prepared BPF LSM option in '.$grubPath.'; applies after GRUB update and reboot');
    return true;
}
