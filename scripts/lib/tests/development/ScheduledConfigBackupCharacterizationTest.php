<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/scheduledConfigBackup.php';

/** Lock the cron controller's protocol and account decisions without starting customer PHP. */
class ScheduledConfigBackupCharacterizationTest extends TestCase
{
    private $homeRoot;
    private $home;
    private $archive;
    private $logDir;

    protected function setUp(): void
    {
        $this->homeRoot = $this->pmssMakeTempDir('pmss-scheduled-characterization-');
        $this->home = $this->homeRoot.'/fixtur01';
        $this->pmssWriteFile($this->home.'/www/scriptsInc.php', "<?php\n");
        $this->archive = $this->pmssWriteFile($this->home.'/.pmss-backups/config-20260101-000001.tar.gz', 'archive');
        $this->logDir = $this->pmssEnsureDir($this->homeRoot.'/logs');
        $this->pmssTrackEnvOverrides(['PMSS_LOG_DIR' => $this->logDir]);
    }

    public function testCustomerProgramAndQuotedCommandBytes(): void
    {
        $program = <<<'PHP'
require_once $argv[1];
$home = $argv[2] ?? '';
$action = $argv[3] ?? '';
$keep = (int) ($argv[4] ?? 7);
$result = array('ok' => false, 'message' => 'Unknown scheduled backup action.', 'bytes' => 0, 'path' => '');
if ($action === 'create') {
    $result = pmssCustomerBackupFileCreate($home);
} elseif ($action === 'prune') {
    $result = pmssCustomerBackupRetentionPrune($home, $keep);
}
$encoded = json_encode($result, JSON_UNESCAPED_SLASHES);
echo (is_string($encoded) ? $encoded : '{"ok":false,"message":"Could not encode scheduled backup result.","bytes":0,"path":""}').PHP_EOL;
exit(!empty($result['ok']) ? 0 : 1);
PHP;
        $this->assertSame($program, \pmssScheduledConfigBackupCustomerPhpCode());
        foreach (['create', 'prune'] as $action) {
            $inner = implode(' ', array_map('escapeshellarg', [PHP_BINARY, '-r', $program, '--',
                $this->home.'/www/scriptsInc.php', $this->home, $action, '3']));
            $this->assertSame("su -s '/bin/bash' -c ".escapeshellarg($inner)." 'fixtur01'",
                \pmssScheduledConfigBackupCommandBuild('fixtur01', $this->home, $action, 3));
        }
        foreach ([['bad;user', $this->home, 'create'], ['fixtur01', $this->home, 'unknown'],
            ['fixtur01', $this->homeRoot.'/missing', 'create']] as [$user, $home, $action]) {
            $called = false;
            $result = \pmssScheduledConfigBackupCustomerAction($user, $home, $action, 7,
                static function () use (&$called): array { $called = true; return []; });
            $this->assertSame(['ok' => false, 'message' => 'scheduled backup helper command could not be built',
                'bytes' => 0, 'path' => ''], $result);
            $this->assertFalse($called);
        }
    }

    public function testLastNonemptyLineExitStatusAndNormalization(): void
    {
        $invalid = ['ok' => false, 'message' => 'scheduled backup helper returned invalid output', 'bytes' => 0, 'path' => ''];
        $empty = ['ok' => false, 'message' => '', 'bytes' => 0, 'path' => '', 'keptCount' => 0, 'deletedCount' => 0];
        foreach ([
            [[], $invalid],
            [['rc' => 0, 'stdout' => '[]'], $empty],
            [['rc' => 0, 'stdout' => '{"ok":true}'], array_replace($empty, ['ok' => true])],
            [['stdout' => '{"ok":true}'], $empty],
            [['rc' => '0', 'stdout' => "noise\r\n{\"ok\":true,\"bytes\":\"9\",\"keptCount\":-2,\"deletedCount\":\"4\",\"message\":\" one\\n two \"}\n \n"],
                array_replace($empty, ['ok' => true, 'message' => 'one two', 'bytes' => 9, 'deletedCount' => 4])],
            [['rc' => 124, 'stdout' => '{"ok":true,"bytes":-1,"path":"kept"}'], array_replace($empty, ['path' => 'kept'])],
            [['rc' => 0, 'stdout' => "{\"ok\":true}\ntrailer", 'stderr' => " bad\n news\t"],
                array_replace($invalid, ['message' => $invalid['message'].': bad news'])],
            [['rc' => 0, 'stdout' => 'null'], $invalid],
            [['rc' => 0, 'stdout' => '{"message":"'.str_repeat('x', 305).'"}'],
                array_replace($empty, ['message' => str_repeat('x', 300).'...'])],
        ] as [$process, $expected]) {
            $this->assertSame($expected, \pmssScheduledConfigBackupCustomerResult($process));
        }
    }

