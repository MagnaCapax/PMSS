# AI CLI Tools (etc/skel/install-ai-tools.sh)

This script installs three AI coding assistants in your home directory. Each tool requires your own API key or account to use. No credentials are pre-installed or shared between users.

Installed tools:
- **Gemini CLI** (Google) - Free tier available with Google account
- **Codex CLI** (OpenAI) - Requires API key or subscription
- **Claude Code** (Anthropic) - Requires API key or subscription

All tools install to `~/bin` and `~/.local` with configurations private to your account (mode 700).

## Getting Started

### Install or Update

Run the installer from your home directory:

```bash
bash ~/install-ai-tools.sh
```

The script:
- Prompts for confirmation before installing
- Downloads portable Node.js for tools that need it
- Installs all three CLI tools to your home directory
- Creates an `ai-help` command for quick reference

To update all tools, simply re-run the same command. The script is idempotent and will upgrade existing installations.

### Disk Usage

The full installation uses approximately **1 GB** of disk space. This counts against your account quota. The breakdown:
- Node.js runtime: ~100 MB
- Gemini CLI + dependencies: ~200 MB
- Codex CLI: ~50 MB
- Claude Code: ~600 MB (varies by install method)

### Quick Reference

After installation, run `ai-help` for a summary of all tools and their configuration paths.

## Tool Guides

### Gemini CLI (Google)

Gemini CLI has a free tier that only requires a Google account.

**Setup (free tier):**
```bash
gemini
# Follow the prompts to log in with your Google account
```

**Setup (API key):**
1. Get an API key from https://aistudio.google.com
2. Set the environment variable:
```bash
export GEMINI_API_KEY="your-key-here"
```

**Usage:**
```bash
gemini              # Start interactive session
gemini "prompt"     # One-shot query
```

**Config location:** `~/.gemini/`

**Docs:** https://github.com/google-gemini/gemini-cli

---

### Codex CLI (OpenAI)

Codex CLI requires an OpenAI API key or subscription.

**Setup:**
1. Get an API key from https://platform.openai.com/api-keys
2. Log in with your API key:
```bash
export OPENAI_API_KEY="your-key-here"
printf '%s' "$OPENAI_API_KEY" | codex login --with-api-key
```

**Usage:**
```bash
codex              # Start interactive session
codex "prompt"     # One-shot query
```

**Config location:** `~/.codex/`

**Docs:** https://github.com/openai/codex

**Note for older kernels:** On Debian 10/11 (kernel < 5.13), the installer automatically creates `~/.codex/config.toml` with `sandbox = "danger-full-access"` because the Landlock sandbox requires kernel 5.13+. This is safe on shared hosting where you already have shell access.

---

### Claude Code (Anthropic)

Claude Code requires an Anthropic API key or subscription.

**Setup:**
1. Get an API key from https://console.anthropic.com
2. Set the environment variable:
```bash
export ANTHROPIC_API_KEY="your-key-here"
```

**Usage:**
```bash
claude              # Start interactive session
claude "prompt"     # One-shot query
```

**Config location:** `~/.claude/`

**Docs:** https://github.com/anthropics/claude-code

**Installation notes:** The installer tries Anthropic's native binary first. If it fails (e.g., on older CPUs), it falls back to an npm-based installation automatically.

## Privacy and Security

- **Private credentials:** All API keys and settings are stored in your home directory with mode 700 (owner-only access).
- **No shared keys:** Each user must provide their own API keys. Nothing is pre-configured or shared between accounts.
- **User-local installation:** Tools install to `~/bin` and `~/.local`, not system-wide. Other users cannot access your installation.
- **Checksum verification:** Downloads are verified against known checksums when available.

Configuration paths:
```
~/.gemini/     Gemini settings and credentials
~/.codex/      Codex settings and credentials
~/.claude/     Claude Code settings and credentials
```

## Troubleshooting

### Tool not found after installation

1. **Check PATH:** Ensure `~/bin` is in your PATH:
   ```bash
   echo $PATH | grep -q "$HOME/bin" && echo "OK" || echo "Missing"
   ```

2. **Reload shell:** Log out and back in, or run:
   ```bash
   source ~/.bashrc
   ```

3. **Re-run installer:** The installer is idempotent:
   ```bash
   bash ~/install-ai-tools.sh
   ```

### Authentication errors

- **Gemini:** Re-run `gemini` and follow the login prompts, or verify your `GEMINI_API_KEY` is set correctly.
- **Codex:** Verify `OPENAI_API_KEY` is set and re-run the login command:
  ```bash
  printf '%s' "$OPENAI_API_KEY" | codex login --with-api-key
  ```
- **Claude:** Verify `ANTHROPIC_API_KEY` is set correctly. The key should start with `sk-ant-`.

### Claude binary crashes on startup

On older CPUs, the native Claude binary may crash with "Illegal Instruction". The installer detects this and falls back to npm. If you installed manually, re-run the installer to get the npm version:
```bash
bash ~/install-ai-tools.sh
```

### Codex sandbox errors on Debian 10/11

If Codex fails with sandbox-related errors, ensure `~/.codex/config.toml` contains:
```toml
sandbox = "danger-full-access"
```
The installer creates this automatically on kernels < 5.13.

### Check installation log

The installer logs to stdout with colored output. For persistent logs, redirect:
```bash
bash ~/install-ai-tools.sh 2>&1 | tee ~/install-ai-tools.log
```

## Removal

To completely remove all AI CLI tools:

```bash
# Remove binaries
rm ~/bin/{gemini,codex,claude,ai-help}

# Remove installations
rm -rf ~/.local/share/{node,gemini-cli,claude-code} ~/.local/bin/claude

# Remove configurations (optional - deletes your settings)
rm -rf ~/.gemini ~/.codex ~/.claude
```

## Updates

Re-run the installer to update all tools to their latest versions:

```bash
bash ~/install-ai-tools.sh
```

The script self-updates from GitHub when run interactively, ensuring you always get the latest installer version.
