<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class NetworkCompressionCharacterizationTest extends TestCase
{
    public function testLocalnetDefaultsStayInsideLoader(): void
    {
        $defaultSymbol = 'networkDefault'.'Localnets';
        $hostnameSymbol = 'networkHostname'.'MatchesPulsedmedia';

        $this->pmssAssertRepoFileNotContainsFunction(
            'scripts/lib/network/config.php',
            $defaultSymbol,
            'network/config.php should keep the one-call localnet default inline inside networkLoadLocalnets()'
        );
        $this->pmssAssertRepoFileNotContainsFunction(
            'scripts/lib/network/config.php',
            $hostnameSymbol,
            'network/config.php should keep hostname gating inline inside networkLoadLocalnets()'
        );
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/network/config.php', [
            "\$default = ['185.148.0.0/22'];",
            "preg_match('/(^|\\\\.)pulsedmedia\\\\.com$/', \$hostname)",
        ]);
    }

    public function testFireqosKeepsUidLookupInsideRenderer(): void
    {
        $symbol = 'networkFireqos'.'LookupUid';

        $this->pmssAssertRepoFileNotContainsFunction(
            'scripts/lib/network/fireqos.php',
            $symbol,
            'network/fireqos.php should keep username validation and uid probing local to networkBuildFireqosConfig()'
        );
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/network/fireqos.php', [
            "pmssResolvePathFromEnv('PMSS_FIREQOS_TEMPLATE', '/etc/seedbox/config/template.fireqos')",
            "pmssResolvePathFromEnv('PMSS_TRAFFIC_LIMIT_STATE_DIR', '/var/run/pmss/trafficLimits')",
            "pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home')",
            "if (!pmssValidateUsername(\$username)) {",
            "@shell_exec('id -u '.escapeshellarg(\$username).' 2>/dev/null')",
        ]);
    }
}
