<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Keep guiv-delivered parts guarded and complete after the loader switch. */
class ScriptsIncSplitTest extends TestCase
{
    private const PARTS = array('Format', 'Files', 'Frontend', 'Bonus', 'Cgroup',
        'Backup', 'RestoreTar', 'Restore', 'AppProcess', 'AppsHtml', 'AppsStatus', 'ActionJs');

    /** Return tokens with byte offsets so source comparisons preserve all spacing. */
    private function sourceTokens(string $source): array
    {
        $tokens = token_get_all($source);
        $offsets = array();
        $offset = 0;
        foreach ($tokens as $index => $token) {
            $offsets[$index] = $offset;
            $offset += strlen(is_array($token) ? $token[1] : $token);
        }
        return array($tokens, $offsets);
    }

    private function nextCodeToken(array $tokens, int $index): int
    {
        while (isset($tokens[$index]) && is_array($tokens[$index])
            && in_array($tokens[$index][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
            $index++;
        }
        return $index;
    }

    /** Read a named function through its matching brace, including interpolation braces. */
    private function functionAt(string $source, array $tokens, array $offsets, int $index): array
    {
        $nameIndex = $this->nextCodeToken($tokens, $index + 1);
        $this->assertTrue(isset($tokens[$nameIndex]) && is_array($tokens[$nameIndex])
            && $tokens[$nameIndex][0] === T_STRING, 'Expected a named function.');
        $name = $tokens[$nameIndex][1];
        $open = $nameIndex + 1;
        while (isset($tokens[$open]) && $tokens[$open] !== '{') $open++;
        $this->assertTrue(isset($tokens[$open]), 'Missing body for '.$name);
        $depth = 0;
        for ($end = $open; isset($tokens[$end]); $end++) {
            $token = $tokens[$end];
            if ($token === '{' || is_array($token)
                && in_array($token[0], array(T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES), true)) $depth++;
            if ($token === '}' && --$depth === 0) break;
        }
        $this->assertTrue(isset($tokens[$end]), 'Unclosed body for '.$name);
        return array($name, substr($source, $offsets[$index], $offsets[$end] + 1 - $offsets[$index]), $end + 1);
    }

    private function namedFunctions(string $source): array
    {
        list($tokens, $offsets) = $this->sourceTokens($source);
        $functions = array();
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_FUNCTION) continue;
            $nameIndex = $this->nextCodeToken($tokens, $index + 1);
            if (!is_array($tokens[$nameIndex]) || $tokens[$nameIndex][0] !== T_STRING) continue;
            list($name, $body) = $this->functionAt($source, $tokens, $offsets, $index);
            $this->assertTrue(!isset($functions[$name]), 'Duplicate function '.$name);
            $functions[$name] = $body;
        }
        return $functions;
    }

    /** Parse only an open tag, comments, and individually guarded functions. */
    private function partFunctions(string $source, string $part): array
    {
        list($tokens, $offsets) = $this->sourceTokens($source);
        $index = 0;
        $this->assertTrue(isset($tokens[$index]) && is_array($tokens[$index])
            && $tokens[$index][0] === T_OPEN_TAG, $part.' needs a PHP open tag.');
        $index++;
        $functions = array();
        while (($index = $this->nextCodeToken($tokens, $index)) < count($tokens)) {
            $this->assertTrue(is_array($tokens[$index]) && $tokens[$index][0] === T_IF,
                $part.' has a top-level statement outside a function guard.');
            $guardStart = $offsets[$index];
            $open = $index + 1;
            while (isset($tokens[$open]) && $tokens[$open] !== '{') $open++;
            $this->assertTrue(isset($tokens[$open]), $part.' has an unclosed guard.');
            $guard = substr($source, $guardStart, $offsets[$open] + 1 - $guardStart);
            $this->assertTrue(preg_match('/\Aif\s*\(\s*!\s*function_exists\s*\(\s*\'([A-Za-z_][A-Za-z0-9_]*)\'\s*\)\s*\)\s*\{\z/', $guard, $match) === 1,
                $part.' has an unexpected guard.');
            $functionIndex = $this->nextCodeToken($tokens, $open + 1);
            $this->assertTrue(isset($tokens[$functionIndex]) && is_array($tokens[$functionIndex])
                && $tokens[$functionIndex][0] === T_FUNCTION, $part.' guard must contain one function.');
            list($name, $body, $next) = $this->functionAt($source, $tokens, $offsets, $functionIndex);
            $this->assertSame($match[1], $name, $part.' guard names the wrong function.');
            $this->assertTrue(!isset($functions[$name]), $part.' duplicates '.$name);
            $functions[$name] = $body;
            $index = $this->nextCodeToken($tokens, $next);
            $this->assertSame('}', $tokens[$index] ?? null, $part.' guard contains extra code.');
            $index++;
        }
        return $functions;
    }

