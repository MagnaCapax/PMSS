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

    /** Create a fixture user home under a tracked PMSS_HOME_DIR root. */
    protected function pmssMakeTrackedUserHomeTree(string $prefix, string $user, string $relativeDir = ''): string
    {
        $homeRoot = $this->pmssMakeTrackedHomeRoot($prefix);
        $home = $this->pmssUserHomePath($homeRoot, $user);
        if ($relativeDir !== '') {
            $this->pmssEnsureFixtureDirectory($home.'/'.ltrim($relativeDir, '/'), 0755);
        }

        return $home;
    }

    /** Ensure a directory exists for hermetic filesystem fixtures. */
    protected function pmssEnsureDir(string $path, int $mode = 0755): void
    {
        if (!is_dir($path)) @mkdir($path, $mode, true);
    }

    /** Write fixture content while creating parent directories when needed. */
    protected function pmssWriteFile(string $path, string $content, int $dirMode = 0755): string
    {
        $this->pmssEnsureDir(dirname($path), $dirMode);
        @file_put_contents($path, $content);

        return $path;
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

    /** Write fixture content beneath a base directory and return the resolved path. */
    protected function pmssWriteRelativeFile(string $baseDir, string $relativePath, string $content, int $dirMode = 0755): string
    {
        return $this->pmssWriteFile(rtrim($baseDir, '/').'/'.ltrim($relativePath, '/'), $content, $dirMode);
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

    /** Run a callback with an array-backed logger and return both result and logs. */
    protected function pmssArrayLoggerCapture(callable $callback): array
    {
        $messages = [];
        return [$callback($this->pmssMakeArrayLogger($messages)), $messages];
    }

    /** Assert that a callback runs without emitting PHP warnings/notices. */
    protected function pmssAssertNoPhpWarnings(callable $callback): void
    {
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = [$severity, $message];
            return true;
        });

        try {
            $callback();
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings);
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

    /** Assert that a captured logger array contains the expected substring. */
    protected function pmssAssertMessagesContain(array $messages, string $needle, string $message = ''): void
    {
        $this->assertTrue(
            $this->pmssMessagesContain($messages, $needle),
            $message !== '' ? $message : 'Expected log messages to contain '.var_export($needle, true)
        );
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
