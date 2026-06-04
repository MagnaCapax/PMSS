<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../common/updateBootstrapShim.php';
require_once dirname(__DIR__, 2).'/update/distro.php';
require_once dirname(__DIR__, 2).'/update/repositories.php';
require_once dirname(__DIR__, 2).'/update/apt.php';

class DistroDetectionTest extends TestCase
{
    public function testVersionFromCodenameMapsKnownReleases(): void
    {
        foreach ([
            'jessie' => 8,
            'stretch' => 9,
            'buster' => 10,
            'bullseye' => 11,
            'bookworm' => 12,
            'trixie' => 13,
            'unknown' => 0,
            'Bullseye' => 11,
            'BULLSEYE' => 11,
        ] as $codename => $version) {
            $this->assertEquals($version, \pmssVersionFromCodename($codename), 'Unexpected version for '.$codename);
        }
    }

    public function testDebianCodenameFromMajorMapsKnownReleases(): void
    {
        foreach ([
            8 => 'jessie',
            9 => 'stretch',
            10 => 'buster',
            11 => 'bullseye',
            12 => 'bookworm',
            13 => 'trixie',
            99 => '',
        ] as $version => $codename) {
            $this->assertEquals($codename, \pmssDebianCodenameFromMajor($version), 'Unexpected codename for Debian '.$version);
        }
    }

    public function testStandaloneDistroLibraryStillBootstrapsLegacyLogmsg(): void
    {
        $source = trim($this->pmssRunRepoInlinePhpRequire('scripts/lib/update/distro.php', '$function = new ReflectionFunction("logmsg"); echo str_replace("\\\\", "/", $function->getFileName());', ['PMSS_TEST_MODE' => '1']));

        $this->assertStringContainsString('/scripts/lib/log.php', $source);
    }

    /**
     * Ensure codename mapping overrides a mismatched VERSION_ID.
     */
    public function testDetectPrefersCodenameWhenVersionMismatches(): void
    {
        foreach ([
            ['versionId' => '11', 'codename' => 'bookworm', 'expectedVersion' => 12],
            ['versionId' => '10', 'codename' => 'bullseye', 'expectedVersion' => 11],
        ] as $case) {
            $this->pmssAssertDetectedDistro([
                'ID'                => 'debian',
                'VERSION_ID'        => $case['versionId'],
                'VERSION_CODENAME'  => $case['codename'],
            ], 'debian', $case['expectedVersion'], $case['codename']);
        }
    }

    /**
     * Verify VERSION_ID kicks in when the codename is absent.
     */
    public function testDetectFallsBackToVersionId(): void
    {
        $this->pmssAssertDetectedDistro([
            'ID'         => 'debian',
            'VERSION_ID' => '11',
        ], 'debian', 11, '', true);
    }

    /**
     * Confirm codename case is normalised prior to mapping.
     */
    public function testDetectNormalisesCodenameCase(): void
    {
        $this->pmssAssertDetectedDistro([
            'ID'                => 'Debian',
            'VERSION_CODENAME'  => 'BULLSEYE',
            'VERSION_ID'        => '',
        ], 'debian', 11, 'bullseye');
    }

    public function testDetectHandlesCodenameAndVersionEdgeCases(): void
    {
        foreach ([
            'trimmed codename' => [
                ['ID' => 'debian', 'VERSION_ID' => '13', 'VERSION_CODENAME' => '  trixie  '],
                13,
                'trixie',
                false,
            ],
            'unknown codename keeps version' => [
                ['ID' => 'debian', 'VERSION_ID' => '77', 'VERSION_CODENAME' => 'aurora'],
                77,
                'aurora',
                false,
            ],
            'missing version signals' => [
                ['ID' => 'debian'],
                0,
                '',
                true,
            ],
            'messy VERSION_ID' => [
                ['ID' => 'debian', 'VERSION_ID' => '12 (testing snapshot)'],
                12,
                '',
                true,
            ],
        ] as $label => $case) {
            $this->pmssAssertDetectedDistro($case[0], 'debian', $case[1], $case[2], $case[3]);
        }
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
        $initial = "deb https://old.invalid stable main\n";
        $template = "deb https://mirror.invalid bookworm main\n";
        $this->pmssWithRepoTemplates(['bookworm' => $template], function () use ($initial, $template): void {
            $logs = [];
            $logger = $this->pmssMakeArrayLogger($logs);

            $this->pmssWithTempAptSources($initial, function (string $sources) use ($initial, $template, $logger, &$logs): void {
                \pmssUpdateAptSources('debian', 12, sha1('different'), $this->pmssDebianRepoTemplates([
                    'bookworm' => $template,
                ]), $logger);
                $this->assertEquals($template, file_get_contents($sources));
                $this->assertEquals($initial, file_get_contents($sources.'.pmss-backup'));
                $this->pmssAssertMessagesContain($logs, 'Applied Debian Bookworm repository config');
            });
        });
    }

    public function testUpdateAptSourcesEolSuiteLogsTestModeSkip(): void
    {
        $initial = "deb http://mirror.invalid bullseye main\n";
        $template = "deb http://mirror.example buster main\n";
        $this->pmssWithTempAptSources($initial, function (string $target) use ($initial, $template): void {
            $logs = [];
            $logger = $this->pmssMakeArrayLogger($logs);

            \pmssUpdateAptSources('debian', 10, sha1($initial), $this->pmssDebianRepoTemplates([
                'buster' => $template,
            ]), $logger);

            $this->assertEquals($template, file_get_contents($target));
            $this->pmssAssertMessagesContain($logs, 'PMSS_TEST_MODE: skipping apt conf/clean (Buster)', 'Expected EOL post-hook to log test-mode skip');
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
            $this->pmssWithAptSourcesPath($blocker.'/sources.list', function () use ($logger): void {
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

    public function testDetectDistroResetCacheSwitchesFiles(): void
    {
        $first = $this->pmssWriteTempFile('os-release', $this->pmssRenderOsRelease([
            'ID' => 'debian',
            'VERSION_ID' => '11',
            'VERSION_CODENAME' => 'bullseye',
        ]));
        $second = $this->pmssWriteTempFile('os-release', $this->pmssRenderOsRelease([
            'ID' => 'debian',
            'VERSION_ID' => '12',
            'VERSION_CODENAME' => 'bookworm',
        ]));

        $this->pmssWithEnv(['PMSS_OS_RELEASE_PATH' => $first], function () use ($second): void {
            \pmssResetOsReleaseCache();
            $firstInfo = \pmssDetectDistro();
            $this->assertEquals(11, $firstInfo['version']);

            $this->pmssWithEnv(['PMSS_OS_RELEASE_PATH' => $second], function (): void {
                \pmssResetOsReleaseCache();
                $secondInfo = \pmssDetectDistro();
                $this->assertEquals(12, $secondInfo['version']);
            });
        });
    }

}
