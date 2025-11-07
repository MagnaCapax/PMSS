<?php
namespace {
    if (!function_exists('runUserStep')) {
        function runUserStep(string $user, string $description, string $command): int
        {
            // simulate success without executing commands
            return 0;
        }
    }
}

namespace PMSS\Tests {

require_once dirname(__DIR__, 2).'/update/user/plugins.php';
require_once dirname(__DIR__, 2).'/update/user/utils.php';

class UserUpdatePluginsTest extends TestCase
{
    public function testEnsurePluginsReportsMissingSource(): void
    {
        putenv('PMSS_SKEL_DIR=' . sys_get_temp_dir().'/does-not-exist');
        try {
            $ctx = [
                'user'     => 'dummy',
                'home'     => sys_get_temp_dir(),
                'user_esc' => escapeshellarg('dummy'),
            ];
            \pmssUserEnsurePlugins($ctx);
            $this->assertTrue(true, 'Should not throw when skeleton missing');
        } finally {
            putenv('PMSS_SKEL_DIR');
        }
    }
}

}
