<?php
/** Verify quota display deduplication and runtime refresh age without host I/O. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class QuotaSnapshotUnchangedTest extends TestCase
{
    /** Intercept publication and metadata while executing the real snapshot facade. */
    private function writeObserve(array $scenario = []): array
    {
        $script = <<<'PHP'
namespace QuotaUnchangedFixture;
function pmssRootStageContext($path) { return $GLOBALS['case']['context'] ? ['/home', '/home/fixtur01', 'fixtur01'] : null; }
function pmssResolvePathFromEnv($key, $default) { return $default; }
function posix_getgrnam($group) { return ['gid' => 1020]; }
function posix_geteuid() { return $GLOBALS['case']['euid']; }
function pmssReadRegularFileContentsVerified($path, $uid, $limit, &$metadata) {
    if ($uid !== 0 || $limit !== 4096) throw new \RuntimeException('Untrusted snapshot read');
    $metadata = $GLOBALS['case']['metadata']; return $GLOBALS['case']['previous'];
}
function pmssRootStageFileWrite($path, $content, $group, $mode) {
    $GLOBALS['events'][] = ['display', $path, $content, $group, $mode]; return $GLOBALS['case']['publish'];
}
function pmssEnsureSafeDir($path, $mode) { return $GLOBALS['case']['directory_ok']; }
function lstat($path) { return $GLOBALS['case']['directory']; }
function pmssWriteManagedFile($path, $content, $owner, $group, $mode) {
    $GLOBALS['events'][] = ['stamp', $path, $content, $owner, $group, $mode]; return $GLOBALS['case']['stamp'];
}
PHP;
        $scenario += ['context' => true, 'previous' => 'Disk quotas: unchanged', 'content' => 'Disk quotas: unchanged',
            'metadata' => ['mode' => 0100640, 'gid' => 1020], 'euid' => 0, 'publish' => true,
            'stamp' => true, 'directory_ok' => true, 'directory' => ['uid' => 0, 'mode' => 0040755], 'mode' => 0640];
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/quotaSnapshot.php', 'QuotaUnchangedFixture');
        $script .= '$GLOBALS["case"] = '.var_export($scenario, true).'; $GLOBALS["events"] = [];'
            .'$result = pmssQuotaSnapshotWrite("/home/fixtur01/.quota", $GLOBALS["case"]["content"], $GLOBALS["case"]["mode"]);'
            .'echo json_encode([$result, $GLOBALS["events"]]);';
        return $this->pmssRunInlinePhpJson($script);
    }

    public function testIdenticalSnapshotOnlyPublishesAccountReadableRuntimeDigest(): void
    {
        $this->assertSame([true, [['stamp', '/var/run/pmss/quota-refresh/fixtur01',
            hash('sha256', 'Disk quotas: unchanged')."\n", 'root', 'fixtur01', 0640]]], $this->writeObserve());
        $this->pmssAssertRepoFileContainsString('scripts/cron/updateQuotas.php', 'Per-minute quota refresher.');
    }

    public function testChangedMissingUntrustedAndWrongMetadataSnapshotsStillPublish(): void
    {
        foreach ([['previous' => null], ['previous' => 'old'],
            ['metadata' => ['mode' => 0100600, 'gid' => 1020]],
            ['metadata' => ['mode' => 0100640, 'gid' => 1030]]] as $scenario) {
            $observed = $this->writeObserve($scenario);
            $this->assertSame(true, $observed[0]);
            $this->assertSame(['display', 'stamp'], array_column($observed[1], 0));
        }
    }

    public function testGraceSnapshotsKeepTheirDisplayMtimeAndInvalidHomesDoNothing(): void
    {
        $observed = $this->writeObserve(['content' => 'Disk quotas: 20G*', 'previous' => 'Disk quotas: 20G*']);
        $this->assertSame(['display'], array_column($observed[1], 0));
        $this->assertSame([false, []], $this->writeObserve(['context' => false]));
        foreach ([-1, 01000, 07777] as $mode) $this->assertSame([false, []], $this->writeObserve(['mode' => $mode]));
    }

    public function testStampFailuresFallBackToPublicationAndFailedPublicationsDoNotStamp(): void
    {
        foreach ([['directory_ok' => false], ['directory' => ['uid' => 1000, 'mode' => 0040755]],
            ['directory' => ['uid' => 0, 'mode' => 0040777]], ['euid' => 1000]] as $scenario) {
            $observed = $this->writeObserve($scenario);
            $this->assertSame(true, $observed[0]);
            $this->assertSame(['display'], array_column($observed[1], 0));
        }
        $observed = $this->writeObserve(['stamp' => false]);
        $this->assertSame(['stamp', 'display', 'stamp'], array_column($observed[1], 0));
        $this->assertSame([false, [['display', '/home/fixtur01/.quota', 'Disk quotas: unchanged', 'fixtur01', 0640]]],
            $this->writeObserve(['previous' => null, 'publish' => false]));
    }

    public function testStatsAcceptsOnlyMatchingRootStampsAndNeverMovesTimeBackwards(): void
    {
        // Only the pure age helper is evaluated; no panel modules or real paths are loaded.
        $source = $this->pmssReadRepoFile('etc/skel/www/statsHelpers.php');
        $source = substr($source, strpos($source, 'function pmssStatsQuotaRefreshTime('));
        $script = <<<'PHP'
namespace QuotaAgeFixture;
function filemtime($path) { return 100; }
function realpath($path) { return '/home/fixtur01'; }
function lstat($path) { return $GLOBALS['metadata']; }
function pmssCustomerFileRead($path) { return $GLOBALS['digest']; }
PHP;
        $script .= 'eval('.var_export('namespace QuotaAgeFixture;'.$source, true).');';
        $script .= <<<'PHP'
$rows = [];
foreach ([null, ['mode' => 0100640, 'uid' => 0, 'mtime' => 200],
    ['mode' => 0100640, 'uid' => 0, 'mtime' => 50], ['mode' => 0100640, 'uid' => 1000, 'mtime' => 200],
    ['mode' => 0120640, 'uid' => 0, 'mtime' => 200], ['mode' => 0100666, 'uid' => 0, 'mtime' => 200]] as $metadata) {
    $GLOBALS['metadata'] = $metadata;
    foreach ([hash('sha256', 'snapshot')."\n", "mismatch\n", null] as $digest) {
        $GLOBALS['digest'] = $digest;
        $rows[] = pmssStatsQuotaRefreshTime('/home/fixtur01/.quota', 'snapshot');
    }
}
echo json_encode($rows);
PHP;
        $this->assertSame([100, 100, 100, 200, 100, 100, 100, 100, 100,
            100, 100, 100, 100, 100, 100, 100, 100, 100], $this->pmssRunInlinePhpJson($script));
        $this->pmssAssertRepoFileContainsString('etc/skel/www/stats.php', "pmssStatsQuotaRefreshTime('../.quota', \$content)");
    }
}
