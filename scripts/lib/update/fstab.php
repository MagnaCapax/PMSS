<?php
/**
 * Shared /etc/fstab parsing helpers for updater modules.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once dirname(__DIR__).'/runtime.php';
/**
 * Load /etc/fstab style lines or emit the standard skip warning.
 *
 * @return ?array<int,string>
 */
function pmssFstabLinesRead(string $fstabPath, callable $logger, string $context): ?array
{
    if (!is_readable($fstabPath)) { $logger('[WARN] '.$fstabPath.' not readable; skipping '.$context); return null; }
    $lines = file($fstabPath, FILE_IGNORE_NEW_LINES);
    if ($lines === false) { $logger('[WARN] Unable to read '.$fstabPath.'; skipping '.$context); return null; }
    return $lines;
}
/**
 * Return the first active mount entry for a mount point.
 *
 * @return ?array{index:int,columns:array<int,string>}
 */
function pmssFstabMountEntryRead(array $lines, string $mountPoint, ?string $fsType = null): ?array
{
    foreach ($lines as $index => $line) {
        $columns = pmssConfigLineColumns($line, 4);
        if ($columns === [] || $columns[1] !== $mountPoint || ($fsType !== null && $columns[2] !== $fsType)) continue;
        return ['index' => (int) $index, 'columns' => $columns];
    }
    return null;
}
/**
 * Build an updated option plan for one fstab mount entry.
 *
 * @param array<string,string> $replacePrefixedOptions
 * @return array{columns:array<int,string>,options:array<int,string>,added:array<int,string>,removed:array<int,string>}
 */
function pmssFstabMountOptionsPlan(array $columns, array $requiredOptions = [], array $removeOptions = [], bool $dropDefaultsOnly = false, array $replacePrefixedOptions = [], bool $collapseDuplicates = true): array
{
    $plan = pmssConfigOptionsUpdatePlan($columns[3], $requiredOptions, $removeOptions, $dropDefaultsOnly);
    foreach ($replacePrefixedOptions as $prefix => $replacement) {
        $plan['options'] = pmssFstabOptionsReplacePrefixedValue($plan['options'], $prefix, $replacement, $collapseDuplicates);
    }
    $columns[3] = implode(',', $plan['options']);
    return ['columns' => $columns, 'options' => $plan['options'], 'added' => $plan['added'], 'removed' => $plan['removed']];
}

/**
 * Apply an option plan to the first matching fstab entry.
 *
 * @param array<string,string> $replacePrefixedOptions
 * @return ?array{columns:array<int,string>,options:array<int,string>,added:array<int,string>,removed:array<int,string>,changed:bool,index:int}
 */
function pmssFstabMountOptionsEnsure(array &$lines, string $mountPoint, array $requiredOptions = [], array $removeOptions = [], bool $dropDefaultsOnly = false, ?string $fsType = null, array $replacePrefixedOptions = [], bool $collapseDuplicates = true): ?array
{
    if (($entry = pmssFstabMountEntryRead($lines, $mountPoint, $fsType)) === null) return null;
    $plan = pmssFstabMountOptionsPlan($entry['columns'], $requiredOptions, $removeOptions, $dropDefaultsOnly, $replacePrefixedOptions, $collapseDuplicates);
    $plan['changed'] = $plan['columns'][3] !== $entry['columns'][3];
    $plan['index'] = $entry['index'];
    if ($plan['changed']) $lines[$entry['index']] = implode("\t", $plan['columns']);
    return $plan;
}
/**
 * Replace a prefixed option with one canonical value.
 *
 * @param array<int,string> $options
 * @return array<int,string>
 */
function pmssFstabOptionsReplacePrefixedValue(array $options, string $prefix, string $replacement, bool $collapseDuplicates = true): array
{
    $updated = [];
    $replaced = false;
    foreach ($options as $option) {
        if (strpos($option, $prefix) !== 0) { $updated[] = $option; continue; }
        if (!$replaced) { $updated[] = $replacement; $replaced = true; continue; }
        if (!$collapseDuplicates) $updated[] = $option;
    }
    if (!$replaced) $updated[] = $replacement;
    return $updated;
}
