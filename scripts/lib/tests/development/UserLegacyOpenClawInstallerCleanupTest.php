<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/users/filesystem.php';

final class UserLegacyOpenClawInstallerCleanupTest extends TestCase
{
    private $user = 'fixtur01';
    private $home;
    private $spoolDir;
    private $legacy;

    protected function setUp(): void
    {
        $root = $this->pmssMakeTrackedHomeRoot('pmss-openclaw-home-');
        $this->home = $this->pmssEnsureDir($root.'/'.$this->user);
        $this->spoolDir = $this->pmssMakeTempDir('pmss-openclaw-spool-');
        $this->legacy = $this->home.'/install-openclaw.php';
        $this->pmssWriteFile($this->legacy, "legacy installer\n");
    }

    public function testReferencedInstallerIsKeptByteIdentical(): void
    {
        $this->pmssWriteFile($this->spoolDir.'/'.$this->user, "@reboot php \$HOME/install-openclaw.php check # pmss-openclaw\n");
        \pmssUserRemoveLegacyOpenClawInstaller($this->user, $this->home, $this->spoolDir);
        $this->assertSame("legacy installer\n", file_get_contents($this->legacy));
    }

    public function testMissingSpoolRemovesInstaller(): void
    {
        \pmssUserRemoveLegacyOpenClawInstaller($this->user, $this->home, $this->spoolDir);
        $this->assertFalse(file_exists($this->legacy));
    }

    public function testSpoolWithoutReferenceRemovesInstaller(): void
    {
        $this->pmssWriteFile($this->spoolDir.'/'.$this->user, "* * * * * echo keep\n");
        \pmssUserRemoveLegacyOpenClawInstaller($this->user, $this->home, $this->spoolDir);
        $this->assertFalse(file_exists($this->legacy));
    }

    public function testDirectorySpoolEntryKeepsInstaller(): void
    {
        mkdir($this->spoolDir.'/'.$this->user);
        \pmssUserRemoveLegacyOpenClawInstaller($this->user, $this->home, $this->spoolDir);
        $this->assertSame("legacy installer\n", file_get_contents($this->legacy));
    }

    public function testUnreadableRegularSpoolKeepsInstaller(): void
    {
        $path = $this->pmssWriteFile($this->spoolDir.'/'.$this->user, "@reboot php install-openclaw.php\n");
        chmod($path, 0000);
        try {
            if (is_readable($path)) {
                throw new SkipTest('current user can read mode-0000 files');
            }
            \pmssUserRemoveLegacyOpenClawInstaller($this->user, $this->home, $this->spoolDir);
            $this->assertSame("legacy installer\n", file_get_contents($this->legacy));
        } finally {
            chmod($path, 0600);
        }
    }

    public function testNonregularSpoolSymlinkKeepsInstaller(): void
    {
        $this->pmssCreateSymlinkOrSkip($this->spoolDir.'/missing', $this->spoolDir.'/'.$this->user);
        \pmssUserRemoveLegacyOpenClawInstaller($this->user, $this->home, $this->spoolDir);
        $this->assertSame("legacy installer\n", file_get_contents($this->legacy));
    }

    public function testOutsideSymlinkTargetIsUntouched(): void
    {
        $outside = $this->pmssWriteFile($this->pmssMakeTempFile('pmss-openclaw-outside-'), "outside\n");
        unlink($this->legacy);
        $this->pmssCreateSymlinkOrSkip($outside, $this->legacy);

        \pmssUserRemoveLegacyOpenClawInstaller($this->user, $this->home, $this->spoolDir);
        $this->assertFalse(is_link($this->legacy));
        $this->assertSame("outside\n", file_get_contents($outside));
    }
}
