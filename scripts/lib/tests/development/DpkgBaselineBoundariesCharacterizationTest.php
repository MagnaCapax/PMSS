<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Lock baseline preference and diagnostics without reading host package state. */
class DpkgBaselineBoundariesCharacterizationTest extends TestCase
{
    public function testBaselinePreferenceAndFallbackWarningBytes(): void
    {
        $named = 'selections-debian12.txt';
        $generic = 'selections.txt';
        foreach ([
            [12, [10, 12, 13], [$named], $named, []],
            [null, [10, 12, 13], [$named], $named, []],
            [11, [10, 12, 13], [$named], $named, ['[WARN] Debian 11 dpkg baseline unavailable; using '.$named.'.']],
            [99, [10, 12, 13], [$named], $named, ['[WARN] Debian 99 dpkg baseline missing; using Debian 12 baseline ('.$named.').']],
            [13, [10, 12, 13], [$named, 'selections-debian13.txt'], $named,
                ['[WARN] Debian 13 dpkg baseline exists but is not validated for automatic use; using Debian 12 baseline ('.$named.').']],
            [13, [10, 12, 13], [$generic, 'selections-debian13.txt'], $generic,
                ['[WARN] Debian 13 dpkg baseline exists but is not validated for automatic use; using '.$generic.'.']],
            [12, [10, 12, 13], [$generic], $generic, ['[WARN] Debian 12 dpkg baseline unavailable; using '.$generic.'.']],
            [12, [], [$named], $named, []],
            [12, [10, 12, 13], ['selections-debian10.txt'], null, []],
            [null, [], [], null, []],
            // With no validated snapshots at all, retain the existing highest-version fallback.
            [13, [13], ['selections-debian13.txt'], 'selections-debian13.txt', []],
        ] as $case) {
            // Inject directory entries and readability; the production selector supplies all policy.
            $script = <<<'PHP'
namespace DpkgBaselineFixture;
function glob($pattern) {
    return array_map(static function ($major) use ($pattern) {
        return dirname($pattern).'/selections-debian'.$major.'.txt';
    }, $GLOBALS['majors']);
}
function is_readable($path) { return in_array(basename($path), $GLOBALS['readable'], true); }
PHP;
            $script .= '$GLOBALS["majors"] = '.var_export($case[1], true).';'
                .'$GLOBALS["readable"] = '.var_export($case[2], true).'; $logs = [];';
            $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/update/dpkgBaseline.php', 'DpkgBaselineFixture');
            $script .= '$path = pmssSelectDpkgSelectionsBaseline('.var_export($case[0], true).', static function ($line) use (&$logs) { $logs[] = $line; });'
                .'echo json_encode(["path" => $path === null ? null : basename($path), "logs" => $logs]);';
            $result = $this->pmssRunInlinePhpJson($script);
            $this->assertSame(['path' => $case[3], 'logs' => $case[4]], $result);
        }
    }

    public function testExistingIncludesRetainThePublicPackageHelpers(): void
    {
        foreach (['environment.php', 'dpkgSelections.php'] as $entry) {
            $script = 'require '.var_export($this->pmssRepoPath('scripts/lib/update/'.$entry), true).';'
                .'echo json_encode(array_map("function_exists", '.var_export([
                    'pmssSelectDpkgSelectionsBaseline', 'pmssWriteSanitisedDpkgSelectionsTempFile',
                    'pmssDpkgSelectionsSanitise', 'pmssDpkgSelectionsLogSummary',
                    'pmssDpkgSelectionsDrop', 'pmssHostHasIntelDisplayPciDevice',
                ], true).'));';
            $this->assertSame(array_fill(0, 6, true), $this->pmssRunInlinePhpJson($script));
        }
    }
}
