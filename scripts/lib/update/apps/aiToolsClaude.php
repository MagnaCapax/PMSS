<?php
/** System Claude Code stub and convergence checks. */
require_once __DIR__.'/aiToolsCodex.php';

/** Prefer the shared launcher, but keep the installed npm binary usable. */
function pmssAiToolsClaudeStub(): string
{
    return pmssAiToolsStub('claude', '/opt/pmss/ai-tools/npm/claude/bin/claude');
}

/** Compare content even when an older installer left a command symlink. */
function pmssAiToolsClaudeInstallNeeded(string $launcher, string $stub, string $source, int $owner = 0, int $group = 0): bool
{
    $expected = @file_get_contents($source);
    return pmssAiToolsFileDiffers($launcher, is_string($expected) ? $expected : null, 0644, $owner, $group)
        || pmssAiToolsFileDiffers($stub, pmssAiToolsClaudeStub(), 0755, $owner, $group);
}
