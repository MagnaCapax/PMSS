#!/usr/bin/env php
<?php
/**
 * Static Include Checker
 *
 * Scans PHP files using token_get_all to find require/include statements
 * and verifies the targets exist.
 */

$rootDir = realpath(__DIR__ . '/../..');
$scriptsDir = $rootDir . '/scripts';

$errors = [];
$scanned = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($scriptsDir, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    // Skip vendor
    if (strpos($file->getPathname(), '/vendor/') !== false) continue;

    $scanned++;
    checkFile($file->getPathname());
}

function checkFile(string $path): void
{
    $content = file_get_contents($path);
    $tokens = token_get_all($content);
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $t = $tokens[$i];
        if (!is_array($t)) continue;

        // Look for T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE
        if (in_array($t[0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE])) {
            // Advance past whitespace/comments/opening parens
            $j = $i + 1;
            while ($j < $count) {
                $next = $tokens[$j];
                if (is_array($next) && in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                    $j++; continue;
                }
                if ($next === '(') {
                    $j++; continue;
                }
                break;
            }

            if ($j >= $count) break;

            // Case 1: Absolute/String Literal: require 'foo.php';
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
                $str = str_replace(['"', "'"], '', $tokens[$j][1]);
                // Only check absolute paths (start with /)
                if (isset($str[0]) && $str[0] === '/') {
                    verifyPath($path, $tokens[$j][2], $str, false);
                }
            }
            
            // Case 2: __DIR__ . 'string': require __DIR__ . '/foo.php';
            elseif (is_array($tokens[$j]) && $tokens[$j][0] === T_DIR) {
                // Expect '.' then string
                $k = $j + 1;
                while ($k < $count) {
                    $next = $tokens[$k];
                    if (is_array($next) && in_array($next[0], [T_WHITESPACE, T_COMMENT])) {
                        $k++; continue;
                    }
                    if ($next === '.') {
                        // Found dot, look for next string
                        $l = $k + 1;
                        while ($l < $count) {
                            $nextL = $tokens[$l];
                            if (is_array($nextL) && in_array($nextL[0], [T_WHITESPACE, T_COMMENT])) {
                                $l++; continue;
                            }
                            if (is_array($nextL) && $nextL[0] === T_CONSTANT_ENCAPSED_STRING) {
                                $str = str_replace(['"', "'"], '', $nextL[1]);
                                verifyPath($path, $t[2], $str, true);
                            }
                            break; 
                        }
                        break;
                    }
                    break;
                }
            }
        }
    }
}

function verifyPath(string $sourceFile, int $line, string $target, bool $isRelative): void
{
    global $errors;

    if ($isRelative) {
        $sourceDir = dirname($sourceFile);
        $resolved = realpath($sourceDir . $target);
        if ($resolved === false) {
            $logical = $sourceDir . $target;
            $errors[] = "File: $sourceFile:$line\n  Target: $target (Relative)\n  Resolved: $logical (MISSING)";
        }
    } else {
        if (!file_exists($target)) {
             $errors[] = "File: $sourceFile:$line\n  Target: $target (Absolute)\n  Status: MISSING";
        }
    }
}

echo "Scanned $scanned files.\n";

if (!empty($errors)) {
    echo "\nFound " . count($errors) . " missing include(s):\n\n";
    foreach ($errors as $e) {
        echo "$e\n\n";
    }
    exit(1);
}

echo "OK: All static includes resolved.\n";
exit(0);