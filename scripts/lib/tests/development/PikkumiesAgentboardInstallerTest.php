<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
if (!defined('PMSS_TEST_MODE')) define('PMSS_TEST_MODE', true);
require_once dirname(__DIR__, 2).'/update/apps/pikkumiesAgentboard.php';

final class PikkumiesAgentboardInstallerTest extends TestCase
{
    /** Build a real release archive from a test-owned source tree. */
    private function archive(string $temp, string $name, bool $index = true, string $extra = ''): string
    {
        $source = $temp.'/source-'.$name;
        mkdir($source.'/dist/client', 0755, true);
        file_put_contents($source.'/agentboard', "#!/bin/sh\nexit 0\n");
        if ($index) file_put_contents($source.'/dist/client/index.html', '<h1>Agentboard</h1>');
        file_put_contents($source.'/dist/client/app.js', 'console.log(1);');
        if ($extra === 'symlink') symlink('/etc/passwd', $source.'/dist/client/escape');
        if ($extra === 'hardlink') link($source.'/dist/client/app.js', $source.'/dist/client/extra.js');
        $archive = $temp.'/'.$name.'.tar.gz';
        $transform = $extra === 'traversal' ? " --transform='s|^dist/client/app.js$|../escape|'" : '';
        $this->pmssCreateTarGzArchive($archive, $source, ['agentboard', 'dist/client'], $transform);
        return $archive;
    }

    /** A local archive exercises checksum verification and atomic publication. */
    public function testVerifiedArchiveInstallsAndBadChecksumDoesNotPublish(): void
    {
        $temp = $this->pmssMakeTempDir('pmss-agentboard-');
        $root = $temp.'/installed';
        $archive = $this->archive($temp, 'good');
        $sha256 = hash_file('sha256', $archive);
        $ownerUid = posix_geteuid();

        $this->assertFalse(\pmssPikkumiesAgentboardArchiveInstall($archive, 'v1', str_repeat('0', 64), $root, $ownerUid));
        $this->assertFalse(file_exists($root));
        $before = umask(0077);
        try {
            $this->assertTrue(\pmssPikkumiesAgentboardArchiveInstall($archive, 'v1', $sha256, $root, $ownerUid));
        } finally {
            umask($before);
        }
        $this->assertSame(0755, fileperms($root) & 0777);
        $this->assertSame('v1/agentboard', readlink($root.'/agentboard'));
        $this->assertSame("#!/bin/sh\nexit 0\n", file_get_contents($root.'/agentboard'));
        $this->assertSame('<h1>Agentboard</h1>', file_get_contents($root.'/v1/dist/client/index.html'));
        $this->assertSame(0755, fileperms($root.'/v1/dist/client') & 0777);
        $this->assertSame(0644, fileperms($root.'/v1/dist/client/app.js') & 0777);
        $inode = fileinode($root.'/v1');
        $this->assertTrue(\pmssPikkumiesAgentboardArchiveInstall($archive, 'v1', $sha256, $root, $ownerUid));
        $this->assertSame('v1/agentboard', readlink($root.'/agentboard'));
        $this->assertSame($inode, fileinode($root.'/v1'));
        $this->assertFalse(\pmssPikkumiesAgentboardArchiveInstall($archive, '../v2', $sha256, $root, $ownerUid));
    }

    /** Invalid archive entries never replace a working release or leave extraction stages. */
    public function testUnsafeArchivesDoNotSwitch(): void
    {
        $temp = $this->pmssMakeTempDir('pmss-agentboard-unsafe-');
        $root = $temp.'/installed';
        $good = $this->archive($temp, 'good');
        $uid = posix_geteuid();
        $this->assertTrue(\pmssPikkumiesAgentboardArchiveInstall($good, 'v1', hash_file('sha256', $good), $root, $uid));
        foreach (['symlink', 'hardlink', 'traversal', 'missing-index'] as $case) {
            $archive = $this->archive($temp, $case, $case !== 'missing-index', $case);
            $this->assertFalse(\pmssPikkumiesAgentboardArchiveInstall($archive, 'v2', hash_file('sha256', $archive), $root, $uid), $case);
            $this->assertSame('v1/agentboard', readlink($root.'/agentboard'));
            $this->assertFalse(file_exists($root.'/v2'));
            $this->assertSame([], glob($root.'/.stage-*'));
        }
        $this->assertFalse(\pmssPikkumiesAgentboardArchiveInstall($good, 'v2', str_repeat('0', 64), $root, $uid));
        $this->assertSame('v1/agentboard', readlink($root.'/agentboard'));
    }

    /** Link entries are refused before an extraction directory is made. */
    public function testLinkEntriesAreRefusedBeforeExtraction(): void
    {
        $temp = $this->pmssMakeTempDir('pmss-agentboard-links-');
        $root = $temp.'/installed';
        foreach (['symlink', 'hardlink'] as $case) {
            $archive = $this->archive($temp, $case, true, $case);
            $this->assertFalse(\pmssPikkumiesAgentboardArchiveMembersSafe($archive), $case);
            $this->assertFalse(\pmssPikkumiesAgentboardArchiveInstall(
                $archive, 'v1', hash_file('sha256', $archive), $root, posix_geteuid()
            ), $case);
            $this->assertSame([], glob($root.'/.stage-*'));
            $this->assertFalse(file_exists($root.'/v1'));
        }
    }

