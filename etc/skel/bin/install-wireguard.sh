#!/bin/bash
# Simple installer for the linuxserver.io Wireguard container
# This is only a basic example. Adjust paths and ports.

PORT=${1:-$(shuf -i 20000-40000 -n 1)}
CONFIG_DIR="$HOME/.config/docker-wireguard"
echo "Using port: $PORT"

mkdir -p "$CONFIG_DIR"

docker run -d \
	--name wireguard \
	--cap-add NET_ADMIN \
	--cap-add SYS_MODULE \
	-e PUID="$(id -u)" \
	-e PGID="$(id -g)" \
	-e TZ="$(cat /etc/timezone 2>/dev/null || echo 'UTC')" \
	-e SERVERURL="$(hostname)" \
	-e SERVERPORT="$PORT" \
	-e PEERS=3 \
	-e PEERDNS=auto \
	-e INTERNAL_SUBNET=10.13.13.0 \
	-e ALLOWEDIPS=0.0.0.0/0 \
	-e LOG_CONFS=true \
	-v "$CONFIG_DIR":/config \
	-v /lib/modules:/lib/modules \
	-p "$PORT":51820/udp \
	--sysctl net.ipv4.conf.all.src_valid_mark=1 \
	--restart unless-stopped \
	lscr.io/linuxserver/wireguard:latest
