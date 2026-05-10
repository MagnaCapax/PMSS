<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/networking.php';

class UpdateNetworkingTemplateTest extends TestCase
{
    public function testEnsureNetworkTemplateDefinesCanonicalConfigPath(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/update/networking.php');
        $hasCanonicalPath = strpos($source, "\$path = '/etc/seedbox/config/network';") !== false;
        $hasEnvOverride = strpos($source, "getenv('PMSS_NETWORK_CONFIG')") !== false;
        $this->assertTrue($hasCanonicalPath || $hasEnvOverride, 'Expected canonical path default or PMSS_NETWORK_CONFIG override');
    }

    public function testEnsureNetworkTemplateSeedsExpectedThrottleDefaultsInTemplate(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/update/networking.php');
        $this->assertStringContainsString("'interface' => 'eth0'", $source);
        $this->assertStringContainsString("'progressiveThrottleEnabled' => true", $source);
        $this->assertStringContainsString("'limitSoft' => 80", $source);
    }

    public function testEnsureNetworkTemplateLogsCreationEvent(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/update/networking.php');
        $this->assertStringContainsString("\$log('Created default network configuration');", $source);
    }
}
