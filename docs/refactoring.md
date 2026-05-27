# Refactoring Guidelines

PMSS follows Linux kernel style expectations when restructuring code. Keep the
following points in mind whenever you touch large files:

- **Keep single source files short.** Target ~150 lines per file; if you are
  pushing past ~200 lines, extract cohesive helpers or move logic into
  `scripts/lib/…` so callers compose small units. Splitting early keeps review
  surface manageable and mirrors the Linux kernel guidance captured in the repo
  root `README.md`.
- **Prefer focused modules.** When breaking a script apart, group related
  routines (e.g. package helpers vs. orchestration code) into dedicated files
  under the same feature directory. Avoid dumping unrelated functions into
  shared files.
- **Preserve behaviour.** Always keep upgrade paths for Debian 10 and 11 working
  while modernising logic for newer releases. Add adapters or fallbacks instead
  of rewriting flows in place.
- **Comment new helpers.** Maintain the 1-in-10 comment ratio by documenting why
  the split exists and what each helper does. Favour short docblocks at the top
  of each file.
- **Re-run lint/tests.** After refactoring, execute `php -l` on the touched
  files and run `php scripts/lib/tests/development/Runner.php` so regressions
  surface before shipping.

These rules complement the “Linux kernel style” note already present in the
repository documentation and should be referenced before undertaking larger
clean-ups.

## Minimal Contract Rules (Joukahainen)

For any optimization/refactor touching existing CLI/API output, follow this
minimal loop before merge:

1. **Name the consumer**: State who parses the output (human, cron, WHMCS,
   callback, etc.).
2. **Freeze baseline behavior**: Capture current input -> output + exit-code
   behavior before changes.
3. **Refactor behind the contract**: Internal code may change, but default
   output contract must remain byte- and parser-compatible.
4. **Prove compatibility**: Add/extend tests that assert legacy contract
   behavior on the default path.
5. **Version if change is required**: Introduce an explicit new flag/path and
   keep legacy behavior as default until migration is complete.

Non-negotiable guardrail: machine-consumed stdout is payload-only. Any
diagnostics go to stderr/logs.

Reject the refactor if any of these are true:
- Default invocation output format changed.
- Default invocation exit-code meaning changed.
- Machine payload channel (stdout) now includes diagnostics/noise.
- No compatibility test proves legacy default behavior still works.

## Helper Extraction Rules

When a helper pattern reaches three similar implementations, extract the shared
shape before shipping the third clone. The third implementation is the refactor
trigger, not proof that a duplicated pattern should persist.

### Third-Instance Refactor Trigger

When code review identifies that a proposed function has the same API shape as
an existing two-function pair, file the refactor issue first, or complete the
refactor if it is already tracked, and represent the third case as data or
configuration. The existing "extract at three call sites" threshold describes
when extraction is warranted; this rule defines when the work must happen. For
example, a proposed
`pmssEnsureXyzBlacklist()` sibling to `pmssEnsureAlgifAeadBlacklist()` and
`pmssEnsureDirtyFragBlacklist()` should become a registry entry behind one
shared blacklist helper instead of a third near-duplicate function.
