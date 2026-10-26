<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/apps/arr.php';

class ArrActivationCharacterizationTest extends TestCase
{
    /** Lock every activation boundary against in-memory dependency traces. */
    public function testActivationPreservesCommandsDiagnosticsAndCleanup(): void
    {
        $download = ['command', "curl -sSL --fail -o '/fixture/work/bundle.tar.gz' 'https://example.invalid/bundle'"];
        $extract = ['command', "umask 022 && tar --no-same-owner --no-same-permissions -xzf '/fixture/work/bundle.tar.gz' -C '/fixture/work'"];
        $guard = ['guard', '/fixture/work', 'radarr-', '/fixture/work/Package', '/fixture/install'];
        $remove = ['remove', '/fixture/install'];
        $move = ['command', "mv '/fixture/work/Package' '/fixture/install'"];
        $phases = [$download, $extract, $guard, $remove, $move];
        foreach ([
            'workspace' => [0, 'Failed to create temporary workspace'],
            'download' => [1, 'Download failed; keeping existing installation'],
            'archive' => [1, 'Download failed; keeping existing installation'],
            'extract' => [2, 'Extraction failed; keeping existing installation'],
            'directory' => [2, 'Extraction failed; keeping existing installation'],
            'parent' => [2, 'Install parent directory missing; refusing to replace application'],
            'guard' => [3, 'Unsafe activation paths; keeping existing installation'],
            'remove' => [4, 'Failed to remove existing installation; keeping existing installation'],
            'move' => [5, 'Failed to activate extracted release'],
            'unpublished' => [5, 'Failed to activate extracted release'],
            'success' => [5, null],
            'absent' => [5, 'Failed to activate extracted release'],
        ] as $scenario => [$count, $message]) {
            $trace = array_merge([['workspace', 'radarr-']], array_slice($phases, 0, $count));
            if ($scenario === 'absent') $trace = array_values(array_filter($trace, static function ($event): bool { return $event[0] !== 'remove'; }));
            if ($message !== null) $trace[] = ['log', $message];
            if ($scenario !== 'workspace') {
                $trace[] = ['cleanup', '/fixture/work', 'radarr-', 'Cleaning Radarr updater workspace'];
                $trace[] = ['command', 'fixture-cleanup'];
            }
            $this->assertSame(['result' => $message === null, 'trace' => $trace], $this->activationTrace($scenario), $scenario);
        }
    }

    /** Read the real implementation so the fixture never carries a second activation flow. */
    private function activationTrace(string $scenario): array
    {
        $script = 'namespace ArrActivationCharacterization; require '.var_export($this->pmssRepoPath('scripts/lib/tests/fixtures/arrActivationCharacterization.php'), true).';';
        foreach (['pmssArrReleaseActivate'] as $name) {
            $function = new \ReflectionFunction($name);
            $source = implode('', array_slice(file($function->getFileName()), $function->getStartLine() - 1, $function->getEndLine() - $function->getStartLine() + 1));
            // Legacy string callbacks bypass namespace resolution; bind only those predicates to the same stubs.
            $source = str_replace(["'is_file'", "'is_dir'"], [var_export('ArrActivationCharacterization\\is_file', true), var_export('ArrActivationCharacterization\\is_dir', true)], $source);
            $script .= 'eval('.var_export('namespace ArrActivationCharacterization; '.$source, true).');';
        }
        $script .= '$GLOBALS["scenario"] = '.var_export($scenario, true).';';
        $script .= '$result = pmssArrReleaseActivate(["extract_dir" => "Package", "install_path" => "/fixture/install"], "Radarr", "https://example.invalid/bundle", "bundle.tar.gz", static function ($message) { $GLOBALS["trace"][] = ["log", $message]; });';
        return $this->pmssRunInlinePhpJson($script.'echo json_encode(["result" => $result, "trace" => $GLOBALS["trace"]]);');
    }
}
