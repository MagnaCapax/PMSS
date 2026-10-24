<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserRootArtifactPermissionsTest extends TestCase
{
    /** Exercise the lstat decision and captured user log without system changes. */
    private function decide(string $path, $stat, string $writer = 'scripts/util/writeHomeMarker.php'): array
    {
        $code = 'function pmssUserLog($user, $message) { $GLOBALS["logs"][] = $message; } '
            .'$GLOBALS["logs"] = []; '
            .'require '.var_export($this->pmssRepoPath('scripts/lib/user/rootArtifactPermissions.php'), true).'; '
            .'$allowed = pmssUserRootArtifactRepairable("testuser", '
            .var_export($path, true).', '.var_export($stat, true).', '.var_export($writer, true).'); '
            .'echo json_encode([$allowed, $GLOBALS["logs"]]);';
        $run = $this->pmssExecInlinePhpWithTempStderr($code);
        $this->assertSame(0, $run['result']['rc']);
        return json_decode($run['result']['output'], true);
    }

    public function testUserOwnedRegularFileRemainsUnadopted(): void
    {
        $home = $this->pmssMakeTempDir('pmss-root-artifact-permissions-');
        $path = $home.'/.billingServiceId';
        file_put_contents($path, "123\n");
        chmod($path, 0660);
        $before = lstat($path);
        $this->assertTrue($before['uid'] !== 0, 'fixture must be non-root-owned');

        [$allowed, $logs] = $this->decide($path, $before);

        $this->assertFalse($allowed);
        $this->assertSame($before['uid'], lstat($path)['uid']);
        $this->assertSame($before['mode'], lstat($path)['mode']);
        $this->assertSame("123\n", file_get_contents($path));
        $this->assertSame(1, count($logs));
        $this->assertStringContainsAllStrings([
            '.billingServiceId', 'uid='.$before['uid'], 'left unadopted',
            'readers require a root-owned file', 'scripts/util/writeHomeMarker.php',
        ], $logs[0]);
    }

    /** A tenant-written recipient must not become the trusted root-owned file (notificationEmail.php). */
    public function testTenantWrittenNotifyEmailRemainsUnadopted(): void
    {
        $home = $this->pmssMakeTempDir('pmss-root-artifact-permissions-');
        $path = $home.'/.notifyEmail';
        file_put_contents($path, "elsewhere@example.test\n");
        chmod($path, 0644);
        $before = lstat($path);
        $this->assertTrue($before['uid'] !== 0, 'fixture must be non-root-owned');

        [$allowed, $logs] = $this->decide($path, $before, 'billing-system provisioning');

        $this->assertFalse($allowed);
        $this->assertSame($before['mode'], lstat($path)['mode']);
        $this->assertSame(1, count($logs));
        $this->assertStringContainsAllStrings(['.notifyEmail', 'left unadopted', 'billing-system provisioning'], $logs[0]);
        $this->assertStringNotContainsString('elsewhere@example.test', $logs[0]);
    }

    public function testRootOwnedRegularFileIsEligibleForExistingRepairs(): void
    {
        foreach (['.billingId', '.notifyEmail', '.quota', '.trafficData'] as $name) {
            [$allowed, $logs] = $this->decide('/home/testuser/'.$name, ['mode' => 0100600, 'uid' => 0]);
            $this->assertTrue($allowed, $name);
            $this->assertSame([], $logs, $name);
        }
    }

    /** Every root-owned entry whose reader trusts a root owner goes through the gate. */
    public function testUserPermissionsGatesEveryRootTrustedEntry(): void
    {
        $source = $this->pmssReadRepoFile('scripts/util/userPermissions.php');
        $this->assertStringContainsString('["/home/{$thisUser}/.billingId", 0640]', $source);
        $this->assertStringContainsString('["/home/{$thisUser}/.notifyEmail", "root:{$thisUser}"]', $source);
        $this->assertStringContainsAllStrings([
            '"/home/{$thisUser}/.billingServiceId" => ',
            '"/home/{$thisUser}/.billingId" => ',
            '"/home/{$thisUser}/.billingClientId" => ',
            '"/home/{$thisUser}/.notifyEmail" => ',
            '"/home/{$thisUser}/.quota" => ',
            "\$trafficPaths['normal'] => ",
            "\$trafficPaths['local'] => ",
            "\$trafficPaths['ingress'] => ",
            "\$trafficPaths['ingressLocal'] => ",
            "pmssUserPermissionsOpenedGroupSet(\$thisUser, \$homeDir, \$path, substr(\$owner, strpos(\$owner, ':') + 1), 0);",
            "&& (\$rootArtifactRepairable[\$path] ?? true);",
        ], $source);
        $this->assertSame(2, substr_count($source, 'array_key_exists($path, $rootArtifactRepairable) && !$rootArtifactRepairable[$path]'));
    }

    public function testSymlinkIsLeftAsIsWithoutFollowingTarget(): void
    {
        $home = $this->pmssMakeTempDir('pmss-root-artifact-permissions-');
        $target = $home.'/target';
        $path = $home.'/.billingClientId';
        file_put_contents($target, "456\n");
        chmod($target, 0660);
        $this->pmssCreateSymlinkOrSkip($target, $path);
        $before = lstat($target);

        [$allowed, $logs] = $this->decide($path, lstat($path));

        $this->assertFalse($allowed);
        $this->assertTrue(is_link($path));
        $this->assertSame($before['mode'], lstat($target)['mode']);
        $this->assertSame($before['uid'], lstat($target)['uid']);
        $this->assertSame(1, count($logs));
        $this->assertStringContainsAllStrings(['.billingClientId', 'left as-is'], $logs[0]);
    }

    public function testDirectoryIsLeftAsIsAndAbsentPathIsQuiet(): void
    {
        $home = $this->pmssMakeTempDir('pmss-root-artifact-permissions-');
        $path = $home.'/.billingId';
        mkdir($path);

        [$allowed, $logs] = $this->decide($path, lstat($path));
        $this->assertFalse($allowed);
        $this->assertTrue(is_dir($path));
        $this->assertSame(1, count($logs));
        $this->assertStringContainsAllStrings(['.billingId', 'left as-is'], $logs[0]);

        [$allowed, $logs] = $this->decide($home.'/.billingClientId', @lstat($home.'/.billingClientId'));
        $this->assertFalse($allowed);
        $this->assertSame([], $logs);
    }
}
