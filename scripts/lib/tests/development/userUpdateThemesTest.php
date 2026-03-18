<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/update/user/rutorrent.php';

class UserUpdateThemesTest extends TestCase
{
    public function testUpdateThemesUsesSkelOverrideForMissingTheme(): void
    {
        $home = sys_get_temp_dir().'/pmss-user-theme-home-'.bin2hex(random_bytes(4));
        $skel = sys_get_temp_dir().'/pmss-user-theme-skel-'.bin2hex(random_bytes(4));
        @mkdir($home.'/www/rutorrent/plugins/theme/themes', 0755, true);
        @mkdir($skel.'/www/rutorrent/plugins/theme/themes', 0755, true);

        $ctx = [
            'user'               => 'dummy',
            'home'               => $home,
            'user_esc'           => escapeshellarg('dummy'),
            'rutorrent_index_sha'=> '',
        ];

        $jsonLog = $this->tmpFile();
        @file_put_contents($jsonLog, '');

        $previous = $this->stashEnv(['PMSS_DRY_RUN', 'PMSS_JSON_LOG', 'PMSS_SKEL_DIR']);
        putenv('PMSS_DRY_RUN=1');
        putenv('PMSS_JSON_LOG='.$jsonLog);
        putenv('PMSS_SKEL_DIR='.$skel);
        $GLOBALS['PMSS_JSON_LOG_PATH'] = null;

        $cmd = null;
        try {
            \pmssUserUpdateThemes($ctx);
            $cmd = $this->findStepCommand($jsonLog, 'Installing ruTorrent theme Agent34');
        } finally {
            $this->restoreEnv($previous);
            $GLOBALS['PMSS_JSON_LOG_PATH'] = null;
            $this->cleanup($home);
            $this->cleanup($skel);
        }
        @unlink($jsonLog);

        $expected = sprintf(
            'cp -r %s %s',
            escapeshellarg($skel.'/www/rutorrent/plugins/theme/themes/Agent34'),
            escapeshellarg($home.'/www/rutorrent/plugins/theme/themes/')
        );
        $this->assertEquals($expected, $cmd ?? '');
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

            $description = (string) ($entry['description'] ?? '');
            if (strpos($description, $needle) === false) {
                continue;
            }

            return isset($entry['command']) ? (string) $entry['command'] : null;
        }

        return null;
    }

    private function stashEnv(array $names): array
    {
        $previous = [];
        foreach ($names as $name) {
            $previous[$name] = getenv($name);
        }
        return $previous;
    }

    private function restoreEnv(array $previous): void
    {
        foreach ($previous as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name.'='.$value);
            }
        }
    }

    private function tmpFile(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'pmss-user-theme-');
        if ($file === false) {
            $file = sys_get_temp_dir().'/pmss-user-theme-'.bin2hex(random_bytes(4));
            @touch($file);
        }
        return $file;
    }

    private function cleanup(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }
}
