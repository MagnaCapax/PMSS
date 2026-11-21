<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** static-lints.sh runs every blocking static check of CI's build job, so they can be caught before a push. */
class StaticLintsTest extends TestCase
{
    /** Script names static-lints.sh runs, read from its check list. */
    private function listed(): array
    {
        preg_match('/^for check in ([^;]+);/m', $this->pmssReadRepoFile('scripts/testing/static-lints.sh'), $m);
        return preg_split('/\s+/', trim($m[1] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
    }

    /** scripts/testing/*.sh that CI's build job runs in a blocking (not advisory) step. */
    private function blockingBuildChecks(): array
    {
        $build = preg_match('/^  build:\n(.*?)(?=^  [a-z0-9_-]+:\n|\z)/ms',
            $this->pmssReadRepoFile('.github/workflows/ci.yml'), $m) === 1 ? $m[1] : '';
        $checks = [];
        foreach (preg_split('/^      - name: /m', $build) as $step) {
            $name = strtok($step, "\n");
            if (stripos((string) $name, 'advisory') !== false) {
                continue;
            }
            preg_match_all('#^\s+(?:[A-Z_]+=\S+\s+)*(?:bash\s+)?scripts/testing/([A-Za-z0-9_-]+\.sh)(.*)$#m', $step, $runs, PREG_SET_ORDER);
            foreach ($runs as $run) {
                if (strpos($run[2], '|| true') === false && $run[1] !== 'check-tools.sh') {
                    $checks[] = $run[1];
                }
            }
        }
        return $checks;
    }

    public function testEveryBlockingBuildCheckIsListed(): void
    {
        $blocking = $this->blockingBuildChecks();
        $this->assertTrue(count($blocking) >= 5, 'blocking build checks found in ci.yml: '.implode(', ', $blocking));
        $listed = $this->listed();
        foreach ($blocking as $check) {
            $this->assertTrue(in_array($check, $listed, true), "ci.yml build step $check is missing from static-lints.sh");
        }
    }

    public function testEveryListedCheckExists(): void
    {
        foreach ($this->listed() as $check) {
            $this->assertTrue(is_file($this->pmssRepoPath('scripts/testing/'.$check)), "static-lints.sh lists missing $check");
        }
    }

    public function testStaticLintsSkipsTheDevSuite(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/testing/test-php.sh', [
            'PMSS_TESTING_SKIP_DEV_TESTS', 'scripts/lib/tests/development/Runner.php',
        ], '', 'test-php.sh skips Runner.php when asked: ');
        $this->pmssAssertRepoFileContainsString('scripts/testing/static-lints.sh', 'PMSS_TESTING_SKIP_DEV_TESTS=1');
    }
}
