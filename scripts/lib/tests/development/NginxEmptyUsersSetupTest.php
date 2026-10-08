<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Verify empty managed-user selections without touching nginx or /etc. */
class NginxEmptyUsersSetupTest extends TestCase
{
    private function runSelection(array $selection, array $arguments = []): array
    {
        $root = $this->pmssMakeTempDir('pmss-nginx-empty-users-');
        $script = <<<'PHP'
namespace NginxEmptyUsersFixture;
$GLOBALS['selection'] = __SELECTION__;
$GLOBALS['template'] = __TEMPLATE__;
$GLOBALS['target'] = __TARGET__;
$GLOBALS['setupCalls'] = 0;
$GLOBALS['configTestCalls'] = [];

function pmssManagedUsersSelectFromCommand($command, $user, $options) {
    return $GLOBALS['selection'];
}
function pmssCreateNginxConfigSetup(): array {
    ++$GLOBALS['setupCalls'];
    \file_put_contents($GLOBALS['target'], \file_get_contents($GLOBALS['template']));
    return [];
}
function pmssCreateNginxConfigTestAndMaybeRestart(bool $restart): int {
    $GLOBALS['configTestCalls'][] = $restart;
    return 0;
}
PHP;
        $script = str_replace(
            ['__SELECTION__', '__TEMPLATE__', '__TARGET__'],
            [var_export($selection, true), var_export($this->pmssRepoPath('etc/seedbox/config/template.nginx-site-default'), true), var_export($root.'/default', true)],
            $script
        );
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/nginxConfig/main.php', 'NginxEmptyUsersFixture');
        $script .= '\ob_start(); $rc = pmssCreateNginxConfigMain('.var_export(array_merge(['createNginxConfig.php'], $arguments), true).'); \ob_end_clean();';
        $script .= 'echo json_encode(["rc" => $rc, "setupCalls" => $GLOBALS["setupCalls"], "configTestCalls" => $GLOBALS["configTestCalls"], "site" => \file_exists($GLOBALS["target"]) ? \file_get_contents($GLOBALS["target"]) : null]);';
        return $this->pmssRunInlinePhpJson($script);
    }

    public function testEmptyListingRefreshesDefaultSiteAndTestsConfig(): void
    {
        $result = $this->runSelection(['exitCode' => 0, 'username' => '', 'users' => []]);
        $this->assertSame(0, $result['rc']);
        $this->assertSame(1, $result['setupCalls']);
        $this->assertSame([false], $result['configTestCalls']);
        $this->assertMatches('/location \/\s*\{/', $result['site']);
        $this->assertTrue(strpos($result['site'], 'try_files $uri $uri/ =404;') !== false);
    }

    public function testEmptyListingWithRestartUsesExistingRestartPath(): void
    {
        $result = $this->runSelection(['exitCode' => 0, 'username' => '', 'users' => []], ['--restart']);
        $this->assertSame(0, $result['rc']);
        $this->assertSame(1, $result['setupCalls']);
        $this->assertSame([true], $result['configTestCalls']);
    }

    public function testMissingRequestedUserSkipsSetup(): void
    {
        $result = $this->runSelection(['exitCode' => 1, 'username' => 'missing', 'users' => []], ['--user', 'missing']);
        $this->assertSame(1, $result['rc']);
        $this->assertSame(0, $result['setupCalls']);
        $this->assertSame([], $result['configTestCalls']);
        $this->assertSame(null, $result['site']);
    }

    public function testListingFailureSkipsSetup(): void
    {
        $result = $this->runSelection(['exitCode' => 2, 'username' => '', 'users' => []]);
        $this->assertSame(2, $result['rc']);
        $this->assertSame(0, $result['setupCalls']);
        $this->assertSame([], $result['configTestCalls']);
        $this->assertSame(null, $result['site']);
    }
}
