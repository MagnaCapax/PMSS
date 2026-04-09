<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/userConfigStore.php';

class userConfigToggleCharacterizationTest extends TestCase
{
    /** @var string */
    private $tempDir = '';

    public function testToggleNormalizerKeepsFalseTokensStable(): void
    {
        foreach (['false', '0', 'no', 'off', '', 0, false] as $value) {
            $this->assertFalse(\pmssUserConfigNormaliseToggleValue(['dockerEnabled' => $value], 'dockerEnabled'));
        }
    }

    public function testToggleNormalizerKeepsTruthyTokensStable(): void
    {
        foreach (['true', '1', 'yes', 'on', 1, true] as $value) {
            $this->assertTrue(\pmssUserConfigNormaliseToggleValue(['dockerEnabled' => $value], 'dockerEnabled'));
        }
    }

    public function testToggleNormalizerUsesRequestedDefaultWhenKeyMissing(): void
    {
        $this->assertTrue(\pmssUserConfigNormaliseToggleValue([], 'lighttpdEnabled'));
        $this->assertFalse(\pmssUserConfigNormaliseToggleValue([], 'lighttpdEnabled', false));
    }

    public function testFeatureHelpersKeepInvalidUsernameGuardStable(): void
    {
        $this->assertFalse(\pmssUserDockerEnabled('../evil'));
        $this->assertFalse(\pmssUserLighttpdEnabled('../evil'));
    }

    public function testResolvePayloadKeepsMissingValidUserAsEmptyArray(): void
    {
        $this->pmssEnsureTempDirProperty('tempDir', 'user-config-toggle', 0755, sys_get_temp_dir().'/pmss-user-config-toggle-tests');
        try {
            $store = new \UserConfigStore($this->tempDir.'/seedbox/config');
            $payload = \pmssUserConfigResolvePayload('alice', $store);

            $this->assertEquals([], $payload);
            $this->assertTrue($store instanceof \UserConfigStore);
        } finally {
            $this->pmssCleanupTempDirProperty('tempDir');
        }
    }
}
