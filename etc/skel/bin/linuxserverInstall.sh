#!/usr/bin/env bash
set -euo pipefail

# Install a small set of known-good LinuxServer.io containers on PMSS.
# The helper keeps data under $HOME, reuses one Docker network, and relies on
# Docker restart policies plus the existing PMSS rootless Docker watchdog.

usage() {
  cat <<'EOF'
Usage: linuxserverInstall.sh APP [HOST_PORT] [--dry-run]

Supported apps: jellyfin qbittorrent radarr sonarr prowlarr mariadb phpmyadmin
EOF
}

random_secret() {
  od -An -N 18 -tx1 /dev/urandom | tr -d ' \n'
}

database_identifier() {
  local seed="$1"
  local prefix="$2"
  local value

  value="$(printf '%s' "$seed" | tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9_' '_')"
  value="${value##_}"
  value="${value%%_}"
  if [[ -z "$value" ]]; then
    value='user'
  fi
  value="${prefix}${value}"
  printf '%.24s' "$value"
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
bind_host=''
credential_file=''
db_name=''
db_user=''

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
  mariadb)
    default_port='3306'
    container_port='3306'
    bind_host='127.0.0.1'
    credential_file="$HOME/docker/$app/pmss-credentials.env"
    db_user="$(database_identifier "$(id -un 2>/dev/null || printf 'pmss')" 'db_')"
    db_name="$(database_identifier "${db_user}_app" '')"
    if [[ $dry_run -eq 1 ]]; then
      extra_args=(-e MYSQL_ROOT_PASSWORD='<generated-at-install>' -e MYSQL_DATABASE="$db_name" -e MYSQL_USER="$db_user" -e MYSQL_PASSWORD='<generated-at-install>')
    else
      extra_args=(--env-file "$credential_file")
    fi
    volume_args=(-v "$config_dir:/config")
    ;;
  phpmyadmin)
    default_port='8082'
    container_port='80'
    bind_host='127.0.0.1'
    extra_args=(-e PMA_HOST=mariadb -e PMA_PORT=3306)
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

if [[ "$app" == 'mariadb' && $dry_run -ne 1 && ! -f "$credential_file" ]]; then
  umask 077
  cat >"$credential_file" <<EOF
MYSQL_ROOT_PASSWORD=$(random_secret)
MYSQL_DATABASE=$db_name
MYSQL_USER=$db_user
MYSQL_PASSWORD=$(random_secret)
EOF
fi

docker_cmd=(docker run -d --name "$app" -e PUID=0 -e PGID=0 -e TZ="$timezone")
if [[ -n "$bind_host" ]]; then
  docker_cmd+=(--network "$network_name" -p "$bind_host:$host_port:$container_port")
else
  docker_cmd+=(--network "$network_name" -p "$host_port:$container_port")
fi
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
if [[ "$app" == 'mariadb' ]]; then
  printf '%s is ready on %s:%s (credentials saved to %s)\n' "$app" "$bind_host" "$host_port" "$credential_file"
else
  target_host="$host_name"
  if [[ -n "$bind_host" ]]; then
    target_host="$bind_host"
  fi
  printf '%s is ready at http://%s:%s/\n' "$app" "$target_host" "$host_port"
fi
