<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

// Provide a stub logger before loading the profile helpers.
if (!function_exists('logmsg')) {
    function logmsg(string $message): void
    {
        $GLOBALS['PMSS_TEST_LOGS'][] = $message;
    }
}

require_once dirname(__DIR__, 2).'/update/runtime/profile.php';

class RuntimeProfileTest extends TestCase
{
    private function resetState(): void
    {
        $GLOBALS['PMSS_TEST_LOGS'] = [];
        unset($GLOBALS['PMSS_PROFILE']);
        putenv('PMSS_JSON_LOG');
        putenv('PMSS_PROFILE_OUTPUT');
    }

    public function testInitProfileStoreCreatesArray(): void
    {
        $this->resetState();
        $this->assertTrue(empty($GLOBALS['PMSS_PROFILE'] ?? []));
        pmssInitProfileStore();
        $this->assertTrue(is_array($GLOBALS['PMSS_PROFILE']));
    }

    public function testRecordProfileAppendsEntry(): void
    {
        $this->resetState();
        pmssRecordProfile([
            'description' => 'Sample',
            'command' => 'true',
            'status' => 'OK',
            'rc' => 0,
            'duration' => 0.1,
            'dry_run' => false,
            'stdout_excerpt' => '',
            'stderr_excerpt' => '',
        ]);
        $this->assertEquals(1, count($GLOBALS['PMSS_PROFILE'] ?? []));
    }

    public function testProfileSummaryWritesJsonLog(): void
    {
        $this->resetState();
        $tmpProfile = sys_get_temp_dir().'/pmss-profile-'.bin2hex(random_bytes(4));
        putenv('PMSS_PROFILE_OUTPUT='.$tmpProfile);
        putenv('PMSS_JSON_LOG');
        pmssRecordProfile([
            'description' => 'First',
            'command' => 'true',
            'status' => 'OK',
            'rc' => 0,
            'duration' => 0.2,
            'dry_run' => false,
            'stdout_excerpt' => '',
            'stderr_excerpt' => '',
        ]);
        pmssProfileSummary();
        $this->assertTrue(file_exists($tmpProfile));
        $payload = json_decode(file_get_contents($tmpProfile) ?: '', true);
        $this->assertTrue(is_array($payload), 'Profile output should contain recorded entries');
        @unlink($tmpProfile);
    }

    public function testProfileSummarySkipsWhenEmpty(): void
    {
        $this->resetState();
        $tmpProfile = sys_get_temp_dir().'/pmss-profile-empty-'.bin2hex(random_bytes(4));
        putenv('PMSS_PROFILE_OUTPUT='.$tmpProfile);
        putenv('PMSS_JSON_LOG');
        pmssProfileSummary();
        $this->assertTrue(!file_exists($tmpProfile), 'No file should be written when profile is empty');
        @unlink($tmpProfile);
    }
}
