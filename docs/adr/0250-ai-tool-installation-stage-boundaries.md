# ADR 0250: AI-tool installation stage boundaries

Date: 2026-11-09
Category: architecture

## Status
Accepted

## Context
The system AI-tool installer combined Node selection and cleanup, npm package
convergence, and pinned Codex installation in a single execution scope. Node
cleanup repeated removal and diagnostics for staged and obsolete runtimes.
Later installation work depended on mutable Node and Codex readiness state;
the Node version was independently spelled in its directory, archive and URL.
ADR 0241 already governs artifact comparison and ordered publication.

## Options Considered
- Retain the combined script and its nested prerequisite gates.
- Introduce an installer framework or separate top-level app installers.
- Keep the existing entrypoint and isolate its three sequential stages (chosen).

## Decision
`aiToolsInstall.php` loads and invokes `aiTools/node.php`, `npm.php` and
`codexInstall.php` in that order. The Node stage returns the selected binary;
the npm and Codex stages return after their existing refusal conditions. These
modules live below the app autoloader's glob and have no include-time effects.
The existing fallback-config helper retains its name and remains available
through the installer. Existing stub, defaults and publication helpers remain
authoritative; there is no second installer implementation.

Both obsolete Node tree classes share one removal flow and retain their exact
diagnostic labels. A single version value derives the pinned directory, archive
and download URL. Node removal failure returns directly; Codex acquisition
failure returns through its existing cleanup blocks before publication. Failed
prerequisites cannot suppress the later independent Codex stage unless they
throw, matching the original sequence. npm retains Gemini-before-Claude order
and continues after the same per-tool failures.

## Consequences
- Three existing responsibilities gain explicit boundaries without new options,
  dependencies or deployed state. Four internal concepts disappear: duplicated
  Node removal, mutable Node readiness, mutable Codex readiness and independent
  spellings of the pinned Node version. Runtime source is net smaller.
- Pins, architecture and verified-tag gates, version checks, command arguments,
  PATH changes, metadata, dry runs, old-kernel fallback and diagnostics retain
  their contracts. Private download/staging cleanup retains its order and
  propagates the original exceptions.
- Characterization covers cleanup order, selected Node paths, exact download
  metadata, npm ordering and publication payloads, failed prerequisites, Codex
  acquisition failures, kernel boundaries and throwable cleanup. Existing
  decision and source assertions follow their moved code. Fixtures intercept
  filesystem, environment, process and download effects in memory.
- Validation here is changed-file PHP lint and symbol searches only. Behavioral
  tests and dry-run execution belong on the testing VM/CI, never the dev box.

## Security Review
Upstream Node history includes TLS denial of service (CVE-2026-21637) and
permission-model path disclosure (CVE-2026-21715). Claude Code advisories include
write-time symlink following (GHSA-5j29-h97v-84ch). These illustrate relevant
availability and filesystem attack surfaces; this refactor makes no claim about
whether the unchanged pins are affected. Replacement of a shared binary could
affect every invoking account, so hash verification, root ownership, extraction
flags, atomic publication, path checks and private temporary cleanup stay intact.

Installation stages handle code and public defaults, with no primary or service
credentials. Account credential separation and launcher redaction remain outside
this change. Failure logs retain their labels and do not print file payloads.
No listener, endpoint, public name, DNS record or certificate is introduced.

## References
- [Artifact publication](0241-ai-tool-launcher-artifact-publication.md)
- [Node.js March 2026 security release](https://nodejs.org/en/blog/vulnerability/march-2026-security-releases)
- [Claude Code upstream advisories](https://github.com/anthropics/claude-code/security/advisories/)
