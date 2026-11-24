#!/usr/bin/env bash
# Seed missing account-local Prowlarr application links after protected apps start.

# Return 2 for an existing provider/name; never replace customer application settings.
media_stack_prowlarr_payload_write() {
	local existing="$1" schema="$2" curl_config="$3" app="$4" app_url="$5" prowlarr_url="$6"
	# PHP reads the key file itself; only non-secret paths and URLs enter argv.
	# shellcheck disable=SC2016
	php -r '
$existing = json_decode(file_get_contents($argv[1]));
$schema = json_decode(file_get_contents($argv[2]));
# An unreadable or non-list response cannot certify that no customer links exist.
if (!is_array($existing) || !is_array($schema)) exit(1);
foreach ($existing as $entry) {
    if (!is_object($entry) || !isset($entry->implementation, $entry->name)
        || !is_string($entry->implementation) || !is_string($entry->name)) exit(1);
}
foreach ($existing as $entry) {
    if (strcasecmp($entry->implementation, $argv[4]) === 0
        || strcasecmp($entry->name, $argv[4]) === 0) exit(2);
}
$matches = array_values(array_filter($schema, static function ($entry) use ($argv) {
    return is_object($entry) && ($entry->implementation ?? null) === $argv[4];
}));
if (count($matches) !== 1) exit(1);
$payload = $matches[0];
# Use only known providers, retaining category defaults from this installed version.
if (($payload->configContract ?? null) !== $argv[4]."Settings"
    || !isset($payload->fields) || !is_array($payload->fields)
    || !preg_match("/X-Api-Key: ([a-fA-F0-9]{32})\"/", file_get_contents($argv[3]), $key)) exit(1);
$values = ["baseUrl" => $argv[5], "prowlarrUrl" => $argv[6], "apiKey" => $key[1]];
$seen = [];
# Incomplete or ambiguous fields must never become a partially populated request.
foreach ($payload->fields as $field) {
    if (!is_object($field) || !isset($field->name) || !is_string($field->name)) exit(1);
    if (isset($seen[$field->name])) exit(1);
    $seen[$field->name] = true;
    if (array_key_exists($field->name, $values)) {
        $field->value = $values[$field->name];
        unset($values[$field->name]);
    }
}
if ($values) exit(1);
unset($payload->id, $payload->presets);
$payload->name = $argv[4];
$payload->syncLevel = "fullSync";
$payload->tags = [];
$json = json_encode($payload, JSON_UNESCAPED_SLASHES);
if (!is_string($json)) exit(1);
echo $json;
' "$existing" "$schema" "$curl_config" "$app" "$app_url" "$prowlarr_url"
}

# A private workspace owns every key-bearing request/reply, including failed requests.
media_stack_prowlarr_seed() (
	umask 077
	local workspace config_file host_url prowlarr_url api_url app lower port_variable app_url code payload_rc
	workspace=$(mktemp -d) || return 1
	trap 'rm -rf -- "$workspace"' EXIT
	config_file="$HOME/.config/prowlarr/config.xml"
	if ! servarr_config_auth_configured "$config_file" ||
		! servarr_api_key_curl_config_write "$config_file" "$workspace/prowlarr.curl"; then
		log_warn "Prowlarr application links skipped: protected local configuration unavailable"
		return 0
	fi
	# Apply proxy exclusion to readiness probes as well as API requests.
	printf 'noproxy = "*"\n' >>"$workspace/prowlarr.curl" || return 1
	host_url=$(servarr_host_config_url "$config_file" "$PROWLARR_PORT" v1)
	prowlarr_url="${host_url%/api/v1/config/host}"
	api_url="$prowlarr_url/api/v1/applications"
	if ! media_stack_wait_http_ok "$host_url" "$MEDIA_STACK_APP_START_WAIT" prowlarr "$workspace/prowlarr.curl" ||
		! curl -fsS --max-time 10 --config "$workspace/prowlarr.curl" "$api_url" -o "$workspace/existing.json" 2>/dev/null ||
		! curl -fsS --max-time 10 --config "$workspace/prowlarr.curl" "$api_url/schema" -o "$workspace/schema.json" 2>/dev/null; then
		log_warn "Prowlarr application links skipped: local applications API unavailable"
		return 0
	fi
	for app in Radarr Sonarr; do
		lower="${app,,}"
		port_variable="${app^^}_PORT"
		config_file="$HOME/.config/$lower/config.xml"
		if ! servarr_config_auth_configured "$config_file" ||
			! servarr_api_key_curl_config_write "$config_file" "$workspace/sibling.curl"; then
			log_warn "Prowlarr $app link skipped: protected local configuration unavailable"
			continue
		fi
		printf 'noproxy = "*"\n' >>"$workspace/sibling.curl" || return 1
		app_url=$(servarr_host_config_url "$config_file" "${!port_variable}" v3)
		app_url="${app_url%/api/v3/config/host}"
		if media_stack_prowlarr_payload_write "$workspace/existing.json" "$workspace/schema.json" \
			"$workspace/sibling.curl" "$app" "$app_url" "$prowlarr_url" >"$workspace/payload.json"; then
			payload_rc=0
		else
			payload_rc=$?
		fi
		if [[ $payload_rc -eq 2 ]]; then
			# A renamed/disabled provider is still customer-owned configuration.
			log_info "Prowlarr $app application already exists; preserving settings"
			continue
		fi
		if [[ $payload_rc -ne 0 ]] ||
			! media_stack_wait_http_ok "$app_url/api/v3/system/status" "$MEDIA_STACK_APP_START_WAIT" "$lower" "$workspace/sibling.curl"; then
			log_warn "Prowlarr $app link skipped: application unavailable or unsupported schema"
			continue
		fi
		# Normal POST retains upstream connection validation; never force-save a failed link.
		code=$(media_stack_http_code "$api_url" --config "$workspace/prowlarr.curl" -X POST \
			-H 'Content-Type: application/json' --data-binary "@$workspace/payload.json" --max-time 90)
		if [[ "$code" == 201 ]]; then
			log_ok "Prowlarr $app application added with full sync"
		else
			# A timed-out POST may already be stored; the next install must list again.
			log_warn "Prowlarr $app link not confirmed (HTTP $code); check Settings > Apps or rerun installer"
		fi
	done
)
