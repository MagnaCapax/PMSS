<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/scheduledConfigBackup.php';

/** Boundary coverage for scripts/lib/user/scheduledConfigBackupCommand.php. */
class ScheduledConfigBackupResultSafetyTest extends TestCase
{
    public function testConsumedContainersUseInvalidOutputWithoutConversionWarnings(): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): void {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            foreach (['ok', 'message', 'bytes', 'path', 'keptCount', 'deletedCount'] as $field) {
                foreach ([[], ['private fixture value'], new \stdClass(), (object) ['nested' => true]] as $value) {
                    foreach ([0, 1, 124] as $rc) {
                        $payload = [$field => $value] + ['ok' => true];
                        $this->assertSame([
                            'ok' => false, 'message' => 'scheduled backup helper returned invalid output: helper failed',
                            'bytes' => 0, 'path' => '',
                        ], \pmssScheduledConfigBackupCustomerResult([
                            'rc' => $rc, 'stdout' => "ignored line\n".json_encode($payload)."\n \n",
                            'stderr' => " helper\n failed\t",
                        ]));
                    }
                }
            }
        } finally {
            restore_error_handler();
        }
    }

    public function testScalarConversionsNullDefaultsAndExtensionFieldsStayCompatible(): void
    {
        $empty = ['ok' => false, 'message' => '', 'bytes' => 0, 'path' => '', 'keptCount' => 0, 'deletedCount' => 0];
        foreach ([
            [[], $empty],
            [array_fill_keys(array_keys($empty), null), $empty],
            [['ok' => '0', 'message' => false, 'bytes' => -2, 'path' => false], $empty],
            [['ok' => 'false', 'message' => true, 'bytes' => '9', 'path' => 12, 'keptCount' => 2.9, 'deletedCount' => '4'],
                ['ok' => true, 'message' => '1', 'bytes' => 9, 'path' => '12', 'keptCount' => 2, 'deletedCount' => 4]],
            [['ok' => 1.5, 'message' => " one\n two ", 'bytes' => true, 'path' => 'fixture', 'deletedCount' => -3],
                ['ok' => true, 'message' => 'one two', 'bytes' => 1, 'path' => 'fixture', 'keptCount' => 0, 'deletedCount' => 0]],
            [['ok' => true, 'extension' => ['nested' => ['ignored']]], array_replace($empty, ['ok' => true])],
        ] as [$payload, $expected]) {
            foreach ([0, '0', 1, 124] as $rc) {
                $this->assertSame(array_replace($expected, ['ok' => $rc == 0 && $expected['ok']]),
                    \pmssScheduledConfigBackupCustomerResult(['rc' => $rc, 'stdout' => json_encode($payload)]));
            }
        }
    }

    public function testInvalidFieldsKeepBoundedStderrAndNeverEchoStdout(): void
    {
        $this->assertSame([
            'ok' => false,
            'message' => 'scheduled backup helper returned invalid output: '.str_repeat('x', 300).'...',
            'bytes' => 0, 'path' => '',
        ], \pmssScheduledConfigBackupCustomerResult([
            'rc' => 0, 'stdout' => '{"ok":true,"message":{"private":"fixture"}}',
            'stderr' => str_repeat('x', 305),
        ]));
    }

    public function testMalformedCreateCannotStartRetentionAndMalformedPruneCannotSucceed(): void
    {
        $root = $this->pmssMakeTempDir('pmss-backup-result-');
        $home = $root.'/fixtur01';
        $this->pmssWriteFile($home.'/www/scriptsInc.php', "<?php\n");
        $archive = $this->pmssWriteFile($home.'/.pmss-backups/config-20260101-000001.tar.gz', 'archive');
        foreach (['create', 'prune'] as $stage) {
            foreach (['ok', 'message', 'bytes', 'path', 'keptCount', 'deletedCount'] as $field) {
                $calls = $logs = [];
                $result = \pmssScheduledConfigBackupRunUser('fixtur01', ['scheduledConfigBackup' => true], [
                    'homeRoot' => $root,
                    // Every command is intercepted; neither customer PHP nor retention runs.
                    'runner' => static function (string $command, array $context) use (&$calls, $stage, $field, $archive): array {
                        $calls[] = $context['action'];
                        $payload = ['ok' => true, 'message' => '', 'bytes' => 7, 'path' => $archive, 'keptCount' => 1];
                        if ($context['action'] === $stage) $payload[$field] = ['private fixture value'];
                        return ['rc' => 0, 'stdout' => json_encode($payload)];
                    },
                    'userLogger' => static function (...$args) use (&$logs): void { $logs[] = $args; },
                ]);
                $this->assertSame('failed', $result['status']);
                $this->assertSame('scheduled backup helper returned invalid output', $result['message']);
                $this->assertSame($stage === 'create' ? ['create'] : ['create', 'prune'], $calls);
                $this->assertSame([['fixtur01', 'scheduled config backup: failed reason='.$result['message']
                    .($stage === 'prune' ? ' bytes=7' : '')]], $logs);
                $this->assertSame('archive', file_get_contents($archive));
            }
        }
    }
}
