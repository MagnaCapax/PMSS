# Incident Report: `/home` Directory Deletion Due to `updateQuotas.php` Consuming Faulty Output from `listUsers.php`

**Date:** 2025-12-08  
**Affected Component:** PM Software Stack (PMSS) - Quota Management (`updateQuotas.php`)  
**Impact:** Complete deletion of `/home` user directories on a single seedbox server; only users on this server were affected.

---

## Executive Summary

On December 8th, 2025, an automatic cron script (`updateQuotas.php`), which has been part of the PMSS since 2011, inadvertently deleted all user home directories under `/home` due to an unexpected combination of script output errors and missing PHP dependencies during a routine software update. No external compromise occurred; this incident arose entirely from internal script interactions during a partial update scenario. Pulsed Media immediately investigated, rectified the underlying causes, and implemented comprehensive safeguards. This affected only a single node. No other servers or services were impacted.

---

## Detailed Timeline

### Pre-Incident Conditions

* **Historical Setup (since 2011):**

  * The quota script (`updateQuotas.php`) executed every minute via cron:

    ```php
    $users = shell_exec('/scripts/listUsers.php');
    $users = explode("\n", trim($users));

    foreach ($users as $thisUser) {
        $command = "rm -rf /home/{$thisUser}/.quota; quota -u {$thisUser} -s >> /home/{$thisUser}/.quota; chmod o+r /home/{$thisUser}/.quota";
        system($command, $ret);
    }
    ```
  * Assumption: `listUsers.php` consistently returned only valid usernames.
  * No explicit username validation or path checks existed.

### Incident Trigger (2025-12-08 ~15:22)

* **Routine PMSS Update Initiation:**

  * Initiated a software update with standard practices:

    * Temporarily deleting the contents of `/scripts` before repopulating with updated code.
    * Continuous cron execution continued uninterrupted, every minute.

### Immediate Cause

* **Dependency and Environment Issue:**

  * Updated scripts invoked `posix_getpwnam()` without confirming availability of the `posix` PHP extension.
  * During the short window of deletion and replacement, `/scripts/listUsers.php` emitted PHP fatal errors to standard output instead of valid usernames.

### The Critical Failure Path

* **`updateQuotas.php` received malformed input from listUsers.php:**

  ```text
    thrown in /scripts/lib/user/userFilesystem.php on line 95
  ```
* This string became `$thisUser`, creating a malformed shell command:

  ```shell
  rm -rf /home/  thrown in /scripts/lib/user/userFilesystem.php on line 95/.quota
  ```
* Due to shell tokenization, `/home/` became the primary operand for `rm -rf`, deleting user home directories recursively.

### Verification Through Logs

* Logs explicitly show evidence matching exactly this scenario:

  * Permission errors on immutable quota files (`aquota.user`, `aquota.group`).
  * Malformed paths and usernames like `Stack trace:`, `line`, and `95/.quota` appearing in logs confirming the script's incorrect execution.
  * Logged error messages lead us to make an exact reproduction.

---

## Root Cause Analysis

The primary cause was the combination of three factors:

1. **Unsafe Shell Usage:**

   * `updateQuotas.php` executed multiple shell commands chained with semicolons, directly embedding unvalidated output from another script.

2. **Implicit Trust of Internal Script Output:**

   * The script assumed internal tools always provide valid, predictable output without error checking.

3. **No Explicit Safety Checks:**

   * The absence of path validation or checks (e.g., using `realpath`) enabled execution of unintended shell commands.

---

## Probability and Uniqueness of the Incident

Considering:

* Approximately 14 years of continuous operation
* Millions and Millions of successful invocations of `updateQuotas.php`
* Tens of Thousands routine software updates executed
* Thousands of hosting nodes with similar configurations

The statistical likelihood of this exact scenario was extraordinarily low, underscoring why this latent issue remained unnoticed for over a decade. This incident highlights the critical importance of robust validation and guardrails, even in stable, battle-tested over decades systems.

