<?php
namespace PMSS\Tests;

/**
 * Hermetic guards for the GH #326 browser console (ADR 0031).
 *
 * Source-inspection only — no processes spawned, no sockets, no side effects.
 * Asserts the security and provisioning invariants that must not regress:
 * pinned-binary install, loopback-socket proxy, customer-tree separation,
 * no-root / no-request-data-in-command, and disabled-path fail-soft behaviour.
 */
class BrowserConsoleArtifactsTest extends TestCase
{
    private function repoFile(string $rel): string
    {
        $path = dirname(__DIR__, 4).'/'.$rel;
        $this->assertTrue(is_file($path), "missing artifact: {$rel}");
        return (string) file_get_contents($path);
    }

    public function testInstallerUsesPinnedChecksumPath(): void
    {
        $src = $this->repoFile('scripts/lib/update/apps/ttyd.php');
        // Pinned version + SHA256 + arch gate + supported fetch helper (no ad-hoc curl|bash).
        $this->assertStringContainsString('1.7.7', $src);
        $this->assertMatches('/[a-f0-9]{64}/', $src, 'ttyd installer must pin a SHA256');
        $this->assertStringContainsString('pmssPinnedRemoteAmd64ArtifactsSupported', $src);
        $this->assertStringContainsString('pmssFetchPinnedRemoteFile', $src);
        $this->assertStringContainsString('/usr/bin/ttyd', $src);
    }

    public function testLauncherKeepsCustomerTreeSeparation(): void
    {
        $src = $this->repoFile('etc/skel/www/console.php');
        // AGENTS.md INVIOLABLE: customer-facing PHP must never require/include from
        // the operator-only /scripts/ tree. console.php is designed self-contained,
        // so assert it carries no include/require statement at all (strongest form).
        $this->assertFalse(
            (bool) preg_match('/^[^\S\r\n]*(require|require_once|include|include_once)\b/m', $src),
            'console.php must contain no include/require statement (customer-tree separation)'
        );
    }

    public function testLauncherIsUnprivilegedAndInjectionSafe(): void
    {
        $src = $this->repoFile('etc/skel/www/console.php');
        // Loopback UNIX socket, origin-checked, and no root.
        $this->assertStringContainsString('console.sock', $src);
        $this->assertStringContainsString('--check-origin', $src);
        $this->assertStringNotContainsString('sudo', $src);
        $this->assertStringNotContainsString(' su ', $src);
        // Mode is a fixed enum: request data must not build the spawned command.
        $this->assertStringContainsString("'persist'", $src);
        $this->assertFalse(
            (bool) preg_match('/exec\([^;]*\$_(GET|POST|REQUEST)/', $src),
            'console.php must not interpolate request data into an exec command'
        );
    }

    public function testLauncherFailsSoftWhenGatewayAbsent(): void
    {
        $src = $this->repoFile('etc/skel/www/console.php');
        // Disabled-path behaviour: guard on the binary before spawning.
        $this->assertStringContainsString('is_executable', $src);
        $this->assertStringContainsString('--once', $src);
    }

    public function testProxyBlockUsesLoopbackSocketUpgrade(): void
    {
        $src = $this->repoFile('etc/seedbox/config/template.lighttpd');
        $this->assertStringContainsString('^/user-##username/console/', $src);
        $this->assertStringContainsString('console.sock', $src);
        $this->assertStringContainsString('"upgrade" => "enable"', $src);
    }

    public function testConsoleButtonIsAdditive(): void
    {
        $src = $this->repoFile('etc/skel/www/info.php');
        $this->assertStringContainsString('console.php', $src);
        // Must not have removed the existing stats include.
        $this->assertStringContainsString('stats.php', $src);
    }

    public function testConsoleButtonRequiresLocalBackendArtifact(): void
    {
        $src = $this->repoFile('etc/skel/www/info.php');
        // guiv may heal info.php before console.php reaches the same user tree;
        // hide the buttons unless their target exists in this customer directory.
        $this->assertMatches(
            '/is_file\(__DIR__\s*\.\s*[\'"]\/console\.php[\'"]\)/',
            $src,
            'info.php must hide console buttons when console.php is not deployed locally'
        );
    }

    public function testConsolePhpRegisteredForExistingUserDistribution(): void
    {
        // Regression guard (2026-07-11): a NEW skel www file reaches EXISTING users
        // only if it is listed in pmssUserApplySkeletonFiles()'s distribution
        // allowlist. Without this, the info.php "Open console" button 404s for every
        // current customer (caught on first fleet deploy to arkstream).
        $src = $this->repoFile('scripts/lib/update/users/filesystem.php');
        $this->assertStringContainsString("'www/console.php'", $src);
    }
}
