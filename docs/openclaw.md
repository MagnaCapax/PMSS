# OpenClaw on a PMSS account

OpenClaw is optional. It is a chat-driven agent with shell access to your
account. Anyone with its gateway token or access to an authorized bot can
exercise that access. Give chat access only to your own identity.

## Requirements

QA on 2026-10-21 measured 52,535 additional files and 0.87 GB of disk quota
for an install taking 106 seconds. The installer refuses accounts whose
file-count quota cannot fit the installation. The four OpenClaw processes
used 0.87–1.0 GB PSS at idle over 15 minutes; the whole account peaked at
1.42 GB at gateway start. Allow at least 2048 MiB of account RAM: the 1 GB
tier cannot hold the idle gateway plus the account's other processes. The
first gateway start can take a minute or two on a busy server.

In **Configure → Apps**, choose **Turn on**, or press **Start** on the OpenClaw tile
on Welcome. OpenClaw runs commands as your account and uses your Pulsed Media AI
credits by default. The install runs in the background; allow about three
minutes and use **Show log** if needed. **Open** launches the Control UI in a
separate browser tab with a single-use login handoff. **Restart** and **Turn off**
use the same account installer; Turn off persists across cron checks.
Uninstall remains an SSH command.

The panel and installer arrive through separate delivery channels:

| Files | Delivery |
| --- | --- |
| `www/userOpenClaw.php`, `www/scriptsIncAppsStatus.php`, `www/welcome.php`, `www/configure.php` | guiv panel heal |
| `bin/install-openclaw`, `bin/pmssOpenClaw*.php` | `update.php` step2 per-account refresh |

When the panel arrives first, an installer without explicit-home support is
unavailable to panel controls until step2 refreshes it. Status keeps its existing
marker-derived value; an OpenClaw helper failure leaves Welcome, Configure, and
the other app rows available. Installer-first delivery retains normal status.

Browser access uses `https://<server>/user-<user>/openclaw/`, protected by your
panel login and gateway token. The canonical server hostname may ask for your
panel login once when you entered through another hostname. The OpenClaw panel
tab links to Configure because the UI cannot be embedded. Some cosmetic
avatar/icon requests can show 401; chat still works. Keep the account realm.

After a full PMSS update has reserved your port, run:

```sh
install-openclaw install
install-openclaw status
```

The installer asks for confirmation (`--yes` skips the prompt). It installs
Node v24.22.1 and OpenClaw in your home, verifies the Node archive against
the pinned SHA256, configures a token-authenticated loopback gateway, and
adds two marked jobs to your own crontab. It skips channel setup. On a fresh
install, a nonempty key in your account-owned, mode-0600 `~/.virsilipasKey`
with an HTTPS `endpoint=` selects Pulsed Media AI credits and model `default`.
The key is read from that file when the gateway or CLI starts; it is not copied
into OpenClaw configuration. An existing OpenClaw configuration is preserved.
Without a valid key, the private `~/.openclaw/pmss-provider-pending` marker lets
the minute check adopt a later valid key while the primary model is unset.
Choosing your own primary model cancels adoption. You can also configure a
provider with `~/bin/openclaw configure`.
Add a channel only after restricting the bot to your own account. Never paste
a key into a shell command that will be saved in shell history.

To reach the gateway from your computer, read the port in
`~/.openclaw/gateway.port` and open an SSH tunnel:

```sh
ssh -L <localport>:127.0.0.1:<port> user@host
```

Visit `http://127.0.0.1:<localport>/user-<user>/openclaw/` locally. The token is displayed only
by `install-openclaw status --show-token`; keep it private.
Tailscale Serve is an optional way to reach the same loopback service if you
already use Tailscale. Do not make the gateway public or switch its bind away
from loopback.

