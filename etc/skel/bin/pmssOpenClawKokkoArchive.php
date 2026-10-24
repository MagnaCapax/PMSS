<?php
/** Validate tar's effective member names and types before extracting a Kokko archive. */

/** Reject links, special files, escaped names and every path outside the main archive root. */
function ocKokkoArchiveValidate(string $archive): void
{
    $env = getenv();
    $env['LC_ALL'] = 'C';
    unset($env['TAR_OPTIONS']);
    list($rc, $listing) = ocRun(array('tar', '-tzvf', $archive, '--numeric-owner', '--quoting-style=escape'), $env);
    if ($rc !== 0 || trim($listing) === '') throw new RuntimeException('Invalid skill archive');
    $root = 'mcxKokko-main/';
    foreach (explode("\n", rtrim($listing, "\n")) as $line) {
        $fields = preg_split('/\s+/', $line, 6);
        $name = $fields[5] ?? '';
        if (count($fields) !== 6 || !preg_match('/^[-d][rwxStTs-]{9}$/D', $fields[0])
            || strpos($name, $root) !== 0 || strpos($name, '..') !== false
            || !preg_match('#^[A-Za-z0-9_./+@-]+$#D', $name)) {
            throw new RuntimeException('Unsafe skill archive member');
        }
    }
}

/** Download without credentials or diagnostics that could expose the account environment. */
function ocKokkoDownload(string $url, string $archive): bool
{
    list($rc) = ocRun(array('curl', '-fsSL', '--proto', '=https', '--proto-redir', '=https',
        '--connect-timeout', '15', '--max-time', '120', $url, '-o', $archive));
    return $rc === 0;
}

/** Only owned, marked version directories authorize publication or recursive cleanup. */
function ocKokkoManaged(string $dir): bool
{
    $marker = $dir.'/.pmss-kokko';
    return !is_link($dir) && is_dir($dir) && fileowner($dir) === posix_geteuid()
        && !is_link($marker) && is_file($marker) && fileowner($marker) === posix_geteuid()
        && preg_match('/^(?:main|v[0-9]+\.[0-9]+\.[0-9]+)\n[a-f0-9]{64}\n$/D', (string) @file_get_contents($marker)) === 1;
}

/** Remove marked older versions, leaving unrecognized paths and redirected directories alone. */
function ocKokkoCleanup(array $paths, ?string $keep): void
{
    foreach (new DirectoryIterator($paths['state']) as $entry) {
        $dir = $entry->getPathname();
        if (preg_match('/^pmss-kokko-(?:v[0-9]+\.[0-9]+\.[0-9]+|[a-f0-9]{12})$/D', $entry->getFilename())
            && $dir !== $keep && ocKokkoManaged($dir)) ocRemoveTree($dir);
    }
}
