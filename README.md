# Pulsed Media Seedbox Software

## Description

Pulsed Media Seedbox Software, server side code.
This builds and installs all the software and scripts to operate a seedbox server.

Works on Debian 10/11/12. Deb10 is stable, Deb11 is in production qualification and Deb12 is development/experimental stage.

This can be used standalone fully, does not require a server from Pulsed Media. You can _freely_ use this for your own self-hosted seedbox.
No commercial restrictions, you are free to provide seedbox services using this and there are even some minimalistic whitelabeling features.
Some pieces will still be fetched from Pulsed Media servers, such as the latest GUI version.

More information available at https://wiki.pulsedmedia.com/index.php/PM_Software_Stack


## Documentation

You can find information for common tasks such as adding/creating/suspending users at https://wiki.pulsedmedia.com/index.php/Category:PM_Software_Stack_Guides.

For a quick maintenance checklist covering dry-runs, structured logging and
tests, see `docs/maintenance.md`. WireGuard usage notes are available in
`docs/wireguard.md`.

### Contributing & Decisions

See `CONTRIBUTING.md` for workflow and validation steps. Significant technical
choices are recorded as ADRs under `docs/adr/`; new decisions should include an
ADR alongside code, tests, and documentation.

### Installation

Install minimal Debian system, and run following as root:
```
wget -q https://github.com/MagnaCapax/PMSS/raw/main/install.sh; bash install.sh
```

`install.sh` accepts optional flags for unattended runs:
- `--hostname=<name>` to set `/etc/hostname` without opening an editor
- `--skip-hostname` to retain the current hostname
- `--quota-mount=<mountpoint>` to inject quota options into `/etc/fstab`
- `--skip-quota` to leave quota configuration manual

### Update from pre-github version

If you have older PMSS installed which is not yet based on this github version, here is how you can upgrade it:
```
wget -qO /scripts/update.php https://raw.githubusercontent.com/MagnaCapax/PMSS/main/scripts/update.php;  chmod u+x/scripts/update.php; /scripts/update.php release; reboot
```
with reboot using git/main ("testing") as the source instead of release:
```
wget -qO /scripts/update.php https://raw.githubusercontent.com/MagnaCapax/PMSS/0f24e004e44245a9be834d8b1920caf0f119a282/scripts/update.php;  chmod u+x/scripts/update.php; /scripts/update.php git/main:2025-05-11; reboot
```
The updater will now refresh itself from GitHub at the start of every run, so it
is usually enough to simply execute `/scripts/update.php` once installed.

To upgrade the underlying Debian release automatically, run the updater with the
`--updatedistro` flag. This executes `scripts/util/update-distro.php` which
performs a dist-upgrade (Debian&nbsp;10→11 or 11→12) using the recommended
commands.

Need to refresh only the new scripts and skeleton without running the
heavyweight configuration pass? Invoke `/scripts/update.php` with
`--scripts-only` to deploy the fetched `/scripts` and `/etc/skel` content while
skipping `update-step2.php`.

See `docs/update.md` for a deep dive into the two-phase updater architecture
and helper module layout introduced in the recent refactor.

### Debian 10 to Debian 11 Upgrade

Dist-upgrade functions.
YOLO Mostly Unattended command for the base system update:
```
export DEBIAN_FRONTEND=noninteractive; \
sed -i 's/\<buster\>/bullseye/g' /etc/apt/sources.list; \
sed -i 's#bullseye/updates#bullseye-security#g' /etc/apt/sources.list; \
sed -i 's/\<buster\>/bullseye/g' /etc/apt/sources.list.d/*.list; \
sed -i 's#bullseye/updates#bullseye-security#g' /etc/apt/sources.list.d/*.list; \
apt update; \
apt upgrade -y -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold"; \
apt full-upgrade -y -o Dpkg::Options::="--force-confdef" -o Dpkg::Options::="--force-confold"; \
apt autoremove -y; \
systemctl reboot

```

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
