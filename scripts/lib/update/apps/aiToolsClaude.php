<?php
/** System Claude Code stub and convergence checks. */

/** Prefer the shared launcher, but keep the installed npm binary usable. */
function pmssAiToolsClaudeStub(): string
{
    return "#!/bin/sh\nif [ -x /usr/bin/php ] && [ -r /opt/pmss/ai-tools/pmss-ai-launch.php ]; then\n"
        ."    exec /usr/bin/php /opt/pmss/ai-tools/pmss-ai-launch.php claude \"\$@\"\n"
        ."fi\nexec /opt/pmss/ai-tools/npm/claude/bin/claude \"\$@\"\n";
}

/** Compare content even when an older installer left a command symlink. */
function pmssAiToolsClaudeInstallNeeded(string $launcher, string $stub, string $source, int $owner = 0, int $group = 0): bool
{
    $expected = @file_get_contents($source);
    return !is_string($expected)
        || is_link($launcher)
        || @file_get_contents($launcher) !== $expected
        || @fileowner($launcher) !== $owner
        || @filegroup($launcher) !== $group
        || (@fileperms($launcher) & 0777) !== 0644
        || is_link($stub)
        || @file_get_contents($stub) !== pmssAiToolsClaudeStub()
        || @fileowner($stub) !== $owner
        || @filegroup($stub) !== $group
        || (@fileperms($stub) & 0777) !== 0755;
}
