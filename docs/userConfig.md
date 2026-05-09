# Adjusting User Settings

`userConfig.php` modifies an existing PMSS account's quota, cgroup, and service settings. The script now exposes structured `-h` / `--help` output.

```text
Usage
  ./userConfig.php USERNAME RAM_MiB DISK_QUOTA_GiB [TRAFFIC_LIMIT_GB] [CPUWEIGHT] [IOWEIGHT] [IO_READ_BW] [IO_WRITE_BW] [IO_READ_IOPS] [IO_WRITE_IOPS] [CPU_QUOTA_PERCENT] [TRAFFIC_CAP_MBIT] [IO_LATENCY_MS] [IO_COST_QOS] [IO_COST_MODEL]
  ./userConfig.php USERNAME [RESOURCE_OPTIONS]
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
- `IO_LATENCY_MS` — optional `IODeviceLatencyTargetSec` target in milliseconds; defaults to the `/home` backing device.
- `IO_COST_QOS` — optional io.cost QoS nested keys; defaults to the `/home` backing device major:minor.
- `IO_COST_MODEL` — optional io.cost model nested keys; defaults to the `/home` backing device major:minor.

## Named Options

- `--traffic-limit-gb=GIB` — monthly traffic quota in GiB.
- `--iops-limit=OPS` — monthly combined read+write I/O operations budget.
- `--cpu-weight=WEIGHT` — systemd `CPUWeight`; systemd expects `1-10000` and PMSS auto-derives a value from RAM when omitted.
- `--io-weight=WEIGHT` — systemd `IOWeight`; systemd expects `1-10000` and PMSS auto-derives a value from RAM when omitted.
- `--io-read-bw=/dev/DEVICE:RATE` — read bandwidth cap in `/dev/DEVICE:RATE` form.
- `--io-write-bw=/dev/DEVICE:RATE` — write bandwidth cap in `/dev/DEVICE:RATE` form.
- `--io-read-iops=/dev/DEVICE:IOPS` — read IOPS cap in `/dev/DEVICE:IOPS` form.
- `--io-write-iops=/dev/DEVICE:IOPS` — write IOPS cap in `/dev/DEVICE:IOPS` form.
- `--cpu-quota-percent=PERCENT|infinity` — CPU quota percentage; use `infinity` to remove the limit. If omitted, the current slice policy stays unchanged.
- `--traffic-cap-mbit=MBIT` — traffic shaper ceiling in Mbit/s; `0` disables shaping.
- `--io-latency-ms=MS` — `IODeviceLatencyTargetSec` target in milliseconds; defaults to the `/home` backing device.
- `--io-cost-qos=SETTING` — io.cost QoS nested keys; defaults to the `/home` backing device major:minor.
- `--io-cost-model=SETTING` — io.cost model nested keys; defaults to the `/home` backing device major:minor.
- `--upload-throttle-kib=KIB` — persist torrent upload throttle in KiB/s; `0` removes it.
- `--welcome-message=HTML` — set or clear `~/.config/welcome-message.html`.
- `--docker-enabled=true|false` — persist the rootless Docker policy for this user.
- `-h`, `--help` — show the structured help output and exit successfully.

## Examples

```bash
/scripts/util/userConfig.php alice 1024 200
/scripts/util/userConfig.php alice --io-weight=300
/scripts/util/userConfig.php alice 2048 500 750 300 300 /dev/sda:20M /dev/sda:20M /dev/sda:500 /dev/sda:500 125 150 50 "enable=1 ctrl=user rpct=95.00 rlat=75000 wpct=95.00 wlat=150000 min=50.00 max=150.00" "ctrl=user model=linear rbps=834913556 rseqiops=93622 rrandiops=102913 wbps=618985353 wseqiops=72325 wrandiops=71025" --upload-throttle-kib=2048 --docker-enabled=true
/scripts/util/userConfig.php alice --welcome-message='<p>Planned maintenance tonight.</p>'
```

## Notes

- `RAM_MiB` is applied through `userConfigCgroup.php`; PMSS clamps the effective `MemoryHigh` floor to 250 MiB and derives `MemoryMax` at roughly 1.25x with at most 2048 MiB of headroom.
- If `RAM_MiB` is below `245`, PMSS persists `dockerEnabled=false` for safety.
- Named resource options override legacy positional values, and `USERNAME` plus named options reuses the stored RAM/quota baseline.
- Use `userConfigCgroup.php` directly when you only need targeted slice tuning without the wider quota and service orchestration performed by `userConfig.php`.
