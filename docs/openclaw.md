# OpenClaw on a PMSS account

OpenClaw is optional. It is a chat-driven agent with shell access to your
account. Anyone with its gateway token or access to an authorized bot can
exercise that access. Give chat access only to your own identity.

## Requirements

OpenClaw needs about 55,000 files and 1.3 GB of disk space. The installer
refuses accounts whose file-count quota cannot fit the installation. The
first gateway start can take a minute or two on a busy server. Allow at least
2048 MiB of account RAM (the gateway and helpers used about 1.6 GB in QA).

In **Configure → Apps**, choose **Turn on** and accept the account command-access
and AI credit consent. The install runs in the background; allow about three
minutes and use **Show log** if needed. **Open** launches the Control UI in a
separate browser tab with a single-use login handoff. **Restart** and **Turn off**
use the same account installer; Turn off persists across cron checks.
Uninstall remains an SSH command.

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
Node v24.21.0 and OpenClaw in your home, verifies the Node archive against
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

The installer pins Node v24.21.0 from
`https://nodejs.org/dist/v24.21.0/SHASUMS256.txt` (linux-x64 archive SHA256
`fd8e59d5a511510f6a298afb548f18c7d2b1be404d8b4a27d94fbe49f56cb2d6`).
Its bundled npm 11.19.0 supports `--allow-scripts`; npm defaults to denying
install scripts outside the allowlist, so the installer permits the required
`@google/genai`, `esbuild`, `koffi`, `protobufjs`, and `openclaw` scripts.
OpenClaw onboarding uses non-interactive local mode, skips the daemon and
channels, binds loopback, and stores a reference to
`OPENCLAW_GATEWAY_TOKEN` in the private gateway environment file.
The fresh Virsilipas setup uses a reference to `CUSTOM_API_KEY`; the installer
reads the current value from `~/.virsilipasKey` into the process environment
at startup.

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
