<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/log.php';
require_once dirname(__DIR__, 2).'/update/arrRootConfigHardening.php';

/**
 * Root-side ARR configs must never present the *arr first-run defaults
 * (AuthenticationMethod=None, BindAddress=*) that produced the 2026-07-03
 * root compromise. Hermetic: every path is a temp directory.
 */
class ArrRootConfigHardeningTest extends TestCase
{
    /** Collect log lines while running the convergence over temp roots. */
    private function harden(string $configRoot, string $installRoot): array
    {
        $messages = [];
        \pmssEnsureArrRootConfigHardening(static function (string $message) use (&$messages): void {
            $messages[] = $message;
        }, $configRoot, $installRoot);
        return $messages;
    }

    public function testSeedsHardenedConfigWhereAnAccidentalRootLaunchIsPossible(): void
    {
        $configRoot = $this->pmssMakeTempDir('pmss-arr-cfg-', 0700);
        $installRoot = $this->pmssMakeTempDir('pmss-arr-opt-', 0700);
        @mkdir($installRoot.'/Radarr', 0755, true);

        $this->harden($configRoot, $installRoot);

        $path = $configRoot.'/Radarr/config.xml';
        $this->assertTrue(is_file($path), 'installed app should get a pre-seeded hardened config');
        $xml = (string) @file_get_contents($path);
        $this->assertStringContainsAndOmitsStrings([
            '<BindAddress>127.0.0.1</BindAddress>',
            '<AuthenticationMethod>Forms</AuthenticationMethod>',
            '<AuthenticationRequired>Enabled</AuthenticationRequired>',
            \PMSS_ARR_ROOT_CONFIG_MARKER,
        ], [
            '<BindAddress>*</BindAddress>' => 'root ARR config must never bind the wildcard address',
            '<AuthenticationMethod>None</AuthenticationMethod>' => 'root ARR config must never disable authentication',
        ], $xml);
        $this->assertSame(1, preg_match('#<ApiKey>[a-f0-9]{32}</ApiKey>#', $xml), 'a random API credential must be seeded');
        $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
    }

    public function testRepairsResidueLeftBehindByAPastAccidentalRootLaunch(): void
    {
        $configRoot = $this->pmssMakeTempDir('pmss-arr-cfg-', 0700);
        $installRoot = $this->pmssMakeTempDir('pmss-arr-opt-', 0700);
        // Residue exists but the app is NOT installed: repair must still happen.
        @mkdir($configRoot.'/Sonarr', 0700, true);
        @file_put_contents($configRoot.'/Sonarr/config.xml', "<Config>\n"
            ."  <BindAddress>*</BindAddress>\n"
            ."  <Port>8989</Port>\n"
            ."  <AuthenticationMethod>None</AuthenticationMethod>\n"
            ."  <ApiKey>deadbeefdeadbeefdeadbeefdeadbeef</ApiKey>\n"
            .'</Config>');

        $this->harden($configRoot, $installRoot);

        $xml = (string) @file_get_contents($configRoot.'/Sonarr/config.xml');
        $this->assertStringContainsAndOmitsStrings([
            '<BindAddress>127.0.0.1</BindAddress>',
            '<AuthenticationMethod>Forms</AuthenticationMethod>',
            '<AuthenticationRequired>Enabled</AuthenticationRequired>',
            '<Port>8989</Port>',
            // A strong existing key is preserved, keeping repeated updates idempotent.
            '<ApiKey>deadbeefdeadbeefdeadbeefdeadbeef</ApiKey>',
        ], [
            '<BindAddress>*</BindAddress>' => 'wildcard bind must be repaired',
            '<AuthenticationMethod>None</AuthenticationMethod>' => 'disabled authentication must be repaired',
        ], $xml);
    }

    public function testSkipsAppsThatAreNeitherInstalledNorPreviouslyRootRun(): void
    {
        $configRoot = $this->pmssMakeTempDir('pmss-arr-cfg-', 0700);
        $installRoot = $this->pmssMakeTempDir('pmss-arr-opt-', 0700);

        $this->harden($configRoot, $installRoot);

        foreach (array_keys(\PMSS_ARR_APP_BRANCHES) as $app) {
            $this->assertFalse(is_dir($configRoot.'/'.$app), 'must not create root config dirs for absent apps');
        }
    }

