<?php
/** System Gemini CLI stub, defaults, and content convergence. */

/** Keep the npm command usable when PHP or its launcher is unavailable. */
function pmssAiToolsGeminiStub(): string
{
    return "#!/bin/sh\nif [ -x /usr/bin/php ] && [ -r /opt/pmss/ai-tools/pmss-ai-launch.php ]; then\n"
        ."    exec /usr/bin/php /opt/pmss/ai-tools/pmss-ai-launch.php gemini \"\$@\"\n"
        ."fi\nexec /opt/pmss/ai-tools/npm/gemini/bin/gemini \"\$@\"\n";
}

/** The CLI accepts this root-owned file as its lowest-precedence settings. */
function pmssAiToolsGeminiDefaults(): string
{
    return "{\n"
        .'  "security": {"auth": {"selectedType": "gemini-api-key"}},'."\n"
        .'  "model": {"name": "default"},'."\n"
        .'  "modelConfigs": {"customOverrides": [{"match": {"overrideScope": "core"}, "modelConfig": {"model": "default"}}]},'."\n"
        .'  "agents": {"overrides": {"codebase_investigator": {"modelConfig": {"model": "inherit"}},'."\n"
        .'                           "cli_help": {"modelConfig": {"model": "inherit"}}}}'."\n"
        ."}\n";
}

/** Compare all three published artifacts, including owner and mode. */
function pmssAiToolsGeminiInstallNeeded(string $launcher, string $stub, string $source, string $defaults, int $owner = 0, int $group = 0): bool
{
    $expected = @file_get_contents($source);
    $directory = dirname($defaults);
    clearstatcache(true, $directory);
    if (is_link($directory) || !is_dir($directory) || @fileowner($directory) !== $owner
        || @filegroup($directory) !== $group || (@fileperms($directory) & 0777) !== 0755) {
        return true;
    }
    foreach ([$launcher => [is_string($expected) ? $expected : null, 0644],
        $stub => [pmssAiToolsGeminiStub(), 0755],
        $defaults => [pmssAiToolsGeminiDefaults(), 0644]] as $path => $spec) {
        clearstatcache(true, $path);
        if ($spec[0] === null || is_link($path) || @file_get_contents($path) !== $spec[0]
            || @fileowner($path) !== $owner || @filegroup($path) !== $group
            || (@fileperms($path) & 0777) !== $spec[1]) {
            return true;
        }
    }
    return false;
}
