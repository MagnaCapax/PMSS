# Pulsed Media Seedbox Software

## Description

Pulsed Media Seedbox Software, server side code.
This builds and installs all the software and scripts to operate a seedbox server.

Works on Debian 10/11/12/13. Debian 12 (Bookworm) is the recommended
production target. Debian 13 (Trixie) support is experimental/development.
Debian 10 is upstream end-of-life, but existing installations continue to function.

This can be used standalone fully, does not require a server from Pulsed Media. You can _freely_ use this for your own self-hosted seedbox.
No commercial restrictions, you are free to provide seedbox services using this and there are even some minimalistic whitelabeling features.
Some pieces will still be fetched from Pulsed Media servers, such as the latest GUI version.

More information available at https://wiki.pulsedmedia.com/index.php/PM_Software_Stack


## Documentation

You can find information for common tasks such as adding/creating/suspending users at https://wiki.pulsedmedia.com/index.php/Category:PM_Software_Stack_Guides.

For a quick maintenance checklist covering dry-runs, structured logging and
tests, see `docs/maintenance.md`. WireGuard usage notes are available in
`docs/wireguard.md`. Hardware acceleration setup for FFmpeg/Jellyfin is
documented in `docs/hardware-transcoding.md`.

### Contributing & Decisions

See `CONTRIBUTING.md` for workflow and validation steps. Significant technical
choices are recorded as ADRs; new decisions should include an ADR alongside
code, tests, and documentation.

For a Debian-based development container that can run the local validation
suite without touching a real host, see the `Development Container` section in
`CONTRIBUTING.md`.

### Installation

Install minimal Debian system, and run following as root

Release:
```
wget -qO- https://github.com/MagnaCapax/PMSS/raw/main/install.sh | bash
```
To pin a specific version, pass it as the first argument (e.g. `| bash -s -- git/main` for the latest main branch or `| bash -s -- release:2023-07-22` for a tagged release).
When installing via a pipe in an interactive SSH session, `install.sh` uses `/dev/tty` so hostname and quota prompts still work; use `--non-interactive` to suppress prompts. If you run the one-liner via `ssh host "..."`, add `ssh -t` so a TTY exists.
Note: `install.sh` enables `/proc` `hidepid=2` for multi-tenant privacy and ensures `systemd.unified_cgroup_hierarchy=0` is present in `/etc/default/grub` for rootless Docker compatibility; reboot after install for the boot parameter to apply.

git/main "testing":
```
wget -qO- https://github.com/MagnaCapax/PMSS/raw/main/install.sh | bash -s -- git/main
```

`install.sh` accepts optional flags for unattended runs:
- `--hostname=<name>` to set `/etc/hostname` without opening an editor
- `--skip-hostname` to retain the current hostname
- `--quota-mount=<mountpoint>` to inject quota options into `/etc/fstab`
- `--skip-quota` to leave quota configuration manual
- `--non-interactive` to skip hostname/quota prompts even on a TTY
- `--skip-upgrade` to skip the initial apt full-upgrade
- `--dry-run` to parse and print the plan without changing the system
- `--skip-update` to stop after staging files (do not run `update.php`)
- `--scripts-only` to pass through to `update.php --scripts-only`

### Update

Release:
```
wget -qO /scripts/update.php https://raw.githubusercontent.com/MagnaCapax/PMSS/main/scripts/update.php;  chmod u+x /scripts/update.php; /scripts/update.php release;
```
"Testing": Git/main:
```
wget -qO /scripts/update.php https://raw.githubusercontent.com/MagnaCapax/PMSS/main/scripts/update.php;  chmod u+x /scripts/update.php; /scripts/update.php git/main;
```
The updater will now refresh itself from GitHub at the start of every run, so it
is usually enough to simply execute `/scripts/update.php` once installed.

Supported flags for `update.php`:
- `<spec>`: `git/<branch>[:YYYY-MM-DD]`, `release[:tag]`, or `main` (reuse last recorded spec)
- `--repo=<url>` / `--branch=<name>`: override git remote/branch for `git/*` specs
- `--dry-run`: fetch/stage without copying files or running phase 2
- `--scripts-only`: deploy `/scripts` + `/etc/skel` only; never runs apt
- `--dist-upgrade=<target>`: run the built-in dist-upgrade helper (`scripts/lib/update/distUpgrade.php`) for one safe major-version step capped at `<target>`, then continue with phase 2; **cannot** be combined with `--scripts-only`
- `--help`: print usage/examples

To upgrade the underlying Debian release automatically, run
`/scripts/update.php --dist-upgrade=<target>` with an explicit target
(`11`/`bullseye`, `12`/`bookworm`, or `13`/`trixie` for experimental validation).
The helper performs one safe major-version step per run, capped at your target.

Need to refresh only the new scripts and skeleton without running the
heavyweight configuration pass? Invoke `/scripts/update.php` with
`--scripts-only` to deploy the fetched `/scripts` and `/etc/skel` content while
skipping `update-step2.php`.

See `docs/update.md` for a deep dive into the two-phase updater architecture
and helper module layout introduced in the recent refactor.

### Upgrading Debian

Update PMSS first, then run the built-in dist-upgrade helper:
```
/scripts/update.php git/main
/scripts/update.php git/main --dist-upgrade=12
```

Supported targets: `11` (`bullseye`), `12` (`bookworm`), `13` (`trixie`, experimental).
The helper performs one major-version step per run and resumes the normal PMSS
update flow after the apt phase.

After the dist-upgrade and reboot, run a regular update to rebuild/update
services for the new Debian release:
```
/scripts/update.php git/main
```

See also:
- https://wiki.pulsedmedia.com/index.php/PMSS:Updating
- https://wiki.pulsedmedia.com/index.php/Debian

### Support

You may ask our discord for guidance.
Pulsed Media as a company will not provide support to use this on your own servers without a fee, unless the server is bought from Pulsed Media directly.


## Contributions

All contributions will be considered. No matter small or big. Your contribution could be as tiny as fixing a typo, or badly worded sentence and it will be much appreciated.

Before submitting updater changes, run the lightweight test suite via `php scripts/lib/tests/development/Runner.php` to confirm the spec parser still behaves as expected.

Some important guidelines:

 * Never break old users, ever.
 * Backwards compatibility is paramount (intermediary migration code has to be done if 100% compatibility cannot be ensured)
 * Has to be of generally beneficial for most users

 ### Code guidelines

 We try to stick Linux kernel development rules, in all regards. Some highlights:

  * Many small things doing single task very well
  * Descriptive function and variable names, functions preferrably with comment what it does and why
  * camelCase. reallyCamelCaseYourVariablesAndFunctionsAndClasses
  * Line width general rule of thumb stick to ~120-160characters
  * Maximum 4 nesting/indents in single source file. Need more? Create a function/separate file/lib/class out of it.
  * Single source code file try to stick to ~150lines or so (if a lot of comments/user help, can be deviated)
  * Write open nested function calls and comment them
  * ~10 lines try to comment what is done
  * Consider how this function/method/script will break or fail, this leads to less bugs or regressions

 ### Rewards

Best contributions may get rewards when implemented and tested, in the form of Pulsed Media service credit.

## License

This project is distributed under the terms of the [GNU General Public License v3](LICENSE).
Use at your own risk. There are no guarantees or warranties.
