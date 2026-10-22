<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/**
 * ADR 0150: no new public listener on a host that has not turned the account UIs on. Each account-UI default server
 * written by the nginx setup (ports 844, 845, 846) sits behind the host marker check.
 */
final class AccountUiDefaultServerMarkerStaticTest extends TestCase
{
    private const MARKER = "is_file('/etc/seedbox/config/pikkumiesEternals.enabled')";

    public function testEveryAccountUiDefaultServerNeedsTheHostMarker(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4).'/scripts/lib/nginxConfig/setup.php');
        foreach (['pmssPikkumiesUiDefaultNginxBlock', 'pmssPikkumiesHermesDefaultNginxBlock', 'pmssHermesUiDefaultNginxBlock'] as $builder) {
            $at = strpos($source, $builder.'(');
            $this->assertTrue($at !== false, 'default server builder not found: '.$builder);
            $ifAt = strrpos(substr($source, 0, (int) $at), "\n    if (");
            $this->assertTrue($ifAt !== false, 'no guarding if before '.$builder);
            $condition = substr($source, (int) $ifAt, (int) strpos($source, ') {', (int) $ifAt) - (int) $ifAt);
            if (preg_match('/^\s*if \(\$([A-Za-z_]+)$/', $condition, $m) === 1) {
                // `if ($flag) {`: judge the assignment of $flag that precedes it
                $assignAt = strrpos(substr($source, 0, (int) $ifAt), '$'.$m[1].' = ');
                $this->assertTrue($assignAt !== false, 'no assignment of $'.$m[1].' before '.$builder);
                $condition = substr($source, (int) $assignAt, (int) strpos($source, ';', (int) $assignAt) - (int) $assignAt);
            }
            $this->assertStringContainsString(self::MARKER, $condition, $builder.' is written without the host marker check');
        }
    }
}