    public function testLeavesUnrecognisedPayloadsUntouchedInsteadOfDestroyingThem(): void
    {
        $configRoot = $this->pmssMakeTempDir('pmss-arr-cfg-', 0700);
        $installRoot = $this->pmssMakeTempDir('pmss-arr-opt-', 0700);
        @mkdir($configRoot.'/Lidarr', 0700, true);
        @file_put_contents($configRoot.'/Lidarr/config.xml', 'not-xml-at-all');

        $messages = $this->harden($configRoot, $installRoot);

        $this->assertSame('not-xml-at-all', (string) @file_get_contents($configRoot.'/Lidarr/config.xml'));
        $this->assertStringContainsString('unrecognised', implode("\n", $messages));
    }

    public function testRefusesToFollowSymlinkedRootConfigPaths(): void
    {
        $configRoot = $this->pmssMakeTempDir('pmss-arr-cfg-', 0700);
        $installRoot = $this->pmssMakeTempDir('pmss-arr-opt-', 0700);
        @mkdir($installRoot.'/Prowlarr', 0755, true);
        $outside = $this->pmssMakeTempDir('pmss-arr-outside-', 0700);
        @file_put_contents($outside.'/config.xml', 'original');
        $this->pmssCreateSymlinkOrSkip($outside, $configRoot.'/Prowlarr');

        $messages = $this->harden($configRoot, $installRoot);

        $this->assertSame('original', (string) @file_get_contents($outside.'/config.xml'));
        $this->assertStringContainsString('symlinked', implode("\n", $messages));
    }

    public function testConvergenceIsIdempotentAcrossRepeatedUpdates(): void
    {
        $configRoot = $this->pmssMakeTempDir('pmss-arr-cfg-', 0700);
        $installRoot = $this->pmssMakeTempDir('pmss-arr-opt-', 0700);
        @mkdir($installRoot.'/Readarr', 0755, true);

        $this->harden($configRoot, $installRoot);
        $first = (string) @file_get_contents($configRoot.'/Readarr/config.xml');
        $this->harden($configRoot, $installRoot);

        $this->assertSame($first, (string) @file_get_contents($configRoot.'/Readarr/config.xml'));
    }

    public function testElementSetReplacesExistingValuesAndAppendsMissingOnes(): void
    {
        $this->assertStringContainsString(
            '<BindAddress>127.0.0.1</BindAddress>',
            \pmssArrRootConfigElementSet("<Config>\n  <BindAddress>*</BindAddress>\n</Config>", 'BindAddress', '127.0.0.1')
        );
        $this->assertStringContainsString(
            '<AuthenticationRequired>Enabled</AuthenticationRequired>',
            \pmssArrRootConfigElementSet("<Config>\n</Config>", 'AuthenticationRequired', 'Enabled')
        );
        // A self-closing document is normalized so elements can still be applied.
        $this->assertStringContainsString('<BindAddress>127.0.0.1</BindAddress>', (string) \pmssArrRootConfigHarden('<Config />'));
        // Non-config payloads are rejected rather than rewritten.
        $this->assertSame(null, \pmssArrRootConfigHarden('<html><body>hello</body></html>'));
    }

    public function testSeededCredentialsAreRandomPerConfig(): void
    {
        $keys = [];
        foreach ([0, 1] as $ignored) {
            $configRoot = $this->pmssMakeTempDir('pmss-arr-cfg-', 0700);
            $installRoot = $this->pmssMakeTempDir('pmss-arr-opt-', 0700);
            @mkdir($installRoot.'/Radarr', 0755, true);
            $this->harden($configRoot, $installRoot);
            preg_match('#<ApiKey>([a-f0-9]{32})</ApiKey>#', (string) @file_get_contents($configRoot.'/Radarr/config.xml'), $match);
            $keys[] = $match[1] ?? '';
        }

        $this->assertTrue($keys[0] !== '' && $keys[0] !== $keys[1], 'each seeded config must get its own random credential');
    }
}
