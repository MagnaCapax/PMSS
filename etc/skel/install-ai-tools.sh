#!/usr/bin/env bash
#
# PMSS: User-Local AI CLI Tools Installer
#
# Installs Gemini CLI, Codex CLI, and Claude Code in ~/bin and ~/.local
# with portable Node.js for Gemini (no system dependencies).
#
# Run:    bash ~/install-ai-tools.sh
# Update: bash ~/install-ai-tools.sh          (re-run anytime)
# Remove: rm ~/bin/{gemini,codex,claude,ai-help} && rm -rf ~/.local/share/{node,gemini-cli,claude} ~/.local/bin/claude
#
# Each tool requires your own API key or account.
# No shared credentials. Config is private to your account.
#
# Copyright (C) 2010-2026 Magna Capax Finland Oy
# @author Pulsed Media Dev Team
#
# PROVIDED AS-IS. NO GUARANTEES, NO MAINTENANCE, NO SUPPORT.
#

# Self-update is opt-in via --self-update (default off); otherwise the local copy,
# kept current by update.php, is used. Behaviour no longer depends on whether stdin
# is a TTY -- same policy as install-media-stack.sh (GH #800, ADR-0007).
if [[ " $* " == *" --self-update "* && " $* " != *" --skip-update "* ]]; then
	REMOTE_RAW_URL="https://raw.githubusercontent.com/MagnaCapax/PMSS/refs/heads/main/etc/skel/install-ai-tools.sh"
	tmp=$(mktemp) || true
	if [[ -n "$tmp" ]]; then
		if command -v curl >/dev/null 2>&1; then
			curl -fsSL "$REMOTE_RAW_URL" -o "$tmp" 2>/dev/null || true
		elif command -v wget >/dev/null 2>&1; then
			wget -q "$REMOTE_RAW_URL" -O "$tmp" 2>/dev/null || true
		fi
		if [[ -s "$tmp" ]]; then
			# Verify downloaded script looks like a PMSS installer (not HTML error page, binary, etc.)
			if ! head -1 "$tmp" | grep -q '^#!/usr/bin/env bash$'; then
				echo "[WARN] Downloaded script has unexpected shebang — refusing to exec, using local copy" >&2
				rm -f "$tmp" 2>/dev/null || true
			elif ! grep -q '^# PMSS: .* Installer' "$tmp"; then
				echo "[WARN] Downloaded script missing PMSS marker — refusing to exec, using local copy" >&2
				rm -f "$tmp" 2>/dev/null || true
			else
				chmod +x "$tmp" 2>/dev/null || true
				exec "$tmp" --skip-update "$@"
			fi
		fi
		rm -f "$tmp" 2>/dev/null || true
	fi
fi

set -euo pipefail

BIN_DIR="$HOME/bin"
LOCAL_DIR="$HOME/.local"
NODE_DIR="$LOCAL_DIR/share/node"
GEMINI_PREFIX="$LOCAL_DIR/share/gemini-cli"

mkdir -p "$BIN_DIR" "$LOCAL_DIR/share" "$LOCAL_DIR/bin"
export PATH="$BIN_DIR:$LOCAL_DIR/bin:$NODE_DIR/bin:$PATH"

# --- Helpers ---
info() { printf '\033[1;34m[info]\033[0m  %s\n' "$*"; }
ok() { printf '\033[1;32m[ok]\033[0m    %s\n' "$*"; }
warn() { printf '\033[1;33m[warn]\033[0m  %s\n' "$*"; }
fail() { printf '\033[1;31m[fail]\033[0m  %s\n' "$*"; }

# --- Download integrity verification ---
CHECKSUMS_FILE="$HOME/.install-checksums.sha256"
CHECKSUMS_RAW_URL="https://raw.githubusercontent.com/MagnaCapax/PMSS/refs/heads/main/etc/skel/.install-checksums.sha256"

fetch_checksums_file() {
	local tmp_ck
	tmp_ck=$(mktemp) || return 1
	if command -v curl >/dev/null 2>&1; then
		curl -fsSL "$CHECKSUMS_RAW_URL" -o "$tmp_ck" 2>/dev/null || true
	elif command -v wget >/dev/null 2>&1; then
		wget -q "$CHECKSUMS_RAW_URL" -O "$tmp_ck" 2>/dev/null || true
	fi
	if [[ -s "$tmp_ck" ]] && head -1 "$tmp_ck" | grep -q '^# PMSS'; then
		mv "$tmp_ck" "$CHECKSUMS_FILE" 2>/dev/null || true
		chmod 600 "$CHECKSUMS_FILE" 2>/dev/null || true
	else
		rm -f "$tmp_ck" 2>/dev/null || true
	fi
}

