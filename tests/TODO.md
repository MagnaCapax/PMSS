# Coverage Tracker

Track planned test coverage and known gaps. Close items as tests land or decisions obsolete them.

Format:
- Area: short description — planned tests or rationale

Examples:
- Distro detection — add edge cases for empty/malformed `/etc/os-release` (env overrides only)
- Repository templating — hash stability checks for unchanged templates
- Safe write helpers — forced write failure restores backup (temp dir only)

