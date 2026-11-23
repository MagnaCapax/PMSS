<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/**
 * The lighttpd mod_magnet module must be declared by a name dpkg --set-selections can resolve.
 *
 * Debian 11 and 12 ship mod_magnet.so only inside lighttpd-modules-lua; lighttpd-mod-magnet is a virtual
 * name there, which the selection baseline silently drops. Debian 10 still has the real package.
 */
class DpkgBaselineLighttpdLuaModuleTest extends TestCase
{
    /** Return the install-state package names of one release baseline. */
    private function baselinePackages(int $debianMajor): array
    {
        $packages = [];
        foreach (explode("\n", $this->pmssReadRepoFile('scripts/lib/update/dpkg/selections-debian'.$debianMajor.'.txt')) as $line) {
            if (preg_match('/^(\S+)\s+install$/', $line, $match)) {
                $packages[] = $match[1];
            }
        }
        return $packages;
    }

    public function testDebian11And12DeclareTheRealLuaModulesPackage(): void
    {
        foreach ([11, 12] as $major) {
            $packages = $this->baselinePackages($major);
            $this->assertTrue(in_array('lighttpd-modules-lua', $packages, true), 'Debian '.$major.' must install lighttpd-modules-lua');
            $this->assertFalse(in_array('lighttpd-mod-magnet', $packages, true), 'Debian '.$major.' must not name the virtual lighttpd-mod-magnet');
        }
    }

    public function testDebian10KeepsItsRealMagnetPackage(): void
    {
        $packages = $this->baselinePackages(10);
        $this->assertTrue(in_array('lighttpd-mod-magnet', $packages, true), 'Debian 10 ships lighttpd-mod-magnet as a real package');
        $this->assertFalse(in_array('lighttpd-modules-lua', $packages, true), 'Debian 10 has no lighttpd-modules-lua package');
    }

    public function testLuaModulesRowStaysSortedInsideTheLighttpdGroup(): void
    {
        foreach ([11, 12] as $major) {
            $packages = $this->baselinePackages($major);
            $position = array_search('lighttpd-modules-lua', $packages, true);
            $this->assertTrue($position !== false && $position > 0, 'Debian '.$major.' row must exist');
            $this->assertTrue(strcmp($packages[$position - 1], 'lighttpd-modules-lua') < 0, 'Debian '.$major.' predecessor must sort before it');
            $this->assertTrue(!isset($packages[$position + 1]) || strcmp('lighttpd-modules-lua', $packages[$position + 1]) < 0, 'Debian '.$major.' successor must sort after it');
        }
    }
}
