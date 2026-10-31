<?php
/** Checked executable discovery; loaded by environment.php. @license GPL-3.0-only */

function pmssCommandBinaryNameIsSafe(string $binary): bool { return preg_match('/^[A-Za-z0-9._+-]+$/', $binary) === 1; }
function pmssCommandPathCandidateIsSafe(string $path): bool { return strpos($path, '/') === 0 && strpbrk($path, "\0\r\n") === false; }

function pmssCommandPath(string $binary): string
{
    // trim() removes edge NULs; reject them before they can name another binary.
    if (pmssFilesystemPathHasNulByte($binary)) return '';
    $binary = trim($binary);
    if (!pmssCommandBinaryNameIsSafe($binary)) return '';
    $resolved = @shell_exec('command -v '.escapeshellarg($binary).' 2>/dev/null');
    // Reject raw NUL bytes before trim() can turn malformed output into a valid path.
    if (!is_string($resolved) || pmssFilesystemPathHasNulByte($resolved)) return '';
    $path = trim($resolved);
    return pmssCommandPathCandidateIsSafe($path) && is_executable($path) ? $path : '';
}
