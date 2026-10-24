# ADR 0167: Runtime filesystem acquisition boundary

Date: 2026-10-24
Category: architecture

## Status
Accepted

## Context
The runtime filesystem include mixes temporary workspace management, stream
writes, regular-file acquisition, record decoding, and host-value interpretation.
Many libraries load that include directly, without the complete runtime facade.
Moving public helpers to an independently loaded runtime module would break
those callers.

The bounded and unbounded regular-file readers separately own the same
lstat/open/fstat/read/close sequence. Each repeats the device/inode comparison
already provided by the lock-file handle matcher. Their policies differ:
unbounded reads accept relative paths and pseudo-files whose reported size is
zero; bounded reads require absolute paths, an optional owner, a representable
byte limit, and an exact match between the bytes read and the opened size.

## Options Considered
- Keep the mixed file and two independent checked-open implementations.
- Move helpers to existing runtime modules and migrate every direct consumer.
- Preserve the include as a facade, separate its responsibilities, and share
  acquisition while retaining both public read policies.

## Decision
Keep `runtime/filesystem.php` as the stable include and owner of path/inode
primitives. Load sibling modules for private temporary workspaces, stream writes,
regular-file reads and decoding, and host values. Move unrelated helper bodies
without changing their behavior or adding alternate include paths to consumers.

Both public readers delegate to one regular-file acquisition function. It uses
the existing handle matcher with the pre-open lstat snapshot and owns the
stream through `finally`. A null internal byte limit selects the unbounded
policy; bounded calls keep their original path, size, owner, and overread checks.
There is one acquisition implementation and one device/inode matcher.

## Consequences
The existing public signatures and include surface remain available. Single-link
regular-file checks, non-blocking opens, owner restrictions, read failure results,
metadata reset, and exception cleanup remain enforced. Root-side configuration
reads retain their ownership boundary; no credential values, permissions,
logging, service configuration, or network exposure change.

Characterization tests cover payload and metadata bytes, empty files, exact size
limits, wrong owners, relative paths, symlinks, hard links, changed opened inode
metadata, failed/short/oversized reads, and cleanup after read exceptions. Existing
FIFO, temporary-prefix, partial-write, and snapshot tests remain behavior locks.
Fault injection targets the stream module after decomposition.

## References
- [Architecture overview](../architecture.md)
- [Runtime contracts](../contracts.md#runtime-execution--profiling)
- [Operational safety](../security/operational-safety.md)
