<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Phase 1 keeps guiv-delivered parts inert and byte-matched to scriptsInc.php. */
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

    public function testFunctionSetAndBodiesMatchMonolith(): void
    {
        $monolithPath = getenv('PMSS_SCRIPTSINC_TEST_SOURCE') ?: $this->pmssRepoPath('etc/skel/www/scriptsInc.php');
        $monolith = $this->namedFunctions((string) file_get_contents($monolithPath));
        unset($monolith['writeLog']);
        $parts = array();
        foreach (self::PARTS as $part) {
            $source = $this->pmssReadRepoFile('etc/skel/www/scriptsInc'.$part.'.php');
            foreach ($this->partFunctions($source, $part) as $name => $body) {
                $this->assertTrue(!isset($parts[$name]), 'Function appears in two parts: '.$name);
                $parts[$name] = $body;
            }
        }
        $this->assertSame(88, count($parts));
        $this->assertSame(88, count($monolith));
        $partNames = array_keys($parts);
        $monolithNames = array_keys($monolith);
        sort($partNames);
        sort($monolithNames);
        $this->assertSame($monolithNames, $partNames, 'Function names differ from scriptsInc.php.');
        foreach ($monolith as $name => $body) {
            $this->assertSame($body, $parts[$name], 'Copied source drifted for '.$name);
        }
    }

    public function testNoPartIsIncludedDuringPhaseOne(): void
    {
        foreach (array('etc/skel', 'scripts') as $directory) {
            $paths = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
                $this->pmssRepoPath($directory), \FilesystemIterator::SKIP_DOTS));
            foreach ($paths as $path) {
                if (!$path->isFile() || $path->isLink() || substr($path->getFilename(), -4) !== '.php'
                    || $path->getPathname() === __FILE__) continue;
                $source = (string) file_get_contents($path->getPathname());
                if (preg_match('/scriptsInc[A-Z][A-Za-z]*\.php/', $source) !== 1) continue;
                $tokens = token_get_all($source);
                foreach ($tokens as $index => $token) {
                    if (!is_array($token) || !in_array($token[0], array(T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE), true)) continue;
                    $expression = '';
                    for ($next = $index + 1; isset($tokens[$next]) && $tokens[$next] !== ';'; $next++) {
                        $expression .= is_array($tokens[$next]) ? $tokens[$next][1] : $tokens[$next];
                    }
                    $this->assertTrue(preg_match('/scriptsInc[A-Z][A-Za-z]*\.php/', $expression) !== 1,
                        $path->getPathname().' includes a Phase 1 part.');
                }
            }
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
