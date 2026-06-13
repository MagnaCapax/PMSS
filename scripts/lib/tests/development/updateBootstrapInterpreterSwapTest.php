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
 * passthru/exec using that stale path fails with exit 127. The bootstrap must
 * resolve a usable PHP CLI at exec time, with versioned fallbacks for hosts
 * where the generic `php` alternative is temporarily missing.
 *
 * These are source-level assertions (cheap, deterministic) — they pin the fix
 * so it cannot silently regress to PHP_BINARY in a future refactor.
 */
class UpdateBootstrapInterpreterSwapTest extends TestCase
{
    public function testBootstrapExecPathsResolvePhpAtRuntime(): void
    {
        $data = $this->pmssReadRepoFile('scripts/update.php');
        $this->assertStringContainsAllStrings([
            "passthru(pmssShellCommandWithoutInheritedUpdateLock(pmssBootstrapPhpCommand('/scripts/util/update-step2.php'))",
            '$command = pmssBootstrapPhpCommand(__FILE__, $args);',
        ], $data);
        $this->pmssAssertStringNotContainsString(
            'passthru(PHP_BINARY',
            $data,
            'no passthru() may use the stale PHP_BINARY constant (GH#589)'
        );
        $this->pmssAssertStringNotContainsString(
            'escapeshellarg(PHP_BINARY)',
            $data,
            'no escapeshellarg(PHP_BINARY) invocation may remain; use the PHP CLI resolver (GH#589)'
        );
    }

    public function testPhpCliResolverPrefersCurrentlyResolvedPhpCommand(): void
    {
        $binDir = $this->pmssMakeExecutableStub(
            'php',
            "#!/bin/sh\nif [ \"\$1\" = '-r' ]; then exit 0; fi\nexit 0\n",
            'pmss-php-cli-'
        );

        $this->assertEquals($binDir.'/php', trim($this->pmssRunRepoInlinePhpRequire('scripts/lib/tests/common/updateBootstrapShim.php', 'echo pmssResolvePhpCliBinary();', ['PATH' => $binDir])));
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
