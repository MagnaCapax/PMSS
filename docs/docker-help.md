# Rootless Docker Basics

Docker runs under each user account without sudo. Useful commands:

```
systemctl --user start docker.service   # start daemon
systemctl --user restart docker.service # restart daemon
docker ps                               # running containers
docker images                           # downloaded images
```

Pull images and run containers normally:

```
docker pull lscr.io/linuxserver/wireguard:latest
```

A helper script `install-wireguard.sh` resides in `~/bin` for quick setup of the
linuxserver.io Wireguard container. Invoke it with an optional port:

```
install-wireguard.sh 51820
```


To ensure Docker commands talk to the correct daemon, the `DOCKER_HOST` environment variable is set in your `~/.bashrc`:

```
export DOCKER_HOST=unix:///run/user/$(id -u)/docker.sock
```

If you need docker-compose, download the latest binary into `~/bin` and make it executable. The helper script `install-wireguard.sh` defaults to a random port if none is supplied and prints the chosen port.

See the [rootless Docker limitations](https://docs.docker.com/engine/security/rootless/#known-limitations) for details.

For a deeper guide to running linuxserver.io application containers on PMSS, see
[`docs/linuxserver.io.md`](./linuxserver.io.md).
