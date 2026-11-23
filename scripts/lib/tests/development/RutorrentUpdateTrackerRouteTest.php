<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/users.php';

/** "Update tracker" reaches rTorrent 0.16 through a fixed trusted handler, never a client-named method. */
class RutorrentUpdateTrackerRouteTest extends TestCase
{
    private const ACTION = 'etc/skel/www/rutorrent/plugins/httprpc/action.php';
    private const INIT = 'etc/skel/www/rutorrent/plugins/httprpc/init.js';

    /** The two delivery edits for existing accounts, keyed by the file they change. */
    private function trackerPatches(): array
    {
        $found = [];
        foreach (\pmssUserRutorrentRpcUpdateTrackerPatches() as [$path, $legacy, $patched]) {
            $found[$path] = [$legacy, $patched];
        }
        return $found;
    }

    public function testHandlerSendsOnlyTheFixedCommandThroughTheTrustedCall(): void
    {
        $action = $this->pmssReadRepoFile(self::ACTION);
        $this->assertStringContainsString(
            "\tcase \"updatetracker\":\t/**/\n\t{\n",
            $action
        );
        $start = strpos($action, 'case "updatetracker"');
        $block = substr($action, $start, strpos($action, 'case "dsetprio"') - $start);
        $this->assertStringContainsString('$result = makeSimpleCall(array("d.tracker_announce"), $hash);', $block);
        // The method name is a literal: nothing from the request names a command in this mode.
        foreach (['$vs', '$ss', '$add', 'HTTP_RAW_POST_DATA', '$_'] as $requestValue) {
            $this->assertStringNotContainsString($requestValue, $block, $requestValue);
        }
        // The raw default branch and the trusted gate stay as they were.
        $this->assertStringContainsString('$result = rXMLRPCRequest::send($HTTP_RAW_POST_DATA,false);', $action);
        $this->assertStringContainsString('$trusted = rTorrentSettings::get()->iVersion >= 0x1000;', $action);
        $this->assertStringContainsString('return($req->success($trusted) ? $req->val : false);', $action);
    }

    public function testBrowserStubUsesTheModeAndKeepsTheFallbackNameEqual(): void
    {
        $init = $this->pmssReadRepoFile(self::INIT);
        $this->assertOrderedStrings([
            'plugin.origupdatetracker = rTorrentStub.prototype.updateTracker;',
            'rTorrentStub.prototype.updateTracker = function()',
            'this.getCommon("updatetracker");',
        ], $init);
        // getCommon() falls back to plugin["orig"+cmd]: the stored name must be exactly orig + mode.
        $this->assertStringContainsString('plugin["orig"+cmd].call(this);', $init);
        $this->assertStringNotContainsString('origupdateTracker', $init);
    }

    public function testDeliveryEditsCoverBothFilesAndMatchTheSkeleton(): void
    {
        $patches = $this->trackerPatches();
        $this->assertSame([ 'plugins/httprpc/action.php', 'plugins/httprpc/init.js'], array_keys($patches));
        $this->assertStringContainsString($patches['plugins/httprpc/action.php'][1], $this->pmssReadRepoFile(self::ACTION));
        $this->assertStringContainsString($patches['plugins/httprpc/init.js'][1], $this->pmssReadRepoFile(self::INIT));
    }

    /** One account's upgrade must leave another account's customised copy alone and name no second trusted tracker command. */
    public function testUpgradeTouchesOnlyTheAccountBeingUpdated(): void
    {
        $updated = $this->pmssMakeTrackedUserHomeTree('pmss-rutorrent-tracker-', 'dummy', 'www/rutorrent/plugins/httprpc');
        // Both accounts live under the one tracked home root, as on a host.
        $other = dirname($updated).'/other';
        $this->pmssEnsureDir($other.'/www/rutorrent/plugins/httprpc', 0755);
        [$legacy, $patched] = $this->trackerPatches()['plugins/httprpc/action.php'];
        $legacyFile = str_replace($patched, $legacy, $this->pmssReadRepoFile(self::ACTION));
        $this->pmssWriteRelativeFile($updated, 'www/rutorrent/plugins/httprpc/action.php', $legacyFile);
        $custom = "<?php // customised copy without the Remove handler anchor\n";
        $this->pmssWriteRelativeFile($other, 'www/rutorrent/plugins/httprpc/action.php', $custom);

        \pmssUserMaintainRutorrentPhpCompatibility(['home' => $updated]);

        $this->assertStringContainsString('case "updatetracker"', file_get_contents($updated.'/www/rutorrent/plugins/httprpc/action.php'));
        $this->assertSame($custom, file_get_contents($other.'/www/rutorrent/plugins/httprpc/action.php'));
        // The handler trusts exactly one tracker command and no other new command.
        $this->assertSame(1, substr_count($this->pmssReadRepoFile(self::ACTION), 'array("d.tracker_announce")'));
    }

    /** The mode string is matched exactly: a differently cased or client-extended mode reaches only the untrusted raw branch. */
    public function testOnlyTheExactModeIsTrustedAndSendsEveryHashThroughTheSharedHelper(): void
    {
        $action = $this->pmssReadRepoFile(self::ACTION);
        $this->assertSame(1, substr_count($action, 'case "updatetracker":'));
        foreach (['case "updateTracker":', 'case "updatetracker ":', 'case "updatetrackers":'] as $lookalike) {
            $this->assertStringNotContainsString($lookalike, $action, $lookalike);
        }
        // makeSimpleCall loops over every submitted hash, so a multi-torrent selection announces each one.
        $this->assertOrderedStrings([
            'function makeSimpleCall($cmds,$hash)',
            'foreach($hash as $h)',
            'foreach($cmds as $cmd)',
            'new rXMLRPCCommand( $cmd, $h )',
        ], $action);
        // With no hash submitted nothing is added to the request; the handler holds no hash of its own.
        $this->assertStringNotContainsString('$hash[0]', substr($action, strpos($action, 'case "updatetracker"'), 400));
    }

    public function testExistingAccountFilesAreHealedOnceAndRepeatRunsChangeNothing(): void
    {
        $home = $this->pmssMakeTrackedUserHomeTree('pmss-rutorrent-tracker-', 'dummy', 'www/rutorrent/plugins/httprpc');
        $expected = [];
        foreach ($this->trackerPatches() as $path => [$legacy, $patched]) {
            $skeleton = $this->pmssReadRepoFile($path === 'plugins/httprpc/action.php' ? self::ACTION : self::INIT);
            $this->assertSame(1, substr_count($skeleton, $patched), $path);
            $expected[$path] = $skeleton;
            // A legacy account copy is the skeleton without this change.
            $this->pmssWriteRelativeFile($home, 'www/rutorrent/'.$path, str_replace($patched, $legacy, $skeleton));
        }

        \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
        foreach ($expected as $path => $skeleton) {
            $this->assertSame($skeleton, file_get_contents($home.'/www/rutorrent/'.$path), $path);
        }
        \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
        foreach ($expected as $path => $skeleton) {
            $this->assertSame($skeleton, file_get_contents($home.'/www/rutorrent/'.$path), 'second run '.$path);
        }
    }
}