    public function testPartsArePresentAndContainOnlyGuardedFunctions(): void
    {
        foreach (self::PARTS as $part) {
            $path = $this->pmssRepoPath('etc/skel/www/scriptsInc'.$part.'.php');
            $this->assertTrue(is_file($path), $part.' part is missing.');
            $source = (string) file_get_contents($path);
            $this->assertTrue(strlen($source) > 100, $part.' part is too small.');
            $this->assertTrue(strpos($source, '<?php') === 0, $part.' does not start with PHP.');
            $this->assertTrue(preg_match('/\A<\?php\n\/\*\* [^\r\n]*Loaded by scriptsInc\.php; never require a part directly\. \*\/\n/', $source) === 1,
                $part.' needs its one-line loader notice.');
            $this->assertTrue($this->partFunctions($source, $part) !== array(), $part.' has no functions.');
        }
    }

    public function testFunctionSetMatchesHistoricalMonolithWithoutDuplicates(): void
    {
        // Historical inventory from 59fc75bd plus later shared customer helpers.
        $expected = array(
            'writeLog', 'pmssFrontendActionRequest', 'pmssFrontendPostActionRequired', 'pmssFrontendFunctionAvailable', 'pmssFrontendShellExecAvailable',
            'pmssFrontendShellExec', 'pmssFormatBytes', 'pmssWelcomeSerializedArrayHasObject', 'pmssWelcomeSerializedArrayDecode', 'pmssCustomerSerializedArrayFileRead',
            'pmssJsonDecodeAssoc', 'pmssJsonEncodePretty', 'pmssCustomerFileRead', 'pmssCustomerTrimmedFileRead', 'pmssCustomerUnsignedIntegerValue',
            'pmssCustomerNonnegativeFloat', 'pmssCustomerUnsignedIntegerFileRead', 'pmssCustomerPositiveIntegerFileRead', 'pmssCustomerBonusDisplayStateRead', 'pmssCustomerBonusDisplayTextBuild',
            'pmssCustomerBonusDisplayNoteBuild', 'pmssCustomerKeyValueFileRead', 'pmssCustomerCgroupSelfEntries', 'pmssCustomerCgroupDirOwnsMemoryController', 'pmssCustomerCgroupCounterPaths',
            'pmssCustomerCgroupCounterRead', 'pmssCustomerCgroupMemoryRead', 'pmssCustomerHtmlAttr', 'pmssCustomerAccountContext', 'pmssCustomerContextualHelpLinkBuild', 'pmssJsonFileReadAssoc',
            'pmssCustomerHomeRoot', 'pmssCustomerHomePath', 'pmssCustomerPathIsSafe', 'pmssCustomerBackupRelativePaths', 'pmssCustomerBackupEntriesRead',
            'pmssCustomerBackupTarCommandBuild', 'pmssCustomerBackupDownload', 'pmssCustomerBackupFileResult', 'pmssCustomerBackupFileDirectoryEnsure', 'pmssCustomerBackupFilePathBuild',
            'pmssCustomerBackupFileCreate', 'pmssCustomerBackupArchivesRead', 'pmssCustomerBackupRetentionPrune', 'pmssCustomerPathIsInsideHome', 'pmssCustomerBackupRestoreResult',
            'pmssCustomerBackupRestoreTargetPathIsSafe', 'pmssCustomerBackupRestoreLog', 'pmssCustomerBackupRestoreFinish', 'pmssCustomerBackupRestoreArchiveResolve', 'pmssCustomerBackupRestoreDiskPrecheck',
            'pmssCustomerBackupRestoreTarSize', 'pmssCustomerBackupRestoreTarSkipPayload', 'pmssCustomerBackupRestoreTarPayloadRead', 'pmssCustomerBackupRestorePaxPayloadParse', 'pmssCustomerBackupRestoreMemberNormalize',
            'pmssCustomerBackupRestoreTarMemberTypesRead', 'pmssCustomerBackupRestoreMemberAllowed', 'pmssCustomerBackupRestoreMemberTypeAllowed', 'pmssCustomerBackupRestoreMemberNameFromPhar', 'pmssCustomerBackupRestoreEntriesSelect',
            'pmssCustomerBackupRestoreTouchesRtorrentLiveState', 'pmssCustomerBackupRestoreRtorrentProcRunning', 'pmssCustomerBackupRestoreRtorrentRunning', 'pmssCustomerBackupRestoreSnapshotPathBuild', 'pmssCustomerBackupRestoreSnapshotCreate',
            'pmssCustomerBackupRestore', 'pmssWelcomeMessageCustomerPathIsSafe', 'pmssWelcomeRequireLocalHelper', 'pmssCustomerManagedAppDefinitions', 'pmssWelcomeServiceAvailable',
            'pmssCustomerNativePidsRead', 'pmssCustomerNativeSignal', 'pmssCustomerNativeStop', 'pmssCustomerDelugeSystemCommand', 'pmssCustomerTorrentClientToggleStart', 'pmssCustomerTorrentClientSelectedBinary', 'pmssCustomerRtorrentBinaries', 'pmssWelcomeHttpContextCreate', 'pmssCustomerAppsMediaIdsRead',
            'pmssCustomerAppsTmuxSessionsParse', 'pmssCustomerAppsTmuxSessionsRead', 'pmssCustomerAppsStatusRead', 'pmssCustomerAppsDescriptionsRead', 'pmssCustomerAppRowStart',
            'pmssCustomerAppButton', 'pmssCustomerAppRowHtmlBuild', 'pmssCustomerAppsMediaRowsBuild', 'pmssCustomerAppsSectionHtmlBuild', 'pmssCustomerAppsCss',
            'pmssCustomerAppsStatusJsonEmit', 'pmssCustomerAppsLogJsonEmit', 'pmssActionScriptJs', 'pmssFrontendToggleAction',
        );
        $loader = $this->namedFunctions($this->pmssReadRepoFile('etc/skel/www/scriptsInc.php'));
        $this->assertSame(array('writeLog'), array_keys($loader), 'The loader must retain only writeLog.');
        $functions = $loader;
        foreach (self::PARTS as $part) {
            $source = $this->pmssReadRepoFile('etc/skel/www/scriptsInc'.$part.'.php');
            foreach ($this->partFunctions($source, $part) as $name => $body) {
                $this->assertTrue(!isset($functions[$name]), 'Function appears in two files: '.$name);
                $functions[$name] = $body;
            }
        }
        $this->assertSame(94, count($functions));
        $actual = array_keys($functions);
        sort($actual);
        sort($expected);
        $this->assertSame($expected, $actual, 'Customer helper inventory changed unexpectedly.');
    }

