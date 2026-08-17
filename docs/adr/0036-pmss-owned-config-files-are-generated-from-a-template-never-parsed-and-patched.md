# ADR 0036: PMSS-owned config files are generated from a template, never parsed and patched

Date: 2026-07-30
Category: architecture

## Status
Accepted

## Context
PMSS produces config files for the services and accounts it manages. Two shapes exist in the
codebase for doing that:

- **Generate from a template.** A static `etc/seedbox/config/template.*` file carrying `##token`
  placeholders, one `str_replace()`, then write. Around fifty templates already follow this —
  `template.lighttpd`, `template.nginx-user`, `template.proftpd`, `template.deluge.core.conf`,
  `template.qbittorrent.conf`, `template.rtorrent.rc`. Reference implementations:
  `scripts/util/userConfig.php` (qBittorrent: one `str_replace` over three tokens) and
  `scripts/lib/rtorrentConfig.php` (rTorrent: a keyed `##token => value` map fed to
  `str_replace(array_keys(...), array_values(...), $template)`).
- **Parse the existing file and patch it.** Read whatever is on disk, then surgically set fields in
  place. `arrRootConfigHardening.php` did this for a root-side Servarr `config.xml`: a
  `preg_replace_callback` per element, a normalizer for self-closing documents, a heuristic deciding
  whether an existing credential was acceptable to keep, and a marker injected by a third callback —
  roughly 120 lines to produce a file PMSS owns end to end.

The two are not stylistic variants. Generated output is a pure function of (template, inputs), so the
same inputs give a byte-identical file on every host. Patched output is a function of whatever state
happened to be on disk, so two hosts on the same PMSS version can legitimately diverge and nothing
in the code explains why. The "is the existing value good enough" heuristic exists only because a
patcher has to ask; a generator never does.

## Options Considered
- **Generate from a template (chosen)** – deterministic, one substitution, uniform with ~50 existing
  consumers. Cost: the whole file is rewritten, so anything not in the template is not preserved.
- **Parse and patch in place** – preserves unknown content. Cost: output depends on prior on-disk
  state (undiagnosable drift), and every field the upstream format gains is another regex to write
  and re-audit.
- **Per-format library (an XML/INI/JSON writer)** – correct parsing, but imports a dependency and
  still produces state-dependent output for the same reason as patching.

## Decision
**A config file PMSS owns is GENERATED. It is never parsed in order to be changed.**

The pattern, exactly as the existing consumers use it — no variant, no new abstraction:

1. a static template at `etc/seedbox/config/template.<name>`, using `##token` placeholders
   (`##token`, not `{{token}}` — the codebase convention is load-bearing: a mismatched convention
   silently ships a file that still contains its placeholders);
2. one substitution pass — a single `str_replace()` of the token list against the template. A
   template with no tokens needs no substitution at all: a straight copy is the same rule at its
   degenerate case (`template.sshd_config` is installed exactly that way, in
   `scripts/lib/update/services/bootstrap.php`);
3. write through `pmssWriteManagedPathFile()` / `pmssRefreshManagedPathFile()`, which already provide
   atomic write, mode, and change detection.

**Ownership is the test, and it is the same test as ADR 0032.** That ADR decides *where* managed
config lives; this one decides *how* it is produced. PMSS owns the file when PMSS authors it,
controls its lifecycle, and the customer is not expected to hand-edit it.

**Two exceptions, both narrow:**

1. **Foreign content must survive.** The file is not PMSS's — someone else's lines have to remain.
   Patching is legitimate; the commit must say whose content is being preserved. Live example: the
   dist-upgrade suite rewrite in `scripts/lib/update/distUpgrade/sources.php` edits
   `/etc/apt/sources.list` and `sources.list.d/*.list` in place, because third-party repository lines
   there are not ours to regenerate.
2. **Bounded convergence repair.** A one-time in-place edit that moves an existing file on an
   already-provisioned host toward the templated state, guarded on the defect being present. Live
   example: `pmssHealOpensshServerIfMissing()` in `scripts/lib/update/opensslSsh2Compat.php` strips
   `hmac-ripemd160` from `/etc/ssh/sshd_config` only when that string is found, because OpenSSH 9.2
   removed the cipher and a host provisioned before then will not start otherwise. A repair is
   guarded, converges toward the template, and is expected to stop firing — it is not the steady-state
   production mechanism, and it must not grow into one.

"It already exists and I only want to change one field" is neither exception — an owned file is
regenerated.

Prohibited for owned files: a setter per field; a normalizer that reshapes an existing document so it
can be patched; a "keep the current value if it looks acceptable" heuristic; any hand-rolled
format transformer (regex over XML/INI/JSON/rc).

## Consequences
- Positive: byte-identical output across the fleet for identical inputs, so config drift becomes
  detectable instead of invisible.
- Positive: the format lives in a reviewable text file, not distributed across regex callbacks. A new
  upstream field costs one template line rather than one more transformer.
- Positive: uniform with ~50 existing consumers, so there is one pattern to learn and to review.
- Negative (accepted): a generated file does not preserve out-of-band local edits. That is the
  intended semantics for a file PMSS owns; where local content genuinely must survive, the file is
  not owned and the exception above applies.
- Negative (accepted): template changes have fleet-wide blast radius on regeneration — the same
  trade-off ADR 0032 accepts, mitigated the same way (hermetic tests assert the rendered shape).
- Follow-up: PMSS #741 proposed migrating `arrRootExecutionBlock.php`'s config generation to
  `template.arr.config.xml` plus one `str_replace`. Superseded-by-deletion: that file never held
  the rewriting functions this ADR describes (they were removed earlier, in 73f8e818), and no
  root-side Servarr config is generated today — ADR 0034 blocks root execution instead. Closed
  with no code change.

## References
- ADR 0032 — ownership decides placement; this ADR is its production-mechanism counterpart.
- ADR 0034 — install is not execution; the root-side Servarr config is the case that triggered this.
- `scripts/util/userConfig.php`, `scripts/lib/rtorrentConfig.php` — the reference implementations.
- PMSS #741 — proposed first migration; closed superseded-by-deletion (no code change needed).
- Operator directive, 2026-07-30: all config files idempotently templated on the same minimal pattern
  as the other `template.*` files.
