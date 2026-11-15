# ADR 0287: Restore ruTorrent's channel global-rate pin

Date: 2026-11-15
Category: compatibility

## Status
Accepted

## Context
The compatibility patch for #742 replaced ruTorrent's global-rate pin with
zero to avoid a stale 3072 KiB/s constant. On the 0.9.8 line, named channels
receive quota from the global throttle, whose tick stops at an unlimited rate.
Zeroing the pin therefore leaves channel settings ineffective (#1257).

The skeleton uses 327625 KiB/s. rTorrent 0.9.8 derives unchoke slots from the
rate, and libtorrent 0.13.8 rejects more than 65536 slots. At the default
divisor, 327625 / 5 + 10 yields 65535 slots. A higher byte-rate ceiling alone
does not protect the download path from this separate limit.

## Options Considered
- Keep zero: preserves unlimited totals but leaves named channels ineffective.
- Restore the skeleton's pin through the existing account compatibility pass:
  repairs both stale constants and already-zeroed account copies.
- Set higher global rates in managed rc: introduces a startup failure risk
  from the unchoke limit and changes the configuration path.
- Hide channels: removes an existing customer feature.

## Decision
Replace exact MAX_SPEED and previously zeroed upload/download pin calls with
the literal 327625*1024 bytes/s in the first-party compatibility table. Leave
the unused constant alone, so stale values cannot determine the pin. Reuse
the existing guarded, metadata-preserving account writer and patch ordering.
Keep the plugin's existing guards: only a direction reading zero is pinned;
nonzero global rates remain authoritative. Bundled vendor sources stay unchanged.

## Consequences
Existing account files converge during ordinary user maintenance, including
partially patched copies. UI loads and the startup hook engage the restored
pin. No managed rc edit or forced restart is needed.

This restores a roughly 2.68 Gbit/s ceiling in each previously unlimited
direction, including accounts without active channels. It can constrain
high-throughput accounts; this is the cost of restoring the historical pin.

Hermetic regression cases cover both constants, zeroed and mixed-direction
copies, repeat-run inode stability, nonmatching rates and missing/symlinked
files. These run only on testing machines. Before rollout, live SCGI acceptance
on system 0.9.8 and r16 must confirm 335488000 bytes/s, successful plugin load,
and an active torrent on up16 holding near 16 KiB/s for a minute. That live
behavior is unmeasured here. Independent 0.16 channel-assignment failures are
outside this repair.

## References
- [Issue #1257](https://github.com/MagnaCapax/PMSS/issues/1257), including the 2026-11-14 adversarial review.
- [ADR 0196](0196-rutorrent-rpc-compatibility-catalog-boundaries.md).
- [rTorrent 0.9.8 rate setters](https://github.com/rakshasa/rtorrent/blob/v0.9.8/src/ui/root.cc#L177-L234).
- [libtorrent 0.13.8 unchoke bounds](https://github.com/rakshasa/libtorrent/blob/v0.13.8/src/torrent/download/resource_manager.cc#L268-L280).
- [libtorrent 0.13.8 root ticks and slave quota](https://github.com/rakshasa/libtorrent/blob/v0.13.8/src/net/throttle_internal.cc#L64-L145).
