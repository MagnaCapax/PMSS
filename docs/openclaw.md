# OpenClaw on a PMSS account

OpenClaw is optional. It is a chat-driven agent with shell access to your
account. Anyone with its gateway token or access to an authorized bot can
exercise that access. Give chat access only to your own identity.

After a full PMSS update has reserved your port, run:

```sh
install-openclaw install
install-openclaw status
```

The installer asks for confirmation (`--yes` skips the prompt). It installs
Node v24.21.0 and OpenClaw in your home, verifies the Node archive against
the pinned SHA256, configures a token-authenticated loopback gateway, and
adds two marked jobs to your own crontab. It skips channel and model-provider
setup. Configure your provider credentials with `~/bin/openclaw configure`
and add a channel only after restricting the bot to your own account. No
provider key is supplied by PMSS. Never paste a key into a shell command
that will be saved in shell history.

To reach the gateway from your computer, read the port in
`~/.openclaw/gateway.port` and open an SSH tunnel:

```sh
ssh -L <localport>:127.0.0.1:<port> user@host
```

Visit `http://127.0.0.1:<localport>/` locally. The token is displayed only
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

Useful commands:

```sh
install-openclaw start
install-openclaw stop
install-openclaw check
install-openclaw status
install-openclaw uninstall
install-openclaw uninstall --purge
```

The cron check stops trying after five consecutive failed starts. `start`
resets that state; read `~/.openclaw/logs/gateway.log` first. `uninstall`
keeps `~/.openclaw` for recovery. `--purge` removes it, including credentials
and workspace data.

If a provider returns 404 for one model, accept that model's own access terms
first. Large free models may return 503 when overloaded; try a smaller model
you have access to. If `models set` writes an unsupported key and breaks
configuration, use the supported config-set path for the primary model and
check the result:

```sh
~/bin/openclaw config set agents.defaults.model.primary 'provider/model'
~/bin/openclaw config get agents.defaults.model.primary
```
