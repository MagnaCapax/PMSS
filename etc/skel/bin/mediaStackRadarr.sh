#!/usr/bin/env bash
# Seed a fresh Radarr movie root during the existing private authentication start.

# Return 2 for customer roots; malformed replies cannot certify an empty installation.
media_stack_radarr_root_payload_write() {
	local roots="$1" path="$2"
	# shellcheck disable=SC2016
	php -r '
$roots = json_decode(file_get_contents($argv[1]));
if (!is_array($roots)) exit(1);
foreach ($roots as $root) {
    if (!is_object($root) || !isset($root->path) || !is_string($root->path)
        || $root->path === "") exit(1);
}
if ($roots) exit(2);
$json = json_encode(["path" => $argv[2]], JSON_UNESCAPED_SLASHES);
if (!is_string($json)) exit(1);
echo $json;
' "$roots" "$path"
}

# The caller owns freshness and the already-verified API key; no app is launched here.
media_stack_radarr_defaults_seed() (
	[[ "${PMSS_MEDIA_STACK_SEED_PRIVATE:-0}" == 1 ]] || return 1
	umask 077
	local host_url="$1" api_config="$2" workspace api_url payload_rc code
	workspace=$(mktemp -d) || return 1
	trap 'rm -rf -- "$workspace"' EXIT
	api_url="${host_url%/config/host}/rootfolder"
	if ! curl -fsS --max-time 10 --noproxy '*' --config "$api_config" \
		"$api_url" -o "$workspace/roots.json" 2>/dev/null; then
		return 1
	fi
	if media_stack_radarr_root_payload_write "$workspace/roots.json" "$HOME/data/Movies" >"$workspace/root.json"; then
		payload_rc=0
	else
		payload_rc=$?
	fi
	if [[ "$payload_rc" -eq 2 ]]; then
		log_info "Radarr root folders already exist; preserving settings"
		return 0
	fi
	[[ "$payload_rc" -eq 0 ]] || return 1
	mkdir -p "$HOME/data/Movies" || return 1
	# Normal POST retains Radarr's path and writability validation. Never force-save.
	code=$(media_stack_http_code "$api_url" --noproxy '*' --config "$api_config" \
		-X POST -H 'Content-Type: application/json' --data-binary "@$workspace/root.json")
	[[ "$code" == 201 ]] || return 1
	log_ok "Radarr movie root added under ~/data/Movies"
)
