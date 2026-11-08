<?php
namespace PMSS\Tests\AiToolsInstallDecisionFixture;

/** Observations stay in memory; the real installer never executes. */
function pmssRootOwnedTreeNeedsRefresh(string $path): bool
{
    $GLOBALS['pmssAiToolsDecisionFixture']['events'][] = ['refresh', $path];
    return $GLOBALS['pmssAiToolsDecisionFixture']['refresh'];
}
function pmssRootOwnedTreeRemove(string $path): bool
{
    $GLOBALS['pmssAiToolsDecisionFixture']['events'][] = ['remove', $path];
    return $GLOBALS['pmssAiToolsDecisionFixture']['remove'];
}
function is_executable(string $path): bool
{
    $GLOBALS['pmssAiToolsDecisionFixture']['events'][] = ['executable', $path];
    return $GLOBALS['pmssAiToolsDecisionFixture']['executable'];
}
function file_get_contents(string $path)
{
    $GLOBALS['pmssAiToolsDecisionFixture']['events'][] = ['read', $path];
    return 'package fixture';
}
function pmssJsonDecodeAssoc(string $raw): ?array
{
    $GLOBALS['pmssAiToolsDecisionFixture']['events'][] = ['decode', $raw];
    return $GLOBALS['pmssAiToolsDecisionFixture']['package'];
}

namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class AiToolsInstallDecisionCharacterizationTest extends TestCase
{
    /** Select bounded decision code before any command or download can run. */
    private function decisionSource(string $start, string $end): string
    {
        $source = $this->pmssReadRepoFile('scripts/lib/update/apps/aiToolsInstall.php');
        $first = strpos($source, $start);
        $this->assertTrue($first !== false);
        $last = strpos($source, $end, $first);
        $this->assertTrue($last !== false);
        return 'namespace PMSS\\Tests\\AiToolsInstallDecisionFixture; '.substr($source, $first, $last - $first);
    }

    public function testNodeRefreshKeepsRemovalFailureAndDryRunOrder(): void
    {
        $decision = $this->decisionSource('        if (!$dryRun && pmssRootOwnedTreeNeedsRefresh($nodeDir))',
            "            runStep('Ensuring AI tools install root exists'");
        foreach ([[true, true, false, false], [false, false, false, true], [false, false, false, false],
            [false, true, false, false], [false, true, true, false], [false, true, true, true]] as $case) {
            [$dryRun, $refresh, $remove, $executable] = $case;
            $nodeDir = '/opt/pmss/ai-tools/node-fixture';
            $nodeBinary = $nodeDir.'/bin/node';
            $original = $nodeBinary;
            $events = [];
            $GLOBALS['pmssAiToolsDecisionFixture'] = compact('refresh', 'remove', 'executable', 'events');
            $logger = static function (string $message): void { $GLOBALS['pmssAiToolsDecisionFixture']['events'][] = ['log', $message]; };
            try {
                eval($decision.'$GLOBALS["pmssAiToolsDecisionFixture"]["events"][] = ["download"]; }');
                $expected = [];
                if (!$dryRun) $expected[] = ['refresh', $nodeDir];
                if (!$dryRun && $refresh) {
                    $expected[] = ['log', '[INFO] Refreshing AI tools Node.js runtime with non-root-owned entries: '.$nodeDir];
                    $expected[] = ['remove', $nodeDir];
                    if (!$remove) $expected[] = ['log', '[WARN] Unable to remove AI tools Node.js runtime; skipping its installation'];
                }
                $ready = $dryRun || !$refresh || $remove;
                if ($ready) {
                    $expected[] = ['executable', $original];
                    if (!$executable) $expected[] = ['download'];
                }
                $this->assertSame($ready ? $original : '', $nodeBinary);
                $this->assertSame($expected, $GLOBALS['pmssAiToolsDecisionFixture']['events']);
            } finally {
                unset($GLOBALS['pmssAiToolsDecisionFixture']);
            }
        }
    }

    public function testNpmVersionChecksOnlyReadWhenInstalledAndPinned(): void
    {
        $decision = $this->decisionSource('        if (!$installNeeded && $toolSpec[2]', '        if ($installNeeded) {');
        foreach ([[true, '2.1.112', null], [false, '', null], [false, '2.1.112', null],
            [false, '2.1.112', []], [false, '2.1.112', ['version' => '2.1.112']],
            [false, '2.1.112', ['version' => '2.1.111']]] as $case) {
            [$installNeeded, $pin, $package] = $case;
            $original = $installNeeded;
            $toolSpec = ['Claude Code', '@anthropic-ai/claude-code', $pin, 'claude'];
            $prefixDir = '/opt/pmss/ai-tools/npm/claude';
            $GLOBALS['pmssAiToolsDecisionFixture'] = ['events' => [], 'package' => $package];
            try {
                eval($decision);
                $read = !$original && $pin !== '';
                $this->assertSame($read ? [['read', $prefixDir.'/lib/node_modules/@anthropic-ai/claude-code/package.json'],
                    ['decode', 'package fixture']] : [], $GLOBALS['pmssAiToolsDecisionFixture']['events']);
                $this->assertSame($read ? !is_array($package) || ($package['version'] ?? '') !== $pin : $original, $installNeeded);
            } finally {
                unset($GLOBALS['pmssAiToolsDecisionFixture']);
            }
        }
    }
}
