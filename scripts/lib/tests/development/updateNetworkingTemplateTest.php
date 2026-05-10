<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/update/networking.php';

class UpdateNetworkingTemplateTest extends TestCase
{
    public function testEnsureNetworkTemplateSeedsMissingOverridePath(): void
    {
        $path = $this->pmssMakeTempPath('pmss-network-template-');
        $messages = [];

        $this->pmssWithEnv(['PMSS_NETWORK_CONFIG' => $path], function () use ($path, &$messages): void {
            \pmssEnsureNetworkTemplate($this->pmssMakeArrayLogger($messages));

            $contents = $this->pmssReadFileOrEmpty($path);
            $this->assertStringContainsString("'interface' => 'eth0'", $contents);
            $this->assertStringContainsString("'progressiveThrottleEnabled' => true", $contents);
        });

        $this->pmssAssertMessagesContain($messages, 'Created default network configuration');
    }

    public function testEnsureNetworkTemplateKeepsExistingConfigUntouched(): void
    {
        $path = $this->pmssWriteTempFile('network-existing', "<?php return ['interface' => 'eth9'];\n");
        $messages = [];

        $this->pmssWithEnv(['PMSS_NETWORK_CONFIG' => $path], function () use ($path, &$messages): void {
            \pmssEnsureNetworkTemplate($this->pmssMakeArrayLogger($messages));

            $this->assertEquals("<?php return ['interface' => 'eth9'];\n", $this->pmssReadFileOrEmpty($path));
        });

        $this->assertEquals([], $messages);
    }

    public function testEnsureNetworkTemplateRejectsUnsafeParentPath(): void
    {
        $root = $this->pmssMakeTempDir('pmss-network-parent-');
        $targetDir = $root.'/target';
        @mkdir($targetDir, 0755, true);
        $linkDir = $root.'/linked';
        $this->pmssCreateSymlinkOrSkip($targetDir, $linkDir);
        $path = $linkDir.'/network';
        $messages = [];

        $this->pmssWithEnv(['PMSS_NETWORK_CONFIG' => $path], function () use (&$messages): void {
            \pmssEnsureNetworkTemplate($this->pmssMakeArrayLogger($messages));
        });

        $this->assertFalse(file_exists($path));
        $this->assertFalse(file_exists($targetDir.'/network'));
        $this->pmssAssertMessagesContain($messages, 'Unsafe network configuration directory');
    }
}
