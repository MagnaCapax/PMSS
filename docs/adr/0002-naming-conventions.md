# ADR 0002: PMSS Naming Conventions

Date: 2025-11-06

Status: Accepted

Context

- PMSS spans PHP, Bash, and assorted config/templates. Inconsistencies have
  crept in across class names, test files, and helpers. A sibling project
  (nodeCore) enforces camelCase and consistent capitalization; we want PMSS to
  adopt an equivalent, PSR-aligned scheme while respecting existing entrypoints
  and third‑party code.

Decision

- PHP (application code, libraries, tests)
  - Classes and interfaces: UpperCamelCase (StudlyCaps), e.g., `WireGuardInstallerTest`.
  - Methods and functions: lowerCamelCase, e.g., `resolveEndpoint()`.
  - Constants: UPPER_SNAKE_CASE, e.g., `SONARR_INSTALL_PATH`.
  - Namespaces: UpperCamelCase segments under `PMSS`, mirroring directories.
  - Test files: end with `*Test.php` and contain a single class whose name
    matches the filename (case sensitive on Linux).

- PHP file names
  - Library modules may be snake_case or StudlyCaps depending on whether they
    are class‑oriented (prefer StudlyCaps for class‑only files). Avoid mixtures
    within a single directory.
  - Entrypoint scripts under `scripts/` may retain historical names for
    compatibility (cron, automation, existing docs). New PHP entrypoints should
    prefer lower-kebab or snake_case for CLI clarity and stability.

- Bash
  - Script filenames: lower-kebab (preferred) or snake_case; functions inside
    scripts: lower_snake_case.

- Brand capitalization
  - Use “WireGuard” (capital G) in user‑facing docs and class names. Internal
    script filenames may keep historical casing to avoid breaking operators.

- Third‑party code
  - Treat as read‑only; do not rename vendored assets.

Consequences

- Improves readability and cross‑project consistency with nodeCore while
  aligning with PSR‑12 for PHP.
- Test discovery remains simple: `Runner.php` includes `*Test.php`; consistent
  class names avoid confusion on case‑sensitive filesystems.
- Avoids churn on public entrypoints; large‑scale renames require migration
  shims and documentation updates.

Migration Plan

- Apply conventions incrementally. Start with tests and library classes.
- Update references in docs when file/class names change.
- Defer renaming of public entrypoints (`scripts/*.php`, cron targets) unless
  specifically requested; provide symlinks or wrapper scripts when renaming.

References

- PSR‑1/PSR‑12 (PHP basic coding standards and style guide)
- AGENTS.md (governing rails and repository expectations)