# Verify SHA256 checksum against the checksums file.
# Missing entry = warn + proceed; mismatch = error + fail.
verify_checksum() {
	local file="$1" artifact_id="$2"
	if [[ ! -f "$file" ]]; then
		return 0 # File not present (e.g., dry-run mode) — skip
	fi
	if [[ ! -f "$CHECKSUMS_FILE" ]]; then
		warn "No checksums file — skipping verification for $(basename "$file")"
		return 0
	fi
	local expected_hash
	expected_hash=$(grep -F "  ${artifact_id}" "$CHECKSUMS_FILE" 2>/dev/null | grep -v '^#' | awk '{print $1}' | head -1)
	if [[ -z "$expected_hash" ]]; then
		warn "No checksum on file for '${artifact_id}' — skipping verification"
		return 0
	fi
	local actual_hash
	actual_hash=$(sha256sum "$file" | cut -d' ' -f1)
	if [[ "$actual_hash" != "$expected_hash" ]]; then
		fail "CHECKSUM MISMATCH for ${artifact_id}"
		fail "  Expected: $expected_hash"
		fail "  Got:      $actual_hash"
		fail "  This could indicate a corrupted download or supply chain compromise."
		return 1
	fi
	ok "Checksum verified: ${artifact_id}"
	return 0
}

# --- Disk usage warning + confirmation ---
cat <<'BANNER'

  ╔══════════════════════════════════════════════════════╗
  ║          AI CLI Tools Installer (PMSS)              ║
  ╠══════════════════════════════════════════════════════╣
  ║  gemini  — Google Gemini CLI (free tier available)  ║
  ║  codex   — OpenAI Codex CLI                         ║
  ║  claude  — Anthropic Claude Code                    ║
  ╚══════════════════════════════════════════════════════╝

BANNER

echo "This installs AI CLI tools in your home directory (~1 GB)."
echo "Disk usage counts against your quota."
echo ""
echo "Each tool requires YOUR OWN API key or account to use."
echo "No credentials are pre-installed or shared."
echo ""
read -rp "Install/update AI CLI tools? [Y/n] " confirm
[[ "${confirm:-Y}" =~ ^[Yy]$ ]] || {
	echo "Cancelled."
	exit 0
}
echo ""

# --- Portable Node.js (for Gemini CLI) ---
install_node() {
	# Pinned to LTS 22.x. Update this version to upgrade Node.js.
	local NODE_VERSION="22.22.1"
	local ARCH="x64"

	if [ -x "$NODE_DIR/bin/node" ]; then
		local current
		current=$("$NODE_DIR/bin/node" --version 2>/dev/null || echo "")
		if [ "$current" = "v${NODE_VERSION}" ]; then
			ok "Node.js $current already installed (portable, for Gemini CLI)"
			return 0
		fi
		info "Upgrading Node.js from $current to v${NODE_VERSION}..."
	fi

	info "Downloading portable Node.js v${NODE_VERSION}..."
	local tmp
	tmp=$(mktemp -d)
	if ! curl -fsSL "https://nodejs.org/dist/v${NODE_VERSION}/node-v${NODE_VERSION}-linux-${ARCH}.tar.xz" \
		-o "$tmp/node.tar.xz"; then
		fail "Failed to download Node.js v${NODE_VERSION}"
		rm -rf "$tmp"
		return 1
	fi
	if ! verify_checksum "$tmp/node.tar.xz" "node-v${NODE_VERSION}-linux-${ARCH}.tar.xz"; then
		fail "Node.js download failed integrity check"
		rm -rf "$tmp"
		return 1
	fi
	tar xJf "$tmp/node.tar.xz" -C "$tmp"
	rm -rf "$NODE_DIR"
	mv "$tmp/node-v${NODE_VERSION}-linux-${ARCH}" "$NODE_DIR"
	rm -rf "$tmp"
	ok "Node.js $("$NODE_DIR/bin/node" --version) installed"
}

