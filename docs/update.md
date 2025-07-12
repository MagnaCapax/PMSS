# Update Workflow

PMSS uses a two‑phase updater. The main entry point is `update.php`, which refreshes the scripts from GitHub and then invokes `util/update-step2.php` for configuration changes.

```
/scripts/update.php [<source-spec>] [--scriptonly] [--verbose]
```

`<source-spec>` defines what to update to. Examples from the script header:

```
release                  # latest GitHub release
release:2025-07-12       # explicit tag
git/main                 # branch "main" from default repo
git/dev:2024-12-05       # branch "dev" pinned to a past date
git/https://url/repo.git:beta[:2025-01-01]  # custom repo
```

Options:
- **--scriptonly** – download and deploy the scripts without running phase 2
- **--verbose** – print debug information

After copying files, phase 2 (`update-step2.php`) performs system-level tasks such as package installation, configuration tweaks and user environment updates.

Example for upgrading to the current release:

```
/scripts/update.php release
```

**Documentation quality**: The update process is extensive and only partially documented in comments. Additional user-facing notes on rollback and expected downtime would be helpful.
