<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/CodexLauncherTestCase.php';

class CodexLaunchersTest extends CodexLauncherTestCase
{
    public function testAllLauncherPathsUsePinnedDefaultsAndSeparateShards(): void
    {
        $entries = ['agentic.sh', 'agentic-refactor.sh', 'agentic-ci.sh', 'agentic-issues.sh',
            'agentic-qa.sh', 'codex.sh', 'codex-headless.sh', 'codex-refactor.sh', 'codex-ci.sh', 'ci.sh',
            'ci-logs.sh', 'codex-run.sh'];
        foreach ($entries as $entry) {
            $args = $entry === 'codex-run.sh' ? ['run', '--prompt', 'fixture'] : [];
            if ($entry === 'ci-logs.sh') {
                $args[] = 'codex';
            }
            $args[] = '--dry-run';
            $result = $this->launch($entry, $args);
            $this->assertSame(0, $result['rc'], $entry.': '.$result['output']);
            $this->assertStringContainsAllStrings(['model="gpt-6-astra"', 'model_reasoning_effort="xhigh"'], $result['output']);
        }
        $this->assertFalse(file_exists($this->tempDir.'/assistant.json'));
        $this->assertSame(12, count(glob($this->launcherRoot.'/log/codex-run/*/*.jsonl')));
        $this->assertSame(24, count($this->events()));
    }

    public function testPromptTransportPreservesQuotesNewlinesAndSpacePaths(): void
    {
        $prompt = "Fixture 'quote' \"double\" ".'$() `literal` & ##PROMPT_STDIN## ##PROMPT_FILE## ##PROMPT##'."\nSecond line";
        foreach (['', ' ##PROMPT_STDIN##', ' ##PROMPT##', ' ##PROMPT_FILE##'] as $placeholder) {
            $result = $this->launch('codex-run.sh', ['run', '--prompt', $prompt,
                '--outdir', $this->tempDir.'/output with spaces', '--exec', 'codex exec'.$placeholder]);
            $this->assertSame(0, $result['rc'], $result['output']);
            $capture = $this->pmssReadJsonArrayFile($this->tempDir.'/assistant.json');
            $this->assertSame($this->launcherRoot, $capture['cwd']);
            $this->assertStringContainsString('model="gpt-6-astra"', implode(' ', $capture['args']));
            $payload = $placeholder === ' ##PROMPT_STDIN##' ? $capture['stdin'] : implode(' ', $capture['args']);
            if ($placeholder === ' ##PROMPT_FILE##') {
                $payload = file_get_contents($this->tempDir.'/output with spaces/prompt.txt');
                $this->assertTrue(in_array($this->tempDir.'/output with spaces/prompt.txt', $capture['args'], true));
            }
            $this->assertStringContainsString($prompt, $payload);
        }
    }

    public function testExplicitModelsAndReasoningFollowDefaults(): void
    {
        $result = $this->launch('codex-run.sh', ['run', '--prompt', 'fixture', '--exec',
            'codex exec --model chosen-model -c \'model_reasoning_effort="low"\'']);
        $this->assertSame(0, $result['rc'], $result['output']);
        $args = $this->pmssReadJsonArrayFile($this->tempDir.'/assistant.json')['args'];
        $this->assertOrderedStrings(['model="gpt-6-astra"', 'chosen-model', 'model_reasoning_effort="low"'], implode(' ', $args));
    }

    public function testCustomAutocommitPromptUsesDefaultOrExplicitPrefix(): void
    {
        foreach (['Inspect only the fixture.' => 'refactor(compression):',
            'COMMIT PREFIX OVERRIDE: Use "refactor(decompose):" as commit prefix.' => 'refactor(decompose):'] as $prompt => $prefix) {
            $result = $this->launch('codex-refactor.sh', ['--dry-run', '--autocommit', '--prompt', $prompt]);
            $this->assertSame(0, $result['rc'], $result['output']);
            $files = glob($this->tempDir.'/artifacts/pmss-refactor-codex-*/prompt.txt');
            $matches = array_filter($files, static function ($file) use ($prompt, $prefix) {
                $text = file_get_contents($file);
                return strpos($text, $prompt) === 0 && strpos($text, 'PREFIX = '.$prefix) !== false;
            });
            $this->assertTrue(count($matches) > 0);
        }
    }

    public function testSandboxOverridesKeepTheirExactArguments(): void
    {
        foreach (['-s read-only', '--sandbox read-only', '--sandbox=read-only',
            '-c \'sandbox_mode="read-only"\'', '--dangerously-bypass-approvals-and-sandbox'] as $override) {
            $result = $this->launch('codex-run.sh', ['run', '--prompt', 'fixture', '--exec', 'codex exec '.$override]);
            $this->assertSame(0, $result['rc'], $result['output']);
            $args = $this->pmssReadJsonArrayFile($this->tempDir.'/assistant.json')['args'];
            $this->assertFalse(in_array('--sandbox', array_slice($args, 0, array_search('exec', $args, true)), true));
            $this->assertFalse(in_array('danger-full-access', $args, true));
            $this->assertTrue(in_array('sandbox_mode="danger-full-access"', $args, true));
            $this->assertTrue(in_array('read-only', $args, true) || in_array('--sandbox=read-only', $args, true)
                || in_array('sandbox_mode="read-only"', $args, true) || in_array('--dangerously-bypass-approvals-and-sandbox', $args, true));
        }
    }

    public function testRefactorLaunchesWithoutExtraArgsAndNormalizesYolo(): void
    {
        foreach ([[], ['--yolo'], ['--ask-for-approval', 'never']] as $extra) {
            $result = $this->launch('codex-refactor.sh', array_merge(['--prompt', 'Inspect the fixture.'], $extra));
            $this->assertSame(0, $result['rc'], $result['output']);
            $args = $this->pmssReadJsonArrayFile($this->tempDir.'/assistant.json')['args'];
            $this->assertFalse(in_array('--ask-for-approval', $args, true));
            if ($extra) {
                $this->assertTrue(in_array('approval_policy="never"', $args, true));
            }
            $this->assertSame([], glob($this->tempDir.'/claims/*'));
            $this->assertStringContainsString('[agentic-refactor] done', $result['output']);
        }
    }

    public function testMissingOptionValuesFailWithoutLoopingOrLaunching(): void
    {
        foreach (['--prompt', '--exec', '--outdir', '--event-log', '--context'] as $option) {
            $result = $this->launch('codex-run.sh', ['run', $option]);
            $this->assertSame(2, $result['rc'], $result['output']);
        }
        $this->assertFalse(file_exists($this->tempDir.'/assistant.json'));
    }

    public function testExplicitApprovalConfigBeatsConvenienceYolo(): void
    {
        foreach ([['-c', 'approval_policy="on-request"'], ['--config=approval_policy="on-request"']] as $config) {
            $result = $this->launch('codex-headless.sh', array_merge(['--prompt', 'fixture', '--yolo', '--'], $config));
            $this->assertSame(0, $result['rc'], $result['output']);
            $args = $this->pmssReadJsonArrayFile($this->tempDir.'/assistant.json')['args'];
            $this->assertFalse(in_array('approval_policy="never"', $args, true));
            $this->assertStringContainsString('approval_policy="on-request"', implode(' ', $args));
        }
    }
}
