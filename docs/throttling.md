# Per-User Bandwidth Throttling Architecture

How PMSS limits per-user network egress when a customer exceeds their monthly traffic allowance. End-to-end pipeline from sample → enforcement, with state files, code paths, and verification commands.

## High-Level Flow

```
┌────────────────────────┐    ┌────────────────────────────┐    ┌───────────────────────┐
│ trafficLog.php (5min)  │ →  │ trafficStats.php (5min)    │ →  │ /home/<u>/.trafficData│
│ iptables OWNER UID     │    │ aggregate to month/week/   │    │ (PHP-serialized)      │
│ counter sample         │    │ day/hour/15min windows     │    │                       │
└────────────────────────┘    └────────────────────────────┘    └───────────┬───────────┘
                                                                            │
                                                                            ▼
┌────────────────────────────────────────────────────────────────────────────────────────┐
│ trafficLimits.php (cron) — reads .trafficData vs trafficLimits/<u>                     │
│   if usage > limit:           hard cap          → .enabled marker + /home/<u>/.throttle (Mbit)
│   if under limit + cooldown:  remove .enabled, restore default cap
└────────────────────────────┬───────────────────────────────────────────────────────────┘
                             │
                             ▼
┌────────────────────────────────────────────────────────────────────────────────────────┐
│ fireqos.php::networkBuildFireqosConfig                                                 │
│   per user: read .enabled + .throttle (hard) > defaultCap                              │
│   render: class <user> ceil <N>Mbit                                                    │
│             match rawmark <fireqosMark>                                                │
│   write to /etc/seedbox/config/fireqos.conf                                            │
└────────────────────────────┬───────────────────────────────────────────────────────────┘
                             │
                             ▼
┌────────────────────────────────────────────────────────────────────────────────────────┐
│ networkApplyFireqos: shell `fireqos start /etc/seedbox/config/fireqos.conf`            │
│   parses DSL, programs kernel:                                                         │
│     - iptables mangle OUTPUT: MARK packets per UID with fireqosMark                    │
│     - tc qdisc add eth0 root htb                                                       │
│     - tc class add eth0 parent N: classid X:Y htb rate ... ceil <N>Mbit                │
│     - tc filter on rawmark to direct packets to per-user class                         │
└────────────────────────────────────────────────────────────────────────────────────────┘
```

End result: any packet from a UID over the limit hits a kernel HTB class with a rate ceiling, drops/throttles to the configured Mbit/s.

The persisted `raw.month` total and `daily` chart series use the same rolling
30-day timestamp cutoff. The boundary calendar day remains as a partial bucket.

## State Files

### Per-user (in /home and /var/run)

| Path | Owner | Format | Written by | Meaning |
|---|---|---|---|---|
| `/home/<user>/.trafficData` | user | PHP-serialize | `trafficStats.php` | Monthly + window aggregates of egress bytes |
| `/etc/seedbox/runtime/trafficLimits/<user>` | root | plain int | `userTrafficLimit.php` | Monthly traffic cap in **GiB** (0 = unlimited) |
| `/home/<user>/.bonusTraffic` | root | plain int | `userBonusTraffic.php` | Extra GiB granted on top of base limit |
| `/var/run/pmss/trafficLimits/<user>.enabled` | root | empty marker | `trafficLimits.php` | Hard-cap state active; mtime drives cooldown |
| `/home/<user>/.throttle` | user | plain int | `setRateLimit()` | Hard-cap ceiling in **Mbit/s** when limit is enforced |

### System (per host)

| Path | Owner | Format | Written by | Meaning |
|---|---|---|---|---|
| `/etc/seedbox/config/network` | root | INI-like | operator | `interface=`, `speed=`, throttle settings |
| `/etc/seedbox/config/template.fireqos` | root | text template | operator | Outer scaffolding for fireqos.conf |
| `/etc/seedbox/config/fireqos.conf` | root | FireQOS DSL | `fireqos.php` | Rendered config consumed by `fireqos start` |
| `/var/log/pmss/fireqos.log` | root | text log | `fireqos start` | Parser/installer messages — check this first when throttle isn't applying |

## Throttle Algorithm

### Hard cap (over 100% of limit)

When `usage > limit`:

1. `touch /var/run/pmss/trafficLimits/<user>.enabled` (or refresh mtime if already set)
2. Compute `effectiveCapMbit` via tiered overage stages OR progressive throttle (see below)
3. `setRateLimit(user, effectiveCapMbit)` → writes `/home/<user>/.throttle` (Mbit value)

### Post-cap default profile

Defined in `pmssTrafficLimitDefaultOverageStages()` (file: `scripts/lib/user/trafficLimit.php`). The default profile holds post-cap users at 100 Mbit/s for any overage and never raises a lower per-user `trafficCapMbit` override.

| Overage % over limit | Min absolute overage | Cap (Mbit) |
|---|---|---|
| 0%+ | 0 GiB | **100** |

Custom `overageStages` in `/etc/seedbox/config/network` still use highest-threshold-first matching. The exact PMSS-owned legacy five-tier table is treated as stale generated config and falls back to the current default so old hosts stop enforcing the 1 Mbit floor without overwriting operator-edited network files.

If no tier matches AND `progressiveThrottleEnabled` is true, `pmssTrafficLimitComputeProgressiveCapMbit()` returns a continuous formula instead of a tier.

### Cooldown