    public function testNativeCaptureArgumentsAndPhpFallbackOrder(): void
    {
        $script = <<<'PHP'
namespace ScheduledBackupCommandFixture;
class UserValidator { public static function isValidUsername($user) { return $user === 'fixtur01'; } }
function pmssRegularFilePathIsReadable($path) { $GLOBALS['trace'][] = ['readable', $path]; return true; }
function pmssPathWithinResolvedRoot($path, $home) { $GLOBALS['trace'][] = ['within', $path, $home]; return true; }
function is_executable($path) { $GLOBALS['trace'][] = ['executable', $path]; return array_shift($GLOBALS['executables']); }
function pmssCommandArgvShellQuote($args) { $GLOBALS['argv'] = $args; return 'quoted argv'; }
function pmssBuildUserShellCommand(...$args) { $GLOBALS['trace'][] = ['shell', $args]; return 'customer command'; }
function pmssCommandBashInvocation($command) { $GLOBALS['trace'][] = ['bash', $command]; return 'bash invocation'; }
function pmssCommandTimeoutSeconds($command) { $GLOBALS['trace'][] = ['timeout', $command]; return 45; }
function pmssCommandPipedCapture(...$args) { $GLOBALS['trace'][] = ['capture', $args]; return ['rc' => 0, 'stdout' => '{"ok":true}']; }
PHP;
        // The same characterization runs against the original facade on the testing VM.
        $script .= 'require_once '.var_export($this->pmssRepoPath('scripts/lib/user/scheduledConfigBackup.php'), true).';';
        $module = 'scripts/lib/user/scheduledConfigBackupCommand.php';
        $script .= $this->pmssInlinePhpLibraryInNamespace(is_file($this->pmssRepoPath($module))
            ? $module : 'scripts/lib/user/scheduledConfigBackup.php', 'ScheduledBackupCommandFixture');
        $script .= <<<'PHP'
$rows = [];
foreach ([[true], [false, true], [false, false]] as $executables) {
    $GLOBALS['executables'] = $executables;
    $GLOBALS['trace'] = [];
    $result = pmssScheduledConfigBackupCustomerAction('fixtur01', '/srv/pmss-fixture', 'prune', 3);
    $rows[] = [$result, $GLOBALS['argv'], $GLOBALS['trace'], PHP_BINARY];
}
echo json_encode($rows);
PHP;
        $rows = $this->pmssRunInlinePhpJson($script);
        foreach ($rows as $index => [$result, $argv, $trace, $phpBinary]) {
            $this->assertSame(['ok' => true, 'message' => '', 'bytes' => 0, 'path' => '', 'keptCount' => 0, 'deletedCount' => 0], $result);
            $this->assertSame([$phpBinary, '/usr/bin/php', 'php'][$index], $argv[0]);
            $this->assertSame(['-r', \pmssScheduledConfigBackupCustomerPhpCode(), '--',
                '/srv/pmss-fixture/www/scriptsInc.php', '/srv/pmss-fixture', 'prune', '3'], array_slice($argv, 1));
            $expected = [['readable', '/srv/pmss-fixture/www/scriptsInc.php'],
                ['within', '/srv/pmss-fixture/www/scriptsInc.php', '/srv/pmss-fixture'], ['executable', $phpBinary]];
            if ($index > 0) $expected[] = ['executable', '/usr/bin/php'];
            $this->assertSame(array_merge($expected, [
                ['shell', ['fixtur01', 'quoted argv', '/bin/bash']], ['bash', 'customer command'],
                ['timeout', 'customer command'], ['capture', ['bash invocation', 'customer command', 45,
                    1048576, false, 'scheduled config backup command launch failed', 1, true]],
            ]), $trace);
        }
    }

    /** scheduledConfigBackupAccount.php must keep every archive refusal before retention. */
    public function testArchiveVerificationRetainsNameContainmentAndPositiveSizeGuards(): void
    {
        $outside = $this->pmssWriteFile($this->homeRoot.'/.pmss-backups/config-20260101-000001.tar.gz', 'outside');
        $empty = $this->pmssWriteFile($this->home.'/.pmss-backups/config-20260101-000002.tar.gz', '');
        $wrongName = $this->pmssWriteFile($this->home.'/.pmss-backups/other.tar.gz', 'other');
        $link = $this->home.'/.pmss-backups/config-20260101-000003.tar.gz';
        $this->pmssWriteSymlinks($this->home, ['.pmss-backups/'.basename($link) => $this->archive]);
        foreach (['', 'relative.tar.gz', $outside, $empty, $wrongName, $link] as $path) {
            $this->assertSame(0, \pmssScheduledConfigBackupArchiveVerified($this->home, $path), $path);
        }
        $this->assertSame(7, \pmssScheduledConfigBackupArchiveVerified($this->home, $this->archive));
    }