    /** A release cannot activate with a version and no verified checksum. */
    public function testReleasePinsAreCompleteTogether(): void
    {
        if (\PMSS_PIKKUMIES_AGENTBOARD_VERSION === '') {
            $this->assertSame('', \PMSS_PIKKUMIES_AGENTBOARD_SHA256);
        } else {
            $this->assertTrue(preg_match('/^[a-f0-9]{64}$/iD', \PMSS_PIKKUMIES_AGENTBOARD_SHA256) === 1);
        }
    }

    public function testHostMarkerGateRejectsAbsentAndLinkedFiles(): void
    {
        $root = $this->pmssMakeTempDir('pmss-agentboard-gate-');
        $marker = $root.'/enabled';
        $this->assertFalse(\pmssPikkumiesAgentboardHostEnabled($marker));
        file_put_contents($marker, '');
        $this->assertTrue(\pmssPikkumiesAgentboardHostEnabled($marker));
        unlink($marker);
        symlink($root.'/missing', $marker);
        $this->assertFalse(\pmssPikkumiesAgentboardHostEnabled($marker));
    }

    public function testInvalidExistingVersionIsQuarantinedAndReplaced(): void
    {
        $temp = $this->pmssMakeTempDir('pmss-agentboard-refresh-');
        $root = $temp.'/installed';
        mkdir($root);
        mkdir($root.'/v2');
        file_put_contents($root.'/v2/agentboard', 'stale');
        $archive = $this->archive($temp, 'refresh');
        $before = umask(0077);
        try {
            $this->assertTrue(\pmssPikkumiesAgentboardArchiveInstall($archive, 'v2', hash_file('sha256', $archive), $root, posix_geteuid()));
            $this->assertSame(0077, umask());
        } finally {
            umask($before);
        }
        $this->assertSame("#!/bin/sh\nexit 0\n", file_get_contents($root.'/v2/agentboard'));
        $this->assertSame('<h1>Agentboard</h1>', file_get_contents($root.'/v2/dist/client/index.html'));
        $this->assertSame(0700, fileperms($root.'/.quarantine') & 0777);
        $retired = glob($root.'/.quarantine/v2-*');
        $this->assertSame(1, count($retired));
        $this->assertSame('stale', file_get_contents($retired[0].'/agentboard'));
    }

    public function testChangedFrontendRefreshesSameVersion(): void
    {
        $temp = $this->pmssMakeTempDir('pmss-agentboard-client-');
        $root = $temp.'/installed';
        $archive = $this->archive($temp, 'client');
        $uid = posix_geteuid();
        $sha = hash_file('sha256', $archive);
        $this->assertTrue(\pmssPikkumiesAgentboardArchiveInstall($archive, 'v1', $sha, $root, $uid));
        file_put_contents($root.'/v1/dist/client/app.js', 'changed');
        $this->assertTrue(\pmssPikkumiesAgentboardArchiveInstall($archive, 'v1', $sha, $root, $uid));
        $this->assertSame('console.log(1);', file_get_contents($root.'/v1/dist/client/app.js'));
        $this->assertSame(1, count(glob($root.'/.quarantine/v1-*')));
    }

    /** An unchanged published release skips the download; any change or a new pin downloads again. */
    public function testInstalledReleaseIsDetectedOnlyWhenUnchanged(): void
    {
        $temp = $this->pmssMakeTempDir('pmss-agentboard-installed-');
        $root = $temp.'/installed';
        $archive = $this->archive($temp, 'stamp');
        $uid = posix_geteuid();
        $sha = hash_file('sha256', $archive);
        $this->assertFalse(\pmssPikkumiesAgentboardInstalled('v1', $sha, $root, $uid));
        $this->assertTrue(\pmssPikkumiesAgentboardArchiveInstall($archive, 'v1', $sha, $root, $uid));
        $this->assertTrue(\pmssPikkumiesAgentboardInstalled('v1', $sha, $root, $uid));
        $this->assertFalse(\pmssPikkumiesAgentboardInstalled('v1', str_repeat('a', 64), $root, $uid), 'new pin');
        $this->assertFalse(\pmssPikkumiesAgentboardInstalled('v2', $sha, $root, $uid), 'other version');
        $this->assertFalse(\pmssPikkumiesAgentboardInstalled('v1', $sha, $root, $uid + 1), 'other owner');
        file_put_contents($root.'/v1/dist/client/app.js', 'changed');
        $this->assertFalse(\pmssPikkumiesAgentboardInstalled('v1', $sha, $root, $uid), 'changed content');
        $this->assertTrue(\pmssPikkumiesAgentboardArchiveInstall($archive, 'v1', $sha, $root, $uid));
        $this->assertTrue(\pmssPikkumiesAgentboardInstalled('v1', $sha, $root, $uid));
        unlink($root.'/agentboard');
        $this->assertFalse(\pmssPikkumiesAgentboardInstalled('v1', $sha, $root, $uid), 'link removed');
        symlink('v1/agentboard', $root.'/agentboard');
        unlink($root.'/.installed-v1');
        symlink($temp.'/stamp.tar.gz', $root.'/.installed-v1');
        $this->assertFalse(\pmssPikkumiesAgentboardInstalled('v1', $sha, $root, $uid), 'linked stamp');
    }

    public function testWrongOwnerVersionIsNotTrusted(): void
    {
        $root = $this->pmssMakeTempDir('pmss-agentboard-owner-');
        $version = $root.'/v3';
        mkdir($version);
        $uid = posix_geteuid();
        $this->assertTrue(\pmssPikkumiesAgentboardVersionOwned($version, $uid));
        $this->assertFalse(\pmssPikkumiesAgentboardVersionOwned($version, $uid + 1));
        symlink($version, $root.'/linked');
        $this->assertFalse(\pmssPikkumiesAgentboardVersionOwned($root.'/linked', $uid));
    }
}
