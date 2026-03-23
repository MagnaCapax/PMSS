<?php
namespace {
    pmssTestInstallRunUserStepShim('last');
}

namespace PMSS\Tests {

require_once __DIR__.'/../common/FilesystemCleanupTrait.php';
require_once dirname(__DIR__, 2).'/update/users.php';

class UserUpdatePermissionsTest extends TestCase
{
    use FilesystemCleanupTrait;

    public function testRefreshPermissionsBuildsExpectedCommand(): void
    {
        $home = sys_get_temp_dir().'/pmss-perm-cmd-'.bin2hex(random_bytes(4));
        mkdir($home, 0755, true);
        $jsonLog = tempnam(sys_get_temp_dir(), 'pmss-user-perm-json-');
        if ($jsonLog === false) {
            $jsonLog = sys_get_temp_dir().'/pmss-user-perm-json-'.bin2hex(random_bytes(4));
            file_put_contents($jsonLog, '');
        }

        $ctx = [
            'user'     => 'dummy',
            'home'     => $home,
            'user_esc' => escapeshellarg('dummy'),
        ];

        $previousTimeout = getenv('PMSS_COMMAND_TIMEOUT');
        $previousDryRun = getenv('PMSS_DRY_RUN');
        $previousJsonLog = getenv('PMSS_JSON_LOG');
        $previousJsonLogPath = $GLOBALS['PMSS_JSON_LOG_PATH'] ?? null;
        putenv('PMSS_COMMAND_TIMEOUT=321');
        putenv('PMSS_DRY_RUN=1');
        putenv('PMSS_JSON_LOG='.$jsonLog);
        $GLOBALS['PMSS_JSON_LOG_PATH'] = null;
        unset($GLOBALS['PMSS_TEST_RUNUSERSTEP_LAST']);

        try {
            \pmssUserRefreshPermissions($ctx);

            $expectedCommand = \pmssBuildCommand('/scripts/util/userPermissions.php', ['dummy']);
            foreach (['/usr/bin/ionice', '/bin/ionice'] as $ionicePath) {
                if (!is_executable($ionicePath)) {
                    continue;
                }
                $expectedCommand = \pmssBuildCommand($ionicePath, ['-c3', '/scripts/util/userPermissions.php', 'dummy']);
                break;
            }

            $observedCommand = isset($GLOBALS['PMSS_TEST_RUNUSERSTEP_LAST']['command'])
                ? (string) $GLOBALS['PMSS_TEST_RUNUSERSTEP_LAST']['command']
                : $this->findStepCommand($jsonLog, 'Refreshing user permissions');

            $this->assertEquals($expectedCommand, $observedCommand ?? '');
            $this->assertEquals('321', getenv('PMSS_COMMAND_TIMEOUT'));
        } finally {
            if ($previousTimeout === false) {
                putenv('PMSS_COMMAND_TIMEOUT');
            } else {
                putenv('PMSS_COMMAND_TIMEOUT='.$previousTimeout);
            }
            if ($previousDryRun === false) {
                putenv('PMSS_DRY_RUN');
            } else {
                putenv('PMSS_DRY_RUN='.$previousDryRun);
            }
            if ($previousJsonLog === false) {
                putenv('PMSS_JSON_LOG');
            } else {
                putenv('PMSS_JSON_LOG='.$previousJsonLog);
            }
            $GLOBALS['PMSS_JSON_LOG_PATH'] = $previousJsonLogPath;
            unset($GLOBALS['PMSS_TEST_RUNUSERSTEP_LAST']);
            @unlink($jsonLog);
            $this->cleanup($home);
        }
    }

    public function testRefreshPermissionsUpdatesLegacyFile(): void
    {
        $home = sys_get_temp_dir().'/pmss-perm-'.bin2hex(random_bytes(4));
        mkdir($home, 0755, true);
        $legacy = 'dcf21704d49910d1670b3fdd04b37e640b755889';
        file_put_contents($home.'/.rtorrent.rc.custom', "legacy");
        $newContent = 'new-skel';
        putenv('PMSS_SKEL_DIR='.$home);
        try {
            file_put_contents($home.'/.rtorrent.rc.custom', 'legacy');
            $ctx = [
                'user'     => 'dummy',
                'home'     => $home,
                'user_esc' => escapeshellarg('dummy'),
            ];
            \pmssUserRefreshPermissions($ctx);
        } finally {
            putenv('PMSS_SKEL_DIR');
            $this->cleanup($home);
        }
        $this->assertTrue(true);
    }

    private function findStepCommand(string $jsonLog, string $needle): ?string
    {
        $lines = @file($jsonLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return null;
        }
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (!is_array($decoded) || ($decoded['event'] ?? '') !== 'step') {
                continue;
            }
            $entry = $decoded['data'] ?? null;
            if (!is_array($entry)) {
                continue;
            }
            if (strpos((string) ($entry['description'] ?? ''), $needle) === false) {
                continue;
            }
            return isset($entry['command']) ? (string) $entry['command'] : null;
        }
        return null;
    }
}

}
