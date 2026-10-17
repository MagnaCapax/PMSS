<?php
/** Pinned Codex layout and atomic publication helpers for aiToolsInstall.php. */

const PMSS_AI_CODEX_TAG = 'rust-v0.93.0';
// A pin bump requires a live Virsilipas check before adding another chat-capable tag.
const PMSS_AI_CODEX_CHAT_TAGS = ['rust-v0.93.0'];

/** Exact system stub; use the pinned binary if PHP or the launcher is unavailable. */
function pmssAiToolsCodexStub(): string
{
    $binary = '/opt/pmss/ai-tools/codex/codex-'.PMSS_AI_CODEX_TAG;
    return "#!/bin/sh\nif [ -x /usr/bin/php ] && [ -r /opt/pmss/ai-tools/pmss-ai-launch.php ]; then\n"
        ."    exec /usr/bin/php /opt/pmss/ai-tools/pmss-ai-launch.php codex \"\$@\"\n"
        ."fi\nexec {$binary} \"\$@\"\n";
}

/** Compare managed content, so the pre-launcher binary layout converges. */
function pmssAiToolsCodexInstallNeeded(string $binary, string $launcher, string $stub, string $source, bool $force): bool
{
    if ($force || !is_file($binary) || !is_executable($binary) || is_link($binary)) {
        return true;
    }
    $expectedLauncher = @file_get_contents($source);
    return !is_string($expectedLauncher)
        || is_link($launcher)
        || @file_get_contents($launcher) !== $expectedLauncher
        || is_link($stub)
        || @file_get_contents($stub) !== pmssAiToolsCodexStub();
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
            || @file_put_contents($temporary, $content) !== strlen($content)
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
