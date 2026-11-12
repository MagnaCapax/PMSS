<?php
// PMSS cgroup policy (defaults). Return a PHP array.
// Minimalistic defaults for seedboxes: low CPU/IO weights, modest process cap, small memory limits.
// Every host runs these values; there is no per-host override (ADR 0233).
// Guardrails still apply in code: MemoryHigh >= 250MiB; MemoryMax <= 95% of system RAM.
return [
    'cgroup' => [
        // Keep account tasks in the user slice where cgroup-v1 read caps act (ADR 0231).
        // Running tasks move at the next user-manager restart or reboot.
        'ioSlicePlacement' => true,

        // Per-user derived read-IOPS caps may oversell the measured host ceiling.
        // 2.0 means PMSS assigns up to 200% of the passive host read-IOPS estimate.
        'assignMax' => ['iops' => 2.0],

        // 'auto' applies read caps only on a kernel with metadata-first dispatch and cgroup-v1 memory (ADR 0271);
        // elsewhere it acts as 'off' and clears them (ADR 0243). 'enforce' applies them (ADRs 0230, 0231).
        'dynamicIops' => [
            'mode' => 'auto',
            'idlePressure' => 5.0, 'busyPressure' => 15.0,
            'idleMultiplier' => 10.0, 'busyMultiplier' => 2.0,
            'floor' => 15, 'floorCharge' => 0.25,
            'curbStartIowait' => 50.0, 'curbFullIowait' => 80.0, 'curbMaxCut' => 0.25,
            'quotaExponent' => 1.0,
            'workConservingHeadroom' => 3.0,
            // Hourly HDD A/B blocks; solid state retains its latency step (ADRs 0237, 0238).
            'probeStartLo' => 5.0, 'probeBlockCycles' => 12, 'probePairs' => 11, 'probeGain' => 0.10,
            'latencyStep' => 1.1,
            'ssdLatencyLowX' => 5.0, 'ssdLatencyHighX' => 10.0,
        ],

        // Read-dimension safety floors for derived per-user caps. NVMe is not
        // represented in the current fleet sample; keep this placeholder
        // review-adjustable while ensuring it is at least the SSD floor.
        'readIopsClassFloors' => [
            'default' => 200,
            'storage' => 200,
            'hdd' => 200,
            'ssd' => 500,
            'nvme' => 750,
        ],
    ],

    // Passive estimate only: maximum daily P95 over completed UTC days.
    'ioCeiling' => ['percentile' => 95, 'windowDays' => 7, 'minSamplesPerDay' => 144, 'minDays' => 3],

    // CPU/IO weights (1..10000). Lower = lower priority vs others. Default systemd is ~100.
    'cpuWeight'        => 100,
    'ioWeight'         => 100,
    'ioLatencyMs'      => 50,

    // Process/thread cap under the user slice. When omitted, PMSS derives a
    // default from host capacity (CPU threads / RAM GiB) in systemPrep.php.

    // Memory controls (MiB)
    'memoryHighMiB'    => 500,    // soft throttle at 500 MiB (min enforced as 250)
    'memoryMaxMiB'     => 750,    // hard cap ~+50% over High (still capped at 95% of system RAM)

    // Optional per-user file descriptor caps for the systemd user manager.
    // This path is implemented: when both values are valid integers, PMSS
    // installs /etc/systemd/system/user@.service.d/20-pmss-limits.conf.
    // 'limitNoFileSoft' => 8192,
    // 'limitNoFileHard' => 16384,

    // Per-mount device defaults (resolved to devices at runtime)
    'mounts' => [
        '/'     => ['ioWeight'=>90,  'readBw'=>'2000M', 'writeBw'=>'2000M'],
        '/home' => ['ioWeight'=>75,  'readBw'=>'1000M', 'writeBw'=>'1000M'],
    ],

    // Profiles for shorthand selection (operators can use --io-profile=…)
    'profiles' => [
        // CPU/memory/tasks profile families for CLI flags:
        // --cpu-profile, --mem-profile, --tasks-profile.
        // Built-in fallbacks remain available even when these are omitted.
        // 'cpu' => ['balanced' => 180],
        // 'mem' => ['streaming' => 1536],
        // 'tasks' => ['service' => 12000],
        'io' => [
            'hdd'  => ['ioWeight'=>200, 'readBw'=>'5M',   'writeBw'=>'10M', 'readIops'=>100, 'writeIops'=>100],
            'nvme' => ['ioWeight'=>200],
            'bulk' => ['ioWeight'=>500, 'cpuWeight'=>300, 'tasksMax'=>8192],
        ],
    ],

    // #TODO Pending extension: scheduler-aware IO auto-policy. Mount-based IO
    // controls already work today via the 'mounts' map above.
    // #TODO Pending extension: per-user burst allowances (temporary MemoryMax
    // raise for a time-boxed operation).
    // #TODO Pending extension: network shaping hints in cgroup policy
    // (integration point with existing QoS/FireQOS remains to be designed).
];
