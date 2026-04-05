<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/update.php';
require_once dirname(__DIR__, 2).'/update/distro.php';
require_once dirname(__DIR__, 2).'/update/repositories.php';
require_once dirname(__DIR__, 2).'/update/apt.php';

class DistroDetectionTest extends TestCase
{
    public function testVersionFromCodenameMapsKnownReleases(): void
    {
        $this->assertEquals(8, \pmssVersionFromCodename('jessie'));
        $this->assertEquals(9, \pmssVersionFromCodename('stretch'));
        $this->assertEquals(10, \pmssVersionFromCodename('buster'));
        $this->assertEquals(11, \pmssVersionFromCodename('bullseye'));
        $this->assertEquals(12, \pmssVersionFromCodename('bookworm'));
        $this->assertEquals(13, \pmssVersionFromCodename('trixie'));
        $this->assertEquals(0, \pmssVersionFromCodename('unknown'));
        $this->assertEquals(11, \pmssVersionFromCodename('Bullseye'));
    }

    public function testDebianCodenameFromMajorMapsKnownReleases(): void
    {
        $this->assertEquals('jessie', \pmssDebianCodenameFromMajor(8));
        $this->assertEquals('stretch', \pmssDebianCodenameFromMajor(9));
        $this->assertEquals('buster', \pmssDebianCodenameFromMajor(10));
        $this->assertEquals('bullseye', \pmssDebianCodenameFromMajor(11));
        $this->assertEquals('bookworm', \pmssDebianCodenameFromMajor(12));
        $this->assertEquals('trixie', \pmssDebianCodenameFromMajor(13));
        $this->assertEquals('', \pmssDebianCodenameFromMajor(99));
    }

    public function testStandaloneDistroLibraryStillBootstrapsLegacyLogmsg(): void
    {
        $path = dirname(__DIR__, 2).'/update/distro.php';
        $script = 'require '.var_export($path, true).'; $function = new ReflectionFunction("logmsg"); echo str_replace("\\\\", "/", $function->getFileName());';
        $source = trim($this->pmssRunInlinePhp($script, ['PMSS_TEST_MODE' => '1']));

        $this->assertStringContainsString('/scripts/lib/log.php', $source);
    }

    /**
     * Ensure codename mapping overrides a mismatched VERSION_ID.
     */
    public function testDetectPrefersCodenameWhenVersionMismatches(): void
    {
        $this->pmssWithOsRelease([
            'ID'                => 'debian',
            'VERSION_ID'        => '11',
            'VERSION_CODENAME'  => 'bookworm',
        ], function (): void {
            $detected = \pmssDetectDistro();
            $this->assertEquals(12, $detected['version']);
            $this->assertEquals('bookworm', $detected['codename']);
        });
    }

    /**
     * Verify VERSION_ID kicks in when the codename is absent.
     */
    public function testDetectFallsBackToVersionId(): void
    {
        $this->pmssWithOsRelease([
            'ID'         => 'debian',
            'VERSION_ID' => '11',
        ], function (): void {
            $detected = \pmssDetectDistro();
            $this->assertEquals(11, $detected['version']);
            $this->assertEquals('', $detected['codename']);
        }, true);
    }

    /**
     * Confirm codename case is normalised prior to mapping.
     */
    public function testDetectNormalisesCodenameCase(): void
    {
        $this->pmssWithOsRelease([
            'ID'                => 'debian',
            'VERSION_CODENAME'  => 'Bullseye',
            'VERSION_ID'        => '',
        ], function (): void {
            $detected = \pmssDetectDistro();
            $this->assertEquals('bullseye', $detected['codename']);
            $this->assertEquals(11, $detected['version']);
        });
    }

    public function testOsReleaseHelpersNormalizeCodenameAndMajorVersion(): void
    {
        $this->pmssWithOsRelease([
            'ID'                => 'debian',
            'VERSION_ID'        => '12 (testing snapshot)',
            'VERSION_CODENAME'  => ' Bookworm ',
        ], function (): void {
            $this->assertEquals('12', \getDistroVersion());
            $this->assertEquals('bookworm', \getDistroCodename());
        });
    }

    /**
     * If both codename and version are missing we should surface zero.
     */
    public function testDetectHandlesMissingVersionSignals(): void
    {
        $this->pmssWithOsRelease([
            'ID' => 'debian',
        ], function (): void {
            $detected = \pmssDetectDistro();
            $this->assertEquals(0, $detected['version']);
        }, true);
    }

    /**
     * Non-numeric VERSION_ID strings should still produce an integer.
     */
    public function testDetectParsesMessyVersionId(): void
    {
        $this->pmssWithOsRelease([
            'ID'         => 'debian',
            'VERSION_ID' => '12 (testing snapshot)',
        ], function (): void {
            $detected = \pmssDetectDistro();
            $this->assertEquals(12, $detected['version']);
        }, true);
    }

