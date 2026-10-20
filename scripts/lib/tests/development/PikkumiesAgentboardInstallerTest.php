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

    /** The release stays inert until both immutable pins are supplied. */
    public function testPublishedPinsAreBothUnset(): void
    {
        $this->assertSame('', \PMSS_PIKKUMIES_AGENTBOARD_VERSION);
        $this->assertSame('', \PMSS_PIKKUMIES_AGENTBOARD_SHA256);
    }
}