The conditions needed to trigger this bug are insanely specific; It sat dorman for ~14 years.

---

## Immediate Corrective Measures Implemented

We moved decisively to strengthen the PM Software Stack:

* **Enhanced Validation:**

  * All usernames and internal tool outputs are now explicitly validated (`pmssValidateUsername()`).

* **Robust Shell Execution Policies:**

  * Complete elimination of chaining shell commands with semicolons involving dynamic inputs.
  * Adoption of single-command execution with explicit argument escaping (`escapeshellarg`).

* **Mandatory Invariant Checks:**

  * Path resolutions (`realpath`) now confirm exact match to intended paths before destructive operations.

* **Improved Error Handling:**

  * All scripts (including `listUsers.php`) now gracefully handle missing dependencies, emitting errors only to standard error and aborting clearly without stack traces.

* **Structured and Unified Logging:**

  * Centralized and structured logging (`users.log`, `users.jsonl`, and per-user logs), greatly enhancing observability and debugging.

* **Cron safety during updates:**

  * Update bootstrap now disables `/etc/cron.d/pmss` at the start of an update run; `update-step2` re-applies the template afterward. This prevents cron jobs from running against a partially refreshed `/scripts` tree.
  * The legacy `/etc/cron.d/updateQuotas` entry is unlinked at update start to prevent any recurrence of the quota refresh regression (see this incident). A TODO remains to remove this guard around 2030-12 once the fleet is fully refreshed.
* Phase 1 now stages `/scripts` and `/etc/seedbox` using atomic rename swaps (commit `ab31f8b`), eliminating partial-tree windows for shared libraries during updates.

The initial corrective measures (core fix to `updateQuotas.php`, `listUsers.php`, logging, and guardrails) were landed in commit [229c1e7a11230af7ed9e73cf42369d7878f589b2](https://github.com/MagnaCapax/PMSS/commit/229c1e7a11230af7ed9e73cf42369d7878f589b2). Additional hardening to the updater (cron disable/unlink and simplified wipes) followed in [31872d1e29917bf8eece14510142d507b4b23ea9](https://github.com/MagnaCapax/PMSS/commit/31872d1e29917bf8eece14510142d507b4b23ea9).

  
### Server Updates

Already started rolling out this patch to number of servers, on a standard rolling pattern. Considering the rarity and how unlikely it is to happen it does not override fully the rolling updates nature.

### Future Options 

We may opt to use our backend automation to push a minimally corrected version immediately to all servers after customer communication like this and immediate mitigations has finished, bypassing rolling update pathway we typically use. We are also considering some further failsafes which would mitigate issues during update, but these issues are very rare and very difficult to test against (ie. timing related).

---

## Continuous Development and Enhancement at Pulsed Media

This incident is a concrete example of Pulsed Media’s approach to reliability, transparency, and constant improvement. Over 14 years of uninterrupted service expansion and technical enhancement testify to the strength and resilience of our practices. Each rare issue is thoroughly analyzed, swiftly rectified, and followed by rigorous improvements, making Pulsed Media a robust and reliable choice for advanced hosting needs.

Our philosophy remains clear and pragmatic:

* Reliability and Stability above all else
* Keep systems straightforward and effective
* Do not unnecessarily alter proven working solutions
* Continuously reinforce safety and error tolerance

---

## Key Lessons for the Future

* **Explicit Validation:** Always validate dynamic input and even internal tool outputs.
* **Shell Safety:** Never embed untrusted or dynamic data directly into multi-command shell strings without rigorous validation and escaping.
* **Maintenance Safety:** Ensure transient states (like in-place updates) cannot inadvertently trigger unsafe automated behaviors. If necessary, introduce explicit locking mechanisms during critical operations.
* **Test for Failures:** Regularly test not only standard operation but also all conceivable failure scenarios and environmental conditions.

Pulsed Media reinforces these lessons into our engineering culture and operational standards to maintain and extend our industry-leading reliability and stability. With this document, these lessons become permanent part of the code base and into refactoring routines.
