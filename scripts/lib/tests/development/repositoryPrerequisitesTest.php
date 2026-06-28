<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/updateBootstrapShim.php';
require_once dirname(__DIR__, 2).'/update/repositories.php';

class RepositoryPrerequisitesTest extends TestCase
{
    public function testMediaareaBootstrapSkipsWhenKeyPresent(): void
    {
        $tempKey = $this->pmssWriteTempFile('mediaarea-key', 'placeholder');

        $before = $this->listBootstrapDirs();
        $this->pmssWithTrackedEnv([
            'PMSS_APT_MEDIAAREA_KEY_PATH' => $tempKey,
        ], function (): void {
            \pmssEnsureMediaareaRepository();
        }, ['PMSS_DRY_RUN']);
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
        $root = $this->pmssMakeTempDir('pmss-sonarr-', 0700);
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

        $this->pmssWithTrackedEnv([
            'PMSS_APT_KEYRING_DIR' => $keyDir,
            'PMSS_APT_SONARR_KEY_PATH' => $keyPath,
            'PMSS_APT_SONARR_LEGACY_KEY_PATH' => $legacyKeyPath,
            'PMSS_APT_SOURCES_LIST_D_PATH' => $sourcesDir,
            'PMSS_APT_SOURCES_PATH' => $sourcesPath,
        ], function () use ($callback, $root, $keyPath, $legacyKeyPath, $listPath): void {
            $callback([
                'root' => $root,
                'key' => $keyPath,
                'legacy_key' => $legacyKeyPath,
                'list' => $listPath,
            ]);
        });
    }

    // Legacy helper removed; MediaArea key tests now rely on a single temp file override.
}
