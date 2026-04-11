<?php
declare(strict_types=1);

namespace {
    if (!function_exists('pmssTestRequireUpdateBootstrapShim')) {
        /**
         * Load the updater bootstrap in tests without tripping over its CLI shebang.
         */
        function pmssTestRequireUpdateBootstrapShim(): void
        {
            static $loaded = false;
            if ($loaded) {
                return;
            }

            $loaded = true;
            $sourcePath = dirname(__DIR__, 3).'/update.php';
            $source = @file_get_contents($sourcePath);
            if ($source === false) {
                throw new \RuntimeException('Unable to read scripts/update.php test bootstrap source');
            }

            // CLI execution needs the shebang, but test includes must drop it so
            // `declare(strict_types=1)` remains the first executable statement.
            $sanitized = preg_replace('/^#![^\r\n]*\r?\n/', '', $source, 1);
            if (!is_string($sanitized) || $sanitized === '') {
                throw new \RuntimeException('Unable to strip updater shebang for tests');
            }

            $tempRoot = tempnam(sys_get_temp_dir(), 'pmss-update-bootstrap-');
            if ($tempRoot === false) {
                throw new \RuntimeException('Unable to create updater bootstrap temp path');
            }

            @unlink($tempRoot);
            if (!@mkdir($tempRoot, 0700)) {
                throw new \RuntimeException('Unable to create updater bootstrap temp directory');
            }

            $shimPath = $tempRoot.'/update.php';
            if (@file_put_contents($shimPath, $sanitized) === false) {
                throw new \RuntimeException('Unable to write updater bootstrap test shim');
            }

            register_shutdown_function(static function () use ($shimPath, $tempRoot): void {
                @unlink($shimPath);
                @rmdir($tempRoot);
            });

            require_once $shimPath;
        }

        pmssTestRequireUpdateBootstrapShim();
    }
}
