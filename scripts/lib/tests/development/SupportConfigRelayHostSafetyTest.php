<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/support/config.php';

class SupportConfigRelayHostSafetyTest extends TestCase
{
    private $configDir;

    protected function setUp(): void
    {
        $this->pmssAssignTempDirProperty('configDir', 'pmss-support-config-');
        $this->pmssEnsureDir($this->configDir);
        $this->pmssTrackEnvOverrides([
            'PMSS_CONFIG_DIR' => $this->configDir,
            'PMSS_SUPPORT_CONFIG_PATH' => null,
        ]);
    }

    private function writeConfig(array $overrides): void
    {
        file_put_contents($this->configDir.'/support.php', "<?php\nreturn ".var_export(array_merge([
            'targetEmail' => 'support@example.com',
            'snapshotDirectory' => '.support/requests',
            'smtpPort' => 25,
            'connectTimeout' => 5,
        ], $overrides), true).";\n");
    }

    public function testRelayHostReadAcceptsOperationalHostTokens(): void
    {
        foreach (['mx.example.com', 'localhost', '127.0.0.1', 'MX-1.Example.COM', ''] as $relayHost) {
            $this->writeConfig(['relayHost' => $relayHost]);
            $config = \pmssSupportConfigRead();

            $this->assertSame($relayHost, $config['relayHost']);
        }
    }

    public function testRelayHostReadRejectsUnsafeStreamTargetTokens(): void
    {
        foreach ([
            "mx.example.com\nX-Injected: value",
            'mx example.com',
            'tcp://mx.example.com',
            'mx.example.com:25',
            '../mx.example.com',
            'mx_example.com',
            ['mx.example.com'],
        ] as $relayHost) {
            $this->writeConfig(['relayHost' => $relayHost]);

            $this->assertThrowsRuntime(static function (): void {
                \pmssSupportConfigRead();
            }, 'Support relay host is invalid.');
        }
    }
}
