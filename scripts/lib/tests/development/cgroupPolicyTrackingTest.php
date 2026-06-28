<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class CgroupPolicyTrackingTest extends TestCase
{
    public function testPolicyTodoMarkersOnlyDescribePendingExtensions(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings(
            'etc/seedbox/config/cgroup.policy.php',
            ['scheduler-aware IO auto-policy', 'per-user burst allowances', 'network shaping hints in cgroup policy'],
            ['LimitNOFILE' => 'Implemented NOFILE support should not remain a policy TODO']
        );
    }

    public function testCgroupDocsTrackImplementedAndPendingExtensions(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('docs/cgroup.md', [
            '## Extension Status Matrix (#121)',
            '**Per-device IO controls**: Implemented.',
            '**NOFILE limits**: Implemented.',
            '**Per-user burst allowances**: Not implemented yet.',
            '**Network IO shaping hints in cgroup policy**: Not implemented yet.',
            '### Pending backlog contracts (#121)',
            '| Per-user burst allowances | Pending |',
            '| Network shaping hints in policy | Pending |',
            '| Scheduler-aware IO auto-policy | Partially pending |',
            'Remaining TODOs: scheduler-aware IO auto-policy, burst allowances +',
        ]);
    }

    public function testGlobalTodoIndexReferencesCgroupBacklog(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('docs/TODO.md', [
            '#121: Cgroup policy extension backlog',
            'docs/cgroup.md',
        ]);
    }
}
