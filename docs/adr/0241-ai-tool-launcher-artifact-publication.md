# ADR 0241: AI-tool launcher artifact publication

Date: 2026-11-08
Category: architecture

## Status
Accepted

## Context
The system AI-tool installer compared launcher artifacts through separate Codex,
Claude and Gemini flows, then published them through two separate failure chains.
Gemini already used a file specification, while the other tools repeated calls.
The installer also carried Node readiness separately from its selected binary
and retained a single-use launcher-refresh flag.

## Options Considered
- Keep the separate comparison and publication implementations.
- Introduce installer classes or a configurable application framework.
- Share ordered artifact traversal, with tool policy retained at the callers.

## Decision
`update/apps/aiTools/publication.php` owns one comparison traversal and one
publication traversal. Artifact records carry the path, content and optional
comparison mode; publication records also carry their existing failure message.
The installer supplies complete, pure string payloads before publication. The
atomic writer and all per-tool stub/default builders remain authoritative.

Keep all three existing install-needed functions. Codex continues to compare
content only after its force/binary gate. Claude checks content, owner, group
and mode without clearing stat caches. Gemini still reads the source first,
checks its defaults directory, and refreshes each visited artifact's stat cache.
Its legacy path-keyed overwrite semantics and launcher/stub/defaults check order
are retained, including coincident paths. Publication instead retains
launcher/defaults/stub order and stops on the first failed write or throwable.
Failure messages retain their exact tool labels and text.

Delete the single-file comparison adapter after migrating its three callers.
Node removal failure clears the selected binary directly; npm version checks
and launcher-refresh decisions retain their original short-circuit behavior.
No alternate comparison or publication implementation remains in-tree.

## Consequences
- Five comparison/publication flows become two shared traversals; two redundant
  readiness flags disappear. The runtime source footprint decreases overall.
- Existing helper entrypoints, artifact bytes, write modes, root ownership,
  download pins, architecture gates, dry runs and fallback config stay fixed.
- The new helper sits below the updater's top-level installer glob, so it cannot
  acquire an independent installer execution or profiling step.
- Characterization tests lock missing/stale/redirected artifacts, source links,
  metadata differences, force/binary gates, Gemini directory/defaults gates,
  coincident paths, publication order, every write failure and throwable identity.
  The existing launcher tests retain their byte and metadata assertions. The
  installer source contract follows publication into the shared module.
- Validation on the dev box is changed-file PHP syntax checks and deleted-symbol
  searches. Behavioral execution belongs on the testing VM and CI; no installed
  tool, installer, dry run or test suite executes on the dev box.

## Security Review
Upstream history includes Node.js TLS denial of service (CVE-2026-21637) and
filesystem permission-model disclosure (CVE-2026-21715), plus Claude Code
write-time symlink races (GHSA-5j29-h97v-84ch). This is a structural refactor,
not an assessment that the pinned versions are affected or unaffected.
Shared-binary or launcher replacement would affect every invoking account, so
the existing atomic writer, root ownership, modes, symlink checks and extraction
guards remain in force. Characterization preserves the stricter npm metadata
checks rather than widening Codex's legacy content-only policy to other tools.

These installation records contain code and public defaults, not account
passwords or service credentials. Primary/service credential handling remains
outside this flow, in the unchanged account launcher and credential stores.
Failure logs contain the existing diagnostic labels, never artifact payloads.
The change adds no bind, listener, endpoint, public name, or certificate.

## References
- [Node.js March 2026 security release](https://nodejs.org/en/blog/vulnerability/march-2026-security-releases)
- [Claude Code upstream security advisories](https://github.com/anthropics/claude-code/security/advisories/)
- [Update application inventory](../update.md)
