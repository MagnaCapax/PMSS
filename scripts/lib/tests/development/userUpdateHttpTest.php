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
