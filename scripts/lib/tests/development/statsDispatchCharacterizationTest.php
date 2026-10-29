<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/resources/processor.php';
require_once dirname(__DIR__, 2).'/traffic/processor.php';

/** Record dispatch without spawning commands or reading account data. */
trait StatsDispatchRecording
{
    public $events = [];
    public $users = ['user10', 'ghost', 'user2-localnet'];
    public $lockAvailable = true;
    public $thresholds = [];

    protected function beforeRunCli(): void { $this->events[] = ['prepare']; }
    public function beforeSpawn(): bool
    {
        $this->events[] = ['lock'];
        return $this->lockAvailable;
    }
    public function discoverUsers(): array { $this->events[] = ['discover']; return $this->users; }
    public function validateUser(string $user): bool
    {
        $this->events[] = ['validate', $user];
        return $user !== 'ghost' && $user !== '';
    }
    public function spawnWorkers(string $scriptPath, array $users): void
    {
        $this->events[] = ['spawn', $scriptPath, $users];
    }
    public function processUser(string $user, array $compareTimes): void
    {
        $this->events[] = ['process', $user, array_keys($compareTimes)];
        $this->thresholds[] = $compareTimes;
    }
}

/** Freeze the two concrete processors' event order and CLI result bytes. */
class StatsDispatchCharacterizationTest extends TestCase
{
    public function testDispatchSnapshots(): void
    {
        $windows = ['month', 'week', 'day', 'hour', '15min'];
        // Both processors aggregate inline: prepare, take the run lock, then validate and process.
        $inline = [['prepare'], ['lock'], ['discover'], ['validate', 'user10'], ['validate', 'ghost'], ['validate', 'user2-localnet'],
            ['process', 'user10', $windows], ['process', 'user2-localnet', $windows]];
        foreach (['resource', 'traffic'] as $kind) {
            foreach ([
                [[], true, null, 0, '', $inline],
                [[], true, [], 0, "No users in this system!\n", [['prepare'], ['lock'], ['discover']]],
                [[], false, null, 0, '', [['prepare'], ['lock']]],
                [['u!ser2-localnet'], false, null, 0, '', [['prepare'], ['validate', 'user2-localnet'], ['process', 'user2-localnet', $windows]]],
                [['ghost'], true, null, 0, "Invalid user specified: ghost\n", [['prepare'], ['validate', 'ghost']]],
                [['!!!'], true, null, 0, "Invalid user specified: \n", [['prepare'], ['validate', '']]],
                [[], true, ['ghost'], 0, "No users in this system!\n", [['prepare'], ['lock'], ['discover'], ['validate', 'ghost']]],
            ] as [$args, $lock, $users, $expectedRc, $expectedOutput, $expectedEvents]) {
                $processor = $kind === 'resource'
                    ? new class(new \resourceStatistics()) extends \ResourceStatsProcessor { use StatsDispatchRecording; }
                    : new class(new \trafficStatistics()) extends \TrafficStatsProcessor { use StatsDispatchRecording; };
                $processor->lockAvailable = $lock;
                if ($users !== null) $processor->users = $users;
                [$rc, $output] = $this->pmssCaptureStdout(static function () use ($processor, $args): int {
                    return $processor->runCli(array_merge(['stats.php'], $args), '/worker.php');
                });
                $this->assertSame([$expectedRc, $expectedOutput, $expectedEvents], [$rc, $output, $processor->events]);
                if (count($processor->thresholds) === 2) {
                    $this->assertSame($processor->thresholds[0], $processor->thresholds[1]);
                }
            }
        }
    }

    /** The shared inline loop calls beforeRunCli() and beforeSpawn() on the traffic path too; they must stay the base no-ops there (#1167 review). */
    public function testTrafficProcessorInheritsTheNoOpRunHooks(): void
    {
        foreach (['beforeRunCli', 'beforeSpawn'] as $hook) {
            $declaring = (new \ReflectionMethod(\TrafficStatsProcessor::class, $hook))->getDeclaringClass()->getName();
            $this->assertSame(\PmssUserStatsProcessor::class, $declaring, "TrafficStatsProcessor overrides {$hook}()");
        }
    }
}
