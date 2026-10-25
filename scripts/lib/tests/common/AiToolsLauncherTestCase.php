<?php
namespace PMSS\Tests;

require_once __DIR__.'/TestCase.php';
require_once __DIR__.'/../../update/apps/templates/pmss-ai-launch.php';
require_once __DIR__.'/../../update/apps/aiToolsCodex.php';

/** Shared fixtures and passthrough assertions; provider-specific cases stay in each test. */
abstract class AiToolsLauncherTestCase extends TestCase
{
    protected function pmssTempDirFixtureArguments(): array
    {
        return ['tempDir', 'pmss-ai-'.static::TOOL.'-'];
    }

    /** Keep the valid account state identical across launcher decision tests. */
    protected function launcherState(array $overrides = []): array
    {
        return array_merge(['uid' => 1000, 'keyFileValid' => true, 'key' => 'private-test-key',
            'endpoint' => 'https://virsilipas.mcx.fi/v1', 'configChoice' => false], $overrides);
    }

    /** Shared refusal inputs; callers append their provider-specific precedence rows. */
    protected function launcherPassthroughCases(): array
    {
        return [
            'off' => [['PMSS_VIRSILIPAS' => 'off'], [], []],
            'zero' => [['PMSS_VIRSILIPAS' => '0'], [], []],
            'no' => [['PMSS_VIRSILIPAS' => 'no'], [], []],
            'root' => [[], ['uid' => 0], []],
            'empty home' => [['HOME' => ''], [], []],
            'missing key' => [[], ['keyFileValid' => false], []],
            'empty key' => [[], ['key' => ''], []],
            'space key' => [[], ['key' => 'bad key'], []],
            'control key' => [[], ['key' => "bad\nkey"], []],
            'unicode space' => [[], ['key' => "bad\xC2\xA0key"], []],
            'invalid key encoding' => [[], ['key' => "bad\xFFkey"], []],
            'http endpoint' => [[], ['endpoint' => 'http://example.com/v1'], []],
            'endpoint query' => [[], ['endpoint' => 'https://example.com/v1?x=1'], []],
            'config choice' => [[], ['configChoice' => true], []],
        ];
    }

    /** Both ordinary and zero-valued explicit settings must win over injection. */
    protected function launcherEnvironmentCases(array $names): array
    {
        $cases = [];
        foreach ($names as $name) {
            $cases[$name] = [[$name => 'own'], [], []];
            $cases[$name.' zero'] = [[$name => '0'], [], []];
        }
        return $cases;
    }

    /** Each original row must preserve argv, the full environment, and notice suppression. */
    protected function assertLauncherPassthroughCases(array $cases, array $state, array $env): void
    {
        $decision = '\\pmssAi'.ucfirst(static::TOOL).'Decision';
        $binary = constant('PMSS_AI_'.strtoupper(static::TOOL).'_BINARY');
        foreach ($cases as $label => [$envChange, $stateChange, $args]) {
            $input = array_merge($env, $envChange);
            $result = $decision($args, $input, array_merge($state, $stateChange));
            $this->assertSame(array_merge([$binary], $args), $result['argv'], $label);
            $this->assertSame($input, $result['env'], $label.' environment');
            $this->assertFalse($result['notice'], $label.' notice');
        }
        $this->assertFalse($decision([], [], $state)['notice'], 'unset HOME');
    }
}
