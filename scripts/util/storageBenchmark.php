#!/usr/bin/env php
<?php
/**
 * storageBenchmark.php — Non-destructive storage benchmark (fio wrapper)
 * Context-first naming (storage → benchmark) as per doctrine.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
// TODO(complexity-refactor): Split the remaining execution path away from CLI parsing.

require_once __DIR__.'/../lib/runtime.php';
require_once __DIR__.'/../lib/cli/optionParser.php';
require_once __DIR__.'/../lib/storageHealth/common.php';

$valueOptionNames = [
    '--target',
    '--size',
    '--json',
    '--label',
    '--dd-size',
    '--runtime',
    '--device-runtime',
    '--idle-latency-ms',
    '--idle-util',
];
// Keep long flag literals inline for CLI characterization coverage: '--require-idle'.
$parsed = pmssParseCliTokens($argv, $valueOptionNames);
if (pmssCliHelpRequested($parsed)) {
    echo "\nStorage benchmark (non-destructive)\n";
    echo pmssCliHelpUsageOptions('storageBenchmark.php [options]', [
        ['--target <dir>', 'Directory for file-backed tests (default /home).'],
        ['--size <bytes|MiB|GiB>', 'Target file size (default 500G, capped to 80% free).'],
        ['--runtime <seconds>', 'Per-test runtime for volume fio tests (default 60).'],
        ['--label <name>', 'Tag results (e.g., hostname/site/array).'],
        ['--json <path>', 'JSON Lines log (default /var/log/pmss/benchmark-storage.jsonl).'],
        ['--devices', 'Enable per-device tests (dd seqread + fio randread).'],
        ['--dd-size <MiB|GiB>', 'Size for dd seqread per device (default 1G).'],
        ['--device-runtime <sec>', 'Per-device fio runtime (default 30).'],
        ['--require-idle', 'Abort if busy (ioping/iostat exceed thresholds).'],
        ['--idle-latency-ms <ms>', 'ioping avg latency threshold (default 100).'],
        ['--idle-util <percent>', 'iostat util threshold (default 85).'],
        ['--show-last', 'Print the last run human summary and exit.'],
        ['--help', 'Show this help.'],
    ], 28, ['Also accepts --key=value form for all value options.']);
    exit(0);
}
$testDevices = pmssCliOptionPresent($parsed, 'devices', null, true);
$requireIdle = pmssCliOptionPresent($parsed, 'require-idle', null, true);
$showLast = pmssCliOptionPresent($parsed, 'show-last', null, true);
$targetDir = (string) pmssCliOptionString($parsed, 'target', null, '/home', true);
$fileSize = (string) pmssCliOptionString($parsed, 'size', null, '500G', true);
$jsonLog = (string) pmssCliOptionString($parsed, 'json', null, '/var/log/pmss/benchmark-storage.jsonl', true);
$label = (string) pmssCliOptionString($parsed, 'label', null, '', true);
$ddSize = (string) pmssCliOptionString($parsed, 'dd-size', null, '1G', true);
function storageBenchmarkRequirePositiveSizeBytes(string $optionName, string $value): int { $bytes = preg_match('/^([0-9]+)([KMGTP]i?B?)?$/i', trim($value)) === 1 ? pmssParseSizeToBytes($value, true, true) : null; if ($bytes === null || $bytes <= 0.0) { fwrite(STDERR, "Error: {$optionName} must be a positive size (examples: 1G, 512M, 1048576).\n"); exit(1); } return (int) $bytes; }
// Reject malformed numeric knobs before they reach fio/dd runtime settings.
function storageBenchmarkRequireIntOption(array $parsed, string $optionName, int $default, int $minimum, string $minimumLabel): int
{
    $value = pmssCliOption($parsed, $optionName, null, null);
    if ($value === null || $value === true) {
        return $default;
    }

    if (!is_string($value) || !ctype_digit($value) || (int) $value < $minimum) {
        fwrite(STDERR, "Error: --{$optionName} must be a {$minimumLabel} integer.\n");
        exit(1);
    }

    return (int) $value;
}
function storageBenchmarkRequireJsonLogPath(string $jsonLog): void
{
    $jsonDir = dirname($jsonLog);
    $jsonDirError = null;
    if (!pmssLogWriteDirectoryPrepare($jsonDir, 0755, $jsonDirError, true)) {
        if ($jsonDirError === 'create') {
            fwrite(STDERR, "Error: failed to create JSON log directory: {$jsonDir}\n");
            exit(1);
        }
        fwrite(STDERR, "Error: unsafe JSON log path: {$jsonLog}\n");
        exit(1);
    }
    if (!pmssLogWritePathIsSafe($jsonLog)) {
        fwrite(STDERR, "Error: unsafe JSON log path: {$jsonLog}\n");
        exit(1);
    }
}
function storageBenchmarkRequireTargetDir(string $targetDir): string
{
    $path = rtrim($targetDir, '/');
    if ($path === '' || preg_match('/[\r\n\0]/', $path) === 1 || !pmssPathSegmentsAreSafe($path, true, true, true, true)) {
        fwrite(STDERR, "Error: unsafe target directory: {$targetDir}\n");
        exit(1);
    }
    if (!is_dir($path) || !is_writable($path)) {
        fwrite(STDERR, "Error: target not writable: {$targetDir}\n");
        exit(1);
    }
    return $path;
}
function storageBenchmarkAppendJsonLine(string $jsonLog, array $entry): void { if(!pmssJsonLineAppend($jsonLog,$entry)){ fwrite(STDERR,"Error: failed to append JSON log entry: {$jsonLog}\n"); exit(1); } }
function storageBenchmarkIostatUtilPctRead(string $path): ?float
{
    $payload = pmssReadSerializedArrayFile($path);
    if ($payload === null || !array_key_exists('diskUtil', $payload)) {
        return null;
    }

    $util = $payload['diskUtil'];
    if (is_int($util) || is_float($util)) {
        return (float) $util;
    }
    if (is_string($util) && is_numeric(trim($util))) {
        return (float) trim($util);
    }

    return null;
}
function storageBenchmarkRequireCommandField(string $command, string $label): string
{
    $result = pmssCommandCapture($command, 30);
    $value = trim((string) ($result['stdout'] ?? ''));
    if ((int) ($result['rc'] ?? 1) !== 0 || $value === '' || preg_match('/[\r\n\0]/', $value) === 1) {
        fwrite(STDERR, "Error: failed to read {$label}.\n");
        exit(1);
    }

    return $value;
}
function storageBenchmarkRequirePositiveIntCommandField(string $command, string $label): int
{
    $value = storageBenchmarkRequireCommandField($command, $label);
    if (!ctype_digit($value) || (int) $value <= 0) {
        fwrite(STDERR, "Error: failed to read {$label}.\n");
        exit(1);
    }

    return (int) $value;
}
function storageBenchmarkShowLast(string $jsonLog): int { if (!is_file($jsonLog)) { fwrite(STDERR,"No log at {$jsonLog}\n"); return 1; } $runs=[]; $lastId=''; $lastTs=''; foreach (pmssJsonLineFileRead($jsonLog) as $entry) { if (!isset($entry['run_id']) || !is_string($entry['run_id']) || $entry['run_id']==='') continue; $runId=$entry['run_id']; $runs[$runId][]=$entry; $runTs=(isset($entry['run_ts']) && is_string($entry['run_ts'])) ? $entry['run_ts'] : ''; if ($runTs>$lastTs){$lastTs=$runTs;$lastId=$runId;} } if ($lastId===''){ echo "No runs found.\n"; return 0; } $run=$runs[$lastId]; $first=$run[0]; $labelStr=(isset($first['label']) && $first['label']!=='') ? ('  Label: '.$first['label']) : ''; echo "\n== Storage benchmark (last run) ==\nRun ID: {$lastId}  Time: ".($first['run_ts'] ?? '').$labelStr."\n\n"; foreach ($run as $entry) { if (($entry['test'] ?? '')==='preflight-idle'){ echo "Preflight: ioping=".($entry['ioping_avg_ms']??'n/a')." ms util=".($entry['iostat_util_pct']??'n/a')."%\n\n"; break; } } echo "File-backed tests\n"; echo "test\tread_MB/s\twrite_MB/s\tread_IOPS\twrite_IOPS\tread_p95\twrite_p95\n"; foreach ($run as $entry){ if (isset($entry['test']) && empty($entry['device']) && (($entry['params']['rw']??'')!=='')) { $metrics=$entry['metrics']??[]; printf("%s\t%.2f\t%.2f\t%.1f\t%.1f\t%.2f\t%.2f\n",$entry['test'],$metrics['read_bw_MBps']??0,$metrics['write_bw_MBps']??0,$metrics['read_iops']??0,$metrics['write_iops']??0,$metrics['read_p95_ms']??0,$metrics['write_p95_ms']??0); } } echo "\nPer-device tests\n"; $devices=[]; foreach($run as $entry){ if(isset($entry['device'])) $devices[$entry['device']][]=$entry; } foreach ($devices as $device=>$entries){ echo $device."\n"; foreach ($entries as $entry){ $test=$entry['test']; $metrics=$entry['metrics']??[]; if($test==='device-seqread-dd') printf("  %-18s seq_MB/s=%.2f t=%.2fs\n",$test,$metrics['seqread_MBps']??0,$metrics['elapsed_s']??0); elseif(strpos($test,'dev-randread')===0) printf("  %-18s read_MB/s=%.2f IOPS=%.1f p95=%.2fms\n",$test,$metrics['read_bw_MBps']??0,$metrics['read_iops']??0,$metrics['read_p95_ms']??0); elseif($test==='device-ioping') printf("  %-18s avg_ms=%.2f\n",$test,$metrics['ioping_avg_ms']??0);} } return 0; }

if ($showLast) exit(storageBenchmarkShowLast($jsonLog));

$runtime = storageBenchmarkRequireIntOption($parsed, 'runtime', 60, 1, 'positive');
$devRuntime = $testDevices ? storageBenchmarkRequireIntOption($parsed, 'device-runtime', 30, 1, 'positive') : 30;
$idleLatencyMs = storageBenchmarkRequireIntOption($parsed, 'idle-latency-ms', 100, 0, 'non-negative');
$idleUtilPct = storageBenchmarkRequireIntOption($parsed, 'idle-util', 85, 0, 'non-negative');
$requested = storageBenchmarkRequirePositiveSizeBytes('--size', $fileSize); $ddSizeBytes = $testDevices ? storageBenchmarkRequirePositiveSizeBytes('--dd-size', $ddSize) : 0;
storageBenchmarkRequireJsonLogPath($jsonLog);
$targetDir = storageBenchmarkRequireTargetDir($targetDir);

if (pmssCommandPath('fio')===''){ fwrite(STDERR,"Error: 'fio' not found.\n"); exit(1);} 

$runId=date('YmdHis').'-'.bin2hex(random_bytes(3)); $runTs=date('c');
$fs=storageBenchmarkRequireCommandField('stat -f -c %T '.escapeshellarg($targetDir), 'filesystem type'); $mntDev=storageBenchmarkRequireCommandField('df -P '.escapeshellarg($targetDir).' | awk ' . escapeshellarg('NR==2 {print $1}'), 'mount device');

// Preflight
$pre = ['timestamp'=>$runTs,'label'=>$label?:null,'target_dir'=>$targetDir,'test'=>'preflight-idle','run_id'=>$runId,'run_ts'=>$runTs,'ok'=>true];
$pre['ioping_avg_ms']=pmssIopingAverageMs($targetDir); if(($pre['ioping_avg_ms']??0)>$idleLatencyMs){ $pre['ok']=false; $pre['warn']='ioping above threshold'; }
$iostatUtilPct = storageBenchmarkIostatUtilPctRead('/var/run/pmss/iostat');
if ($iostatUtilPct !== null){ $pre['iostat_util_pct']=$iostatUtilPct; if($pre['iostat_util_pct']>$idleUtilPct){ $pre['ok']=false; $pre['warn_util']='iostat util high'; } }
storageBenchmarkAppendJsonLine($jsonLog,$pre); if($requireIdle && !$pre['ok']){ fwrite(STDERR,"Busy system (--require-idle): aborting.\n"); exit(2);}

// File-backed tests
$free=storageBenchmarkRequirePositiveIntCommandField('df -PB1 '.escapeshellarg($targetDir).' | awk ' . escapeshellarg('NR==2 {print $4}'), 'free space'); $use=(int) min($requested, floor($free*0.8)); if($use<=0){ fwrite(STDERR,"Insufficient free space.\n"); exit(1);}
$testFile=rtrim($targetDir,'/').'/pmss-fio-'.bin2hex(random_bytes(4)).'.dat'; if(pmssCommandPath('fallocate')!=='') runCommand('fallocate -l '.$use.' '.escapeshellarg($testFile));
$tests=[ ['name'=>'randmix-large-95r5w','rw'=>'randrw','rwmixread'=>95,'bssplit'=>'4k/2:64k/3:128k/5:256k/10:512k/20:768k/25:1024k/35','iodepth'=>32,'numjobs'=>4,'direct'=>1], ['name'=>'randread-large','rw'=>'randread','bs'=>'1M','iodepth'=>32,'numjobs'=>4,'direct'=>1], ['name'=>'randread-small','rw'=>'randread','bs'=>'4k','iodepth'=>64,'numjobs'=>4,'direct'=>1], ['name'=>'randwrite-small-short','rw'=>'randwrite','bs'=>'4k','iodepth'=>32,'numjobs'=>2,'direct'=>1,'runtime'=>max(15,(int)floor($runtime/3))], ['name'=>'seqread-large','rw'=>'read','bs'=>'1M','iodepth'=>32,'numjobs'=>2,'direct'=>1] ];
 function fioRun(string $file,int $size,int $runtime,array $job): array { $json=pmssCreatePrivateTempFile('fio-'); if($json===null) return ['ok'=>false,'error'=>'unable to allocate fio JSON temp file']; $opts=['--name='.escapeshellarg($job['name']),'--filename='.escapeshellarg($file),'--size='.$size,'--time_based=1','--runtime='.(int)($job['runtime']??$runtime),'--rw='.escapeshellarg($job['rw']),'--ioengine=libaio','--iodepth='.(int)$job['iodepth'],'--numjobs='.(int)$job['numjobs'],'--direct='.(int)$job['direct'],'--group_reporting=1']; if(isset($job['bs'])) $opts[]='--bs='.escapeshellarg($job['bs']); if(isset($job['bssplit'])) $opts[]='--bssplit='.escapeshellarg($job['bssplit']); if(isset($job['rwmixread'])) $opts[]='--rwmixread='.(int)$job['rwmixread']; $cmd='fio --output-format=json --output '.escapeshellarg($json).' '.implode(' ',$opts); $rc=runCommand($cmd,true); $payload=@file_get_contents($json); @unlink($json); if($rc!==0 || $payload===false || trim($payload)==='') return ['ok'=>false,'error'=>'fio failed']; $j=json_decode($payload,true); if(!is_array($j)||empty($j['jobs'][0])) return ['ok'=>false,'error'=>'invalid fio JSON']; $rbw=0;$wbw=0;$ri=0;$wi=0;$rp=[];$wp=[]; foreach($j['jobs'] as $jobj){ $rbw+=(int)($jobj['read']['bw_bytes']??0); $wbw+=(int)($jobj['write']['bw_bytes']??0); $ri+=(float)($jobj['read']['iops']??0); $wi+=(float)($jobj['write']['iops']??0); $p95r=$jobj['read']['clat_ns']['percentile']['95.000000']??null; $p95w=$jobj['write']['clat_ns']['percentile']['95.000000']??null; if($p95r!==null) $rp[]=(float)$p95r; if($p95w!==null) $wp[]=(float)$p95w; } $avg=function($a){return count($a)?array_sum($a)/count($a):0;}; return ['ok'=>true,'result'=>['read_bw_MBps'=>round($rbw/(1024*1024),2),'write_bw_MBps'=>round($wbw/(1024*1024),2),'read_iops'=>round($ri,1),'write_iops'=>round($wi,1),'read_p95_ms'=>round($avg($rp)/1000000,2),'write_p95_ms'=>round($avg($wp)/1000000,2),'raw'=>$j]]; }
$summary=[]; foreach($tests as $job){ $res=fioRun($testFile,$use,$runtime,$job); $entry=['timestamp'=>$runTs,'label'=>$label?:null,'run_id'=>$runId,'run_ts'=>$runTs,'target_dir'=>$targetDir,'device'=>$mntDev,'filesystem'=>$fs,'test'=>$job['name'],'params'=>['rw'=>$job['rw'],'rwmixread'=>$job['rwmixread']??null,'bs'=>$job['bs']??null,'bssplit'=>$job['bssplit']??null,'iodepth'=>$job['iodepth'],'numjobs'=>$job['numjobs'],'direct'=>$job['direct'],'runtime'=>(int)($job['runtime']??$runtime),'size_bytes'=>$use],'ok'=>$res['ok']]; if($res['ok']){$entry['metrics']=$res['result']; $summary[]=[ $job['name'],$res['result']['read_bw_MBps'],$res['result']['write_bw_MBps'],$res['result']['read_iops'],$res['result']['write_iops'],$res['result']['read_p95_ms'],$res['result']['write_p95_ms'] ];} else {$entry['error']=$res['error']??'unknown';} storageBenchmarkAppendJsonLine($jsonLog,$entry);} @unlink($testFile);
echo "\n== Storage benchmark summary (".($label!==''?$label.' ':'')."on {$targetDir}) ==\n"; echo "test\tread_MB/s\twrite_MB/s\tread_IOPS\twrite_IOPS\tread_p95_ms\twrite_p95_ms\n"; foreach($summary as $row){ printf("%s\t%.2f\t%.2f\t%.1f\t%.1f\t%.2f\t%.2f\n",...$row);} echo "\nJSON log: {$jsonLog}\n";

// Per-device read-only
if($testDevices){ echo "\n== Per-device read-only benchmarks ==\n"; $devs=pmssStorageHealthDiskInventoryFromLsblk((string) shell_exec('lsblk -dn -o KNAME,TYPE,ROTA,MODEL,SERIAL,SIZE 2>/dev/null'));
  $peer=[]; foreach($devs as $meta){ $path=$meta['path']; if(!is_readable($path)) continue; $sizeRaw=trim((string) shell_exec('blockdev --getsize64 '.escapeshellarg($path).' 2>/dev/null')); if($sizeRaw==='' || !ctype_digit($sizeRaw) || (int)$sizeRaw<=0){ storageBenchmarkAppendJsonLine($jsonLog,['timestamp'=>$runTs,'label'=>$label?:null,'run_id'=>$runId,'run_ts'=>$runTs,'device'=>$path,'model'=>$meta['model'],'serial'=>$meta['serial'],'rota'=>$meta['rota'],'size'=>$meta['size'],'test'=>'device-preflight','ok'=>false,'error'=>'unable to determine block device size']); printf("%s\tskipped: unable to determine block device size\n",$path); continue; } $size=(int)$sizeRaw; $count=(int) floor($ddSizeBytes/(1024*1024)); $skip=0; if($size>($count*1024*1024+4*1024*1024)) $skip=random_int(0,(int)floor(($size-$count*1024*1024)/(1024*1024))); $dd=sprintf('dd if=%s of=/dev/null bs=1M count=%d skip=%d iflag=direct 2>&1',escapeshellarg($path),$count,$skip); [$rc,$so,$se]=[runCommand($dd,true),$GLOBALS['PMSS_LAST_COMMAND_OUTPUT']['stdout']??'',$GLOBALS['PMSS_LAST_COMMAND_OUTPUT']['stderr']??'']; $line=trim($se!==''?$se:$so); $mbps=null; $secs=null; if(preg_match('/\s([0-9.]+)\s+s,\s+([0-9.]+)\s+MB\/s/',$line,$m)){ $secs=(float)$m[1]; $mbps=(float)$m[2]; } $e=['timestamp'=>$runTs,'label'=>$label?:null,'run_id'=>$runId,'run_ts'=>$runTs,'device'=>$path,'model'=>$meta['model'],'serial'=>$meta['serial'],'rota'=>$meta['rota'],'size'=>$meta['size'],'test'=>'device-seqread-dd','params'=>['bs'=>'1M','count'=>$count,'skip_blocks'=>$skip],'ok'=>($rc===0&&$mbps!==null)]; if($mbps!==null)$e['metrics']=['seqread_MBps'=>$mbps,'elapsed_s'=>$secs]; else $e['error']='dd parse failed'; storageBenchmarkAppendJsonLine($jsonLog,$e); printf("%s\tdd_seqread_MB/s=%s\n",$path,$mbps!==null?number_format($mbps,2):'n/a'); $iop=pmssIopingAverageMs($path); storageBenchmarkAppendJsonLine($jsonLog,['timestamp'=>$runTs,'label'=>$label?:null,'run_id'=>$runId,'run_ts'=>$runTs,'device'=>$path,'model'=>$meta['model'],'serial'=>$meta['serial'],'rota'=>$meta['rota'],'size'=>$meta['size'],'test'=>'device-ioping','ok'=>($iop!==null),'metrics'=>['ioping_avg_ms'=>round($iop??0,2)]]); if($iop!==null) printf("%s\tioping_avg_ms=%.2f\n",$path,$iop);
    foreach([ ['name'=>'dev-randread-4k','rw'=>'randread','bs'=>'4k','iodepth'=>64], ['name'=>'dev-randread-1M','rw'=>'randread','bs'=>'1M','iodepth'=>32] ] as $job){ $res=fioRun($path,$size,$devRuntime,['name'=>$job['name'],'rw'=>$job['rw'],'bs'=>$job['bs'],'iodepth'=>$job['iodepth'],'numjobs'=>1,'direct'=>1]); $ej=['timestamp'=>$runTs,'label'=>$label?:null,'run_id'=>$runId,'run_ts'=>$runTs,'device'=>$path,'model'=>$meta['model'],'serial'=>$meta['serial'],'rota'=>$meta['rota'],'size'=>$meta['size'],'test'=>$job['name'],'params'=>['rw'=>$job['rw'],'bs'=>$job['bs'],'iodepth'=>$job['iodepth'],'numjobs'=>1,'runtime'=>$devRuntime],'ok'=>$res['ok']]; if($res['ok']){$ej['metrics']=$res['result']; printf("%s\t%s\tread_MB/s=%.2f\tread_IOPS=%.1f\tread_p95_ms=%.2f\n",$path,$job['name'],$res['result']['read_bw_MBps'],$res['result']['read_iops'],$res['result']['read_p95_ms']);} else {$ej['error']=$res['error']??'fio failed';} storageBenchmarkAppendJsonLine($jsonLog,$ej); if($res['ok'] && $job['name']==='dev-randread-4k'){ $peer[$path]['fio4k_mb']=$res['result']['read_bw_MBps']; } }
    $peer[$path]['dd_mb']=$mbps; $peer[$path]['iop_ms']=$iop; }
  // Loose peer checks
  $get=function($peer,$k){$arr=[]; foreach($peer as $p=>$v){ if(isset($v[$k])&&$v[$k]!==null)$arr[]=$v[$k]; } return $arr;}; $median=function($a){ sort($a); $n=count($a); if($n===0)return 0.0; $m=(int)floor(($n-1)/2); return $n%2? (float)$a[$m] : (($a[$m]+$a[$m+1])/2); };
  $medDd=$median($get($peer,'dd_mb')); $medIop=$median($get($peer,'iop_ms')); $med4k=$median($get($peer,'fio4k_mb'));
  foreach($peer as $p=>$r){ if($medDd>0 && isset($r['dd_mb']) && $r['dd_mb']!==null && $r['dd_mb']<0.6*$medDd) echo "WARN: {$p} seqread < 60% median\n"; if($medIop>0 && isset($r['iop_ms']) && $r['iop_ms']!==null && $r['iop_ms']>max(50,2*$medIop)) echo "WARN: {$p} ioping > 2x median\n"; if($med4k>0 && isset($r['fio4k_mb']) && $r['fio4k_mb']!==null && $r['fio4k_mb']<0.5*$med4k) echo "WARN: {$p} 4k randread < 50% median\n"; }
}
