# ADR 0293: Observe log-policy drift without remediation

Date: 2026-11-17
Category: architecture

## Status
Accepted

## Context
Log caps reach hosts through rolling updates. An old snapshot or an incomplete
update can leave OS logs without the current protections (#798). Storage device
health does not describe this policy evidence.

## Options Considered
- Repair policy from cron: rejected; convergence remains in update-step2.
- Extend the existing storage snapshot payload: rejected to preserve its consumers.
- Add a sibling observation using existing JSONL and cron infrastructure: chosen.

## Decision
Run `scripts/cron/logPolicyHealthSnapshot.php` twice daily. Append observations to
`/var/log/pmss/log-policy-health.jsonl`, using the storage snapshot retention policy.
Reuse the updater's journald sizing and substitutions; compare the managed journald
and rsyslog logrotate files byte-for-byte against the installed templates. Check for
exactly one active canonical imklog rate-limited declaration in `/etc/rsyslog.conf`.
Custom or ambiguous declarations warn rather than being assumed protected.

Report dated snapshot content older than four weeks. The optional per-host
`/etc/seedbox/config/logPolicyMaxAgeWeeks` contains an integer from 1 through 520;
this is an administrator's observation interval, not a rollout switch (ADR 0272).
Invalid configuration warns and uses four weeks. Missing, malformed, dateless or
future content dates warn. A missing or different `version.completed` warns too.
Missing/unreadable policies, missing templates, unresolved substitutions and unknown
root capacity cannot produce an OK policy finding. No configuration bytes or version
strings are copied into the payload.

## Consequences
- Findings are alert-only JSONL records (`status=warn`, `level=warning`, reason flags).
  Successful persistence returns zero even for warning findings; output failures return
  one on stderr. No services, packages, configuration or update state are changed.
- Exact comparisons can report harmless formatting changes. This conservative evidence
  check does not certify daemon runtime state, additional override files or syntax.
- It compares against the locally installed policy, not remote main. No network access
  or fleet coordination is needed to deliver the code. Hosts must receive it through
  an update before it can observe their subsequent staleness; missing observations
  from hosts that never received the check require fleet-side monitoring.
- Hermetic fixtures inject all file reads, root capacity and time. Testing machines
  execute them; devbox validation checks changed PHP syntax only.

## References
- #798; ADR 0047 (kernel-input rate limit), ADR 0054 (content date), ADR 0272 (delivery).
