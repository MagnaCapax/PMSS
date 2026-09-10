<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class AgenticRefactorScopeClaimTest extends TestCase
{
    public function testClaimFilterSkipsHeldCandidatesAndReportsCounts(): void
    {
        $root = $this->pmssRepoPath('');
        $workDir = $this->pmssMakeTempDir('pmss-refactor-claims-');
        $claimsDir = $workDir.'/claims';
        $candidates = $workDir.'/candidate-files.txt';
        @mkdir($claimsDir, 0755, true);
        @mkdir($claimsDir.'/scripts_lib_runtime.php', 0755, true);
        file_put_contents($candidates, "scripts/lib/runtime.php\nscripts/update.php\n\nscripts/lib/log.php\n");

        $output = $this->pmssRunShellHarness(
            "#!/usr/bin/env bash\n"
            ."set -euo pipefail\n"
            ."source ".escapeshellarg($root.'/development/lib/codex-common.sh')."\n"
            ."declare -a claimed=()\n"
            ."claimed_count=0\n"
            ."orig_count=0\n"
            ."codex_scope_claim_filter_candidates ".escapeshellarg($claimsDir).' '.escapeshellarg($candidates)." claimed claimed_count orig_count\n"
            ."printf 'counts=%s/%s\\n' \"\$claimed_count\" \"\$orig_count\"\n"
            ."printf 'candidates=' && tr '\\n' ',' <".escapeshellarg($candidates)." && printf '\\n'\n"
            ."printf 'claims=%s\\n' \"\${claimed[*]}\"\n"
        );

        $this->assertStringContainsAllStrings(["counts=2/4\n", "candidates=scripts/update.php,scripts/lib/log.php,\n", 'claims=scripts_update.php scripts_lib_log.php'], $output);
    }

    public function testClaimReleaseOnlyRemovesOwnedClaimKeys(): void
    {
        $root = $this->pmssRepoPath('');
        $claimsDir = $this->pmssMakeTempDir('pmss-refactor-release-');
        @mkdir($claimsDir.'/held_by_other', 0755, true);
        @mkdir($claimsDir.'/owned_one', 0755, true);
        @mkdir($claimsDir.'/owned_two', 0755, true);

        $output = $this->pmssRunShellHarness(
            "#!/usr/bin/env bash\n"
            ."set -euo pipefail\n"
            ."source ".escapeshellarg($root.'/development/lib/codex-common.sh')."\n"
            ."codex_scope_claim_release ".escapeshellarg($claimsDir)." owned_one owned_two\n"
            ."[[ -d ".escapeshellarg($claimsDir.'/held_by_other')." ]] && printf 'held=kept\\n'\n"
            ."[[ ! -e ".escapeshellarg($claimsDir.'/owned_one')." ]] && printf 'owned_one=removed\\n'\n"
            ."[[ ! -e ".escapeshellarg($claimsDir.'/owned_two')." ]] && printf 'owned_two=removed\\n'\n"
        );

        $this->assertStringContainsAllStrings(["held=kept\n", "owned_one=removed\n", 'owned_two=removed'], $output);
    }

    public function testClaimFilterPrunesStaleClaimDirectories(): void
    {
        $root = $this->pmssRepoPath('');
        $claimsDir = $this->pmssMakeTempDir('pmss-refactor-stale-');
        $candidates = $this->pmssMakeTempPath('pmss-refactor-candidates-');
        @mkdir($claimsDir.'/stale_claim', 0755, true);
        @mkdir($claimsDir.'/fresh_claim', 0755, true);
        file_put_contents($candidates, "scripts/update.php\n");
        touch($claimsDir.'/stale_claim', time() - 3600);

        $output = $this->pmssRunShellHarness(
            "#!/usr/bin/env bash\n"
            ."set -euo pipefail\n"
            ."source ".escapeshellarg($root.'/development/lib/codex-common.sh')."\n"
            ."declare -a claimed=()\n"
            ."claimed_count=0\n"
            ."orig_count=0\n"
            ."codex_scope_claim_filter_candidates ".escapeshellarg($claimsDir).' '.escapeshellarg($candidates)." claimed claimed_count orig_count 30\n"
            ."[[ ! -e ".escapeshellarg($claimsDir.'/stale_claim')." ]] && printf 'stale=removed\\n'\n"
            ."[[ -d ".escapeshellarg($claimsDir.'/fresh_claim')." ]] && printf 'fresh=kept\\n'\n"
        );

        $this->assertStringContainsAllStrings(["stale=removed\n", 'fresh=kept'], $output);
    }

    public function testPrepareAgentExecCommandSucceedsWithEmptyPassthroughArgs(): void
    {
        $root = $this->pmssRepoPath('');
        $assistDir = $root.'/development/assistants';

        $output = $this->pmssRunShellHarness(
            "#!/usr/bin/env bash\n"
            ."set -euo pipefail\n"
            ."source ".escapeshellarg($root.'/development/lib/codex-common.sh')."\n"
            ."agent=''\n"
            ."exec_cmd='codex exec'\n"
            ."declare -a passthrough=()\n"
            ."if codex_prepare_agent_exec_command ".escapeshellarg($assistDir)." codex agent exec_cmd passthrough; then rc=0; else rc=\$?; fi\n"
            ."printf 'empty: rc=%s exec=[%s]\\n' \"\$rc\" \"\$exec_cmd\"\n"
            ."exec_cmd='codex exec'\n"
            ."declare -a passthrough2=(--yolo)\n"
            ."if codex_prepare_agent_exec_command ".escapeshellarg($assistDir)." codex agent exec_cmd passthrough2; then rc=0; else rc=\$?; fi\n"
            ."printf 'yolo: rc=%s exec=[%s]\\n' \"\$rc\" \"\$exec_cmd\"\n"
        );

        // An empty passthrough list must not fail the launcher (the refactor lane exited 1 on
        // every cron run for this reason); a non-empty list must still be appended, normalized.
        $this->assertStringContainsAllStrings(["empty: rc=0 exec=[codex exec]\n", 'yolo: rc=0 exec=[codex exec -c approval_policy=\"never\"]'], $output);
    }

    public function testLauncherUsesSharedScopeClaimHelperOnly(): void
    {
        $launcher = $this->pmssReadRepoFile('development/agentic-refactor.sh')
            .$this->pmssReadRepoFile('development/lib/refactor-context.sh');
        $library = $this->pmssReadRepoFile('development/lib/codex-common.sh');

        $this->assertStringContainsAndOmitsStrings(['codex_scope_claim_filter_candidates'], ['candidate-files.filtered.txt', 'pmss_refactor'.'_key='], $launcher);
        $this->assertStringContainsString('codex_scope_claim_filter_candidates()', $library);
    }
}
