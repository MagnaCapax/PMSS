<?php
namespace PMSS\Tests;

trait FilesystemCleanupTrait
{
    /** Build a named temporary directory, optionally under a stable base path. */
    protected function pmssMakeNamedTempDir(string $prefix, int $mode = 0755, ?string $baseDir = null): string
    {
        if ($baseDir === null) {
            return $this->pmssMakeTempDir($prefix, $mode);
        }

        $this->pmssEnsureDir($baseDir, 0755);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $path = $baseDir.'/'.trim($prefix, '-').'-'.bin2hex(random_bytes(4));
            if (@mkdir($path, $mode, true)) {
                return $path;
            }
        }

        return $this->pmssMakeTempDir($prefix, $mode);
    }

    /** Assign a temporary directory to a test property so setup code stays shared. */
    protected function pmssAssignTempDirProperty(
        string $propertyName,
        string $prefix,
        int $mode = 0755,
        ?string $baseDir = null
    ): void {
        $this->{$propertyName} = $this->pmssMakeNamedTempDir($prefix, $mode, $baseDir);
    }

    /** Remove a temporary directory stored on a test property and clear the slot. */
    protected function pmssCleanupTempDirProperty(string $propertyName): void
    {
        $path = (string)($this->{$propertyName} ?? '');
        if ($path === '') {
            return;
        }

        $this->cleanup($path);
        $this->{$propertyName} = '';
    }

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

    /** Write fixture content and mark the target executable for stubbed commands. */
    protected function pmssWriteExecutableFile(
        string $path,
        string $content,
        int $dirMode = 0755,
        int $fileMode = 0755
    ): void {
        $this->pmssWriteFile($path, $content, $dirMode);
        @chmod($path, $fileMode);
    }

    /** Write an executable PHP fixture with the standard test shebang/header. */
    protected function pmssWriteExecutablePhpFile(
        string $path,
        string $body,
        int $dirMode = 0755,
        int $fileMode = 0755
    ): void {
        $this->pmssWriteExecutableFile($path, "#!/usr/bin/env php\n<?php\n".$body."\n", $dirMode, $fileMode);
    }

    /** Write fixture content beneath a base directory so tests can share relative-path setup. */
    protected function pmssWriteRelativeFile(string $baseDir, string $relativePath, string $content, int $dirMode = 0755): void
    {
        $this->pmssWriteFile(rtrim($baseDir, '/').'/'.ltrim($relativePath, '/'), $content, $dirMode);
    }

    /** Read fixture content, treating absent files as empty strings for assertions. */
    protected function pmssReadFileOrEmpty(string $path): string
    {
        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    /** Create a temporary file with deterministic PMSS-style naming. */
    protected function pmssWriteTempFile(string $prefix, string $content, string $namespace = 'pmss'): string
    {
        $path = $this->pmssMakeTempPath($namespace.'-'.$prefix.'-');
        $this->pmssWriteFile($path, $content);
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
