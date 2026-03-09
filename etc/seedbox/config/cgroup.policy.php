<?php
// PMSS cgroup policy (defaults). Return a PHP array.
// Minimalistic defaults for seedboxes: low CPU/IO weights, modest process cap, small memory limits.
// Values here may be overridden per host/SKU as needed.
// Guardrails still apply in code: MemoryHigh >= 250MiB; MemoryMax <= 95% of system RAM.
return [
    // CPU/IO weights (1..10000). Lower = lower priority vs others. Default systemd is ~100.
    'cpuWeight'        => 100,
    'ioWeight'         => 100,

    // Process/thread cap under the user slice. When omitted, PMSS derives a
    // default from host capacity (CPU threads / RAM GiB) in systemPrep.php.

    // Memory controls (MiB)
    'memoryHighMiB'    => 500,    // soft throttle at 500 MiB (min enforced as 250)
    'memoryMaxMiB'     => 750,    // hard cap ~+50% over High (still capped at 95% of system RAM)

    // Optional per-user file descriptor caps (via user@.service LimitNOFILE).
    // 'limitNoFileSoft' => 8192,
    // 'limitNoFileHard' => 16384,

    // Per-mount device defaults (resolved to devices at runtime)
    'mounts' => [
        '/'     => ['ioWeight'=>90,  'readBw'=>'2000M', 'writeBw'=>'2000M'],
        '/home' => ['ioWeight'=>75,  'readBw'=>'1000M', 'writeBw'=>'1000M'],
    ],

    // Profiles for shorthand selection (operators can use --io-profile=…)
    'profiles' => [
        'io' => [
            'hdd'  => ['ioWeight'=>200, 'readBw'=>'5M',   'writeBw'=>'10M', 'readIops'=>100, 'writeIops'=>100],
            'nvme' => ['ioWeight'=>200],
            'bulk' => ['ioWeight'=>500, 'cpuWeight'=>300, 'tasksMax'=>8192],
        ],
        // #TODO: cpu/mem/tasks profiles can be extended here if needed (GH #121)
    ],

    // #TODO Implement: per-device IO scheduling hints (e.g., BFQ vs NVMe), enabling IOWeight only when effective (GH #121)
    // #TODO Implement: per-user burst allowances (temporary MemoryMax raise for a time-boxed operation) (GH #121)
    // #TODO Implement: network IO shaping hints per user (integrated with existing QoS) for fair sharing (GH #121)
];
