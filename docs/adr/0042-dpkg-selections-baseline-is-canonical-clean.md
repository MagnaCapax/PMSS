# ADR 0042: The dpkg selections baseline is the canonical clean package set

Date: 2026-08-10
Category: architecture

## Status
Accepted

## Context
ADR 0018 established the dpkg selections baseline (`scripts/lib/update/dpkg/selections-debianN.txt`)
as the single package-state authority for `update-step2`. `dpkgSelections.php` reads the
version-matched baseline and sanitises it before `dpkg --set-selections`.

The sanitiser did two structurally different kinds of work in one place:

- **Runtime-conditional** drops that genuinely depend on state the static baseline cannot encode:
  package availability in the live repo (`pmssPackageAvailable`), cross-version obsolescence
  (`wireguard-dkms` only on Debian ≥12, `repo-mediaarea`), and kernel/php/python version filters.
- **Static, unconditional** drops of packages that are *always* removed regardless of any runtime
  condition — a hardcoded `in_array($lower, ['nzbdrone', 'pyload-cli'])` branch.

The static case produced a redundant round-trip: `selections-debian12.txt` carried
`nzbdrone install`, which the code then always stripped. A baseline that lists a package a code
rule always removes is not canonical — the data file no longer means "the package set for this
Debian version." This is the parse-and-patch smell ADR 0036 rejects, applied to package selections
instead of config files.

## Decision
The selections baseline is the **canonical clean package set** for its Debian version. It contains
exactly the packages that should be installed on that version, and nothing that is unconditionally
removed afterwards.

The sanitiser handles **ONLY runtime-conditional** cases (availability, cross-version obsolescence,
kernel/version filters, syntax validation). It does **not** carry static always-drop lists — those
belong out of the baseline entirely, not in a strip rule.

Concretely (2026-08-10):
- Removed `nzbdrone` from `selections-debian12.txt` (it was the only always-stripped package present
  in any baseline; `pyload-cli` was already absent from all baselines).
- Deleted the hardcoded `['nzbdrone', 'pyload-cli']` drop branch in `dpkgSelections.php`.
- Kept all runtime-conditional sanitising unchanged.
- Added `nzbget` to `selections-debian12.txt` (native Usenet client; `nzbget 21.0+dfsg-2.1` is in
  Debian 12 main). Per-version availability for Debian 11/13 is unverified here; the availability
  guard makes an absent package safe, but a package should only be ADDED to a baseline where it is
  actually available, to avoid re-introducing the always-dropped smell.

## Consequences
- **Positive:** the baseline data means what it says. A package appearing in `selections-debianN.txt`
  is a package intended for that version. No hidden static override.
- **Positive:** if an obsolete package is ever re-captured into a baseline, the availability guard
  still drops it — as `dropped_unavailable`, the honest classification (the package genuinely is not
  installable), rather than a bespoke `dropped_obsolete` special-case.
- **Behavioural note:** the `dpkgBaselineApplySafetyTest` policy snapshot now classifies a
  no-longer-special package (nzbdrone) as `dropped_unavailable` instead of `dropped_obsolete`, since
  it falls through to the availability check. Same end state (dropped), honest bucket.

## Related
- ADR 0018 (baseline = package-state authority)
- ADR 0036 (PMSS-owned config generated from template, never parsed-and-patched) — same principle,
  package-selection domain.
