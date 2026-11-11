<?php
/** Sysctl assignment policy, ordered values and config rendering. @license GPL-3.0-only */

/** Yield string settings in group order, retaining duplicate keys for change reporting. */
function pmssSysctlSettingsValues(array $groupedSettings): \Generator
{
    foreach ($groupedSettings as $settings) {
        if (!is_array($settings)) continue;
        foreach ($settings as $key => $value) yield (string) $key => (string) $value;
    }
}

/** Parse one sysctl assignment while preserving each caller's comment policy. */
function pmssSysctlAssignmentLineParse(string $line, bool $stripInlineComment = false): ?array
{
    $trimmed = trim($stripInlineComment ? (string) preg_replace('/\s+#.*$/', '', $line) : $line);
    if ($trimmed === '' || $trimmed[0] === '#' || preg_match('/^([A-Za-z0-9_.]+)\s*=\s*(.*)$/', $trimmed, $matches) !== 1) {
        return null;
    }

    return [$matches[1], trim($matches[2])];
}

/** Parse operator-owned sysctl overrides from a config file. */
function pmssSysctlOverridesParse(string $path): array
{
    $keys = [];
    foreach (pmssReadRegularFileNonEmptyLines($path) as $line) if (($assignment = pmssSysctlAssignmentLineParse($line, true)) !== null) $keys[$assignment[0]] = true;
    return array_keys($keys);
}

/** Filter grouped sysctl settings while respecting explicit operator overrides. */
function pmssSysctlSettingsFilterOverrides(array $groupedSettings, array $overrideKeys): array
{
    $overrides = array_flip($overrideKeys);
    foreach ($groupedSettings as $group => $settings) {
        if (!is_array($settings) || ($settings = array_diff_key($settings, $overrides)) === []) unset($groupedSettings[$group]);
        else $groupedSettings[$group] = array_map('strval', $settings);
    }
    return $groupedSettings;
}

/** Parse a sysctl config file into key/value pairs for change reporting. */
function pmssSysctlFileParse(string $path): array
{
    $settings = [];
    foreach (pmssReadRegularFileNonEmptyLines($path) as $line) if (($assignment = pmssSysctlAssignmentLineParse($line)) !== null && $assignment[1] !== '') $settings[$assignment[0]] = $assignment[1];
    return $settings;
}

/** Render grouped sysctl settings as an ordered config file body. */
function pmssSysctlConfigRender(array $groupedSettings): string
{
    $labels = [
        'vm' => 'Memory',
        'net' => 'Network',
        'conntrack' => 'Conntrack',
        'security' => 'Security Hardening',
    ];

    $lines = ['# Pulsed Media Config', '# Hardware-aware PMSS baseline'];
    foreach ($groupedSettings as $group => $settings) {
        if (empty($settings)) {
            continue;
        }

        $lines[] = '';
        $lines[] = '# '.($labels[$group] ?? ucfirst($group));
        foreach ($settings as $key => $value) {
            $lines[] = $key.' = '.$value;
        }
    }

    return implode(PHP_EOL, $lines).PHP_EOL;
}

/** Describe value changes between the existing file and the next applied profile. */
function pmssSysctlChangesDescribe(array $existingSettings, array $groupedSettings): array
{
    $changes = [];
    foreach (pmssSysctlSettingsValues($groupedSettings) as $key => $value) {
        $previousValue = array_key_exists($key, $existingSettings) ? (string) $existingSettings[$key] : null;
        if ($previousValue !== $value) $changes[] = $key.': '.($previousValue ?? '<unset>').' -> '.$value;
    }

    return $changes;
}