# --- Gemini CLI ---
install_gemini() {
	info "Installing/updating Gemini CLI..."
	install_node || return 1

	"$NODE_DIR/bin/npm" install --prefix="$GEMINI_PREFIX" -g @google/gemini-cli 2>&1 | tail -3

	# Wrapper in ~/bin (Node.js path + Gemini binary)
	cat >"$BIN_DIR/gemini" <<'WRAPPER'
#!/usr/bin/env bash
export PATH="$HOME/.local/share/node/bin:$PATH"
exec "$HOME/.local/share/gemini-cli/bin/gemini" "$@"
WRAPPER
	chmod +x "$BIN_DIR/gemini"
	ok "Gemini CLI ready: gemini"
}

# --- Codex CLI ---
install_codex() {
	info "Installing/updating Codex CLI..."

	local arch="x86_64"

	# Resolve latest release tag (no -L so redirect_url is populated)
	local tag
	tag=$(curl -fsS -o /dev/null -w '%{redirect_url}' \
		https://github.com/openai/codex/releases/latest 2>/dev/null |
		xargs -r basename 2>/dev/null || true)

	if [ -z "$tag" ]; then
		tag=$(curl -fsSL https://api.github.com/repos/openai/codex/releases/latest 2>/dev/null |
			grep '"tag_name"' | sed 's/.*"tag_name": *"//;s/".*//')
	fi

	if [ -z "$tag" ]; then
		fail "Could not determine latest Codex release"
		return 1
	fi

	info "Latest release: $tag"

	local tmp
	tmp=$(mktemp -d)
	local url="https://github.com/openai/codex/releases/download/${tag}/codex-${arch}-unknown-linux-musl.tar.gz"

	if ! curl -fsSL -o "$tmp/codex.tar.gz" "$url"; then
		fail "Download failed: $url"
		warn "Check https://github.com/openai/codex/releases/latest for correct asset name"
		rm -rf "$tmp"
		return 1
	fi
	if ! verify_checksum "$tmp/codex.tar.gz" "codex-${arch}-unknown-linux-musl.tar.gz"; then
		fail "Codex download failed integrity check"
		rm -rf "$tmp"
		return 1
	fi

	tar xzf "$tmp/codex.tar.gz" -C "$tmp"

	# Find the codex binary (name may vary across releases)
	local binary
	binary=$(find "$tmp" -maxdepth 2 -type f -name 'codex' ! -name '*.tar.gz' 2>/dev/null | head -1)

	if [ -z "$binary" ]; then
		# Broader search: any executable starting with codex
		binary=$(find "$tmp" -maxdepth 2 -type f -name 'codex*' -perm /u+x ! -name '*.tar.gz' 2>/dev/null | head -1)
	fi

	if [ -z "$binary" ]; then
		fail "Could not find Codex binary in archive"
		warn "Archive contents:"
		find "$tmp" -maxdepth 2 -type f 2>/dev/null | while read -r f; do warn "  $f"; done
		rm -rf "$tmp"
		return 1
	fi

	mv "$binary" "$BIN_DIR/codex"
	chmod +x "$BIN_DIR/codex"
	rm -rf "$tmp"

	# Sandbox config for kernels < 5.13 (Debian 10/11 — Landlock unavailable)
	local kernel_major kernel_minor
	kernel_major=$(uname -r | cut -d. -f1)
	kernel_minor=$(uname -r | cut -d. -f2)

	if [ "$kernel_major" -lt 5 ] || { [ "$kernel_major" -eq 5 ] && [ "$kernel_minor" -lt 13 ]; }; then
		mkdir -p "$HOME/.codex"
		if [ ! -f "$HOME/.codex/config.toml" ]; then
			cat >"$HOME/.codex/config.toml" <<'TOML'
# Landlock sandbox requires kernel >= 5.13 (Debian 12+).
# This server's kernel is older, so sandboxing is disabled.
# You already have shell access — sandbox protects from Codex, not from you.
sandbox = "danger-full-access"
TOML
			warn "Kernel < 5.13: created ~/.codex/config.toml with sandbox workaround"
		fi
	fi

	ok "Codex CLI ready: codex"
}

