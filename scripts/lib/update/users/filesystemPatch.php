<?php
/** Guarded compatibility patches with metadata-preserving publication. @license GPL-3.0-only */

/**
 * Apply a text patch to a writable tenant file when it exists.
 *
 * @param string   $path    Writable file path in the tenant home.
 * @param callable $patcher Content transformer that returns updated text.
 */
function pmssUserPatchWritableFile(string $path, callable $patcher): void
{
    if (!pmssUserPathWithinHomeRoot($path)
        || ($content = pmssReadRegularFileContents($path)) === null
        || $content === '') {
        return;
    }

    $updated = $patcher($content);
    if (is_string($updated) && $updated !== $content) {
        pmssReplaceUserFilePreservingMetadata($path, $updated);
    }
}

/** @param array<int, array{legacy:string,patched:string}> $replacements */
function pmssUserPatchWritableStrings(string $path, array $replacements): void
{
    pmssUserPatchWritableFile($path, static function (string $updated) use ($replacements): string {
        foreach ($replacements as $replacement) {
            $placeholder = '';
            if (strpos($replacement['patched'], $replacement['legacy']) !== false
                && strpos($updated, $replacement['patched']) !== false) {
                $placeholder = '__PMSS_PATCHED_'.sha1($replacement['patched']).'__';
                while (strpos($updated, $placeholder) !== false) {
                    $placeholder .= '_X';
                }
                $updated = str_replace($replacement['patched'], $placeholder, $updated);
            }
            // str_replace applies these in order and ignores an empty placeholder.
            $updated = str_replace([$replacement['legacy'], $placeholder], $replacement['patched'], $updated);
        }
        return $updated;
    });
}
