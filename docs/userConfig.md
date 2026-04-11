# Adjusting User Settings

`userConfig.php` modifies an existing PMSS account's quota, cgroup, and service settings. The script now exposes structured `-h` / `--help` output.

```text
Usage
  ./userConfig.php USERNAME RAM_MiB DISK_QUOTA_GiB [TRAFFIC_LIMIT_GB] [CPUWEIGHT] [IOWEIGHT] [IO_READ_BW] [IO_WRITE_BW] [IO_READ_IOPS] [IO_WRITE_IOPS] [CPU_QUOTA_PERCENT] [TRAFFIC_CAP_MBIT]
  ./userConfig.php USERNAME --welcome-message=HTML
```

## Positional Parameters

- `USERNAME` — existing PMSS username; lowercase `[a-z][a-z0-9]{2,7}`.
- `RAM_MiB` — account RAM target in MiB; forwarded to `userConfigCgroup.php` as `MemoryHigh` with a 250 MiB floor.
- `DISK_QUOTA_GiB` — disk quota in GiB.
- `TRAFFIC_LIMIT_GB` — optional monthly traffic quota in GiB.
- `CPUWEIGHT` — optional systemd `CPUWeight`; systemd expects `1-10000` and PMSS auto-derives a value from RAM when omitted.
- `IOWEIGHT` — optional systemd `IOWeight`; systemd expects `1-10000` and PMSS auto-derives a value from RAM when omitted.
- `IO_READ_BW` — optional read bandwidth cap in `/dev/DEVICE:RATE` form.
- `IO_WRITE_BW` — optional write bandwidth cap in `/dev/DEVICE:RATE` form.
- `IO_READ_IOPS` — optional read IOPS cap in `/dev/DEVICE:IOPS` form.
- `IO_WRITE_IOPS` — optional write IOPS cap in `/dev/DEVICE:IOPS` form.
- `CPU_QUOTA_PERCENT` — optional CPU quota percentage; use `infinity` to remove the limit. If omitted, the current slice policy stays unchanged.
- `TRAFFIC_CAP_MBIT` — optional traffic shaper ceiling in Mbit/s; `0` disables shaping.

## Named Options

- `--upload-throttle-kib=KIB` — persist torrent upload throttle in KiB/s; `0` removes it.
- `--welcome-message=HTML` — set or clear `~/.config/welcome-message.html`.
- `--docker-enabled=true|false` — persist the rootless Docker policy for this user.
- `-h`, `--help` — show the structured help output and exit successfully.

## Examples

```bash
/scripts/util/userConfig.php alice 1024 200
/scripts/util/userConfig.php alice 2048 500 750 300 300 /dev/sda:20M /dev/sda:20M /dev/sda:500 /dev/sda:500 125 150 --upload-throttle-kib=2048 --docker-enabled=true
/scripts/util/userConfig.php alice --welcome-message='<p>Planned maintenance tonight.</p>'
```

## Notes

- `RAM_MiB` is applied through `userConfigCgroup.php`; PMSS clamps the effective `MemoryHigh` floor to 250 MiB and derives `MemoryMax` at roughly 1.25x with at most 2048 MiB of headroom.
- If `RAM_MiB` is below `245`, PMSS persists `dockerEnabled=false` for safety.
- Use `userConfigCgroup.php` directly when you only need targeted slice tuning without the wider quota and service orchestration performed by `userConfig.php`.
