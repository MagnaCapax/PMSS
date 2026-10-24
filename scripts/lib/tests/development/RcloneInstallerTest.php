<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
if (!defined('PMSS_TEST_MODE')) define('PMSS_TEST_MODE', true);
require_once dirname(__DIR__, 2).'/update/apps/rclone.php';

/** The rclone zip is installed only after its SHA-256 matches a pin or the release's SHA256SUMS. */
final class RcloneInstallerTest extends TestCase
{
    public function testPinIsAWellFormedVersionAndSha256(): void
    {
        $this->assertMatches('/\A\d+\.\d+\.\d+\z/', PMSS_RCLONE_VERSION);
        $this->assertMatches('/\A[a-f0-9]{64}\z/', PMSS_RCLONE_SHA256);
    }

    public function testChecksumIsReadFromAClearsignedSha256Sums(): void
    {
        // Lines from https://downloads.rclone.org/v1.69.1/SHA256SUMS, which rclone clearsigns.
        $sums = "-----BEGIN PGP SIGNED MESSAGE-----\nHash: SHA1\n\n"
            ."0428c2df3cd47213d40a8485218555b5e7970cdb7892d7eab79c005f79becce3  rclone-v1.69.1-freebsd-amd64.zip\n"
            ."231841f8d8029ae6cfca932b601b3b50d0e2c3c2cb9da3166293f1c3eae7d79c  rclone-v1.69.1-linux-amd64.zip\r\n"
            ."a03de8f700fcda7a1aef6b568f88d44218b698fb4e1637596c024d341bb24124  rclone-v1.69.1-linux-arm64.zip\n"
            ."-----BEGIN PGP SIGNATURE-----\n\nabc\n-----END PGP SIGNATURE-----\n";

        $this->assertSame(PMSS_RCLONE_SHA256, \pmssRcloneChecksumFromSums($sums, \pmssRcloneArchiveName('1.69.1')));
        $this->assertSame(null, \pmssRcloneChecksumFromSums($sums, 'rclone-v1.69.1-linux-386.zip'));
        $this->assertSame(null, \pmssRcloneChecksumFromSums($sums, 'rclone-v1.69.1-linux-amd64'));
        $this->assertSame(null, \pmssRcloneChecksumFromSums("231841f8  rclone-v1.69.1-linux-amd64.zip\n", 'rclone-v1.69.1-linux-amd64.zip'));
        $this->assertSame(null, \pmssRcloneChecksumFromSums('<html>not found</html>', 'rclone-v1.69.1-linux-amd64.zip'));
    }

    public function testLatestReleaseIsUsedOnlyWithItsChecksum(): void
    {
        $sha = str_repeat('b', 64);
        $none = static function (): ?string { return null; };
        $newer = static function (): ?string { return '1.70.3'; };
        $pinnedVersion = static function (): ?string { return PMSS_RCLONE_VERSION; };
        $checksum = static function (string $version) use ($sha): ?string { return $version === '1.70.3' ? $sha : null; };
        $unexpected = function (string $version): ?string { $this->fail('no SHA256SUMS fetch for '.$version); return null; };
        $pinned = [PMSS_RCLONE_VERSION, PMSS_RCLONE_SHA256];

        $this->assertSame($pinned, \pmssRcloneTargetRelease(false, $newer, $unexpected));
        [$release, $out] = $this->pmssCaptureStdout(static function () use ($none, $unexpected): array { return \pmssRcloneTargetRelease(true, $none, $unexpected); });
        $this->assertSame($pinned, $release);
        $this->assertStringContainsString('Unable to determine latest rclone version', $out);
        [$release] = $this->pmssCaptureStdout(static function () use ($pinnedVersion, $unexpected): array { return \pmssRcloneTargetRelease(true, $pinnedVersion, $unexpected); });
        $this->assertSame($pinned, $release, 'latest equal to the pin keeps the pinned checksum');
        [$release, $out] = $this->pmssCaptureStdout(static function () use ($newer, $none): array { return \pmssRcloneTargetRelease(true, $newer, $none); });
        $this->assertSame($pinned, $release, 'a release without a readable checksum is never installed');
        $this->assertStringContainsString('Unable to read the SHA-256 for rclone 1.70.3, falling back to pinned release.', $out);
        [$release] = $this->pmssCaptureStdout(static function () use ($newer, $checksum): array { return \pmssRcloneTargetRelease(true, $newer, $checksum); });
        $this->assertSame(['1.70.3', $sha], $release);
    }

    public function testInstallUnpacksTheVerifiedDownloadOnly(): void
    {
        $this->pmssResetRuntimeProfile();
        $this->pmssCaptureStdout(static function (): void {
            \pmssRcloneInstallFromZip('1.69.1', PMSS_RCLONE_SHA256);
            \pmssRcloneInstallFromZip('1.69.2', 'not-a-sha256');
            \pmssRcloneInstallFromZip('1.69.1; id', PMSS_RCLONE_SHA256);
        }, ['PMSS_DRY_RUN' => '1']);

        $commands = $this->pmssProfileCommands();
        $this->assertSame(2, count($commands), implode(' | ', $commands));
        $this->assertMatches("~\Awget '-q' '-O' '([^']+)' 'https://downloads\.rclone\.org/v1\.69\.1/rclone-v1\.69\.1-linux-amd64\.zip'\z~", $commands[0]);
        preg_match("~'-O' '([^']+)'~", $commands[0], $download);
        $install = (string) $this->pmssFindProfileCommand('Installing rclone 1.69.1');
        $this->assertStringContainsString("unzip '-q' '".$download[1]."'", $install);
        $this->assertStringContainsString("install '-m' '0755' '-o' 'root' '-g' 'root' 'rclone' '/usr/bin/rclone'", $install);
        $this->assertStringNotContainsString('wget', $install);
    }
}
