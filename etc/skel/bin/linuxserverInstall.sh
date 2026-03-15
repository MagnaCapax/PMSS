#!/usr/bin/env bash
set -euo pipefail

# Install a small set of known-good LinuxServer.io containers on PMSS.
# The helper keeps data under $HOME, reuses one Docker network, and relies on
# Docker restart policies plus the existing PMSS rootless Docker watchdog.

usage() {
  cat <<'EOF'
Usage: linuxserverInstall.sh APP [HOST_PORT] [--dry-run]

Supported apps: jellyfin qbittorrent radarr sonarr prowlarr
EOF
}

dry_run=0
args=()
for arg in "$@"; do
  if [[ "$arg" == "--dry-run" ]]; then
    dry_run=1
    continue
  fi
  args+=("$arg")
done

if [[ ${#args[@]} -lt 1 || ${#args[@]} -gt 2 ]]; then
  usage >&2
  exit 1
fi

app="${args[0]}"
host_port="${args[1]:-}"
timezone="$(cat /etc/timezone 2>/dev/null || printf 'UTC')"
network_name="pmss-media"
image="lscr.io/linuxserver/${app}:latest"

# Shared mounts make the common media stack interoperate without extra wiring.
config_dir="$HOME/docker/$app/config"
downloads_dir="$HOME/downloads"
movies_dir="$HOME/movies"
tv_dir="$HOME/tv"
media_dir="$HOME/media"
container_port=''
default_port=''
extra_args=()
volume_args=()
mkdir_args=("$config_dir")

case "$app" in
  jellyfin)
    default_port='8096'
    container_port='8096'
    mkdir_args+=("$media_dir")
    volume_args=(-v "$config_dir:/config" -v "$media_dir:/data")
    ;;
  qbittorrent)
    default_port='8080'
    container_port='8080'
    mkdir_args+=("$downloads_dir")
    extra_args=(-e WEBUI_PORT=8080)
    volume_args=(-v "$config_dir:/config" -v "$downloads_dir:/downloads")
    ;;
  radarr)
    default_port='7878'
    container_port='7878'
    mkdir_args+=("$movies_dir" "$downloads_dir")
    volume_args=(-v "$config_dir:/config" -v "$movies_dir:/movies" -v "$downloads_dir:/downloads")
    ;;
  sonarr)
    default_port='8989'
    container_port='8989'
    mkdir_args+=("$tv_dir" "$downloads_dir")
    volume_args=(-v "$config_dir:/config" -v "$tv_dir:/tv" -v "$downloads_dir:/downloads")
    ;;
  prowlarr)
    default_port='9696'
    container_port='9696'
    volume_args=(-v "$config_dir:/config")
    ;;
  *)
    usage >&2
    exit 1
    ;;
esac

host_port="${host_port:-$default_port}"

# Keep dry runs dependency-light so users can inspect the plan safely.
for path in "${mkdir_args[@]}"; do
  mkdir -p "$path"
done

docker_cmd=(docker run -d --name "$app" -e PUID=0 -e PGID=0 -e TZ="$timezone")
docker_cmd+=(--network "$network_name" -p "$host_port:$container_port")
docker_cmd+=("${extra_args[@]}" "${volume_args[@]}" --restart unless-stopped "$image")

if [[ $dry_run -eq 1 ]]; then
  printf '[dry-run] docker network inspect %q || docker network create %q\n' "$network_name" "$network_name"
  printf '[dry-run]'
  printf ' %q' "${docker_cmd[@]}"
  printf '\n'
else
  if ! command -v docker >/dev/null 2>&1; then
    echo 'docker command not found in PATH' >&2
    exit 1
  fi

  if ! docker info >/dev/null 2>&1; then
    echo 'Docker daemon unavailable; wait for the PMSS rootless Docker watchdog and retry.' >&2
    exit 1
  fi

  # Refuse to clobber existing containers; the helper is for first install.
  if docker container inspect "$app" >/dev/null 2>&1; then
    echo "Container ${app} already exists; remove it manually if you want to recreate it." >&2
    exit 1
  fi

  # One shared network keeps Radarr/Sonarr/Prowlarr/qBittorrent naming simple.
  if ! docker network inspect "$network_name" >/dev/null 2>&1; then
    docker network create "$network_name" >/dev/null
  fi

  "${docker_cmd[@]}" >/dev/null
fi

# Print a stable next step instead of dumping Docker JSON.
host_name="$(hostname -f 2>/dev/null || hostname 2>/dev/null || printf 'your-seedbox-hostname')"
printf '%s is ready at http://%s:%s/\n' "$app" "$host_name" "$host_port"
