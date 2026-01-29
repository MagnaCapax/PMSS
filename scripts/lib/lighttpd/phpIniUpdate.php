<?php
/**
 * php.ini helpers for per-user lighttpd configuration.
 *
 * @license GPL-3.0-only
 */

function pmssUpdatePhpIni(string $path, int $memoryLimitMb): void
{
    if (is_link($path) || !is_file($path)) {
        return;
    }
    $content = @file_get_contents($path);
    if ($content === false) {
        return;
    }
    $memoryLine = 'memory_limit = '.$memoryLimitMb.'M';
    if (preg_match('/^memory_limit\s*=.*$/m', $content)) {
        $content = preg_replace('/^memory_limit\s*=.*$/m', $memoryLine, $content, 1);
    } else {
        $content = rtrim($content, "\n")."\n".$memoryLine."\n";
    }
    pmssAtomicWriteFile($path, $content);
}

