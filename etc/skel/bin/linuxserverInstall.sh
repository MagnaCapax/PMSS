#!/usr/bin/env bash
set -euo pipefail

# Keep the legacy entrypoint available while the context-first command name
# takes over. The real implementation now lives in shared PHP.

if ! command -v php >/dev/null 2>&1; then
	echo 'linuxserverInstall.sh requires PHP CLI.' >&2
	exit 1
fi

wrapper_path="${PMSS_DOCKER_INSTALL_LSIO_WRAPPER:-$HOME/bin/docker-install-lsio}"
script_path="${PMSS_DOCKER_INSTALL_LSIO_SCRIPT:-/scripts/util/dockerInstallLsio.php}"

if [[ -f "$wrapper_path" ]]; then
	exec php "$wrapper_path" "$@"
fi

exec php "$script_path" "$@"
