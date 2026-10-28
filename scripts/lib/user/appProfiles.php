<?php
/** Account-creation app defaults; omitted apps retain their existing policy (ADR 0197). */
return [
    'default' => ['rtorrent' => true, 'pikkumies' => true, 'docker' => true],
    'openclaw' => ['rtorrent' => false, 'pikkumies' => true, 'docker' => true, 'openclaw' => true],
    'storagebox' => ['rtorrent' => false, 'pikkumies' => true, 'docker' => false, 'rclone' => true, 'copyparty' => true],
];
