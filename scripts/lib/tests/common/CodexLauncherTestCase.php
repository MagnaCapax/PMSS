<?php
namespace PMSS\Tests;

require_once __DIR__.'/TestCase.php';

/** Synthetic launcher checkout and fake assistant; never runs the installed assistant. */
abstract class CodexLauncherTestCase extends TestCase
{
    protected $launcherRoot;
    protected $launcherEnv;

    protected function setUp(): void
    {
        $this->tempDir = $this->pmssMakeTempDir('pmss-launcher-');
        $this->launcherRoot = $this->tempDir.'/repo';
        mkdir($this->launcherRoot);
        mkdir($this->tempDir.'/bin');
        mkdir($this->tempDir.'/artifacts');
        $source = $this->pmssRepoPath('development');
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $target = $this->launcherRoot.'/development/'.substr($file->getPathname(), strlen($source) + 1);
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0755, true);
            }
            copy($file->getPathname(), $target);
        }
        file_put_contents($this->launcherRoot.'/fixture.php', "<?php echo 'fixture';\n");
        $stub = <<<'PHP'
#!/usr/bin/env php
<?php
$record = ['args' => array_slice($argv, 1), 'stdin' => stream_get_contents(STDIN), 'cwd' => getcwd()];
file_put_contents(getenv('PMSS_TEST_CODEX_CAPTURE'), json_encode($record));
exit((int) getenv('PMSS_TEST_CODEX_RC'));
PHP;
        file_put_contents($this->tempDir.'/bin/codex', $stub);
        chmod($this->tempDir.'/bin/codex', 0755);
        // Any unexpected external fetch fails locally; no auth or network is used.
        foreach (['gh', 'curl', 'wget'] as $name) {
            file_put_contents($this->tempDir.'/bin/'.$name, "#!/bin/sh\nexit 99\n");
            chmod($this->tempDir.'/bin/'.$name, 0755);
        }
        $this->launcherEnv = [
            'PATH' => $this->tempDir.'/bin:'.getenv('PATH'),
            'TMPDIR' => $this->tempDir.'/artifacts',
            'PMSS_TEST_CODEX_CAPTURE' => $this->tempDir.'/assistant.json',
            'PMSS_TEST_CODEX_RC' => '0',
            'PMSS_AGENTIC_DEFAULT_AGENT' => 'codex',
            'PMSS_CODEX_NO_SANDBOX' => '0',
            'PMSS_CODEX_RUN_EVENT_LOG' => '',
            'PMSS_REFACTOR_CLAIMS_DIR' => $this->tempDir.'/claims',
        ];
        foreach (['init -q', 'add development fixture.php', '-c user.name=Fixture -c user.email=noreply@example.invalid commit -qm fixture'] as $args) {
            $result = $this->pmssExecShellCommand('git -C '.escapeshellarg($this->launcherRoot).' '.$args);
            $this->assertSame(0, $result['rc'], $result['output']);
        }
    }

    protected function launch(string $entry, array $args, array $env = []): array
    {
        return $this->pmssExecShellCommand(
            'timeout --kill-after=5s 20s bash '.escapeshellarg($this->launcherRoot.'/development/'.$entry).' '
            .implode(' ', array_map('escapeshellarg', $args)).' < /dev/null',
            array_replace($this->launcherEnv, $env)
        );
    }

    protected function events(): array
    {
        $events = [];
        foreach (glob($this->launcherRoot.'/log/codex-run/*/*.jsonl') ?: [] as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
                $event = json_decode($line, true);
                $this->assertTrue(is_array($event), 'Invalid JSONL: '.$line);
                $this->assertMatches('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $event['timestamp']);
                $this->assertSame('UTC', $event['timezone']);
                $events[] = $event;
            }
        }
        return $events;
    }
}
