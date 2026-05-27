<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/**
 * Static architecture smoke tests for updater-library review guardrails.
 */
class ArchitectureSmokeTest extends TestCase
{
    private const NEAR_DUPLICATE_ALLOWLIST = [
        // 'functionA|functionB' => 'Keep separate: explain the concrete contract or safety reason here.',
    ];

    /**
     * Prevent long sibling helpers from differing only by a few string literals.
     */
    public function testNoNearDuplicateFunctionPairs(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $functions = $this->collectFunctionBodies($repoRoot.'/scripts/lib/update', $repoRoot);
        $pairs = $this->findNearDuplicatePairs($functions);

        $this->assertEquals([], $pairs, $this->formatNearDuplicatePairs($pairs));
    }

    /**
     * Collect named function bodies from first-party update-library PHP files.
     *
     * @return array<int, array{name:string,path:string,line:int,body:string,preview:array<int,string>}>
     */
    private function collectFunctionBodies(string $path, string $repoRoot): array
    {
        $functions = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || substr($file->getFilename(), -4) !== '.php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            $relativePath = substr($file->getPathname(), strlen($repoRoot) + 1);
            foreach ($this->extractNamedFunctions($source, $relativePath) as $function) {
                $functions[] = $function;
            }
        }

        return $functions;
    }

    /**
     * Extract top-level and nested named functions without including closures.
     *
     * @return array<int, array{name:string,path:string,line:int,body:string,preview:array<int,string>}>
     */
    private function extractNamedFunctions(string $source, string $relativePath): array
    {
        $tokens = token_get_all($source);
        $texts = [];
        $lines = [];
        $line = 1;

        foreach ($tokens as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $texts[] = $text;
            $lines[] = is_array($token) ? $token[2] : $line;
            $line += substr_count($text, "\n");
        }

        $functions = [];
        $sourceLines = preg_split('/\r?\n/', $source) ?: [];
        $count = count($texts);

        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }

            $nameIndex = $this->findFunctionNameIndex($tokens, $texts, $i + 1);
            if ($nameIndex === null) {
                continue;
            }

            $braceIndex = $this->findFunctionOpeningBrace($texts, $nameIndex + 1);
            if ($braceIndex === null) {
                continue;
            }

            $endLine = $this->findMatchingBraceLine($texts, $lines, $braceIndex);
            if ($endLine === null) {
                continue;
            }

            $startLine = $lines[$i];
            $bodyLines = array_slice($sourceLines, $startLine - 1, $endLine - $startLine + 1);
            $functions[] = [
                'name' => (string) $texts[$nameIndex],
                'path' => $relativePath,
                'line' => $startLine,
                'body' => implode("\n", $bodyLines),
                'preview' => array_slice($bodyLines, 0, 5),
            ];
        }

        return $functions;
    }

    /**
     * Locate a named function token, returning null for anonymous closures.
     */
    private function findFunctionNameIndex(array $tokens, array $texts, int $index): ?int
    {
        $count = count($texts);
        for ($i = $index; $i < $count; $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                continue;
            }
            if ($texts[$i] === '&') {
                continue;
            }

            return is_array($tokens[$i]) && $tokens[$i][0] === T_STRING ? $i : null;
        }

        return null;
    }

    /**
     * Find the opening brace for a function declaration.
     */
    private function findFunctionOpeningBrace(array $texts, int $index): ?int
    {
        $count = count($texts);
        for ($i = $index; $i < $count; $i++) {
            if ($texts[$i] === '{') {
                return $i;
            }
            if ($texts[$i] === ';') {
                return null;
            }
        }

        return null;
    }

    /**
     * Return the line where a function body closes.
     */
    private function findMatchingBraceLine(array $texts, array $lines, int $index): ?int
    {
        $depth = 0;
        $count = count($texts);
        for ($i = $index; $i < $count; $i++) {
            if ($texts[$i] === '{') {
                $depth++;
            } elseif ($texts[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return $lines[$i];
                }
            }
        }

        return null;
    }

    /**
     * Find same-shape long functions that vary only by one to three strings.
     *
     * @param array<int, array{name:string,path:string,line:int,body:string,preview:array<int,string>}> $functions
     * @return array<int, array{first:array<string,mixed>,second:array<string,mixed>}>
     */
    private function findNearDuplicatePairs(array $functions): array
    {
        $pairs = [];
        $count = count($functions);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if (!$this->functionNamesShareShape($functions[$i]['name'], $functions[$j]['name'])) {
                    continue;
                }
                if ($this->functionPairAllowlisted($functions[$i]['name'], $functions[$j]['name'])) {
                    continue;
                }
                if ($this->bodyLineCount($functions[$i]['body']) < 30 || $this->bodyLineCount($functions[$j]['body']) < 30) {
                    continue;
                }

                $first = $this->canonicalizeFunctionBody($functions[$i]['body']);
                $second = $this->canonicalizeFunctionBody($functions[$j]['body']);
                if ($first['tokens'] === $second['tokens'] && $this->stringLiteralDiffCount($first['strings'], $second['strings']) <= 3) {
                    $pairs[] = ['first' => $functions[$i], 'second' => $functions[$j]];
                }
            }
        }

        return $pairs;
    }

    /**
     * Check whether two function names differ mainly by their middle subject.
     */
    private function functionNamesShareShape(string $first, string $second): bool
    {
        return $first !== $second
            && $this->commonPrefixLength($first, $second) >= 8
            && $this->commonSuffixLength($first, $second) >= 5;
    }

    /**
     * Normalize PHP tokens while preserving string-literal positions separately.
     *
     * @return array{tokens:array<int,string>,strings:array<int,string>}
     */
    private function canonicalizeFunctionBody(string $body): array
    {
        $tokens = [];
        $strings = [];
        foreach (token_get_all("<?php\n".$body) as $token) {
            if (!is_array($token)) {
                $tokens[] = $token;
                continue;
            }
            if ($token[0] === T_WHITESPACE || $token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            if ($token[0] === T_CONSTANT_ENCAPSED_STRING || $token[0] === T_ENCAPSED_AND_WHITESPACE) {
                $tokens[] = 'T_STRING_LITERAL';
                $strings[] = $token[1];
                continue;
            }

            $tokens[] = token_name($token[0]).':'.$token[1];
        }

        return ['tokens' => $tokens, 'strings' => $strings];
    }

    /** Count changed string literal positions, including added or removed strings. */
    private function stringLiteralDiffCount(array $first, array $second): int
    {
        $diffs = abs(count($first) - count($second));
        $limit = min(count($first), count($second));
        for ($i = 0; $i < $limit; $i++) {
            if ($first[$i] !== $second[$i]) {
                $diffs++;
            }
        }

        return $diffs;
    }

    /** Count body lines without treating a trailing newline as an extra line. */
    private function bodyLineCount(string $body): int
    {
        return count(preg_split('/\r?\n/', rtrim($body, "\r\n")) ?: []);
    }

    /** Return a stable allowlist key independent of pair order. */
    private function functionPairKey(string $first, string $second): string
    {
        $pair = [$first, $second];
        sort($pair);
        return implode('|', $pair);
    }

    /** Check whether a near-duplicate pair is explicitly justified. */
    private function functionPairAllowlisted(string $first, string $second): bool
    {
        $reason = self::NEAR_DUPLICATE_ALLOWLIST[$this->functionPairKey($first, $second)] ?? '';
        return is_string($reason) && strlen(trim($reason)) >= 20;
    }

    /** Count matching characters from the start of two strings. */
    private function commonPrefixLength(string $first, string $second): int
    {
        $limit = min(strlen($first), strlen($second));
        for ($i = 0; $i < $limit; $i++) {
            if ($first[$i] !== $second[$i]) {
                return $i;
            }
        }

        return $limit;
    }

    /** Count matching characters from the end of two strings. */
    private function commonSuffixLength(string $first, string $second): int
    {
        $limit = min(strlen($first), strlen($second));
        for ($i = 1; $i <= $limit; $i++) {
            if ($first[strlen($first) - $i] !== $second[strlen($second) - $i]) {
                return $i - 1;
            }
        }

        return $limit;
    }

    /** Format duplicate function pairs with enough source context for review. */
    private function formatNearDuplicatePairs(array $pairs): string
    {
        if ($pairs === []) {
            return '';
        }

        $message = ["Near-duplicate long function pairs found:"];
        foreach ($pairs as $pair) {
            $message[] = $this->formatFunctionPreview($pair['first']);
            $message[] = $this->formatFunctionPreview($pair['second']);
        }

        return implode("\n", $message);
    }

    /** Format a function path, line, and first five body lines. */
    private function formatFunctionPreview(array $function): string
    {
        return sprintf(
            '- %s() at %s:%d'."\n".'%s',
            $function['name'],
            $function['path'],
            $function['line'],
            implode("\n", array_map(static function (string $line): string {
                return '  '.$line;
            }, $function['preview']))
        );
    }
}
