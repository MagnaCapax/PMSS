<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
if (!defined('PMSS_TEST_MODE')) define('PMSS_TEST_MODE', true);
require_once dirname(__DIR__, 2).'/update/apps/pikkumiesAgentboard.php';

final class PikkumiesAgentboardInstallerTest extends TestCase
{
    /** A local archive exercises checksum verification and atomic publication. */
    public function testVerifiedArchiveInstallsAndBadChecksumDoesNotPublish(): void
    {
        $temp = $this->pmssMakeTempDir('pmss-agentboard-');
        $source = $temp.'/source';
        $root = $temp.'/installed';
        mkdir($source);
        file_put_contents($source.'/agentboard', "#!/bin/sh\nexit 0\n");
        $archive = $temp.'/agentboard.tar.gz';
        exec('tar -czf '.escapeshellarg($archive).' -C '.escapeshellarg($source).' agentboard', $output, $rc);
        $this->assertSame(0, $rc);
        $sha256 = hash_file('sha256', $archive);
        $ownerUid = posix_geteuid();

        $this->assertFalse(\pmssPikkumiesAgentboardArchiveInstall($archive, 'v1', str_repeat('0', 64), $root, $ownerUid));
        $this->assertFalse(file_exists($root));
        $this->assertTrue(\pmssPikkumiesAgentboardArchiveInstall($archive, 'v1', $sha256, $root, $ownerUid));
        $this->assertSame('v1/agentboard', readlink($root.'/agentboard'));
        $this->assertSame(file_get_contents($source.'/agentboard'), file_get_contents($root.'/agentboard'));
        $this->assertTrue(\pmssPikkumiesAgentboardArchiveInstall($archive, 'v1', $sha256, $root, $ownerUid));
        $this->assertSame('v1/agentboard', readlink($root.'/agentboard'));
        $this->assertFalse(\pmssPikkumiesAgentboardArchiveInstall($archive, '../v2', $sha256, $root, $ownerUid));
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
        $source = $temp.'/source';
        $root = $temp.'/installed';
        mkdir($source);
        mkdir($root);
        mkdir($root.'/v2');
        file_put_contents($source.'/agentboard', "#!/bin/sh\nexit 0\n");
        file_put_contents($root.'/v2/agentboard', 'stale');
        $archive = $temp.'/agentboard.tar.gz';
        exec('tar -czf '.escapeshellarg($archive).' -C '.escapeshellarg($source).' agentboard', $output, $rc);
        $this->assertSame(0, $rc);
        $before = umask(0077);
        try {
            $this->assertTrue(\pmssPikkumiesAgentboardArchiveInstall($archive, 'v2', hash_file('sha256', $archive), $root, posix_geteuid()));
            $this->assertSame(0077, umask());
        } finally {
            umask($before);
        }
        $this->assertSame(file_get_contents($source.'/agentboard'), file_get_contents($root.'/v2/agentboard'));
        $this->assertSame(0700, fileperms($root.'/.quarantine') & 0777);
        $retired = glob($root.'/.quarantine/v2-*');
        $this->assertSame(1, count($retired));
        $this->assertSame('stale', file_get_contents($retired[0].'/agentboard'));
    }
}
