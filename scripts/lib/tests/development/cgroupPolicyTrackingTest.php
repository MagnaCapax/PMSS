<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class CgroupPolicyTrackingTest extends TestCase
{
    public function testPolicyTodoMarkersOnlyDescribePendingExtensions(): void
    {
        $policy = $this->pmssReadRepoFile('etc/seedbox/config/cgroup.policy.php');

        $this->assertStringContainsAllStrings(['scheduler-aware IO auto-policy', 'per-user burst allowances', 'network shaping hints in cgroup policy'], $policy);
        $this->pmssAssertStringNotContainsString('LimitNOFILE', $policy, 'Implemented NOFILE support should not remain a policy TODO');
    }

    public function testCgroupDocsTrackImplementedAndPendingExtensions(): void
    {
        $docs = $this->pmssReadRepoFile('docs/cgroup.md');

        $this->assertStringContainsAllStrings([
            '## Extension Status Matrix (#121)',
            '**Per-device IO controls**: Implemented.',
            '**NOFILE limits**: Implemented.',
            '**Per-user burst allowances**: Not implemented yet.',
            '**Network IO shaping hints in cgroup policy**: Not implemented yet.',
            '### Pending backlog contracts (#121)',
            '| Per-user burst allowances | Pending |',
            '| Network shaping hints in policy | Pending |',
            '| Scheduler-aware IO auto-policy | Partially pending |',
        ], $docs);

        $this->assertStringContainsString(
            'Remaining TODOs: scheduler-aware IO auto-policy, burst allowances +',
            $docs
        );
    }

    public function testGlobalTodoIndexReferencesCgroupBacklog(): void
    {
        $docs = $this->pmssReadRepoFile('docs/TODO.md');

        $this->assertStringContainsString('#121: Cgroup policy extension backlog', $docs);
        $this->assertStringContainsString('docs/cgroup.md', $docs);
    }
}
