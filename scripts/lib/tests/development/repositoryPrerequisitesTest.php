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
        $previousKeyPath = getenv('PMSS_APT_MEDIAAREA_KEY_PATH');

        $tempKey = tempnam(sys_get_temp_dir(), 'pmss-mediaarea-key-');
        if ($tempKey === false) {
            $tempKey = sys_get_temp_dir().'/pmss-mediaarea-key-'.bin2hex(random_bytes(4));
            touch($tempKey);
        }
        file_put_contents($tempKey, 'placeholder');

        $before = $this->listBootstrapDirs();
        putenv('PMSS_APT_MEDIAAREA_KEY_PATH='.$tempKey);
        try {
            \pmssEnsureMediaareaRepository();
        } finally {
            if ($previousKeyPath === false) {
                putenv('PMSS_APT_MEDIAAREA_KEY_PATH');
            } else {
                putenv('PMSS_APT_MEDIAAREA_KEY_PATH='.$previousKeyPath);
            }
            if ($previousDryRun === false) {
                putenv('PMSS_DRY_RUN');
            } else {
                putenv('PMSS_DRY_RUN='.$previousDryRun);
            }
            @unlink($tempKey);
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
            if (strpos($base, 'pmss-mediaarea-keyring-') === 0) {
                return false;
            }
            return true;
        }));
        sort($dirs);
        return $dirs;
    }

    // Legacy helper removed; MediaArea key tests now rely on a single temp file override.
}
