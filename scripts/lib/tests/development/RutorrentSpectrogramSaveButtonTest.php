<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/update/users.php';

/**
 * The spectrogram plugin prepends its own Save button to the shared task console. The task plugin only
 * notifies the requesting plugin, so a spectrogram button left visible shows up as a second, inert Save
 * in every other console. The button must start hidden, in the skeleton and in existing accounts' copies.
 */
class RutorrentSpectrogramSaveButtonTest extends TestCase
{
    private const HIDE = "\$('.soxplay').hide();";

    /** The plugin script as shipped before the fix, ending in the init sequence of onLangLoaded. */
    private const LEGACY_INIT = "plugin.onLangLoaded = function()\n{\n\t\t\$(\"#soxsave\").click( function()\n\t\t{\n\t\t\t\$('#soxgetimg').submit();\n\t\t});\n\t\tplugin.markLoaded();\n\t}\n}\n";

    public function testSkeletonHidesTheSaveButtonBeforeMarkingTheSpectrogramPluginLoaded(): void
    {
        $script = $this->pmssReadRepoFile('etc/skel/www/rutorrent/plugins/spectrogram/init.js');
        $this->assertEquals(1, substr_count($script, 'plugin.markLoaded();'));
        // The plugin hides its controls in other handlers too; the init-time hide is the one right before markLoaded().
        $init = self::HIDE."\n\t\tplugin.markLoaded();";
        $this->assertEquals(1, substr_count($script, $init), 'Init must hide the button right before marking the plugin loaded');
        $this->assertTrue(strpos($script, $init) > strpos($script, "id='soxsave'"), 'Hide must run after the button is created');
    }

    public function testExistingAccountCopyGetsTheHideOnceAndStaysStableOnRerun(): void
    {
        $home = $this->pmssMakeTrackedUserHomeTree('pmss-rutorrent-root-', 'dummy', 'www/rutorrent/plugins/spectrogram');
        $path = $this->pmssWriteRelativeFile($home, 'www/rutorrent/plugins/spectrogram/init.js', self::LEGACY_INIT);

        \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
        $first = (string) file_get_contents($path);
        $this->assertEquals(1, substr_count($first, self::HIDE));
        $this->assertTrue(strpos($first, self::HIDE) < strpos($first, 'plugin.markLoaded();'));

        \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
        $this->assertSame($first, (string) file_get_contents($path), 'A second run must not change a patched copy');
    }

    public function testAccountCopyWithoutTheAnchorIsLeftAlone(): void
    {
        $home = $this->pmssMakeTrackedUserHomeTree('pmss-rutorrent-root-', 'dummy', 'www/rutorrent/plugins/spectrogram');
        $custom = "// customer edited\nplugin.loaded();\n";
        $path = $this->pmssWriteRelativeFile($home, 'www/rutorrent/plugins/spectrogram/init.js', $custom);

        \pmssUserMaintainRutorrentPhpCompatibility(['home' => $home]);
        $this->assertSame($custom, (string) file_get_contents($path));
    }
}