The installer pins Node v24.22.1 from
`https://nodejs.org/dist/v24.22.1/SHASUMS256.txt` (linux-x64 archive SHA256
`6c172a9ab6718f7b801fb5a7bd486af2ee5e32758086a04b84fcce2aa77095e6`), the
24.x security release for CVE-2026-111497 (Brotli decoder out-of-bounds write).
Its bundled npm 11.19.1 supports `--allow-scripts`; npm defaults to denying
install scripts outside the allowlist, so the installer permits the required
`@google/genai`, `esbuild`, `koffi`, `protobufjs`, and `openclaw` scripts.
OpenClaw onboarding uses non-interactive local mode, skips the daemon and
channels, binds loopback, and stores a reference to
`OPENCLAW_GATEWAY_TOKEN` in the private gateway environment file.
The fresh Virsilipas setup uses a reference to `CUSTOM_API_KEY`; the installer
reads the current value from `~/.virsilipasKey` into the process environment
at startup.

The PMSS-managed `virsilipas/default` model declares `input: ["text"]` because
the AI credit endpoint rejects image and audio content. Its
`compat.supportsUsageInStreaming: true` enables streamed token and cost
accounting. Fresh onboarding, late-key adoption and `install-openclaw update`
apply these fields. Updates require the PMSS marker, matching account endpoint,
single `default` model and environment SecretRef; user-created providers and
other provider settings remain untouched.

## Pulsed Media skills

