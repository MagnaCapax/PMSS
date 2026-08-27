# Adding a New User

The `addUser.php` helper provisions a PMSS account and now exposes structured `-h` / `--help` output for operators.

```text
Usage
  addUser.php USERNAME PASSWORD RAM_MiB DISK_QUOTA_GiB [TRAFFIC_LIMIT_GB] [TRAFFIC_CAP_MBIT] [UPLOAD_THROTTLE_KIB]
  addUser.php USERNAME --password=PASSWORD --ram-mib=RAM_MiB --disk-quota-gib=DISK_QUOTA_GiB [RESOURCE_OPTIONS]
  addUser.php --user=USERNAME --password=PASSWORD --ram-mib=RAM_MiB --disk-quota-gib=DISK_QUOTA_GiB [RESOURCE_OPTIONS]
```

## Positional Parameters

- `USERNAME` — new PMSS username; lowercase `[a-z][a-z0-9]{2,7}`.
- `PASSWORD` — initial password; use `rand` to generate one automatically.
- `RAM_MiB` — account RAM target in MiB; forwarded to `userConfigCgroup.php` as `MemoryHigh` with a 250 MiB floor.
- `DISK_QUOTA_GiB` — disk quota in GiB.
- `TRAFFIC_LIMIT_GB` — optional monthly traffic quota in GiB.
- `TRAFFIC_CAP_MBIT` — optional traffic shaper ceiling in Mbit/s; `0` disables shaping.
- `UPLOAD_THROTTLE_KIB` — optional torrent upload throttle in KiB/s; `0` removes it.

## Named Options

- `--user=USERNAME` — same as the first positional username.
- `--password=PASSWORD` — same as the second positional password.
- `--ram-mib=RAM_MiB` — same as the RAM positional argument.
- `--disk-quota-gib=DISK_QUOTA_GiB` — same as the disk quota positional argument.
- `--bonus-quota-gib=BONUS_QUOTA_GiB` — optional additional disk quota already included in the total disk quota; positive values are recorded in `.bonusQuota` for immediate panel display.
- `--traffic-limit-gb=GIB` — monthly traffic quota in GiB.
- `--iops-limit=OPS` — monthly combined read+write I/O operations budget.
- `--traffic-cap-mbit=MBIT` — traffic shaper ceiling in Mbit/s; `0` disables shaping.
- `--upload-throttle-kib=KIB` — persist torrent upload throttle in KiB/s; `0` removes it.
- `--cpu-weight=WEIGHT` — systemd `CPUWeight`; systemd expects `1-10000` and PMSS auto-derives a value from RAM when omitted.
- `--io-weight=WEIGHT` — systemd `IOWeight`; systemd expects `1-10000` and PMSS auto-derives a value from RAM when omitted.
- `--io-read-bw=/dev/DEVICE:RATE` — read bandwidth cap in `/dev/DEVICE:RATE` form.
- `--io-write-bw=/dev/DEVICE:RATE` — write bandwidth cap in `/dev/DEVICE:RATE` form.
- `--io-read-iops=/dev/DEVICE:IOPS` — read IOPS cap in `/dev/DEVICE:IOPS` form.
- `--io-write-iops=/dev/DEVICE:IOPS` — write IOPS cap in `/dev/DEVICE:IOPS` form.
- `--cpu-quota-percent=PERCENT|infinity` — CPU quota percentage; use `infinity` to remove the limit.
- `--io-latency-ms=MS` — `IODeviceLatencyTargetSec` target in milliseconds; defaults to the `/home` backing device.
- `--io-cost-qos=SETTING` — io.cost QoS nested keys; defaults to the `/home` backing device major:minor.
- `--io-cost-model=SETTING` — io.cost model nested keys; defaults to the `/home` backing device major:minor.
- `--docker-enabled=true|false` — persist the initial rootless Docker policy.
- `-h`, `--help` — show the structured help output and exit successfully.

## Notes

- Named options override legacy positional values, so automation can skip intermediate optional slots safely.
- The first positional `USERNAME` can be mixed with named options for the remaining values.
- `RAM_MiB` is applied through `userConfig.php` and then `userConfigCgroup.php`; PMSS clamps the effective `MemoryHigh` floor to 250 MiB and derives `MemoryMax` at roughly 1.25x with at most 2048 MiB of headroom.
- If `RAM_MiB` is below `245`, PMSS persists `dockerEnabled=false` for safety.
- Invalid CLI input exits non-zero. `--bonus-quota-gib` accepts only a non-negative integer and writes only positive values. Username validation failures also emit an `ERROR:` line and `###ADDUSER_JSON` with `success=false` for automation.
- Before initial credential sync, provisioning converges the required `~/.lighttpd/` directory and retains the existing rollback path if safe directory creation fails.

## Examples

```bash
/scripts/addUser.php alice rand 1024 200
/scripts/addUser.php alice rand 1024 250 --bonus-quota-gib=50
/scripts/addUser.php alice --password=rand --ram-mib=1024 --disk-quota-gib=200 --io-weight=320
/scripts/addUser.php --user=alice --password=rand --ram-mib=1024 --disk-quota-gib=200 --traffic-limit-gb=500 --cpu-weight=320 --io-weight=320 --cpu-quota-percent=150 --io-latency-ms=50 --io-cost-qos='enable=1 ctrl=user rpct=95.00 rlat=75000 wpct=95.00 wlat=150000 min=50.00 max=150.00' --upload-throttle-kib=2048 --docker-enabled=true
```

On success the script creates the Unix user and home directory, assigns an HTTP service port, applies `userConfig.php`, converges the per-user environment, starts the user services, and emits both text and JSON summary markers for automation.
