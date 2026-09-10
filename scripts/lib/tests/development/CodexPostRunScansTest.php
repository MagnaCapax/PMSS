<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/**
 * The post-run guards in development/lib/codex-common.sh must inspect the commits made since
 * run start, not only the working tree / origin/main..HEAD: the assistant commits and pushes
 * in-session, which leaves a clean tree and an empty origin/main..HEAD range.
 */
class CodexPostRunScansTest extends TestCase
{
    private function gitHarnessPrelude(string $repo): string
    {
        $root = $this->pmssRepoPath('');
        $git = 'git -C "$repo" -c user.name=t -c user.email=t@localhost';

        return "#!/usr/bin/env bash\n"
            ."set -euo pipefail\n"
            ."source ".escapeshellarg($root.'/development/lib/codex-common.sh')."\n"
            ."repo=".escapeshellarg($repo)."\n"
            ."git -C \"\$repo\" init -q\n"
            .$git." commit -q --allow-empty -m init\n"
            ."pre=\$(git -C \"\$repo\" rev-parse HEAD)\n";
    }

    public function testFrozenPathScanReportsCommittedChangesSinceRunStart(): void
    {
        $repo = $this->pmssMakeTempDir('pmss-postrun-frozen-');
        $git = 'git -C "$repo" -c user.name=t -c user.email=t@localhost';

        $output = $this->pmssRunShellHarness(
            $this->gitHarnessPrelude($repo)
            ."mkdir -p \"\$repo/development\" && echo x > \"\$repo/development/x.sh\"\n"
            ."git -C \"\$repo\" add -A && ".$git." commit -q -m 'touch frozen'\n"
            ."if codex_scan_frozen_paths \"\$repo\" \"\$pre\" 2>\"\$repo.err\"; then rc=0; else rc=\$?; fi\n"
            ."printf 'committed: rc=%s hit=%s\\n' \"\$rc\" \"\$(grep -c 'development/x.sh (committed)' \"\$repo.err\" || true)\"\n"
            ."if codex_scan_frozen_paths \"\$repo\" 2>/dev/null; then rc=0; else rc=\$?; fi\n"
            ."printf 'tree-only: rc=%s\\n' \"\$rc\"\n"
            ."if codex_scan_frozen_paths \"\$repo\" \"\$(git -C \"\$repo\" rev-parse HEAD)\" 2>/dev/null; then rc=0; else rc=\$?; fi\n"
            ."printf 'clean-range: rc=%s\\n' \"\$rc\"\n"
        );

        // With the run-start base the committed frozen change is reported (rc=1, path named);
        // the tree-only call (no base) and a base equal to HEAD both stay clean.
        $this->assertStringContainsAllStrings(["committed: rc=1 hit=1\n", "tree-only: rc=0\n", "clean-range: rc=0"], $output);
    }

    public function testCommitMessagePiiScanUsesRunStartBase(): void
    {
        $repo = $this->pmssMakeTempDir('pmss-postrun-pii-');
        $git = 'git -C "$repo" -c user.name=t -c user.email=t@localhost';
        // Assembled at runtime so no address literal sits in the repository.
        $address = 'someone'.'@'.'example.com';

        $output = $this->pmssRunShellHarness(
            $this->gitHarnessPrelude($repo)
            .$git." commit -q --allow-empty -m 'mail ".$address." about it'\n"
            ."if codex_scan_commit_messages_for_pii \"\$repo\" \"\$pre\" 2>/dev/null; then rc=0; else rc=\$?; fi\n"
            ."printf 'run-start-base: rc=%s\\n' \"\$rc\"\n"
            ."if codex_scan_commit_messages_for_pii \"\$repo\" 2>/dev/null; then rc=0; else rc=\$?; fi\n"
            ."printf 'default-base: rc=%s\\n' \"\$rc\"\n"
        );

        // origin/main..HEAD is empty once the agent has pushed (or the ref is absent): the
        // default base sees nothing, the run-start base catches the message.
        $this->assertStringContainsAllStrings(["run-start-base: rc=1\n", "default-base: rc=0"], $output);
    }

    public function testRunnerPassesRunStartHeadToBothScans(): void
    {
        $runner = $this->pmssReadRepoFile('development/codex-run.sh');

        $this->assertStringContainsAllStrings([
            'pre_head="$(git -C "$ROOT" rev-parse HEAD',
            'codex_scan_frozen_paths "$ROOT" "$pre_head"',
            'codex_scan_commit_messages_for_pii "$ROOT" "${pre_head:-origin/main}"',
        ], $runner);
    }
}
