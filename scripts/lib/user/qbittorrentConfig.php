<?php
/** Line-preserving qBittorrent config updates. */
require_once __DIR__.'/qbittorrentWebUi.php';

/** Split config lines while retaining their original newline convention. */
function pmssQbittorrentConfigSplitLines(string $config): array
{
    $lineEnding = strpos($config, "\r\n") !== false ? "\r\n" : "\n";
    $normalized = str_replace(["\r\n", "\r"], "\n", $config);
    $lines = $normalized === '' ? [] : explode("\n", $normalized);
    $trailing = substr($normalized, -1) === "\n";
    if ($trailing) array_pop($lines);
    return [$lines, $trailing, $lineEnding];
}

/** Render normalized qBittorrent config lines with the original newline style. */
function pmssQbittorrentConfigJoinLines(array $lines, bool $hadTrailingNewline, string $lineEnding): string
{
    $updated = implode("\n", $lines);
    if ($updated !== '' && $hadTrailingNewline) {
        $updated .= "\n";
    }

    return str_replace("\n", $lineEnding, $updated);
}

/**
 * Replace or restore one managed qBittorrent key inside the target section.
 */
function pmssQbittorrentConfigSet(string $config, string $section, string $key, string $value, bool $all = false): ?string
{
    list($lines, $hadTrailingNewline, $lineEnding) = pmssQbittorrentConfigSplitLines($config);

    // Restoration replaces stale duplicate legacy keys; ordinary pre-11 writers retain their bytes.
    if ($all) {
        $inside = false;
        $replaced = false;
        foreach ($lines as &$line) {
            if (preg_match('/^\[[^\]]+\]$/', $line) === 1) $inside = $line === '['.$section.']';
            if ($inside && strpos($line, $key.'=') === 0) { $line = $key.'='.$value; $replaced = true; }
        }
        unset($line);
        if ($replaced) return pmssQbittorrentConfigJoinLines($lines, $hadTrailingNewline, $lineEnding);
    }

    $sectionHeader = '['.$section.']';
    $managedLine = $key.'='.$value;
    $sectionStart = null;
    $sectionEnd = count($lines);
    $found = false;

    foreach ($lines as $index => $line) {
        if ($line === $sectionHeader) {
            $sectionStart = $index;
            continue;
        }
        if ($sectionStart !== null && preg_match('/^\[[^\]]+\]$/', $line) === 1) {
            $sectionEnd = $index;
            break;
        }
    }

    if ($sectionStart !== null) {
        for ($index = $sectionStart + 1; $index < $sectionEnd; $index++) {
            if (strpos($lines[$index], $key.'=') !== 0) {
                continue;
            }

            $lines[$index] = $managedLine;
            $found = true;
            break;
        }
    }

    if ($found) {
        return pmssQbittorrentConfigJoinLines($lines, $hadTrailingNewline, $lineEnding);
    }

    if ($sectionStart === null) {
        if (!empty($lines) && end($lines) !== '') {
            $lines[] = '';
        }
        $lines[] = $sectionHeader;
        $lines[] = $managedLine;
    } else {
        for ($index = $sectionStart + 1; $index < $sectionEnd; $index++) {
            if ($lines[$index] === $managedLine) {
                return pmssQbittorrentConfigJoinLines($lines, $hadTrailingNewline, $lineEnding);
            }
        }

        while ($sectionEnd > ($sectionStart + 1) && $lines[$sectionEnd - 1] === '') {
            $sectionEnd--;
        }

        // Insert before the next section header so user-owned settings keep their section.
        array_splice($lines, $sectionEnd, 0, [$managedLine]);
    }

    return pmssQbittorrentConfigJoinLines($lines, $hadTrailingNewline, $lineEnding);
}

/** Mirror managed WebUI writes only after migration 11. */
function pmssQbittorrentConfigUpsert(string $config, string $section, string $key, string $value): ?string
{
    $updated = pmssQbittorrentConfigSet($config, $section, $key, $value);
    if ($section === 'Preferences' && strpos($key, 'WebUI\\') === 0
        && pmssQbittorrentWebUiSection($config)[0] === 'WebUI') {
        $updated = pmssQbittorrentConfigSet($updated, 'WebUI', substr($key, 6), $value);
    }
    return $updated;
}
