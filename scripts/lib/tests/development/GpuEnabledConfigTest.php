<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/add/cli.php';
require_once dirname(__DIR__, 2).'/user/add/userConfigApply.php';
require_once dirname(__DIR__, 2).'/user/userConfigRuntime.php';

/** GPU policy uses the existing operator CLI and durable payload, never home markers. */
class GpuEnabledConfigTest extends TestCase
{
    public function testGpuOptionUsesTheDockerBooleanVocabularyAndRejectsMalformedValues(): void
    {
        $this->assertSame(null, \pmssUserConfigParseGpuEnabledOption(null));
        foreach (['true', '1', 'yes', 'on', ' TRUE '] as $value) {
            $this->assertSame(true, \pmssUserConfigParseGpuEnabledOption($value));
        }
        foreach (['false', '0', 'no', 'off', ' FALSE '] as $value) {
            $this->assertSame(false, \pmssUserConfigParseGpuEnabledOption($value));
        }
        foreach ([true, false, 1, [], new \stdClass(), '', 'invalid', '-1', 'true;id', "false\0"] as $value) {
            try {
                \pmssUserConfigParseGpuEnabledOption($value);
                $this->fail('Malformed GPU option was accepted');
            } catch (\InvalidArgumentException $error) {
                $this->assertStringContainsString('--gpu-enabled', $error->getMessage());
            }
        }
    }

    public function testAddUserAcceptsBothOptionFormsAndForwardsCanonicalValues(): void
    {
        $base = ['addUser.php', 'fixtur01', 'rand', '1024', '200'];
        $default = \pmssAddUserParseCli($base)['user'];
        $this->assertFalse(array_key_exists('gpuEnabled', $default));
        $this->assertStringNotContainsString('--gpu-enabled=', \pmssAddUserBuildUserConfigCommand($default));
        foreach (['true' => true, 'false' => false] as $raw => $expected) {
            foreach ([['--gpu-enabled='.$raw], ['--gpu-enabled', $raw]] as $options) {
                $user = \pmssAddUserParseCli(array_merge($base, $options))['user'];
                $this->assertSame($expected, $user['gpuEnabled']);
                $this->assertStringContainsString("'--gpu-enabled=".$raw."'", \pmssAddUserBuildUserConfigCommand($user));
            }
        }
        foreach (['--gpu-enabled', '--gpu-enabled=', '--gpu-enabled=invalid'] as $option) {
            $this->assertThrows(\InvalidArgumentException::class, static function () use ($base, $option): void {
                \pmssAddUserParseCli(array_merge($base, [$option]));
            });
        }
        $this->assertStringContainsString('--gpu-enabled=true|false', \pmssAddUserCliUsage());
    }

    public function testPayloadDefaultsOnAndPreservesOrOverridesStoredPolicy(): void
    {
        $this->assertSame(true, \pmssUserConfigPayloadNormalise([])['gpuEnabled']);
        $store = new class { public function applyFallbacks($username, $payload): array { return $payload; } };
        $user = ['name' => 'fixtur01', 'memory' => 1024, 'quota' => 200];
        foreach ([[[], null, true], [['gpuEnabled' => false], null, false],
            [['gpuEnabled' => false], true, true], [['gpuEnabled' => true], false, false],
            [[], false, false]] as [$existing, $option, $expected]) {
            $payload = \pmssUserConfigPayloadBuild($store, $existing, $user, [], null, $option);
            $this->assertSame($expected, \pmssUserConfigPayloadNormalise($payload)['gpuEnabled']);
        }
    }

    public function testGpuPolicyReadsTheConfigStoreAndRejectsInvalidNames(): void
    {
        $store = new class extends \UserConfigStore {
            public function get(string $username): ?array {
                return $username === 'fixtur01' ? ['gpuEnabled' => false] : [];
            }
        };
        $this->assertFalse(\pmssUserGpuEnabled('fixtur01', $store));
        $this->assertTrue(\pmssUserGpuEnabled('fixtur02', $store));
        $this->assertFalse(\pmssUserGpuEnabled('../bad', $store));
    }

    public function testOnlyChangedValidIntelAccountsConvergeAndOnlyEnabledAccountsJoinRender(): void
    {
        foreach ([
            ['fixtur01', [], false, true, ['deny']],
            ['fixtur01', ['gpuEnabled' => false], true, true, ['deny', 'group']],
            ['fixtur01', ['gpuEnabled' => false], false, true, []],
            ['fixtur01', [], true, true, []],
            ['fixtur01', [], false, false, []],
            ['bad;id', [], false, true, []],
        ] as [$name, $existing, $enabled, $intel, $expected]) {
            $events = [];
            $ops = self::pmssMakeCallbacks(['intel' => $intel, 'deny' => null, 'group' => null], $events);
            \pmssUserConfigApplyGpuPolicy($name, $existing, ['gpuEnabled' => $enabled], $ops['intel'], $ops['deny'], $ops['group']);
            $this->assertSame($expected, array_values(array_diff($events, ['intel'])));
        }
    }

    public function testReconfigureParsesBeforeLookupAndAppliesOnlyAfterSuccessfulPersistence(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/lib/user/userConfigPrepare.php', [
            "'gpu-enabled'", 'pmssUserConfigParseGpuEnabledOption', '|| $gpuEnabled !== null',
            "pmssPasswdEntryLookup(\$user['name'])", 'array_fill_keys(array_keys($explicitResourceOverrides), true), $dockerEnabled, $gpuEnabled)',
            "if (!\$store->persist(\$user['name'], \$payload))", 'pmssCliExitWithStderr("Error: failed to persist user config',
            "pmssUserConfigApplyGpuPolicy(\$user['name'], \$existing, \$payload)",
        ]);
    }
}
