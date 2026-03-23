<?php
namespace PMSS\Tests;

trait FilesystemCleanupTrait
{
    /** Ensure a directory exists for hermetic filesystem fixtures. */
    protected function pmssEnsureDir(string $path, int $mode = 0755): void
    {
        if (!is_dir($path)) @mkdir($path, $mode, true);
    }

    /** Write fixture content while creating parent directories when needed. */
    protected function pmssWriteFile(string $path, string $content, int $dirMode = 0755): void
    {
        $this->pmssEnsureDir(dirname($path), $dirMode);
        @file_put_contents($path, $content);
    }

    /** Create a temporary file with deterministic PMSS-style naming. */
    protected function pmssWriteTempFile(string $prefix, string $content, string $namespace = 'pmss'): string
    {
        $path = tempnam(sys_get_temp_dir(), $namespace.'-'.$prefix.'-');
        if ($path === false) {
            $path = sys_get_temp_dir().'/'.$namespace.'-'.$prefix.'-'.bin2hex(random_bytes(6));
        }

        file_put_contents($path, $content);
        return $path;
    }

    /** Capture string log output into an array for later assertions. */
    protected function pmssMakeArrayLogger(array &$messages): callable
    {
        return function (string $message) use (&$messages): void {
            $messages[] = $message;
        };
    }

    /** Check whether a captured log message contains a given substring. */
    protected function pmssMessagesContain(array $messages, string $needle): bool
    {
        foreach ($messages as $message) {
            if (strpos($message, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    protected function cleanup(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }

        $this->pmssRemoveTree($path);
    }
}
