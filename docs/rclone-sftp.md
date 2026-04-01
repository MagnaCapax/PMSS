# rclone SFTP Setup

PMSS exposes file access over OpenSSH SFTP. On stock PMSS hosts the SSH
template listens on port `22` and uses the `internal-sftp` subsystem, so
`rclone` should be configured with the `sftp` backend.

## Connection Values

Use these values when `rclone` asks for the remote details:

| Field | Value |
| --- | --- |
| Storage type | `sftp` |
| Host | your seedbox hostname / FQDN |
| Port | `22` |
| User | your seedbox username |
| Path | leave empty to start in your home directory |

If you can already log in with `ssh USER@HOST`, use the same `USER` and `HOST`
for `rclone`.

## Interactive Setup

Run:

```bash
rclone config
```

Recommended answers for a normal PMSS seedbox:

| Prompt | Answer |
| --- | --- |
| `n/s/q>` | `n` |
| `name>` | `pmss` |
| `Storage>` | `sftp` |
| `host>` | your seedbox hostname / FQDN |
| `user>` | your seedbox username |
| `port>` | `22` |
| `y/g/n>` for password | `y` for password login, `n` for SSH key or ssh-agent |
| `key_file>` | leave blank for password login, or set your private key path such as `~/.ssh/id_ed25519` |
| `y/e/d>` | `y` |

Notes:

- If you leave both password and `key_file` empty, `rclone` will try
  `ssh-agent`.
- `pmss:` points to your home directory on the seedbox.
- `pmss:/` points to the server root filesystem.

## Non-Interactive Setup

SSH agent or already-loaded key:

```bash
rclone config create pmss sftp host seedbox.example.com user alice port 22
```

Explicit private key file:

```bash
rclone config create pmss sftp host seedbox.example.com user alice port 22 key_file ~/.ssh/id_ed25519
```

Password login without putting the password itself into shell history:

```bash
read -rsp 'Seedbox password: ' RCLONE_PASS_CLEAR && printf '\n' && rclone config create pmss sftp host seedbox.example.com user alice port 22 pass "$(printf '%s' "$RCLONE_PASS_CLEAR" | rclone obscure -)" && unset RCLONE_PASS_CLEAR
```

Replace `seedbox.example.com` and `alice` with your real hostname and username.

## Common Commands

List the top level of your seedbox home directory:

```bash
rclone lsd pmss:
```

Upload a local directory into your seedbox `data/` tree:

```bash
rclone copy ~/Downloads pmss:data/uploads --progress
```

Download from the seedbox back to your local machine:

```bash
rclone copy pmss:data ~/seedbox-data --progress
```

Preview a destructive sync before you run it:

```bash
rclone sync ~/media pmss:data/media --progress --dry-run
```

Mount the seedbox `data/` directory locally:

```bash
mkdir -p ~/mnt/seedbox
rclone mount pmss:data ~/mnt/seedbox
```

## Transfer Tips

- Prefer `copy` for ordinary uploads and downloads. Use `sync` only when you
  want destination-side deletions to mirror the source.
- Use `--dry-run` before the first `sync` against an important directory.
- Add `--bwlimit` if you want `rclone` to leave room for other traffic on the
  same connection.
- If large transfers feel too aggressive, lower parallelism instead of opening
  more sessions.

## Troubleshooting

### Authentication fails

- Re-check `host`, `user`, and `port`.
- If password login fails, test the same credentials with `ssh USER@HOST`.
- If key login fails, confirm the key is loaded into `ssh-agent` or point
  `key_file` at the correct private key.

### Connection refused or timeout

- Verify that the hostname resolves to the correct server.
- Verify that the server is reachable on TCP port `22`.
- If plain `ssh USER@HOST` fails, fix that first, then retry `rclone`.

### Wrong directory opens

- `pmss:` is your home directory.
- `pmss:/` is the server root.

### Hash check warnings

- The SFTP backend may try remote shell commands to detect hashing support.
- If the remote blocks that, retry with `--sftp-disable-hashcheck`.