Install and update also install the optional Apache-2.0
[mcxKokko pack](https://github.com/MagnaCapax/mcxKokko/tree/main) from
MagnaCapax's own `main` branch, refreshed on `install-openclaw update`.
The account, AI-credit, docs, ticket and order skills come from this pack and
teach OpenClaw to use Pulsed Media services with your own account keys.
The credits, docs and ticket skills hand over to Pikkumies when it is installed.
PMSS validates archive members, stages privately under your home, and names the
completed directory `~/.openclaw/pmss-kokko-<12hex>` using the first 12 characters
of the archive's SHA256. The full hash is stored in its private marker, and
`~/.openclaw/pmss-kokko` selects the directory atomically. An identical archive
leaves the selection unchanged. Download or validation failures leave OpenClaw
working and retain any older pack. Tests use an offline archive with the same
`mcxKokko-main/` root, including the upstream license and notice.

The installer appends `~/.openclaw/pmss-kokko/skills` to
`skills.load.extraDirs` once, preserving your existing entries. To disable the
pack, create `~/.openclaw/pmss-kokko-disabled` and run
`install-openclaw install --yes` or `install-openclaw update`; this removes only
PMSS's load entry and skips installation. Remove the file and rerun either
command to enable it again. `status` and `check` report `main <12hex>` for the
selected pack or `disabled`/`absent`. Existing release selections report their
tag until updated. `uninstall` removes the managed pack and its load entry
even when keeping the rest of your OpenClaw state.

## Updating

Run the PMSS supervisor's update command as your own account:

```sh
install-openclaw update
```

Updates need 60,000 additional file-quota slots for a few minutes: npm
installs the new release beside the old one before removing the old one (QA
on 2026-10-21 measured peaks of 49,505 and 50,139 extra files over about
55,000 in use).
Going over the soft file limit only starts the quota grace period, so when
quota data is available the installer checks that current usage is within
the soft limit and that current usage plus 60,000 fits the hard limit before
stopping the gateway. PMSS sets 500 files per GiB of disk quota and a hard
limit 1.25 times that, so an account holding little besides OpenClaw
(about 55,000 files) can update on a plan of about 184 GiB or more; a smaller
plan can run OpenClaw but `update` refuses until files are freed.

The update holds the watchdog lock, stops the gateway, replaces the private
Node runtime when it is not the pinned version (download and SHA256 check
first; a failure keeps the old runtime and restarts the gateway), removes
old `openclaw-update-runtime-*` directories directly under the private
`~/.local/share/openclaw/lib`, installs the current npm release, runs Doctor's
non-interactive repair and migrations, and starts the gateway again. npm or
Doctor failure still triggers a start attempt; inspect `~/.openclaw/logs`
if recovery fails. Existing configuration and service credentials are reused.

`openclaw update` is refused by design on PMSS through
`OPENCLAW_SUPERVISOR_MODE=external`. PMSS's cron watchdog owns the lifecycle;
the upstream self-updater cannot complete its process-ancestry checks under
PMSS's private `/proc` mount and can leave large runtime directories behind.
`OPENCLAW_SERVICE_REPAIR_POLICY=external` also keeps installer-run Doctor from
installing or starting a native service. These policies permit the foreground
`gateway run` used by PMSS; see upstream's
[external supervisor documentation](https://docs.openclaw.ai/cli/gateway/restart-and-supervision#external-supervisors).
`install-openclaw update` and `install-openclaw install --yes` both rewrite
`~/bin/openclaw`, so older installations gain the supervisor export the first
time either runs. The wrapper also loads the gateway token from
`~/.openclaw/gateway.env`, so standard gateway commands such as
`~/bin/openclaw health` and `~/bin/openclaw gateway restart` work; under
external supervision a restart hands over to a fresh gateway process.

Useful commands:

```sh
install-openclaw start
install-openclaw stop
install-openclaw check
install-openclaw status
install-openclaw open-url
install-openclaw uninstall
install-openclaw uninstall --purge
```

The cron check stops trying after five consecutive failed starts. `start`
resets that state; read `~/.openclaw/logs/gateway.log` first. `uninstall`
keeps `~/.openclaw` for recovery. `--purge` removes it, including credentials
and workspace data.

Operators can provision an OpenClaw-focused account with
`addUser.php USER PASSWORD 2048 QUOTA --app-profile=openclaw`. This disables
rTorrent, skips Pikkumies preinstall, and queues OpenClaw as the account in its
slice only when RAM is at least 2048 MiB, 60000 file-quota slots remain, and
`~/.openclaw` does not exist. Otherwise provisioning logs the reason it skipped
the install. Without the option, existing provisioning behavior is unchanged.
Rerun `install-openclaw install --yes` to enable browser access on an older
installation without repeating onboarding or replacing your provider.

If a provider returns 404 for one model, accept that model's own access terms
first. Large free models may return 503 when overloaded; try a smaller model
you have access to. If `models set` writes an unsupported key and breaks
configuration, use the supported config-set path for the primary model and
check the result:

```sh
~/bin/openclaw config set agents.defaults.model.primary 'provider/model'
~/bin/openclaw config get agents.defaults.model.primary
```

## Amendment 2026-10-28: account app profiles

The provisioning paragraph above is superseded by the data-defined app profiles
in [ADR 0197](adr/0197-account-creation-app-profiles-as-data.md).
`--app-profile=openclaw` disables rTorrent, queues both Pikkumies and OpenClaw,
and enables rootless Docker through the existing user-config policy. Pikkumies
still requires 400 MiB RAM and its skeleton installer; OpenClaw still requires
2048 MiB RAM, 60000 free file-quota slots, its installer and no existing
`~/.openclaw`. Failed gates log a skip reason.

Omitting `--app-profile` selects `default`, the existing seedbox behavior:
rTorrent, Pikkumies and rootless Docker. `--app-profile=storagebox` disables
rTorrent and rootless Docker (#270), enables rclone and Copyparty, and retains Pikkumies. It
enables no torrent-client opt-in markers. Other apps keep their previous
defaults. An explicit `--docker-enabled=true|false` overrides the profile,
subject to the existing 245 MiB RAM floor. Profiles affect account creation
only; moves and recreates keep their existing flow.

The valid names come from `scripts/lib/user/appProfiles.php`; add a profile as
one array entry. App switches live in `scripts/lib/user/appSwitch.php`, with
catalogued enable markers read through `pmssToggleAppCatalogRead()`. PMSS knows
apps and profiles, never products. Marker writes run as the account, and app
access keeps the existing authenticated panel routes without adding public
names, certificates, DNS records or listeners.
