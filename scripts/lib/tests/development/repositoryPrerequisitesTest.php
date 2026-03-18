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

    public function testSonarrSourceLineWithSignedByLeavesCommentedLineUntouched(): void
    {
        $line = '# deb https://apt.sonarr.tv/debian bullseye main';
        $this->assertEquals($line, \pmssSonarrSourceLineWithSignedBy($line, '/tmp/sonarr.gpg'));
    }

    public function testSonarrSourceLineWithSignedByLeavesUnrelatedLineUntouched(): void
    {
        $line = 'deb http://deb.debian.org/debian bullseye main';
        $this->assertEquals($line, \pmssSonarrSourceLineWithSignedBy($line, '/tmp/sonarr.gpg'));
    }

    public function testSonarrSourceLineWithSignedByAddsOptionToPlainLine(): void
    {
        $line = 'deb https://apt.sonarr.tv/debian bullseye main';
        $expected = 'deb [signed-by=/tmp/sonarr.gpg] https://apt.sonarr.tv/debian bullseye main';
        $this->assertEquals($expected, \pmssSonarrSourceLineWithSignedBy($line, '/tmp/sonarr.gpg'));
    }

    public function testSonarrSourceLineWithSignedByPreservesExistingOptions(): void
    {
        $line = 'deb [arch=amd64] https://apt.sonarr.tv/debian bullseye main';
        $expected = 'deb [arch=amd64 signed-by=/tmp/sonarr.gpg] https://apt.sonarr.tv/debian bullseye main';
        $this->assertEquals($expected, \pmssSonarrSourceLineWithSignedBy($line, '/tmp/sonarr.gpg'));
    }

    public function testEnsureSonarrKeyScopesLegacySourcesAndRemovesGlobalKey(): void
    {
        $this->withTempSonarrPaths(function (array $paths): void {
            file_put_contents($paths['key'], 'scoped-key');
            file_put_contents($paths['legacy_key'], 'legacy-key');
            file_put_contents($paths['list'], "deb https://apt.sonarr.tv/debian bullseye main\n");

            \pmssEnsureSonarrKey();

            $updated = file_get_contents($paths['list']);
            $this->assertTrue(strpos($updated, 'signed-by='.$paths['key']) !== false);
            $this->assertTrue(!file_exists($paths['legacy_key']));
        });
    }

    public function testEnsureSonarrKeyDoesNotModifyCommentedSourceLines(): void
    {
        $this->withTempSonarrPaths(function (array $paths): void {
            file_put_contents($paths['key'], 'scoped-key');
            file_put_contents($paths['list'], "# deb https://apt.sonarr.tv/debian bullseye main\n");

            \pmssEnsureSonarrKey();

            $updated = file_get_contents($paths['list']);
            $this->assertEquals("# deb https://apt.sonarr.tv/debian bullseye main\n", $updated);
        });
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

    private function withTempSonarrPaths(callable $callback): void
    {
        $root = sys_get_temp_dir().'/pmss-sonarr-'.bin2hex(random_bytes(6));
        $keyDir = $root.'/keyrings';
        $legacyDir = $root.'/trusted';
        $sourcesDir = $root.'/sources.list.d';
        $sourcesPath = $root.'/sources.list';
        $keyPath = $keyDir.'/sonarr.gpg';
        $legacyKeyPath = $legacyDir.'/sonarr.gpg';
        $listPath = $sourcesDir.'/sonarr.list';

        foreach ([$root, $keyDir, $legacyDir, $sourcesDir] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
        }
        file_put_contents($sourcesPath, "deb http://deb.debian.org/debian bullseye main\n");

        $previousKeyringDir = getenv('PMSS_APT_KEYRING_DIR');
        $previousKeyPath = getenv('PMSS_APT_SONARR_KEY_PATH');
        $previousLegacyKeyPath = getenv('PMSS_APT_SONARR_LEGACY_KEY_PATH');
        $previousSourcesListDir = getenv('PMSS_APT_SOURCES_LIST_D_PATH');
        $previousSourcesPath = getenv('PMSS_APT_SOURCES_PATH');

        putenv('PMSS_APT_KEYRING_DIR='.$keyDir);
        putenv('PMSS_APT_SONARR_KEY_PATH='.$keyPath);
        putenv('PMSS_APT_SONARR_LEGACY_KEY_PATH='.$legacyKeyPath);
        putenv('PMSS_APT_SOURCES_LIST_D_PATH='.$sourcesDir);
        putenv('PMSS_APT_SOURCES_PATH='.$sourcesPath);

        try {
            $callback([
                'root' => $root,
                'key' => $keyPath,
                'legacy_key' => $legacyKeyPath,
                'list' => $listPath,
            ]);
        } finally {
            if ($previousKeyringDir === false) {
                putenv('PMSS_APT_KEYRING_DIR');
            } else {
                putenv('PMSS_APT_KEYRING_DIR='.$previousKeyringDir);
            }
            if ($previousKeyPath === false) {
                putenv('PMSS_APT_SONARR_KEY_PATH');
            } else {
                putenv('PMSS_APT_SONARR_KEY_PATH='.$previousKeyPath);
            }
            if ($previousLegacyKeyPath === false) {
                putenv('PMSS_APT_SONARR_LEGACY_KEY_PATH');
            } else {
                putenv('PMSS_APT_SONARR_LEGACY_KEY_PATH='.$previousLegacyKeyPath);
            }
            if ($previousSourcesListDir === false) {
                putenv('PMSS_APT_SOURCES_LIST_D_PATH');
            } else {
                putenv('PMSS_APT_SOURCES_LIST_D_PATH='.$previousSourcesListDir);
            }
            if ($previousSourcesPath === false) {
                putenv('PMSS_APT_SOURCES_PATH');
            } else {
                putenv('PMSS_APT_SOURCES_PATH='.$previousSourcesPath);
            }

            foreach (glob($sourcesDir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            foreach (glob($keyDir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            foreach (glob($legacyDir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @unlink($sourcesPath);
            @rmdir($sourcesDir);
            @rmdir($keyDir);
            @rmdir($legacyDir);
            @rmdir($root);
        }
    }

    // Legacy helper removed; MediaArea key tests now rely on a single temp file override.
}
