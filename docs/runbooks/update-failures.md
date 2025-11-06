# Runbook: Update Failures

Quick steps to diagnose update issues without making changes.

1. Inspect logs
   - `/var/log/pmss-update.log` (text)
   - `/var/log/pmss-update.jsonl` (JSON events)
2. Look for last successful step and failing command. Note `rc` and stderr excerpt.
3. Confirm environment
   - Distro detection: `PMSS_DISTRO_*` envs in logs and `/etc/os-release`
   - APT state: `dpkg --configure -a`, `apt-get -f install` (non-destructive)
4. Rehearse with dry-run
   - `/scripts/update.php --dry-run`
   - Verify `update_step2_skipped` in JSON log when appropriate
5. Narrow scope
   - If failure is in a module, run that step’s command manually or via a wrapper in dry-run mode when available.
6. Recovery
   - Use backups made by safe write helpers to restore critical files (e.g., sources.list backups).
7. Escalate
   - If behavior needs to change, open an ADR proposal and reference logs.

