<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Guard paired archive-listing commands with intercepted execution and no tar launches. */
final class ArchiveListingCommandSafetyTest extends TestCase
{
    public function testMalformedCommandsRefuseBothListingsBeforeExecution(): void
    {
        foreach (['', "\0", "\0tar -tf fixture", "tar\0 -tf fixture",
            "tar -tf fixture\0", "tar -tf fixture\0ignored", " \0 "] as $command) {
            foreach ([[$command, 'details'], ['paths', $command], [$command, $command]] as $commands) {
                $result = $this->fixture($commands);
                $this->assertSame(['members' => [], 'calls' => []], $result);
            }
        }
    }

    public function testValidCommandBytesOrderAndMemberTypesArePreserved(): void
    {
        // Shell syntax and whitespace are caller-owned; only unusable argv is rejected.
        foreach ([['paths', 'details'], [" \tpaths 'fixture' 2>/dev/null ", "details\n"],
            ['LC_ALL=C tar -tf fixture', 'tar -tvf fixture; :']] as $commands) {
            $result = $this->fixture($commands, [
                [['dir/', 'dir/file', 'dir/link'], 0],
                [['drwx fixture', '-rwx fixture', 'lrwx fixture'], 0],
            ]);
            $this->assertSame(['members' => [
                ['path' => 'dir/', 'type' => 'd'], ['path' => 'dir/file', 'type' => '-'],
                ['path' => 'dir/link', 'type' => 'l'],
            ], 'calls' => $commands], $result);
        }
    }

    public function testFailedEmptyAndMismatchedListingsRetainFailureResults(): void
    {
        // A failed first command never authorizes the details command.
        foreach ([
            [[[], 0]], [[['file'], 1]], [[[], 7]],
            [[['file'], 0], [['-rwx fixture'], 2]],
            [[['file'], 0], [[], 0]],
            [[['file'], 0], [['-rwx fixture', 'drwx fixture'], 0]],
        ] as $responses) {
            $commands = ['paths', 'details'];
            $this->assertSame(['members' => [], 'calls' => array_slice($commands, 0, count($responses))],
                $this->fixture($commands, $responses));
        }
    }

    public function testEmptyDetailRowsRetainTheirUnknownType(): void
    {
        $this->assertSame(['members' => [['path' => 'file', 'type' => '']], 'calls' => ['paths', 'details']],
            $this->fixture(['paths', 'details'], [[['file'], 0], [[''], 0]]));
    }

    /** Load scripts/lib/update/apps/remoteBinary/archive.php behind a recording exec shim. */
    private function fixture(array $commands, array $responses = []): array
    {
        $script = <<<'PHP'
namespace ArchiveListingCommandFixture;
function exec($command, &$output, &$rc) {
    $GLOBALS['calls'][] = $command;
    $response = array_shift($GLOBALS['responses']);
    if ($response === null) throw new \RuntimeException('Unexpected listing execution');
    [$output, $rc] = $response;
    return '';
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/update/apps/remoteBinary/archive.php',
            'ArchiveListingCommandFixture');
        $script .= '$GLOBALS["calls"] = []; $GLOBALS["responses"] = '.var_export($responses, true).';'
            .'$members = pmssPinnedRemoteArchiveMembersRead(...'.var_export($commands, true).');'
            .'echo json_encode(["members" => $members, "calls" => $GLOBALS["calls"]]);';
        return $this->pmssRunInlinePhpJson($script);
    }
}
