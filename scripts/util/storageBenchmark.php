#!/usr/bin/env php
<?php
/** Non-destructive storage benchmark (fio wrapper). */

require_once __DIR__.'/../lib/storageBenchmark.php';
exit(storageBenchmarkMain($argv ?? []));
