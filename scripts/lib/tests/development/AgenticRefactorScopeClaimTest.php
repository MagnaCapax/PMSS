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

        $this->assertStringContainsString("counts=2/4\n", $output);
        $this->assertStringContainsString("candidates=scripts/update.php,scripts/lib/log.php,\n", $output);
        $this->assertStringContainsString('claims=scripts_update.php scripts_lib_log.php', $output);
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

        $this->assertStringContainsString("held=kept\n", $output);
        $this->assertStringContainsString("owned_one=removed\n", $output);
        $this->assertStringContainsString('owned_two=removed', $output);
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

        $this->assertStringContainsString("stale=removed\n", $output);
        $this->assertStringContainsString('fresh=kept', $output);
    }

    public function testLauncherUsesSharedScopeClaimHelperOnly(): void
    {
        $launcher = $this->pmssReadRepoFile('development/agentic-refactor.sh');
        $library = $this->pmssReadRepoFile('development/lib/codex-common.sh');

        $this->assertStringContainsString('codex_scope_claim_filter_candidates', $launcher);
        $this->assertStringContainsString('codex_scope_claim_filter_candidates()', $library);
        $this->assertStringNotContainsString('candidate-files.filtered.txt', $launcher);
        $this->assertStringNotContainsString('pmss_refactor'.'_key=', $launcher);
    }
}
