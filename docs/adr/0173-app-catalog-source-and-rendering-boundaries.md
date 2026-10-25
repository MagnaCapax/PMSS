# ADR 0173: App catalog source and rendering boundaries

Date: 2026-10-24
Category: architecture

## Status

Accepted

## Context

Apps and Configure independently rendered the same wiki rows and grouped
LinuxServer rows. The dated LinuxServer snapshot also stored image and guide
URLs for every entry even though both URLs follow directly from its name.
The expanded six-field array and the legacy Apps page remain compatibility
contracts during staggered customer-file delivery.

## Options Considered

- Keep the duplicate templates and expanded source data.
- Add new customer helper files, introducing another delivery dependency.
- Share rendering in the existing Apps helper and expand snapshot links locally.

## Decision

`appsRuntime.php` owns one row renderer, wiki traversal, and consecutive-category
LinuxServer traversal. Both pages call these helpers while retaining their own
section wrappers, Configure-only AI guide, and search behavior. A page encountering
an older helper uses its existing updating response before calling the renderer.

The generator exports four source fields per LinuxServer entry and a local
projection that appends image and guide URLs in their existing order. Requiring
the snapshot still returns the same six-field array without loading panel helpers.
The developer builder's expanded return value and atomic writer remain unchanged.
No old renderer or expanded source snapshot remains alongside the shared flow.

## Consequences

The runtime mechanisms fall from four page-specific catalog traversals and two
stored derived fields to two shared traversals, one row renderer, and one local
link projection: six mechanisms become four. The existing helper grows, avoiding
new customer-file deployment ordering while the overall runtime footprint shrinks.

Characterization locks every delivered entry and field order by serialized hash,
builder filtering and ordering, generator reproducibility, standalone snapshot
loading, escaped row markup, optional tags, and category boundaries. Existing
panel render tests continue to cover the two page layouts.

## References

- [Apps catalog](0088-panel-apps-catalog-tab.md)
- [Configure migration](0103-configure-tab-replaces-apps-tab.md)
- [Customer Apps contracts](../contracts.md#customer-apps-controls--etcskelwwwscriptsincappsstatusphp-and-related-parts)
