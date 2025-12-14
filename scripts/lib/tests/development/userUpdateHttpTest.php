<?php
namespace {
    if (!function_exists('runUserStep')) {
        function runUserStep(string $user, string $description, string $command): int
        {
            return 0;
        }
    }
}

namespace PMSS\Tests {

require_once dirname(__DIR__, 2).'/update/user/http.php';

class UserUpdateHttpTest extends TestCase
{
    public function testConfigureHttpCreatesTempDirectory(): void
    {
        $tempHome = sys_get_temp_dir().'/pmss-http-'.bin2hex(random_bytes(4));
        mkdir($tempHome.'/.lighttpd', 0755, true);
        file_put_contents($tempHome.'/.lighttpd/php.ini', "display_errors = On\n");

        $ctx = [
            'user'     => 'dummy',
            'home'     => $tempHome,
            'user_esc' => escapeshellarg('dummy'),
        ];

        try {
            \pmssUserConfigureHttp($ctx);

            $ini = file_get_contents($tempHome.'/.lighttpd/php.ini');
            $this->assertTrue(strpos($ini, 'error_log') !== false);
        } finally {
            $this->cleanup($tempHome);
        }
    }

    public function testConfigureHttpUsesDefaultSkelPathForIrssiCopy(): void
    {
        $home = sys_get_temp_dir().'/pmss-http-skel-default-'.bin2hex(random_bytes(4));
        mkdir($home, 0755, true);
        $ctx = [
            'user'     => 'dummy',
            'home'     => $home,
            'user_esc' => escapeshellarg('dummy'),
        ];

        $jsonLog = $this->tmpFile();
        file_put_contents($jsonLog, '');

        $previous = $this->stashEnv(['PMSS_DRY_RUN', 'PMSS_JSON_LOG', 'PMSS_SKEL_DIR']);
        putenv('PMSS_DRY_RUN=1');
        putenv('PMSS_JSON_LOG='.$jsonLog);
        putenv('PMSS_SKEL_DIR');
        if (function_exists('\\pmssResetJsonLogPath')) {
            \pmssResetJsonLogPath();
        }

        $cmd = null;
        try {
            \pmssUserConfigureHttp($ctx);
            $cmd = $this->findStepCommand($jsonLog, 'Copying irssi skeleton config');
        } finally {
            $this->restoreEnv($previous);
            if (function_exists('\\pmssResetJsonLogPath')) {
                \pmssResetJsonLogPath();
            }
            $this->cleanup($home);
        }
        @unlink($jsonLog);

        $expected = sprintf('cp /etc/skel/.irssi/config %s/', escapeshellarg($home.'/.irssi'));
        $this->assertEquals($expected, $cmd ?? '');
    }

    public function testConfigureHttpUsesSkelOverrideForIrssiCopy(): void
    {
        $home = sys_get_temp_dir().'/pmss-http-skel-override-home-'.bin2hex(random_bytes(4));
        mkdir($home, 0755, true);
        $skel = sys_get_temp_dir().'/pmss-http-skel-override-skel-'.bin2hex(random_bytes(4));
        @mkdir($skel.'/.irssi', 0755, true);
        @file_put_contents($skel.'/.irssi/config', 'test');

        $ctx = [
            'user'     => 'dummy',
            'home'     => $home,
            'user_esc' => escapeshellarg('dummy'),
        ];

        $jsonLog = $this->tmpFile();
        file_put_contents($jsonLog, '');

        $previous = $this->stashEnv(['PMSS_DRY_RUN', 'PMSS_JSON_LOG', 'PMSS_SKEL_DIR']);
        putenv('PMSS_DRY_RUN=1');
        putenv('PMSS_JSON_LOG='.$jsonLog);
        putenv('PMSS_SKEL_DIR='.$skel);
        if (function_exists('\\pmssResetJsonLogPath')) {
            \pmssResetJsonLogPath();
        }

        $cmd = null;
        try {
            \pmssUserConfigureHttp($ctx);
            $cmd = $this->findStepCommand($jsonLog, 'Copying irssi skeleton config');
        } finally {
            $this->restoreEnv($previous);
            if (function_exists('\\pmssResetJsonLogPath')) {
                \pmssResetJsonLogPath();
            }
            $this->cleanup($home);
            $this->cleanup($skel);
        }
        @unlink($jsonLog);

        $expected = sprintf('cp %s %s/', escapeshellarg($skel.'/.irssi/config'), escapeshellarg($home.'/.irssi'));
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
        $f = tempnam(sys_get_temp_dir(), 'pmss-json-');
        if ($f === false) {
            $f = sys_get_temp_dir().'/pmss-json-'.bin2hex(random_bytes(4));
            touch($f);
        }
        return $f;
    }

    private function cleanup(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }
}

}
