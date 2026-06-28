<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/**
 * Characterize skip logging for nginx user config generation.
 */
class NginxConfigSkipLoggingTest extends TestCase
{
    public function testSkipHelperMirrorsWarningsToSharedAndUserLogs(): void
    {
        $repoRoot = $this->pmssRepoRoot();
        $script = <<<'PHP'
$repoRoot = __REPO_ROOT__;
$GLOBALS['PMSS_NGINX_SKIP_APPEND_LOGS'] = [];
$GLOBALS['PMSS_NGINX_SKIP_USER_LOGS'] = [];

function pmssCreateNginxConfigAppendLog(string $message): void
{
    $GLOBALS['PMSS_NGINX_SKIP_APPEND_LOGS'][] = $message;
}
__USER_LOG_SHIM__

require $repoRoot.'/scripts/lib/nginxConfig/userConfigsGenerate.php';

pmssCreateNginxConfigLogSkippedUser('alice', 'missing .rtorrent.rc prerequisite');

echo json_encode([
    'append' => $GLOBALS['PMSS_NGINX_SKIP_APPEND_LOGS'],
    'user' => $GLOBALS['PMSS_NGINX_SKIP_USER_LOGS'],
]);
PHP;

        $script = str_replace(
            ['__REPO_ROOT__', '__USER_LOG_SHIM__'],
            [var_export($repoRoot, true), $this->pmssInlinePhpUserLogShim('PMSS_NGINX_SKIP_USER_LOGS')],
            $script
        );
        $decoded = $this->pmssRunInlinePhpJson($script);

        $this->assertEquals(
            ['WARN: skipping nginx config for alice: missing .rtorrent.rc prerequisite'],
            $decoded['append']
        );
        $this->assertEquals(
            [['alice', 'WARN: skipping nginx config for alice: missing .rtorrent.rc prerequisite']],
            $decoded['user']
        );
    }

    public function testGeneratorLogsMissingRtorrentPrerequisite(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/nginxConfig/userConfigsGenerate.php', [
            'function pmssCreateNginxConfigLogSkippedUser(string $user, string $reason): void',
            "pmssCreateNginxConfigLogSkippedUser(\$thisUser, 'missing .rtorrent.rc prerequisite');",
        ]);
    }

    public function testGeneratorLogsInvalidLighttpdPortSkip(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/nginxConfig/userConfigsGenerate.php', [
            'lighttpd port missing or invalid after refresh attempt',
            'WARN: skipping nginx config for %s: %s',
        ]);
    }
}
