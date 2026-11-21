<?php
namespace PMSS\Tests;

/** Characterize streamed web-root installation commands, bytes and accounting. */
require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/users/webRootReconcile.php';

class WebRootInstallCharacterizationTest extends TestCase
{
    public function testLegacyIncludesExposeTheCompleteRecoverySurfaceWithoutOutput(): void
    {
        // scripts/lib/update/users/webRootMisfile.php and
        // scripts/lib/update/users/webRootReconcileRestore.php load through the stable facades.
        $functions = ['pmssUserWebRootMigrationLog', 'pmssUserWebRootMigrationRootParentsSafe',
            'pmssUserWebRootMigrationRun', 'pmssUserWebRootMigrationRenameAsAccount',
            'pmssUserWebRootMigrationCopyTree', 'pmssUserWebRootMigrationRemoveCopy',
            'pmssUserMigrateWebRootBasenameCollapsePath', 'pmssUserMigrateWebRootPath',
            'pmssUserMigrateWebRootState', 'pmssUserWebRootReconcileInstallFile',
            'pmssUserWebRootReconcileFull', 'pmssUserWebRootReconcileLogSummary', 'pmssUserReconcileWebRoot'];
        $script = 'ob_start();require '.var_export(dirname(__DIR__, 2).'/update/users/webRoot.php', true)
            .';require '.var_export(dirname(__DIR__, 2).'/update/users/webRootReconcile.php', true)
            .';$output=ob_get_clean();echo json_encode([$output,array_map("function_exists",'
            .var_export($functions, true).')]);';
        $this->assertSame(['', array_fill(0, count($functions), true)], $this->pmssRunInlinePhpJson($script));
    }

    public function testStreamedInstallRetainsCommandsBytesRefusalsAndFaults(): void
    {
        // Export the real installer; no account command or filesystem mutation runs here.
        $script = <<<'PHP'
namespace WebRootInstallFixture;
function pmssReadRegularFileContents($path) {
    $GLOBALS['events'][] = ['read', $path];
    if ($GLOBALS['mode'] === 'read-throw') throw $GLOBALS['fault'];
    return $GLOBALS['mode'] === 'missing' ? null : $GLOBALS['body'];
}
function pmssUserWebRootMigrationRun($user, $home, $paths, $command, $content) {
    $GLOBALS['events'][] = ['run', $user, $home, $paths, $command, $content];
    if ($GLOBALS['mode'] === 'run-throw') throw $GLOBALS['fault'];
    return $GLOBALS['mode'] !== 'refused';
}
PHP;
        $script .= \pmssTestingFunctionExport('pmssUserWebRootReconcileInstallFile', 'WebRootInstallFixture');
        $script .= <<<'PHP'
$results = [];
foreach (['success', 'refused', 'missing', 'no-account', 'read-throw', 'run-throw'] as $mode) {
    foreach (['', "panel\0\xff\n"] as $body) foreach ([false, true] as $noReplace) {
        foreach ([\RuntimeException::class, \Error::class] as $kind) {
            $GLOBALS['mode'] = $mode; $GLOBALS['body'] = $body; $GLOBALS['events'] = [];
            $GLOBALS['fault'] = new $kind('fixture fault');
            $stats = ['files_restored' => 4, 'preserved_conflict' => 2];
            $result = $caught = null;
            try {
                $result = pmssUserWebRootReconcileInstallFile('/fixture/skel/panel.php',
                    "/fixture/home/www/panel's.php", 0640, $noReplace, $stats,
                    $mode === 'no-account' ? null : 'fixtur01', '/fixture/home');
            } catch (\Throwable $error) { $caught = $error; }
            // Base64 preserves arbitrary payload bytes through JSON without normalization.
            foreach ($GLOBALS['events'] as &$event) {
                if ($event[0] === 'run') $event[5] = base64_encode($event[5]);
            }
            unset($event);
            $results[] = [$mode, base64_encode($body), $noReplace, $result, $stats,
                $GLOBALS['events'], $caught === $GLOBALS['fault']];
        }
    }
}
// Optional accounting stays absent rather than becoming an implicit array.
$GLOBALS['mode'] = 'success'; $GLOBALS['body'] = ''; $GLOBALS['events'] = [];
$stats = null;
$ok = pmssUserWebRootReconcileInstallFile('/fixture/skel/panel.php', '/fixture/home/www/panel.php',
    0755, false, $stats, 'fixtur01', '/fixture/home');
echo json_encode([$results, [$ok, $stats]]);
PHP;
        [$rows, $withoutStats] = $this->pmssRunInlinePhpJson($script);
        $this->assertSame([true, null], $withoutStats);
        $this->assertSame(48, count($rows));
        foreach ($rows as [$mode, $body, $noReplace, $result, $stats, $events, $caught]) {
            $throws = in_array($mode, ['read-throw', 'run-throw'], true);
            $this->assertSame([$throws ? null : $mode === 'success', $throws], [$result, $caught], $mode);
            $this->assertSame(['files_restored' => $mode === 'success' ? 5 : 4, 'preserved_conflict' => 2], $stats, $mode);
            $expected = $mode === 'no-account' ? [] : [['read', '/fixture/skel/panel.php']];
            if (in_array($mode, ['success', 'refused', 'run-throw'], true)) {
                $target = "'/fixture/home/www/panel'\\''s.php'";
                $command = 'tmp=$(mktemp -- \'/fixture/home/www/.pmss-web-root-XXXXXXXX\') || exit 1; '
                    .'trap \'rm -f -- "$tmp"\' EXIT; '
                    .'cat > "$tmp" && test "$(wc -c < "$tmp")" -eq '.strlen(base64_decode($body))
                    .' && chmod 0640 -- "$tmp" && test ! -L '.$target
                    .' && '.($noReplace ? 'ln -- "$tmp" ' : 'mv -T -- "$tmp" ').$target;
                $expected[] = ['run', 'fixtur01', '/fixture/home', ["/fixture/home/www/panel's.php"], $command, $body];
            }
            $this->assertSame($expected, $events, $mode);
        }
    }
}
