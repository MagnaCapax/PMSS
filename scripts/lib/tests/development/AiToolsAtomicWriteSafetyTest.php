<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** File publication boundaries without creating files or installing AI tools. */
class AiToolsAtomicWriteSafetyTest extends TestCase
{
    public function testDestinationGuardsAndPublicationKeepTheirContracts(): void
    {
        $script = <<<'PHP'
namespace AiToolsAtomicWriteFixture;
function observe($stage, array $args = []) {
    $GLOBALS['events'][] = [$stage, $args];
    if ($GLOBALS['fault'] === $stage) throw $GLOBALS['failure'];
    return $GLOBALS['case'] !== $stage.'-false';
}
function tempnam($directory, $prefix) {
    if (!observe('allocate', [$directory, $prefix])) return false;
    $GLOBALS['temporary'] = $GLOBALS['case'] === 'other-directory'
        ? '/fallback/candidate' : $directory.'/.pmss-codex-candidate';
    return $GLOBALS['temporary'];
}
function pmssFileWriteComplete($path, $content) {
    return observe('write', [$path, $content]);
}
function chmod($path, $mode) { return observe('mode', [$path, $mode]); }
function chown($path, $owner) { return observe('owner', [$path, $owner]); }
function chgrp($path, $group) { return observe('group', [$path, $group]); }
function rename($from, $to) {
    if (!observe('publish', [$from, $to])) return false;
    $GLOBALS['temporary'] = null;
    return true;
}
function is_file($path) { observe('inspect', [$path]); return $GLOBALS['temporary'] === $path; }
function unlink($path) { observe('remove', [$path]); $GLOBALS['temporary'] = null; return true; }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/update/apps/aiToolsCodex.php', 'AiToolsAtomicWriteFixture');
        $script .= <<<'PHP'
$results = [];
foreach (json_decode(getenv('PMSS_TEST_AI_ATOMIC_WRITE'), true) as [$path, $case, $fault, $type]) {
    $GLOBALS['case'] = $case; $GLOBALS['fault'] = $fault;
    $GLOBALS['failure'] = $type === '' ? null : new $type('publication fault');
    $GLOBALS['events'] = []; $GLOBALS['temporary'] = null;
    $result = null; $caught = null;
    try { $result = pmssAiToolsCodexAtomicWrite($path, "payload\0bytes\n", 0750, 17, 23); }
    catch (\Throwable $error) { $caught = $error; }
    $results[] = [$result, $caught === $GLOBALS['failure'], $GLOBALS['events'], $GLOBALS['temporary']];
}
echo json_encode($results);
PHP;
        $cases = [];
        foreach (['', "\0", "\0/fixture/tool", "/fixture/to\0ol", "/fixture/tool\0",
            "/fixture/tool\0suffix", "/fixt\0ure/tool"] as $path) {
            $cases[] = [$path, 'invalid', '', ''];
        }
        // Valid path bytes and binary payloads are not normalized by the writer.
        foreach (['/fixture/tool', '/fixture/tool with spaces', "/fixture/tool\n", './tool'] as $path) {
            $cases[] = [$path, 'ok', '', ''];
        }
        foreach (['allocate', 'write', 'mode', 'owner', 'group', 'publish'] as $stage) {
            $cases[] = ['/fixture/tool', $stage.'-false', '', ''];
            foreach (['RuntimeException', 'Error'] as $type) {
                $cases[] = ['/fixture/tool', 'ok', $stage, $type];
            }
        }
        $cases[] = ['/fixture/tool', 'other-directory', '', ''];
        $results = $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_AI_ATOMIC_WRITE' => json_encode($cases)]);
        $this->assertSame(count($cases), count($results));
        foreach ($cases as $index => [$path, $case, $fault, $type]) {
            if ($case === 'invalid') {
                // Even an allocation attempt would be an observable boundary violation.
                $this->assertSame([false, true, [], null], $results[$index], 'Malformed path '.$index);
                continue;
            }
            $directory = dirname($path);
            $temporary = $case === 'other-directory' ? '/fallback/candidate' : $directory.'/.pmss-codex-candidate';
            $operations = [
                ['allocate', [$directory, '.pmss-codex-']],
                ['write', [$temporary, "payload\0bytes\n"]],
                ['mode', [$temporary, 0750]],
                ['owner', [$temporary, 17]],
                ['group', [$temporary, 23]],
                ['publish', [$temporary, $path]],
            ];
            $events = [];
            foreach ($operations as $operation) {
                $events[] = $operation;
                if ($fault === $operation[0] || $case === $operation[0].'-false'
                    || ($case === 'other-directory' && $operation[0] === 'allocate')) break;
            }
            $allocated = $case !== 'allocate-false' && $fault !== 'allocate';
            if ($allocated) {
                $events[] = ['inspect', [$temporary]];
                if ($case !== 'ok' || $fault !== '') $events[] = ['remove', [$temporary]];
            }
            $expected = $fault !== '' ? null : $case === 'ok';
            $this->assertSame([$expected, true, $events, null], $results[$index], implode(':', [$index, $case, $fault, $type]));
        }
    }
}
