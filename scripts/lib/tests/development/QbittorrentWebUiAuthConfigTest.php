<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/qbittorrentWebUiAuth.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/qbittorrentPasswordRequired.php';

final class QbittorrentWebUiAuthConfigTest extends TestCase
{
    /** Repeated settings and sections must settle after one transform. */
    public function testEveryCopyConvergesInOnePass(): void
    {
        $config = "[Preferences]\nWebUI\\LocalHostAuth=false\nWebUI\\LocalHostAuth=false\nWebUI\\AuthSubnetWhitelistEnabled=true\n[Other]\nx=1\n[Preferences]\nWebUI\\LocalHostAuth=false\nWebUI\\AuthSubnetWhitelistEnabled=true\n";
        $forced = \pmssQbittorrentWebUiAuthForce($config);
        $this->assertSame(0, substr_count($forced, 'WebUI\\LocalHostAuth=false'));
        $this->assertSame(3, substr_count($forced, 'WebUI\\LocalHostAuth=true'));
        $this->assertSame(2, substr_count($forced, 'WebUI\\AuthSubnetWhitelistEnabled=false'));
        $this->assertSame($forced, \pmssQbittorrentWebUiAuthForce($forced));
    }

    /** A stored value is an account-owned setting and remains untouched. */
    public function testExistingPasswordIsPreserved(): void
    {
        $config = "[Preferences]\nWebUI\\Password_PBKDF2=@ByteArray(existing)\n";
        $forced = \pmssQbittorrentWebUiAuthForce($config);
        $this->assertStringContainsString('WebUI\\Password_PBKDF2=@ByteArray(existing)', $forced);
        $this->assertSame(1, substr_count($forced, 'WebUI\\Password_PBKDF2='));
    }

    /** A missing value gets the same fixed marker on every pass. */
    public function testMissingPasswordGetsOneStableSentinel(): void
    {
        $forced = \pmssQbittorrentWebUiAuthForce("[Preferences]\n");
        $this->assertStringContainsString('WebUI\\Password_PBKDF2='.\pmssQbittorrentWebUiAuthSentinel(), $forced);
        $this->assertSame(1, substr_count($forced, 'WebUI\\Password_PBKDF2='));
        $this->assertSame($forced, \pmssQbittorrentWebUiAuthForce($forced));
    }

    /** New sections retain the input's newline convention. */
    public function testMissingSectionAndCrLfPreserveShape(): void
    {
        $forced = \pmssQbittorrentWebUiAuthForce("[Other]\r\nx=1\r\n");
        $this->assertStringContainsString("[Preferences]\r\nWebUI\\LocalHostAuth=true\r\n", $forced);
        $this->assertSame($forced, \pmssQbittorrentWebUiAuthForce($forced));
    }

    /** Only a Preferences value satisfies the daemon's password field. */
    public function testPasswordOutsidePreferencesDoesNotCount(): void
    {
        $forced = \pmssQbittorrentWebUiAuthForce("[Other]\nWebUI\\Password_PBKDF2=@ByteArray(unrelated)\n[Preferences]\n");
        $this->assertSame(2, substr_count($forced, 'WebUI\\Password_PBKDF2='));
        $this->assertStringContainsString('WebUI\\Password_PBKDF2='.\pmssQbittorrentWebUiAuthSentinel(), $forced);
    }

    /** The empty-file case must not prepend a blank line. */
    public function testEmptyConfigStartsAtPreferencesHeader(): void
    {
        $forced = \pmssQbittorrentWebUiAuthForce('');
        $this->assertSame(0, strpos($forced, '[Preferences]'));
        $this->assertSame($forced, \pmssQbittorrentWebUiAuthForce($forced));
    }

    /** The panel distinguishes the marker from an owner-set value. */
    public function testFrontendRequestsReplacementForSentinelAndMissingValue(): void
    {
        $path = $this->pmssMakeTempPath('pmss-qbt-front-', '.conf');
        $callbacks = self::pmssMakeCallbacks(['safe' => true, 'unsafe' => false]);
        $safe = $callbacks['safe'];
        $this->pmssWriteFile($path, "[Preferences]\n");
        $this->assertTrue(\pmssQbittorrentFrontendPasswordRequired($path, $safe));
        $this->pmssWriteFile($path, \pmssQbittorrentWebUiAuthForce("[Preferences]\n"));
        $this->assertTrue(\pmssQbittorrentFrontendPasswordRequired($path, $safe));
        $this->pmssWriteFile($path, "[Preferences]\nWebUI\\Password_PBKDF2=@ByteArray(existing)\n");
        $this->assertFalse(\pmssQbittorrentFrontendPasswordRequired($path, $safe));
        $this->assertFalse(\pmssQbittorrentFrontendPasswordRequired($path, $callbacks['unsafe']));
    }

    /** The rollout marker is the first check, before file access. */
    public function testMarkerAbsentDoesNoWork(): void
    {
        $read = 0;
        $this->assertFalse(\pmssQbittorrentWebUiAuthEnsure('alice', '/tmp/pmss-qbt-test/alice', [
            'read' => static function () use (&$read) { $read++; return ''; },
        ] + self::pmssMakeCallbacks(['enabled' => false])));
        $this->assertSame(0, $read);
    }

    /** A path the writer refuses must never reach the differ. */
    public function testWriterRefusedPathDoesNoReadOrStop(): void
    {
        $root = $this->pmssMakeTempDir('pmss-qbt-link-');
        $home = $root.'/alice';
        $target = $root.'/config';
        $this->pmssEnsureDir($home);
        $this->pmssEnsureDir($target.'/qBittorrent');
        @symlink($target, $home.'/.config');
        $reads = 0;
        $this->assertFalse(\pmssQbittorrentWebUiAuthEnsure('alice', $home, [
            'read' => static function () use (&$reads) { $reads++; return ''; },
        ] + self::pmssMakeCallbacks(['enabled' => true, 'contextSafe' => true])));
        $this->assertSame(0, $reads);
    }
}
