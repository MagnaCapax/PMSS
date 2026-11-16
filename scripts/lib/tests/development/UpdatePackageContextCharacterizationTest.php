<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/distro.php';

/** Preserve phase-2 distro projection and read-only package query protocols. */
class UpdatePackageContextCharacterizationTest extends TestCase
{
    public function testDistroContextRetainsSelectionAndDeferredDiagnosticBytes(): void
    {
        foreach ([
            [12, 'bookworm', 12, 12, ''],
            [11, 'bookworm', 12, 12, 'Repository version mapped via codename bookworm -> 12 (reported=11)'],
            [0, 'bookworm', 12, 12, ''],
            [11, '', 11, 11, 'Repository codename unknown unresolved; falling back to VERSION_ID 11'],
            [13, 'unrecognized', 13, 13, 'Repository codename unrecognized unresolved; falling back to VERSION_ID 13'],
            [0, '', 0, null, 'Repository detection failed for distro=debian codename=unknown; skipping repository updates'],
            [0, 'unrecognized', 0, null, 'Repository detection failed for distro=debian codename=unrecognized; skipping repository updates'],
            [-1, '', -1, null, ''],
            ['12', 'bullseye', 11, 11, 'Repository version mapped via codename bullseye -> 11 (reported=12)'],
        ] as [$version, $codename, $repo, $baseline, $message]) {
            $input = ['name' => 'debian', 'version' => $version, 'codename' => $codename];
            $this->assertSame($input + ['repoMessage' => $message, 'repoVersion' => $repo, 'baselineVersion' => $baseline],
                \pmssUpdateStep2DistroContextBuild($input));
        }
    }

    public function testPackageQueriesKeepCommandBytesFirstLineAndFailureResults(): void
    {
        foreach (['pmssPackageStatus' => 'Status', 'pmssPackageVersion' => 'Version'] as $function => $field) {
            foreach ([[0, ['  installed  ', 'ignored'], 'installed'], [0, [], ''], [0, ['   '], ''],
                [1, ['plausible partial result'], ''], [124, ['timed out'], '']] as [$rc, $rows, $expected]) {
                $script = <<<'PHP'
namespace PackageQueryFixture;
function exec($command, &$output, &$rc) {
    $GLOBALS['commands'][] = $command;
    $output = $GLOBALS['rows']; $rc = $GLOBALS['rc'];
}
PHP;
                $script .= '$GLOBALS["commands"] = []; $GLOBALS["rows"] = '.var_export($rows, true).'; $GLOBALS["rc"] = '.$rc.';';
                $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/update/packageState.php', 'PackageQueryFixture');
                $script .= '$value = '.$function.'("fixtur-pkg\x27suffix"); echo json_encode([$value, $GLOBALS["commands"]]);';
                $this->assertSame([$expected, ["dpkg-query -W -f='\${".$field."}' 'fixtur-pkg'\\''suffix' 2>/dev/null"]],
                    $this->pmssRunInlinePhpJson($script));
            }
        }
    }

    public function testAvailabilityKeepsInventoryNormalizationAndPolicyFallbacks(): void
    {
        foreach ([
            [[[' FIXTUR-PKG ', 'other-pkg'], 0], [[], 9], true, 1],
            [[['other-pkg'], 0], [[], 9], false, 1],
            [[[], 0], [[' Candidate: 1.2'], 0], true, 2],
            [[[], 1], [[' Candidate: (none)'], 0], false, 2],
            [[[], 1], [['unrecognized policy output'], 0], true, 2],
            [[[], 1], [[' Candidate: 1.2'], 1], false, 2],
        ] as [$inventory, $policy, $expected, $calls]) {
            $script = <<<'PHP'
namespace PackageAvailableFixture;
function exec($command, &$output, &$rc) {
    $GLOBALS['commands'][] = $command;
    [$output, $rc] = array_shift($GLOBALS['responses']);
}
PHP;
            $script .= '$GLOBALS["commands"] = []; $GLOBALS["responses"] = '.var_export([$inventory, $policy], true).';';
            $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/update/packageState.php', 'PackageAvailableFixture');
            $script .= '$first = pmssPackageAvailable("fixtur-pkg:amd64"); $second = pmssPackageAvailable("fixtur-pkg:amd64");'
                .'echo json_encode([$first, $second, $GLOBALS["commands"]]);';
            $commands = ['apt-cache pkgnames 2>/dev/null'];
            if ($calls === 2) $commands[] = "apt-cache policy 'fixtur-pkg:amd64' 2>/dev/null";
            $this->assertSame([$expected, $expected, $commands], $this->pmssRunInlinePhpJson($script));
        }
    }
}
