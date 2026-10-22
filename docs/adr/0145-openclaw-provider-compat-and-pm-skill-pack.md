# ADR 0145: OpenClaw provider compatibility and PM skill pack

Date: 2026-10-22
Category: architecture

## Status

Accepted

## Context

The AI credit endpoint rejects images/audio with `unsupported_content` and
always streams usage. OpenClaw uses model input metadata to decide whether to
send images, and defaults unknown remote OpenAI-compatible endpoints to no
streamed usage. Missing metadata therefore causes rejected requests and loses
token/cost accounting. Fresh onboarding and delayed key adoption both create
the PMSS `virsilipas` provider (ADR 0087).

mcxKokko is an Apache-2.0 AgentSkills pack for Pulsed Media services using the
account's own keys. Its availability must not become a gateway dependency,
and loading it must preserve user-configured skill directories.

## Options Considered

- Leave provider inference and skill installation to customers: repeats setup
  mistakes and leaves existing PMSS installations without correct metadata.
- Follow the latest skill branch or invoke a remote installer: unreproducible
  supply chain with no reviewed content boundary.
- Apply bounded metadata fixes and install one checksum-pinned release as the
  account user: reproducible, optional and compatible with existing state.

## Decision

Set `input: ["text"]` and `compat.supportsUsageInStreaming: true` on the PMSS
provider's `default` model in both creation paths. After onboarding, update only
the model list through `config set`, retaining the exact provider SecretRef.
Update migration requires the PMSS marker, the private account key file's
matching endpoint, `openai-completions`, one `default` model and the PMSS
environment SecretRef. Other model fields and compatibility flags survive.
Unchanged metadata makes no config call and causes no additional restart;
the existing runtime update still stops/starts the gateway. Late-key adoption
also refuses to replace an existing provider named `virsilipas`.

Pin mcxKokko to `v0.2.0`, SHA256
`4eb7f837dc75dedbbde3e2b92c2d27e65673185d1a1912c11f2122b0fd086a12`,
from `https://github.com/MagnaCapax/mcxKokko/archive/refs/tags/v0.2.0.tar.gz`.
A new pack release requires a PMSS commit changing the tag and checksum.
The downloader is injectable for offline tests; the production pin is fixed.
The test fixture is the original 29,618-byte release tarball with its license
and notice, verified against that same pin.

Create a new 0700 staging directory under the account's home, never `/tmp`.
Verify SHA256 before listing or extracting. Validate tar's effective member
names and types: reject absolute names, `..`, links, special files, escaped
names and anything outside `mcxKokko-0.2.0/`. Extract without foreign ownership
or permissions. Rename the completed tree to `~/.openclaw/pmss-kokko-v0.2.0`,
then atomically replace the relative `pmss-kokko` symlink. Only owned version
directories bearing the installer's private marker authorize replacement or
cleanup. An unsuccessful download or verification retains the old selection,
logs one generic line without command output or exception text, and continues.

Set `skills.load.extraDirs` to our single path only when unset or empty;
otherwise append it once, preserving all existing entries and order. With
`~/.openclaw/pmss-kokko-disabled` present, skip installation and remove only
our exact path. Uninstall also removes our load entry, selection and marked
version directories. Status/check report the selected version or absence.

Account-side helpers live beside the installer, distributed by the existing
skeleton refresh before the installer. They never include operator code from
`/scripts` (ADR 0016).

## Security review

Upstream's [provider credential routing advisory GHSA-vhpg-cq3w-v8p9](https://github.com/openclaw/openclaw/security/advisories/GHSA-vhpg-cq3w-v8p9)
describes credential disclosure when model reloads lose an explicit endpoint
(fixed in 2026.8.1). We preserve the provider endpoint and SecretRef rather
than rebuilding it. [CVE-2026-26322](https://github.com/openclaw/openclaw/security/advisories/GHSA-g6q9-8fvw-f7rf)
and [local token disclosure GHSA-v3j7-34xh-6g3w](https://github.com/openclaw/openclaw/security/advisories/GHSA-v3j7-34xh-6g3w)
remain relevant to gateway and local-listener exposure as recorded in ADR 0087.
Provider-key disclosure exposes the account's AI credits; gateway-token
disclosure grants agent/shell authority as that account. Neither directly
grants root. Primary account credentials are never read, copied or reused. Separate service
secrets remain in the validated private key/env files and process environment,
never config-set argv or routine logs. Config calls carry only model metadata
or skill paths. New staging/markers are private; runtime token mode, loopback
binding and authenticated panel exposure remain in effect. Archive contents
are never executed by the installer; skills subsequently run with the agent's
existing account authority.

## Consequences

Existing installs gain correct PMSS metadata on explicit update, without
replacing user providers. Optional pack failures leave an install usable;
marked versions allow safe retry/cleanup. User changes inside the managed
pack are replaceable; personal skills belong in separate directories.
Offline tests cover both provider creation paths, bounded migration,
download/hash failure, unsafe archives, atomic selection, opt-out, uninstall,
preserved extraDirs and idempotent updates.

## References

- [ADR 0087](0087-separate-per-user-openclaw-installer.md)
- [OpenClaw custom providers](https://docs.openclaw.ai/gateway/config-tools/custom-providers)
- [mcxKokko v0.2.0](https://github.com/MagnaCapax/mcxKokko/tree/v0.2.0)
