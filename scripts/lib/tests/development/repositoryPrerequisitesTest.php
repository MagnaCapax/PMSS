<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/update.php';
require_once dirname(__DIR__, 2).'/update/repositories.php';

class RepositoryPrerequisitesTest extends TestCase
{
    public function testMediaareaBootstrapSkipsWhenKeyPresent(): void
    {
        $previousDryRun = getenv('PMSS_DRY_RUN');
        $previousKeyDir = getenv('PMSS_APT_KEYRING_DIR');
        putenv('PMSS_DRY_RUN=1');

        $tempKey = tempnam(sys_get_temp_dir(), 'pmss-mediaarea-key-');
        if ($tempKey === false) {
            $tempKey = sys_get_temp_dir().'/pmss-mediaarea-key-'.bin2hex(random_bytes(4));
            touch($tempKey);
        }
        file_put_contents($tempKey, 'placeholder');
        $tempKeyDir = sys_get_temp_dir().'/pmss-mediaarea-keyring-'.bin2hex(random_bytes(4));
        @mkdir($tempKeyDir, 0700, true);

        $before = $this->listBootstrapDirs();
        putenv('PMSS_MEDIAAREA_KEY_PATHS='.$tempKey);
        putenv('PMSS_APT_KEYRING_DIR='.$tempKeyDir);
        try {
            \pmssEnsureMediaareaRepository();
        } finally {
            putenv('PMSS_MEDIAAREA_KEY_PATHS');
            if ($previousKeyDir === false) {
                putenv('PMSS_APT_KEYRING_DIR');
            } else {
                putenv('PMSS_APT_KEYRING_DIR='.$previousKeyDir);
            }
            if ($previousDryRun === false) {
                putenv('PMSS_DRY_RUN');
            } else {
                putenv('PMSS_DRY_RUN='.$previousDryRun);
            }
            @unlink($tempKey);
            $this->removeTempDir($tempKeyDir);
        }
        $after = $this->listBootstrapDirs();
        $this->assertEquals($before, $after, 'MediaArea bootstrap should skip when key already present');
    }

    private function listBootstrapDirs(): array
    {
        $pattern = sys_get_temp_dir().'/pmss-mediaarea-*';
        $entries = glob($pattern) ?: [];
        $dirs = array_values(array_filter($entries, static function ($path): bool {
            if (!is_dir($path)) {
                return false;
            }
            $base = basename($path);
            // Ignore intentional test keyring dirs (pmss-mediaarea-keyring-*)
            if (str_starts_with($base, 'pmss-mediaarea-keyring-')) {
                return false;
            }
            return true;
        }));
        sort($dirs);
        return $dirs;
    }

    private function removeTempDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            @unlink($dir.'/'.$entry);
        }
        @rmdir($dir);
    }
}