# --- Claude Code ---
install_claude() {
	info "Installing/updating Claude Code..."

	local installed=0

	# Method 1: Anthropic's native installer (fast, small footprint)
	info "Trying native installer..."
	if curl -fsSL https://claude.ai/install.sh | bash >/dev/null 2>&1; then
		# Find the installed binary
		local native_bin=""
		for candidate in "$LOCAL_DIR/bin/claude" "$HOME/.claude/local/claude"; do
			if [ -x "$candidate" ]; then
				native_bin="$candidate"
				break
			fi
		done

		if [ -n "$native_bin" ]; then
			# Test if the binary actually runs (catches Illegal Instruction on old CPUs)
			if "$native_bin" --version >/dev/null 2>&1; then
				ln -sf "$native_bin" "$BIN_DIR/claude"
				ok "Claude Code ready: claude (native)"
				installed=1
			else
				warn "Native binary crashed (CPU too old?), cleaning up..."
				rm -f "$native_bin" 2>/dev/null || true
				rm -rf "$HOME/.claude/downloads" 2>/dev/null || true
			fi
		fi
	else
		warn "Native installer download failed, trying npm fallback..."
	fi

	# Method 2: npm fallback (works on any CPU, uses portable Node.js)
	if [ "$installed" -eq 0 ]; then
		if [ ! -x "$NODE_DIR/bin/node" ]; then
			info "Installing Node.js for npm fallback..."
			install_node || {
				fail "Cannot install Claude Code: no Node.js available"
				return 1
			}
		fi

		info "Installing Claude Code via npm (native binary unsupported on this CPU)..."
		local claude_prefix="$LOCAL_DIR/share/claude-code"
		"$NODE_DIR/bin/npm" install --prefix="$claude_prefix" -g @anthropic-ai/claude-code 2>&1 | tail -3

		if [ -x "$claude_prefix/bin/claude" ]; then
			# Wrapper in ~/bin (needs Node.js in PATH)
			cat >"$BIN_DIR/claude" <<'WRAPPER'
#!/usr/bin/env bash
export PATH="$HOME/.local/share/node/bin:$PATH"
exec "$HOME/.local/share/claude-code/bin/claude" "$@"
WRAPPER
			chmod +x "$BIN_DIR/claude"
			ok "Claude Code ready: claude (npm)"
			installed=1
		else
			fail "npm install of Claude Code failed"
			warn "Try manually: npm install -g @anthropic-ai/claude-code"
			return 1
		fi
	fi

	[ "$installed" -eq 1 ]
}

# --- ai-help command ---
install_ai_help() {
	cat >"$BIN_DIR/ai-help" <<'HELPSCRIPT'
#!/usr/bin/env bash
cat << 'EOF'
AI CLI Tools
============

Three AI coding assistants are installed in your home directory.
Each requires YOUR OWN API key or account — nothing is shared.

TOOLS:

  gemini    Google Gemini CLI
            Free tier: just log in with a Google account
            Or set: export GEMINI_API_KEY="your-key-from-aistudio.google.com"
            Docs:   https://github.com/google-gemini/gemini-cli

  codex     OpenAI Codex CLI
            Set: export OPENAI_API_KEY="your-key"
            Then: printf '%s' "$OPENAI_API_KEY" | codex login --with-api-key
            Docs: https://github.com/openai/codex

  claude    Anthropic Claude Code
            Set: export ANTHROPIC_API_KEY="your-key"
            Docs: https://github.com/anthropics/claude-code

CONFIG (private to your account):

  ~/.gemini/    Gemini settings and credentials
  ~/.codex/     Codex settings and credentials
  ~/.claude/    Claude Code settings and credentials

UPDATE:

  Re-run the installer:  bash ~/install-ai-tools.sh

REMOVE:

  rm ~/bin/{gemini,codex,claude,ai-help}
  rm -rf ~/.local/share/{node,gemini-cli,claude} ~/.local/bin/claude
  rm -rf ~/.gemini ~/.codex ~/.claude
EOF
HELPSCRIPT
	chmod +x "$BIN_DIR/ai-help"
}

# --- Main ---
main() {
	local ok_count=0 fail_count=0

	# Fetch latest checksums for download verification
	fetch_checksums_file

	install_gemini && ok_count=$((ok_count + 1)) || fail_count=$((fail_count + 1))
	echo ""
	install_codex && ok_count=$((ok_count + 1)) || fail_count=$((fail_count + 1))
	echo ""
	install_claude && ok_count=$((ok_count + 1)) || fail_count=$((fail_count + 1))
	echo ""
	install_ai_help && ok_count=$((ok_count + 1)) || fail_count=$((fail_count + 1))

	echo ""
	echo "════════════════════════════════════════"
	echo "  $ok_count installed, $fail_count failed"
	echo ""
	echo "  Run 'ai-help' for usage instructions."
	echo "  Re-run ~/install-ai-tools.sh to update."
	echo "════════════════════════════════════════"

	[ "$fail_count" -eq 0 ]
}

main "$@"