    public function testAccountOutcomesAndCreateVerificationBeforeRetention(): void
    {
        $created = ['ok' => true, 'message' => '', 'bytes' => 999, 'path' => $this->archive];
        $pruned = ['ok' => true, 'message' => '', 'keptCount' => 2];
        $success = ['status' => 'success', 'message' => 'created config backup', 'bytes' => 7,
            'path' => $this->archive, 'keptCount' => 2];
        $successLog = 'success bytes=7 kept=2 path=.pmss-backups/'.basename($this->archive);
        foreach ([
            [[], [], ['status' => 'skipped', 'message' => 'toggle off', 'enabled' => false], [], null, false],
            [$created, $pruned, $success, ['create', 'prune'], $successLog],
            [array_replace($created, ['path' => '', 'message' => 'nothing to back up']), [],
                ['status' => 'skipped', 'message' => 'nothing to back up', 'enabled' => true, 'bytes' => 0],
                ['create'], 'skipped reason=nothing_to_back_up'],
            [array_replace($created, ['ok' => false, 'message' => " failed\n create "]), [],
                ['status' => 'failed', 'message' => 'failed create', 'bytes' => 999], ['create'], 'failed reason=failed create'],
            [array_replace($created, ['path' => '']), [],
                ['status' => 'failed', 'message' => 'backup failed', 'bytes' => 999], ['create'], 'failed reason=backup failed'],
            [array_replace($created, ['path' => $this->home.'/missing.tar.gz']), [],
                ['status' => 'failed', 'message' => 'archive verification failed'], ['create'], 'failed reason=archive_verification_failed'],
            [$created, ['ok' => false, 'message' => ''],
                ['status' => 'failed', 'message' => 'retention failed', 'bytes' => 7, 'path' => $this->archive],
                ['create', 'prune'], 'failed reason=retention failed bytes=7'],
            [$created, ['ok' => false, 'message' => 'prune refused'],
                ['status' => 'failed', 'message' => 'prune refused', 'bytes' => 7, 'path' => $this->archive],
                ['create', 'prune'], 'failed reason=prune refused bytes=7'],
        ] as $case) {
            [$create, $prune, $expected, $actions, $message] = $case;
            $calls = $logs = [];
            $result = \pmssScheduledConfigBackupRunUser('fixtur01', ['scheduledConfigBackup' => $case[5] ?? true], [
                'homeRoot' => $this->homeRoot, 'retention' => 0,
                'runner' => function (string $command, array $context) use (&$calls, $create, $prune): array {
                    $calls[] = $context['action'];
                    $this->assertSame(['user' => 'fixtur01', 'home' => $this->home,
                        'action' => $context['action'], 'retention' => 1], $context);
                    return ['rc' => 0, 'stdout' => json_encode($context['action'] === 'create' ? $create : $prune)];
                },
                'userLogger' => static function (...$args) use (&$logs): void { $logs[] = $args; },
            ]);
            $this->assertSame($expected, $result);
            $this->assertSame($actions, $calls);
            $this->assertSame($message === null ? [] : [['fixtur01', 'scheduled config backup: '.$message]], $logs);
        }
    }

    public function testMissingHomeInvalidUserAndThrownRunnerKeepTheirBoundaries(): void
    {
        foreach (['bad;user' => 'invalid username', 'fixtur02' => 'home missing'] as $user => $message) {
            $this->assertSame(['status' => 'skipped', 'message' => $message, 'enabled' => true],
                \pmssScheduledConfigBackupRunUser($user, ['scheduledConfigBackup' => true], [
                    'homeRoot' => $this->homeRoot, 'userLogger' => static function (): void {},
                    'runner' => static function (): void { throw new \LogicException('unexpected runner'); },
                ]));
        }
        foreach ([new \RuntimeException('fixture fault'), new \Error('fixture fault')] as $fault) {
            $caught = null;
            try {
                \pmssScheduledConfigBackupRunUser('fixtur01', ['scheduledConfigBackup' => true], [
                    'homeRoot' => $this->homeRoot,
                    'runner' => static function () use ($fault): void { throw $fault; },
                ]);
            } catch (\Throwable $error) {
                $caught = $error;
            }
            $this->assertSame($fault, $caught);
        }
    }

    public function testQueueCatchesAccountFailureAndPublishesOrderedSummary(): void
    {
        $store = new class extends \UserConfigStore {
            public function __construct() {}
            public function loadAll(): array { return ['fixtur01' => ['scheduledConfigBackup' => true], 'fixtur02' => []]; }
        };
        $logs = $userLogs = [];
        $summary = \pmssScheduledConfigBackupRun($store, [
            'homeRoot' => $this->homeRoot,
            'runner' => static function (): void { throw new \Error('private fixture detail'); },
            'logger' => $this->pmssMakeArrayLogger($logs),
            'userLogger' => static function (...$args) use (&$userLogs): void { $userLogs[] = $args; },
        ]);
        $this->assertSame(['timestamp', 'event', 'processed', 'succeeded', 'failed', 'skipped', 'level'], array_keys($summary));
        $this->assertSame(['timestamp' => $summary['timestamp'], 'event' => 'scheduled_config_backup',
            'processed' => 2, 'succeeded' => 0, 'failed' => 1, 'skipped' => 1, 'level' => 'warn'], $summary);
        $this->assertSame([['fixtur01', 'scheduled config backup: failed reason=exception=Error']], $userLogs);
        $this->assertSame(['event=scheduled_config_backup user=fixtur01 status=failed message=exception=Error bytes=0 kept=0',
            'event=scheduled_config_backup_summary processed=2 succeeded=0 failed=1 skipped=1'], $logs);
        $this->assertSame($summary, json_decode(trim(file_get_contents($this->logDir.'/scheduledConfigBackup.jsonl')), true));
    }
}