`.enabled` marker is required to be older than `trafficLimitPeriod` (3 days) of under-limit usage before throttle is removed. `trafficLimits.php` `unlink`s the marker and calls `setRateLimit(user, defaultCap, false)` twice (intentional double-disable to handle router desync).

## Rendered fireqos.conf Shape

Per-user class in the rendered config:

```
class <username> ceil <N>Mbit
    match rawmark <fireqosMark>
```

`fireqosMark` is sequential (1, 2, 3, ...) per the order users are walked. iptables mangle OUTPUT chain marks packets to each user's UID with the corresponding `fireqosMark`. The `match rawmark` line in fireqos directs marked packets into the per-user HTB class.

When `.enabled` is not present, the user's class is rendered without a `ceil` clause — they get the parent class's full bandwidth allocation.

## Verification Commands

```bash
# 1. Has fireqos installed any HTB classes?
tc class show dev eth0
# Expect: HTB classes per user when throttle is active.
# If only `mq` qdiscs visible: fireqos failed; check /var/log/pmss/fireqos.log.

# 2. fireqos parser status — most recent run
tail -20 /var/log/pmss/fireqos.log
# Expect: success messages.
# If "FAILED TO ACTIVATE TRAFFIC CONTROL": rendering bug, throttle not enforcing.

# 3. fireqos config validity
fireqos start /etc/seedbox/config/fireqos.conf
# Expect: clean exit + tc classes installed.

# 4. Per-user state inspection
cat /etc/seedbox/runtime/trafficLimits/<user>      # GiB monthly cap
cat /home/<user>/.throttle                          # Mbit hard cap (when over)
ls -la /var/run/pmss/trafficLimits/<user>.enabled   # presence + mtime = throttled state + cooldown

# 5. Iptables MARK plumbing (mangle table OUTPUT chain)
iptables -t mangle -L OUTPUT -n -v | grep -E 'UID match' | head -10
# Expect: MARK rules for every UID with a managed user.

# 6. Per-user usage data (egress bytes)
php /scripts/util/remote/userTraffic.php
# Returns serialized array: {user => {normal: MB, local: MB, ingress: MB}, ...}

# 7. Read serialized .trafficData (window aggregates)
php -r 'print_r(unserialize(file_get_contents("/home/<user>/.trafficData")));'
```

## Common Failure Modes

### fireqos start fails with `flowid` error

Symptom: `/var/log/pmss/fireqos.log` shows `ERROR: ... match: Please set 'flowid' for match statements above all classes. FAILED TO ACTIVATE TRAFFIC CONTROL. Clearing FireQOS interface(s) ...`. Result: zero HTB classes installed; throttle not enforcing for ANY user.

Cause: rendered fireqos.conf has a `match` statement that the parser interprets as top-level (without a class context), typically the `class local` block leading with `match dst <localnets>`.

Fix surface: `scripts/lib/network/fireqos.php::networkBuildFireqosConfig()`. Either add explicit `flowid` on the local class, or restructure to avoid leading with `match`.

Verification post-fix:
```bash
fireqos start /etc/seedbox/config/fireqos.conf  # should return success
tc class show dev eth0 | grep -c htb            # should be > 0
```

### .trafficData missing or stale

Symptom: `trafficLimits.php` skips users with `if (!file_exists($trafficDataFile)) continue;`.

Cause: trafficStats.php cron not running, or has not yet completed first cycle for a newly-provisioned user.

Fix: verify trafficStats cron in root.cron; check trafficStats.log for errors.

### iptables MARK rules missing

Symptom: HTB classes installed but no traffic flows into them.

Cause: `setupNetwork.php` not run since user added; mangle OUTPUT chain doesn't have UID match for the user.

Fix: re-run `/scripts/util/setupNetwork.php` to regenerate iptables rules.

## Source Map

| File | Role |
|---|---|
| `scripts/cron/trafficLog.php` | Per-cycle per-user iptables OWNER UID egress sample |
| `scripts/cron/trafficIngressLog.php` | Per-cycle per-user systemd IPIngressBytes sample |
| `scripts/cron/trafficStats.php` | Aggregate samples → window totals → write `.trafficData` |
| `scripts/cron/trafficLimits.php` | Throttle decision: hard cap/cooldown |
| `scripts/lib/user/trafficLimit.php` | Tiered overage stages + progressive throttle helpers |
| `scripts/lib/network/fireqos.php` | Render fireqos.conf + apply via `fireqos start` |
| `scripts/util/userTrafficLimit.php` | Operator/admin tool: set per-user GiB cap |
| `scripts/util/userBonusTraffic.php` | Operator/admin tool: grant bonus traffic |
| `scripts/util/setupNetwork.php` | One-shot: regenerate iptables + fireqos config + apply |
| `scripts/util/remote/userTraffic.php` | Read-only: dump per-user serialized usage |
| `etc/seedbox/config/template.fireqos` | Template scaffolding for the rendered fireqos.conf |
| `etc/seedbox/config/network` | Operator-tunable: interface, speed, throttle config |

## Documentation Quality

The throttle pipeline crosses 5+ files and 3 state-file conventions (`/home/<user>/.X`, `/var/run/pmss/trafficLimits/<user>.X`, `/etc/seedbox/runtime/trafficLimits/<user>`). Inline code comments are sparse. This file is the architecture summary. For implementation detail, read the source map above in order.

If a per-user throttle is "set in the system" but `tc class show dev eth0` doesn't reflect it, check `/var/log/pmss/fireqos.log` first — fireqos start failures are the most common reason throttling appears configured but isn't enforcing.
