# Vanilla Hermes

Configure > AI agents offers an opt-in account-owned Hermes install. Install
and later opens run as the account. The installer verifies one upstream tag,
commit, install script and package lock before executing code. An existing
checkout with customer changes is preserved and installation stops with a
message. The account's primary password is not stored in Hermes; the dashboard
gets a separate generated password and session secret.

The Web interface selector offers Hermes web UI (default), Agentboard, and
Agentboard Pikkumies when Pikkumies is installed. The first two use the same
private port-C origin at `/`; the last opens the existing Pikkumies UI on port
A. Only the selected port-C backend is started on demand. The Hermes login
protects its loopback listener, which other local accounts can reach. The
launcher checks that gate at every open. Signing in to the port-B Hermes
window can sign out port C because their cookies share a hostname.

The account can use `~/bin/install-hermes status`, `start`, `stop`,
`repair-gate`, `reconcile-key`, and `uninstall`. `uninstall --purge` removes
account-owned Hermes state. No Hermes cron job is installed. Installation
status is shown in Configure; the card polls every five seconds while open.

During managed account transfer, the opt-in `.hermes` tree is excluded from
the hot main pass. The tracked dashboard stops before the final pass copies
it. An untracked customer-started process can still write during that copy.
See ADR 0125.
