<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class NetworkCompressionCharacterizationTest extends TestCase
{
    public function testLocalnetDefaultsStayInsideLoader(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/network/config.php');
        $defaultSymbol = 'networkDefault'.'Localnets';
        $hostnameSymbol = 'networkHostname'.'MatchesPulsedmedia';

        $this->assertTrue(
            strpos($src, 'function '.$defaultSymbol.'(') === false,
            'network/config.php should keep the one-call localnet default inline inside networkLoadLocalnets()'
        );
        $this->assertTrue(
            strpos($src, 'function '.$hostnameSymbol.'(') === false,
            'network/config.php should keep hostname gating inline inside networkLoadLocalnets()'
        );
        $this->assertStringContainsString("\$default = ['185.148.0.0/22'];", $src);
        $this->assertStringContainsString("preg_match('/(^|\\\\.)pulsedmedia\\\\.com$/', \$hostname)", $src);
    }

    public function testFireqosKeepsUidLookupInsideRenderer(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/network/fireqos.php');
        $symbol = 'networkFireqos'.'LookupUid';

        $this->assertTrue(
            strpos($src, 'function '.$symbol.'(') === false,
            'network/fireqos.php should keep username validation and uid probing local to networkBuildFireqosConfig()'
        );
        $this->assertStringContainsString("pmssResolvePathFromEnv('PMSS_FIREQOS_TEMPLATE', '/etc/seedbox/config/template.fireqos')", $src);
        $this->assertStringContainsString("pmssResolvePathFromEnv('PMSS_TRAFFIC_LIMIT_STATE_DIR', '/var/run/pmss/trafficLimits')", $src);
        $this->assertStringContainsString("pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home')", $src);
        $this->assertStringContainsString("if (!pmssValidateUsername(\$username)) {", $src);
        $this->assertStringContainsString("@shell_exec('id -u '.escapeshellarg(\$username).' 2>/dev/null')", $src);
    }
}
