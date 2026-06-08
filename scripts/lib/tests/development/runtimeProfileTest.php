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
        $this->pmssTrackEnvOverrides([
            'PMSS_JSON_LOG' => null,
            'PMSS_PROFILE_OUTPUT' => null,
        ]);
    }

    public function testRecordProfileInitializesStoreAndAppendsEntry(): void
    {
        $this->resetState();
        $this->assertTrue(empty($GLOBALS['PMSS_PROFILE'] ?? []));

        $this->recordProfileEntry(['description' => 'Sample', 'duration' => 0.1]);

        $this->assertTrue(is_array($GLOBALS['PMSS_PROFILE']));
        $this->assertEquals(1, count($GLOBALS['PMSS_PROFILE'] ?? []));
    }

    public function testProfileSummaryOutputFileWriteDependsOnRecordedEntries(): void
    {
        foreach ([['pmss-profile-', true], ['pmss-profile-empty-', false]] as [$prefix, $hasEntry]) {
            $this->resetState();
            $tmpProfile = $this->pmssMakeTempPath($prefix);
            $this->pmssTrackEnvOverrides(['PMSS_PROFILE_OUTPUT' => $tmpProfile, 'PMSS_JSON_LOG' => null]);
            if ($hasEntry) {
                $this->recordProfileEntry(['description' => 'First', 'duration' => 0.2]);
            }

            pmssProfileSummary();

            $this->assertSame($hasEntry, file_exists($tmpProfile), 'profile output file state');
            if ($hasEntry) {
                $this->pmssReadJsonArrayFile($tmpProfile, null, 'Profile output should contain recorded entries');
            }
        }
    }

    public function testProfileSummaryToleratesMalformedEntries(): void
    {
        $this->resetState();

        $tmpJson = $this->pmssMakeTempPath('pmss-profile-json-');
        $tmpProfile = $this->pmssMakeTempPath('pmss-profile-malformed-');
        $this->pmssTrackEnvOverrides([
            'PMSS_JSON_LOG' => $tmpJson,
            'PMSS_PROFILE_OUTPUT' => $tmpProfile,
        ]);

        $stringableCommand = new class {
            public function __toString(): string
            {
                return "cmd\ntext";
            }
        };
        $GLOBALS['PMSS_PROFILE'] = [
            [
                'description' => "valid\nstep",
                'command' => 'true',
                'status' => 'OK',
                'rc' => 0,
                'duration' => 0.5,
            ],
            [
                'description' => ['not' => 'scalar'],
                'command' => $stringableCommand,
                'status' => null,
                'rc' => 'bad',
                'duration' => 'bad',
            ],
            'diagnostic garbage',
        ];

        pmssProfileSummary();

        $this->assertProfileStatusCounts($tmpJson, ['OK' => 1, 'OTHER' => 1]);

        $profile = $this->pmssReadJsonArrayFile($tmpProfile, null, 'Profile output should contain normalized entries');
        $this->assertSame(2, count($profile));
        $this->assertSame('valid step', $profile[0]['description']);
        $this->assertSame('array', $profile[1]['description']);
        $this->assertSame('cmd text', $profile[1]['command']);
        $this->assertSame('OTHER', $profile[1]['status']);
        $this->assertSame(0, $profile[1]['rc']);
        $this->assertEquals(0.0, $profile[1]['duration']);
    }

    public function testProfileSummaryIncludesStatusCountsAndJsonEvent(): void
    {
        $this->resetState();

        // Route JSON events to a temp file so we can inspect the payload.
        $tmpJson = $this->pmssMakeTempPath('pmss-profile-json-');
        $this->pmssTrackEnvOverrides(['PMSS_JSON_LOG' => $tmpJson]);

        $this->recordProfileEntry(['description' => 'ok-step', 'duration' => 0.1]);
        $this->recordProfileEntry(['description' => 'err-step', 'command' => 'false', 'status' => 'ERR', 'rc' => 1, 'duration' => 0.2]);
        $this->recordProfileEntry(['description' => 'skip-step', 'command' => '', 'status' => 'SKIP', 'duration' => 0.3, 'dry_run' => true]);

        pmssProfileSummary();

        // The JSON log should contain a profile_summary event with status_counts.
        $this->assertTrue(file_exists($tmpJson));
        $this->assertProfileStatusCounts($tmpJson, ['OK' => 1, 'ERR' => 1, 'SKIP' => 1]);
    }

    public function testProfileSummaryNormalizesKnownStatusesAndBucketsUnknownOnes(): void
    {
        $this->resetState();

        $tmpJson = $this->pmssMakeTempPath('pmss-profile-json-');
        $this->pmssTrackEnvOverrides(['PMSS_JSON_LOG' => $tmpJson]);

        $this->recordProfileEntry(['description' => 'ok-step', 'status' => 'ok', 'duration' => 0.1]);
        $this->recordProfileEntry(['description' => 'warn-step', 'status' => 'warn', 'duration' => 0.2]);

        pmssProfileSummary();

        $this->assertProfileStatusCounts($tmpJson, ['OK' => 1, 'OTHER' => 1]);
    }

    private function recordProfileEntry(array $overrides = []): void
    {
        pmssRecordProfile(array_replace([
            'description' => 'step',
            'command' => 'true',
            'status' => 'OK',
            'rc' => 0,
            'duration' => 0.0,
            'dry_run' => false,
            'stdout_excerpt' => '',
            'stderr_excerpt' => '',
        ], $overrides));
    }

    private function profileSummaryEvents(string $jsonPath): array
    {
        return array_values(array_filter(pmssJsonLineFileRead($jsonPath), static function (array $decoded): bool {
            return ($decoded['event'] ?? '') === 'profile_summary';
        }));
    }

    private function assertProfileStatusCounts(string $jsonPath, array $expected): void
    {
        $summaryEvents = $this->profileSummaryEvents($jsonPath);
        $this->assertTrue(count($summaryEvents) >= 1, 'Expected at least one profile_summary JSON event');
        $last = end($summaryEvents);
        $this->assertTrue(isset($last['status_counts']) && is_array($last['status_counts']));
        foreach ($expected as $status => $count) {
            $this->assertEquals($count, $last['status_counts'][$status] ?? null);
        }
    }
}
