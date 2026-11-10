<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/testing/customerContext/files.php';

/** Stream ownership for scripts/lib/testing/customerContext/files.php. */
class CustomerContextDiscoverySafetyTest extends TestCase
{
    public function testShebangReadFaultsCloseStreamsBeforePropagating(): void
    {
        $root = $this->pmssWriteFiles($this->pmssMakeTempDir('pmss-discovery-safety-'), ['command' => 'fixture']);
        // Only the iterator reads the fixture directory; injected I/O uses memory streams.
        $script = <<<'PHP'
namespace CustomerContextDiscoveryFixture;
class DirectoryIterator extends \DirectoryIterator {}
function fopen($path, $mode) {
    $GLOBALS['events'][] = ['open', basename($path), $mode];
    if ($GLOBALS['case'] === 'open-throw') throw $GLOBALS['failure'];
    if ($GLOBALS['case'] === 'open-false') return false;
    return $GLOBALS['handle'] = \fopen('php://memory', 'w+');
}
function fgets($handle) {
    $GLOBALS['events'][] = ['read'];
    if (in_array($GLOBALS['case'], ['read-throw', 'read-close-throw'], true)) throw $GLOBALS['failure'];
    return $GLOBALS['case'] === 'read-false' ? false : "#!/usr/bin/env php\n";
}
function fclose($handle) {
    $GLOBALS['events'][] = ['close'];
    \fclose($handle);
    if (in_array($GLOBALS['case'], ['close-throw', 'read-close-throw'], true)) throw $GLOBALS['closeFailure'];
    return true;
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/testing/customerContext/files.php', 'CustomerContextDiscoveryFixture');
        $script .= <<<'PHP'
$discovery = new class {
    use PmssCustomerContextFiles { shebangPhpFiles as public discover; }
};
$results = [];
foreach (['valid', 'read-false', 'open-false', 'open-throw', 'read-throw', 'close-throw', 'read-close-throw'] as $case) {
    foreach (strpos($case, 'throw') !== false ? ['RuntimeException', 'Error'] : [null] as $kind) {
        $GLOBALS['case'] = $case; $GLOBALS['events'] = []; $GLOBALS['handle'] = null;
        $GLOBALS['failure'] = $kind === null ? null : new $kind('read/open fault');
        $GLOBALS['closeFailure'] = $kind === null ? null : new $kind('close fault');
        $caught = null; $result = null;
        try { $result = $discovery->discover(getenv('PMSS_TEST_DISCOVERY_ROOT')); }
        catch (\Throwable $error) { $caught = $error; }
        $expectedFailure = in_array($case, ['close-throw', 'read-close-throw'], true)
            ? $GLOBALS['closeFailure'] : $GLOBALS['failure'];
        $live = is_resource($GLOBALS['handle']);
        // Observe leaks before fixture cleanup, so a failing test still releases its memory stream.
        if ($live) \fclose($GLOBALS['handle']);
        $results[] = [$case, $result, $caught === $expectedFailure, $live, $GLOBALS['events']];
    }
}
echo json_encode($results);
PHP;
        $results = $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_DISCOVERY_ROOT' => $root]);
        $this->assertSame(11, count($results));
        foreach ($results as [$case, $result, $sameFailure, $live, $events]) {
            $expectedEvents = [['open', 'command', 'r']];
            if (!in_array($case, ['open-false', 'open-throw'], true)) {
                $expectedEvents[] = ['read'];
                $expectedEvents[] = ['close'];
            }
            $expectedResult = strpos($case, 'throw') !== false ? null : ($case === 'valid' ? [$root.'/command'] : []);
            $this->assertSame([$expectedResult, true, false, $expectedEvents],
                [$result, $sameFailure, $live, $events], $case);
        }
    }

    public function testDiscoveryRetainsShebangMatchingAndSortedPaths(): void
    {
        $root = $this->pmssWriteFiles($this->pmssMakeTempDir('pmss-discovery-matching-'), [
            'z-command' => "#!/usr/bin/env php\n<?php\n",
            'a-command' => "#!/usr/bin/php\n<?php\n",
            'php-extension.php' => "#!/usr/bin/php\r\n<?php\n",
            'empty' => '',
            'shell-command' => "#!/bin/bash\n",
            'php-option' => "#!/usr/bin/env php -n\n",
            'no-shebang.php' => "<?php\n",
            'nested/command' => "#!/usr/bin/php\n<?php\n",
        ]);
        $discovery = new class {
            use \PmssCustomerContextFiles { shebangPhpFiles as public discover; }
        };
        // Directories, non-PHP shebangs and PHP without shebangs remain excluded.
        $this->assertSame([$root.'/a-command', $root.'/php-extension.php', $root.'/z-command'], $discovery->discover($root));
        $this->assertSame([], $discovery->discover($root.'/missing'));
    }
}
