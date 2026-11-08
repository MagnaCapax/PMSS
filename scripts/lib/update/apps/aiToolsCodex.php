<?php
/** Pinned Codex layout and atomic publication helpers for aiToolsInstall.php. */
require_once dirname(__DIR__, 2).'/runtime/filesystem.php';
require_once __DIR__.'/aiTools/publication.php';

const PMSS_AI_CODEX_TAG = 'rust-v0.93.0';
// A pin bump requires a live Virsilipas check before adding another chat-capable tag.
const PMSS_AI_CODEX_CHAT_TAGS = ['rust-v0.93.0'];

/** Build the identical system stub for each CLI, retaining its own fallback. */
function pmssAiToolsStub(string $tool, string $fallback): string
{
    return "#!/bin/sh\nif [ -x /usr/bin/php ] && [ -r /opt/pmss/ai-tools/pmss-ai-launch.php ]; then\n"
        ."    exec /usr/bin/php /opt/pmss/ai-tools/pmss-ai-launch.php {$tool} \"\$@\"\n"
        ."fi\nexec {$fallback} \"\$@\"\n";
}

/** Exact system stub; use the pinned binary if PHP or the launcher is unavailable. */
function pmssAiToolsCodexStub(): string
{
    return pmssAiToolsStub('codex', '/opt/pmss/ai-tools/codex/codex-'.PMSS_AI_CODEX_TAG);
}

/** Compare managed content, so the pre-launcher binary layout converges. */
function pmssAiToolsCodexInstallNeeded(string $binary, string $launcher, string $stub, string $source, bool $force): bool
{
    return $force || !is_file($binary) || !is_executable($binary) || is_link($binary)
        || pmssAiToolsFilesDiffer([[$launcher, @file_get_contents($source), null], [$stub, pmssAiToolsCodexStub(), null]]);
}

/** Publish a small root-owned file from a same-directory temporary path. */
function pmssAiToolsCodexAtomicWrite(string $path, string $content, int $mode, int $owner = 0, int $group = 0): bool
{
    $temporary = @tempnam(dirname($path), '.pmss-codex-');
    if (!is_string($temporary)) {
        return false;
    }
    try {
        if (dirname($temporary) !== dirname($path)
            || !pmssFileWriteComplete($temporary, $content)
            || !@chmod($temporary, $mode)
            || !@chown($temporary, $owner)
            || !@chgrp($temporary, $group)) {
            return false;
        }
        return @rename($temporary, $path);
    } finally {
        if (is_file($temporary)) {
            @unlink($temporary);
        }
    }
}
