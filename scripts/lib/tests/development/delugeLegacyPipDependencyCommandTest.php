<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
putenv('PMSS_DELUGE_NO_ENTRYPOINT=1');
require_once dirname(__DIR__, 2).'/update/apps/deluge.php';

class DelugeLegacyPipDependencyCommandTest extends TestCase
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
