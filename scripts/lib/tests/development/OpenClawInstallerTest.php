<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/install-openclaw.php';
require_once dirname(__DIR__, 2).'/mediaStackPorts.php';

final class OpenClawInstallerTest extends TestCase
{
    public function testCronMergePreservesForeignBytesAndIsIdempotent(): void
    {
        $foreign = 'MAILTO=me'.'@'."example.test\r\n# personal\n* * * * * echo keep\n";
        $merged = \ocCronMerge($foreign, '/tmp/oc-account', true);
        $this->assertSame($foreign, substr($merged, 0, strlen($foreign)));
        $this->assertSame($merged, \ocCronMerge($merged, '/tmp/oc-account', true));
        $this->assertSame($foreign, \ocCronMerge($merged, '/tmp/oc-account', false));
        $this->assertSame(2, substr_count($merged, '# pmss-openclaw'));
    }

    public function testCronMergeRemovesOnlyMarkedLines(): void
    {
        $foreign = "# pmss-openclaw is a note, not a trailing marker\n@daily echo keep # other\n";
        $managed = "@reboot php \$HOME/install-openclaw.php check # pmss-openclaw\n";
        $this->assertSame($foreign, \ocCronMerge($foreign.$managed, '/tmp/oc-account', false));
    }

    public function testReservedPortRequiresRegularFirstLineInRange(): void
    {
        $root = $this->pmssMakeTempDir('pmss-openclaw-port-');
        $file = $root.'/port';
        foreach (array('1' => 1, "65535\n" => 65535, "25000\nignored\n" => 25000) as $value => $expected) {
            file_put_contents($file, (string) $value);
            $this->assertSame($expected, \ocReservedPort($file));
        }
        foreach (array('', '0', '65536', '18789x', ' 123', '-1', '999999') as $value) {
            file_put_contents($file, $value);
            $this->assertSame(null, \ocReservedPort($file));
        }
        unlink($file);
        $this->assertSame(null, \ocReservedPort($file));
        $outside = $this->pmssMakeTempFile('pmss-openclaw-outside-');
        file_put_contents($outside, "23000\n");
        $this->pmssCreateSymlinkOrSkip($outside, $file);
        $this->assertSame(null, \ocReservedPort($file));
    }

    public function testCheckStateGivesUpOnFifthFailureAndResetsOnHealth(): void
    {
        $state = '';
        for ($failure = 1; $failure <= 4; ++$failure) {
            $state = \ocNextCheckState($state, false, 123);
            $this->assertSame("failed {$failure} 123\n", $state);
        }
        $state = \ocNextCheckState($state, false, 124);
        $this->assertSame("gave_up 124\n", $state);
        $this->assertSame($state, \ocNextCheckState($state, false, 125));
        $this->assertSame("healthy 0 126\n", \ocNextCheckState($state, true, 126));
    }

    public function testGatewayTokenIsPrivateAndReused(): void
    {
        $root = $this->pmssMakeTempDir('pmss-openclaw-token-');
        $paths = array('env' => $root.'/gateway.env', 'node' => $root.'/node');
        \ocEnsureToken($paths);
        $first = file_get_contents($paths['env']);
        $this->assertSame(0600, fileperms($paths['env']) & 0777);
        $this->assertTrue(preg_match('/^OPENCLAW_GATEWAY_TOKEN=[a-f0-9]{64}\n$/D', $first) === 1);
        \ocEnsureToken($paths);
        $this->assertSame($first, file_get_contents($paths['env']));
    }

    public function testPortCatalogAdoptsGatewayPortAndRejectsInvalidContent(): void
    {
        $root = $this->pmssMakeTempDir('pmss-openclaw-catalog-');
        $home = $root.'/test';
        $this->pmssEnsureDir($home.'/.openclaw');
        $definition = \pmssMediaStackPortDefinitions()['openclaw'];
        $this->assertSame('.openclaw/gateway.port', $definition['path']);
        file_put_contents($home.'/.openclaw/gateway.port', "23010\n");
        $this->assertSame(23010, \pmssMediaStackConfiguredPortRead($home, $definition));
        file_put_contents($home.'/.openclaw/gateway.port', "port=23010\n");
        $this->assertSame(null, \pmssMediaStackConfiguredPortRead($home, $definition));
    }
}
