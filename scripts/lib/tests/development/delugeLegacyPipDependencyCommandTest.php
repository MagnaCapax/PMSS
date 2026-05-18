<?php
namespace PMSS\Tests;

require_once __DIR__.'/DelugeAppTestCase.php';

class DelugeLegacyPipDependencyCommandTest extends DelugeAppTestCase
{
    /**
     * Ensure Debian 10 dependency command keeps pyasn1 pinned.
     */
    public function testCommandIncludesPinnedPyasn1Requirement(): void
    {
        $command = \pmssBuildCommand('pip', array_merge(['install'], \pmssDelugeLegacyPipDependencyPackages()));

        $this->assertTrue(strpos($command, "'pyasn1==0.4.6'") !== false);
    }

    /**
     * Ensure dependency command does not request global package upgrades.
     */
    public function testCommandDoesNotUseUpgradeFlag(): void
    {
        $command = \pmssBuildCommand('pip', array_merge(['install'], \pmssDelugeLegacyPipDependencyPackages()));

        $this->assertTrue(strpos($command, "'--upgrade'") === false);
    }

    /**
     * Keep dependency list deterministic and free from accidental duplicates.
     */
    public function testDependencyPackageListIsStableAndUnique(): void
    {
        $packages = \pmssDelugeLegacyPipDependencyPackages();

        $this->assertEquals(9, count($packages));
        $this->assertEquals(count($packages), count(array_unique($packages)));
        $this->assertEquals('twisted[tls]', $packages[0]);
        $this->assertEquals('pyasn1==0.4.6', $packages[8]);
    }
}