    public function testLoaderGuardsPartsInManifestOrder(): void
    {
        $loader = $this->pmssReadRepoFile('etc/skel/www/scriptsInc.php');
        $this->assertStringContainsString('is_file($p) && !is_link($p)', $loader);
        $this->assertStringContainsString('require_once $p', $loader);
        $this->assertOrderedStrings(array_map(static function ($part): string {
            return "'".$part."'";
        }, self::PARTS), $loader);
        $manifest = $this->pmssReadRepoFile('scripts/lib/update/users/filesystem.php');
        foreach (self::PARTS as $part) {
            $this->assertStringContainsString("'".$part."'", $loader);
            $this->assertStringContainsString("'www/scriptsInc".$part.".php'", $manifest);
            $this->assertStringContainsString("'scriptsInc".$part.".php'",
                $this->pmssReadRepoFile('scripts/cron/checkGui.php'));
        }
    }

    public function testManifestListsPartsImmediatelyBeforeMonolith(): void
    {
        $manifest = $this->pmssReadRepoFile('scripts/lib/update/users/filesystem.php');
        $entries = array();
        foreach (self::PARTS as $part) {
            $entry = "'www/scriptsInc".$part.".php',";
            $this->assertSame(1, substr_count($manifest, $entry), 'Manifest entry must occur once: '.$entry);
            $entries[] = preg_quote($entry, '/');
        }
        $this->assertTrue(preg_match('/'.implode('\s*', $entries).'\s*'.preg_quote("'www/scriptsInc.php',", '/').'/', $manifest) === 1,
            'Parts must appear in order immediately before scriptsInc.php.');
    }
}
