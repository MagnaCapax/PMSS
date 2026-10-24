#!/bin/bash
# Simple installer for the linuxserver.io Wireguard container
# This is only a basic example. Adjust paths and ports.

PORT=${1:-$(shuf -i 20000-40000 -n 1)}
CONFIG_DIR="$HOME/.config/docker-wireguard"
echo "Using port: $PORT"

mkdir -p "$CONFIG_DIR"

# WireGuard sizes wg0 from eth0 (MTU - 80). PMSS raises rootless bridges to 65520
# (ADR 0169), which would let tunnel packets leave the host as IP fragments, so the
# server gets its own network at the usual 1500.
NETWORK=wireguard
docker network inspect "$NETWORK" >/dev/null 2>&1 \
	|| docker network create --opt com.docker.network.driver.mtu=1500 "$NETWORK" >/dev/null

docker run -d \
	--name wireguard \
	--network "$NETWORK" \
	--cap-add NET_ADMIN \
	--cap-add SYS_MODULE \
	-e PUID=0 \
	-e PGID=0 \
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
