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

require_once dirname(__DIR__, 2).'/log.php';
require_once dirname(__DIR__, 2).'/update/runtime/profile.php';

class RuntimeProfileTest extends TestCase
{
    private function resetState(): void
    {
        $GLOBALS['PMSS_TEST_LOGS'] = [];
        unset($GLOBALS['PMSS_PROFILE']);
        $GLOBALS['PMSS_JSON_LOG_PATH'] = null;
        putenv('PMSS_JSON_LOG');
        putenv('PMSS_PROFILE_OUTPUT');
    }

    public function testRecordProfileInitializesStoreWhenMissing(): void
    {
        $this->resetState();
        $this->assertTrue(empty($GLOBALS['PMSS_PROFILE'] ?? []));

        pmssRecordProfile([
            'description' => 'Init',
            'command' => 'true',
            'status' => 'OK',
            'rc' => 0,
            'duration' => 0.0,
            'dry_run' => false,
            'stdout_excerpt' => '',
            'stderr_excerpt' => '',
        ]);

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
        $payload = $this->pmssReadJsonArrayFile($tmpProfile, null, 'Profile output should contain recorded entries');
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

    public function testProfileSummaryIncludesStatusCountsAndJsonEvent(): void
    {
        $this->resetState();

        // Route JSON events to a temp file so we can inspect the payload.
        $tmpJson = sys_get_temp_dir().'/pmss-profile-json-'.bin2hex(random_bytes(4));
        putenv('PMSS_JSON_LOG='.$tmpJson);

        pmssRecordProfile([
            'description' => 'ok-step',
            'command' => 'true',
            'status' => 'OK',
            'rc' => 0,
            'duration' => 0.1,
            'dry_run' => false,
            'stdout_excerpt' => '',
            'stderr_excerpt' => '',
        ]);
        pmssRecordProfile([
            'description' => 'err-step',
            'command' => 'false',
            'status' => 'ERR',
            'rc' => 1,
            'duration' => 0.2,
            'dry_run' => false,
            'stdout_excerpt' => '',
            'stderr_excerpt' => '',
        ]);
        pmssRecordProfile([
            'description' => 'skip-step',
            'command' => '',
            'status' => 'SKIP',
            'rc' => 0,
            'duration' => 0.3,
            'dry_run' => true,
            'stdout_excerpt' => '',
            'stderr_excerpt' => '',
        ]);

        pmssProfileSummary();

        // The JSON log should contain a profile_summary event with status_counts.
        $this->assertTrue(file_exists($tmpJson));
        $summaryEvents = array_values(array_filter(pmssJsonLineFileRead($tmpJson), static function (array $decoded): bool {
            return ($decoded['event'] ?? '') === 'profile_summary';
        }));
        $this->assertTrue(count($summaryEvents) >= 1, 'Expected at least one profile_summary JSON event');
        $last = end($summaryEvents);
        $this->assertTrue(isset($last['status_counts']) && is_array($last['status_counts']));
        $this->assertEquals(1, $last['status_counts']['OK'] ?? null);
        $this->assertEquals(1, $last['status_counts']['ERR'] ?? null);
        $this->assertEquals(1, $last['status_counts']['SKIP'] ?? null);

        @unlink($tmpJson);
        putenv('PMSS_JSON_LOG');
    }

    public function testProfileSummaryNormalizesKnownStatusesAndBucketsUnknownOnes(): void
    {
        $this->resetState();

        $tmpJson = sys_get_temp_dir().'/pmss-profile-json-'.bin2hex(random_bytes(4));
        putenv('PMSS_JSON_LOG='.$tmpJson);

        pmssRecordProfile([
            'description' => 'ok-step',
            'command' => 'true',
            'status' => 'ok',
            'rc' => 0,
            'duration' => 0.1,
            'dry_run' => false,
            'stdout_excerpt' => '',
            'stderr_excerpt' => '',
        ]);
        pmssRecordProfile([
            'description' => 'warn-step',
            'command' => 'true',
            'status' => 'warn',
            'rc' => 0,
            'duration' => 0.2,
            'dry_run' => false,
            'stdout_excerpt' => '',
            'stderr_excerpt' => '',
        ]);

        pmssProfileSummary();

        $summaryEvents = array_values(array_filter(pmssJsonLineFileRead($tmpJson), static function (array $decoded): bool {
            return ($decoded['event'] ?? '') === 'profile_summary';
        }));

        $this->assertTrue(count($summaryEvents) >= 1, 'Expected at least one profile_summary JSON event');
        $last = end($summaryEvents);
        $this->assertEquals(1, $last['status_counts']['OK'] ?? null);
        $this->assertEquals(1, $last['status_counts']['OTHER'] ?? null);

        @unlink($tmpJson);
        putenv('PMSS_JSON_LOG');
    }
}
