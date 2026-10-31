# ADR 0221: Account resource CLI responsibility boundaries

Date: 2026-10-31
Category: architecture

## Status
Accepted

## Context
`userConfigCli.php` combined the resource specification, three parsing modes,
persisted-field selection, command construction and help rendering. Five
consumers independently selected specification rows by their metadata role.
Persistence updates repeated the stored-field traversal, while stored cgroup
commands merged unused account-name and memory fields into resource input.

## Options Considered
- Keep all responsibilities and selection loops in the original file.
- Split files without sharing their repeated selection and persistence rules.
- Retain the include contract and separate metadata, parsing and command
  projection, sharing role selection and the persisted-field projection.

## Decision
Keep `userConfigCli.php` as the existing include and help renderer.
`userConfigResourceSpecs.php` owns the unchanged ordered specification and
persisted-field projection. Its optional metadata-field selector supplies
positional, persistence and cgroup subsets through one filter.
`userConfigParse.php` owns legacy option precedence, raw/explicit/resolved
resource values, Docker-toggle parsing and upload-throttle validation.
`userConfigCgroup.php` owns sparse positionals and cgroup argv/command strings;
execution and account validation remain with existing callers.

Persisted updates intersect the same ordered stored-field projection with
truthy presence keys and replace only those payload entries. Stored commands
pass that resource projection directly, without an unused account record.
No duplicate implementation remains. The five independent metadata selectors
become one; the redundant intermediate account record is removed.

## Consequences
All existing helper names and no-argument specification calls remain available.
The default include, help bytes, option spellings, precedence, defaults,
exceptions and cgroup command order stay unchanged. Null, false and empty
persisted values retain their types and insertion order. Sparse positionals
retain schema traversal order, including the existing final-index behavior.
No limits, validators, stored schema, credentials or execution policy change.
The modules add local include dependencies; metadata can load independently
without runtime or help dependencies.

## Validation
`userConfigCliCharacterizationTest` retains the existing behavior lock.
`userConfigResourceBoundariesTest` adds exact-array characterization for
persisted types/order, presence truthiness, empty and zero cgroup arguments,
memory conversion, sparse positional traversal and isolated module loading.
`userConfigCliSpecificationTest` locks role membership and metadata-only loading.
The source contract follows the moved cgroup command into its owning module.

Only changed-file PHP syntax checks and static reference/diff inspection run
on the devbox. Test execution and behavioral comparison belong to the testing
machine and CI; no live account, service or provisioning dry run runs here.

## References
- [ADR 0080: Share CLI resource projection](0080-shared-cli-resource-projection.md)
- [Function contracts](../contracts.md)
- [Refactoring guidelines](../refactoring.md)
