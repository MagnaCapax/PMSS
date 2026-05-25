<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/**
 * Regression test for GH#589: update.php must NOT invoke child PHP processes
 * via the PHP_BINARY constant in code paths that can fire AFTER a --dist-upgrade
 * swaps the interpreter (php7.4 → php8.2 on Debian 11→12).
 *
 * PHP_BINARY is frozen at SAPI startup to the launching interpreter's path
 * (e.g. /usr/bin/php7.4). After apt removes php7.4 mid-run, any
 * passthru/exec using that stale path fails with exit 127. The fix (commit
 * f4e6e71b) replaces PHP_BINARY with the literal 'php', resolved via $PATH /
 * Debian alternatives at exec time.
 *
 * These are source-level assertions (cheap, deterministic) — they pin the fix
 * so it cannot silently regress to PHP_BINARY in a future refactor.
 */
class UpdateBootstrapInterpreterSwapTest extends TestCase
{
    public function testStep2HandoffUsesPathResolvedPhp(): void
    {
        $data = $this->pmssReadRepoFile('scripts/update.php');
        $this->assertTrue(
            strpos($data, "passthru('php /scripts/util/update-step2.php'") !== false,
            "step2 handoff must use literal 'php' (PATH-resolved), not PHP_BINARY (GH#589)"
        );
    }

    public function testSelfRefreshUsesPathResolvedPhp(): void
    {
        $data = $this->pmssReadRepoFile('scripts/update.php');
        $this->assertTrue(
            strpos($data, "escapeshellarg('php').' '.escapeshellarg(__FILE__)") !== false,
            "self-refresh re-exec must use literal 'php', not PHP_BINARY (GH#589)"
        );
    }

    public function testNoPhpBinaryInExecPaths(): void
    {
        $data = $this->pmssReadRepoFile('scripts/update.php');
        // Code-level invocations (not comments) must not reference PHP_BINARY.
        // The fix converted every passthru/runSoft site to literal 'php'.
        $this->assertTrue(
            strpos($data, 'passthru(PHP_BINARY') === false,
            "no passthru() may use the stale PHP_BINARY constant (GH#589)"
        );
        $this->assertTrue(
            strpos($data, 'escapeshellarg(PHP_BINARY)') === false,
            "no escapeshellarg(PHP_BINARY) invocation may remain — use 'php' (GH#589)"
        );
    }

    public function testDiagnosticsHelperUsesPathResolvedPhp(): void
    {
        $data = $this->pmssReadRepoFile('scripts/lib/agentDiagnostics.php');
        $this->assertTrue(
            strpos($data, 'escapeshellarg(PHP_BINARY)') === false,
            "agentDiagnostics must not use PHP_BINARY for child invocation (GH#589)"
        );
        $this->assertTrue(
            strpos($data, "escapeshellarg('php')") !== false,
            "agentDiagnostics must invoke child scripts via literal 'php' (GH#589)"
        );
    }
}