    /**
     * Unknown versions should skip template rewrites and reuse existing sources.
     */
    public function testRefreshRepositoriesSkipsWhenVersionUnknown(): void
    {
        $logs = [];
        $logger = $this->pmssMakeArrayLogger($logs);
        $this->pmssWithEnv(['PMSS_DRY_RUN' => '1'], function () use ($logger): void {
            \pmssRefreshRepositories('debian', 0, $logger);
        });
        $this->pmssAssertMessagesContain($logs, 'reusing existing sources', 'Expected reuse notice when version unresolved');
    }

    /**
     * Repository updates should write new content when hashes differ.
     */
    public function testUpdateAptSourcesWritesTemplate(): void
    {
        $template = "deb https://mirror.invalid bookworm main\n";
        $this->withConfigTemplates(['bookworm' => $template], function () use ($template): void {
            $tmpDir = $this->pmssMakeTempDir('pmss-apt-', 0775);
            $sources = $tmpDir.'/sources.list';
            file_put_contents($sources, "deb https://old.invalid stable main\n");

            $logs = [];
            $logger = $this->pmssMakeArrayLogger($logs);

            try {
                $this->pmssWithEnv(['PMSS_APT_SOURCES_PATH' => $sources], function () use ($template, $logger): void {
                    \pmssUpdateAptSources('debian', 12, sha1('different'), [
                        'bookworm' => $template,
                        'bullseye' => '',
                        'buster'   => '',
                        'jessie'   => '',
                        'trixie'   => '',
                    ], $logger);
                });

                $this->assertEquals($template, file_get_contents($sources));
                $this->pmssAssertMessagesContain($logs, 'Applied Debian Bookworm repository config');
            } finally {
                @unlink($sources);
                @unlink($sources.'.pmss-backup');
            }
        });
    }

    /**
     * Parent directory creation failures should abort before touching sources.list.
     */
    public function testSafeWriteSourcesLogsParentDirectoryFailure(): void
    {
        $tmpDir = $this->pmssMakeTempDir('pmss-apt-parent-');
        $blocker = $tmpDir.'/blocked';
        file_put_contents($blocker, 'not-a-directory');

        $logs = [];
        $logger = $this->pmssMakeArrayLogger($logs);

        try {
            $this->pmssWithEnv(['PMSS_APT_SOURCES_PATH' => $blocker.'/sources.list'], function () use ($logger): void {
                $this->assertTrue(!\pmssSafeWriteSources("deb https://mirror.invalid bookworm main\n", 'Bookworm', $logger));
            });
            $this->pmssAssertMessagesContain($logs, '[ERROR] Unable to create parent directory for Bookworm sources.list: '.$blocker);
            $this->assertTrue(!file_exists($blocker.'/sources.list'));
            $this->assertTrue(!file_exists($blocker.'/sources.list.pmss-backup'));
        } finally {
            @unlink($blocker);
        }
    }

    /**
     * Unsupported distros should emit an informative log message.
     */
    public function testUpdateAptSourcesLogsUnsupportedDistro(): void
    {
        $logs = [];
        $logger = $this->pmssMakeArrayLogger($logs);
        \pmssUpdateAptSources('alpine', 3, 'hash', [], $logger);
        $this->pmssAssertMessagesContain($logs, 'Unsupported distro');
    }

    public function testLoadRepoTemplateLogsMissingAndEmptyTemplates(): void
    {
        $configDir = $this->pmssMakeTempDir('pmss-config-');

        try {
            $this->pmssWithEnv(['PMSS_CONFIG_DIR' => $configDir], function () use ($configDir): void {
                $logs = [];
                $logger = $this->pmssMakeArrayLogger($logs);

                $this->assertEquals('', \pmssLoadRepoTemplate('bookworm', $logger));
                $this->pmssAssertMessagesContain($logs, 'Repository template missing: ');

                $logs = [];
                file_put_contents($configDir.'/template.sources.bookworm', " \n\t ");
                $this->assertEquals('', \pmssLoadRepoTemplate('bookworm', $logger));
                $this->pmssAssertMessagesContain($logs, 'Repository template empty: ');
            });
        } finally {
            @unlink($configDir.'/template.sources.bookworm');
        }
    }

    public function testDebianReleaseSpecsExposeSharedRepositoryMetadata(): void
    {
        $specs = \pmssDebianReleaseSpecs();

        $this->assertEquals('stretch', $specs[9]['repo']);
        $this->assertEquals('Stretch', $specs[9]['label']);
        $this->assertTrue($specs[9]['eol']);
        $this->assertTrue($specs[9]['sources_template'] === false);
        $this->assertEquals('trixie', $specs[13]['repo']);
        $this->assertTrue($specs[13]['sources_template']);
        $this->assertTrue($specs[13]['eol'] === false);
    }

    /**
     * Helper to stage template directory overrides.
     */
    private function withConfigTemplates(array $templates, callable $callback): void
    {
        $dir = $this->pmssMakeTempDir('pmss-config-', 0775);
        foreach ($templates as $codename => $content) {
            file_put_contents($dir."/template.sources.$codename", $content);
        }
        try {
            $this->pmssWithEnv(['PMSS_CONFIG_DIR' => $dir], function () use ($callback, $dir): void {
                $callback($dir);
            });
        } finally {
            foreach ((glob($dir.'/template.sources.*') ?: []) as $item) {
                @unlink($item);
            }
        }
    }

}
