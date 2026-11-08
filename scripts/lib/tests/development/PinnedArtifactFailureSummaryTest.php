<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Exercise shutdown reporting with real private fixtures and no network or installation. */
class PinnedArtifactFailureSummaryTest extends TestCase
{
    public function testEveryAcquisitionFailureRemainsVisibleAfterLaterSteps(): void
    {
        foreach ([
            'download' => 'download failed, rc=8',
            'checksum' => 'SHA-256 verification failed',
            'unreadable' => 'SHA-256 verification failed',
            'pin' => 'invalid SHA-256 pin',
            'url' => 'unsafe remote URL',
            'temp' => 'download temp file unavailable',
        ] as $mode => $reason) {
            $output = $this->fixture([[$mode, 'demo artifact']]);
            $this->assertStringContainsString('result=null', $output);
            $this->assertStringNotContainsString('callback', $output);
            $this->assertSame("later steps finished\n".$this->summary('demo artifact ('.$reason.')'),
                substr($output, strpos($output, 'later steps finished')));
        }
    }

    public function testMultipleFailuresShareOneFinalSummary(): void
    {
        $output = $this->fixture([['download', 'demo source'], ['success', 'demo success'],
            ['checksum', 'demo package'], ['download', 'demo binary']]);
        $this->assertSame(1, substr_count($output, '[ERROR] UPDATE INCOMPLETE:'));
        $this->assertStringContainsString($this->summary('demo source (download failed, rc=8); '
            .'demo package (SHA-256 verification failed); demo binary (download failed, rc=8)'), $output);
    }

    public function testSuccessAndDryRunsDoNotReportFailures(): void
    {
        foreach ([false, true] as $dryRun) {
            $output = $this->fixture([['success', 'demo artifact']], $dryRun);
            $this->assertStringNotContainsString('UPDATE INCOMPLETE', $output);
            $this->assertStringContainsString($dryRun ? 'result=null' : 'callback'."\n".'result="installed"', $output);
            if ($dryRun) $this->assertStringNotContainsString('callback', $output);
        }
    }

    private function summary(string $failures): string
    {
        return '[ERROR] UPDATE INCOMPLETE: pinned artifact failures: '.$failures;
    }

    /** Run the real artifact lifetime in a child so shutdown output is observable. */
    private function fixture(array $cases, bool $dryRun = false): string
    {
        $root = $this->pmssMakeTempDir('pmss-artifact-summary-', 0700);
        $script = 'namespace PinnedArtifactFailureFixture; $root = '.var_export($root, true).';'
            .'$cases = '.var_export($cases, true).'; $dryRun = '.var_export($dryRun, true).';';
        // Only the download runner and temp-file failure are injected; hashing and cleanup are real.
        $script .= <<<'PHP'
function logmsg(string $message): void { echo $message."\n"; }
function logMessage(string $message): void { echo $message."\n"; }
function pmssEnvFlagEnabled(string $name): bool { return $GLOBALS['dryRun']; }
function pmssCreatePrivateTempFile(string $prefix): ?string
{
    return $GLOBALS['mode'] === 'temp' ? null : tempnam($GLOBALS['root'], $prefix);
}
function pmssBuildCommand(string $binary, array $arguments): string { return $arguments[2]; }
function runStep(string $description, string $path): int
{
    if ($GLOBALS['dryRun']) return 0;
    if ($GLOBALS['mode'] === 'download') return 8;
    if ($GLOBALS['mode'] === 'unreadable') unlink($path);
    else file_put_contents($path, 'payload');
    return 0;
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/update/apps/remoteBinary/artifact.php', 'PinnedArtifactFailureFixture');
        $script .= <<<'PHP'
foreach ($cases as [$mode, $label]) {
    $url = $mode === 'url' ? 'http://example.invalid/artifact' : 'https://example.invalid/artifact';
    $pin = $mode === 'pin' ? 'invalid' : ($mode === 'checksum' ? str_repeat('a', 64) : hash('sha256', 'payload'));
    $result = pmssPinnedRemoteArtifactTempFileUse($label, $url, $pin, static function (string $path): string {
        echo "callback\n";
        return 'installed';
    });
    echo 'result='.json_encode($result)."\n";
}
echo "later steps finished\n";
PHP;
        $result = $this->pmssExecInlinePhp($script);
        $this->assertSame(0, $result['rc'], $result['output']);
        $this->assertSame([], glob($root.'/*'), 'Artifacts must be cleaned before shutdown reporting');
        return $result['output'];
    }
}
