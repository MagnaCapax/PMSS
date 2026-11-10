<?php
/** File-set orchestration and diagnostic projection for the fatal-call scanner. @license GPL-3.0-only */

require_once __DIR__.'/files.php';
require_once __DIR__.'/tokens.php';
require_once __DIR__.'/guards.php';

/** Compose private discovery, token and guard helpers behind the existing scan API. */
final class PmssCustomerContextFatalScanner
{
    use PmssCustomerContextFiles, PmssCustomerContextTokens, PmssCustomerContextGuards;

    private $root, $skel, $www, $builtins;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/');
        $this->skel = $this->root.'/etc/skel';
        $this->www = $this->skel.'/www';
        $this->builtins = array_fill_keys(array_map('strtolower', get_defined_functions()['internal'] ?? []), true);
    }

    /** @return array<int,array{file:string,line:int,function:string,operator_tree_source:string}> */
    public function scan(): array
    {
        if (!is_dir($this->www)) return [];

        // ~/bin carries .php libraries next to the commands that require them (install-openclaw, docker-install-lsio).
        $wwwFiles = array_merge($this->files($this->www, false), $this->files($this->skel.'/bin', false), $this->shebangPhpFiles($this->skel.'/bin'));
        $customerDefs = $this->definitions(array_merge($this->files($this->skel, false), $wwwFiles));
        $operatorDefs = $this->definitions($this->files($this->root.'/scripts/lib', true));
        return $this->scanFiles($wwwFiles, $customerDefs, $operatorDefs);
    }

    /** @return array<int,array{file:string,line:int,function:string}> */
    public function scanOperatorTree(): array
    {
        $scripts = $this->root.'/scripts';
        if (!is_dir($scripts)) return [];

        $files = array_merge($this->files($scripts, true), $this->shebangPhpFiles($scripts));
        $defs = $this->definitions(array_merge($files, $this->files($this->root.'/etc', true)));
        return $this->scanFiles($files, $defs);
    }

    /** Null sources select operator diagnostics; even an empty customer index adds the source field. */
    private function scanFiles(array $files, array $defs, ?array $operatorDefs = null): array
    {
        $violations = [];
        foreach ($files as $file) {
            $tokens = $this->tokens($file);
            foreach ($this->calls($tokens) as $call) {
                $key = strtolower($call['function']);
                if (isset($this->builtins[$key]) || isset($defs[$key]) || $this->guarded($tokens, $call['index'], $key)) continue;
                $entry = ['file' => $this->relative($file), 'line' => $call['line'], 'function' => $call['function']];
                if ($operatorDefs !== null) $entry['operator_tree_source'] = isset($operatorDefs[$key]) ? $this->relative($operatorDefs[$key]) : '';
                $violations[] = $entry;
            }
        }
        return $violations;
    }

    /** Later files retain precedence; references and methods keep the historical definition rules. */
    private function definitions(array $files): array
    {
        $defs = [];
        foreach (array_values(array_unique($files)) as $file) {
            $tokens = $this->tokens($file);
            for ($i = 0, $n = count($tokens); $i < $n; $i++) {
                if (!$this->is($tokens[$i], T_FUNCTION)) continue;
                $name = $this->walk($tokens, $i, 1);
                if ($name !== null && $this->text($tokens[$name]) === '&') $name = $this->walk($tokens, $name, 1);
                if ($name !== null && $this->is($tokens[$name], T_STRING)) $defs[strtolower($tokens[$name][1])] = $file;
            }
        }
        return $defs;
    }

    private function relative(string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $this->root), '/');
        $path = str_replace('\\', '/', $path);
        return strpos($path, $root.'/') === 0 ? substr($path, strlen($root) + 1) : $path;
    }
}
