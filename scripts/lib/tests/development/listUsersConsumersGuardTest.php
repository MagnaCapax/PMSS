<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class ListUsersConsumersGuardTest extends TestCase
{
    /**
     * Ensure helper-based consumers rely on pmssListManagedUsers() instead of
     * re-implementing the legacy listUsers sanitization inline.
     */
    public function testHelperConsumersRelyOnSharedManagedUserParser(): void
    {
        $legacyInlineParsers = [
            "array_map('trim', pmssListManagedUsers",
            "array_filter(pmssListManagedUsers('/scripts/listUsers.php'), 'pmssValidateUsername')",
            'pmssManagedUsersSelectFromList(pmssListManagedUsers(',
        ];

        foreach ($this->pmssListUsersConsumerMap() as $needle => $files) {
            foreach ($files as $file) {
                $this->pmssAssertRepoFileContainsAllStrings($file, [$needle], $file.' must use shared listUsers parsing');
                if ($needle !== 'pmssListManagedUsersResult(') {
                    $this->pmssAssertRepoFileNotContainsStrings(
                        $file,
                        $legacyInlineParsers,
                        $file.' should keep pmssListManagedUsers() output as-is '
                    );
                }
            }
        }
    }

    /**
     * Ensure scripts that still shell out directly to listUsers.php keep their
     * own username validation guards.
     */
    public function testMigratedConsumersDropLegacyListUsersShelling(): void
    {
        $targets = [
            'scripts/cron/trafficLog.php',
            'scripts/cron/trafficLimits.php',
            'scripts/cron/updateQuotas.php',
            'scripts/cron/checkRtorrent.php',
            'scripts/cron/userTrackerCleaner.php',
        ];

        foreach ($targets as $file) {
            $this->pmssAssertRepoFileNotContainsStrings(
                $file,
                [
                    "shell_exec('/scripts/listUsers.php')",
                    "@exec('/scripts/listUsers.php",
                    "`/scripts/listUsers.php`",
                ],
                $file.' must use shared listUsers helpers instead of inline shelling'
            );
        }
    }
}
