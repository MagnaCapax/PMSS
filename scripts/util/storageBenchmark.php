#!/usr/bin/php
<?php
/**
 * storageBenchmark.php — Non-destructive storage benchmark (fio wrapper)
 * Context-first naming (storage → benchmark) as per doctrine.
 */
// TODO(complexity-refactor): Separate CLI parsing + shelling from core
// measurement/aggregation. Extract small, testable helpers for:
//  - size parsing and cap logic
//  - idle detection (ioping/iostat)
//  - fio command generation and result parsing
// Keep runtime flags; preserve non-destructive guarantees and PHP 7.3.

require_once __DIR__.'/../lib/runtime.php';

function usage(): void {
    echo "\nStorage benchmark (non-destructive)\n";
    echo "Usage: storageBenchmark.php [options]\n\n";
    echo "Core options:\n";
    echo "  --target <dir>            Directory for file-backed tests (default /home)\n";
    echo "  --size <bytes|MiB|GiB>    Target file size (default 500G, capped to 80% free)\n";
    echo "  --runtime <seconds>       Per-test runtime for volume fio tests (default 60)\n";
    echo "  --label <name>            Tag results (e.g., hostname/site/array)\n";
    echo "  --json <path>             JSON Lines log (default /var/log/pmss/benchmark-storage.jsonl)\n";
    echo "  (also accepts --key=value form for all options above)\n\n";
    echo "Device options (read-only):\n";
    echo "  --devices                 Enable per-device tests (dd seqread + fio randread)\n";
    echo "  --dd-size <MiB|GiB>       Size for dd seqread per device (default 1G)\n";
    echo "  --device-runtime <sec>    Per-device fio runtime (default 30)\n\n";
    echo "Idle checks:\n";
    echo "  --require-idle            Abort if busy (ioping/iostat exceed thresholds)\n";
    echo "  --idle-latency-ms <ms>    ioping avg latency threshold (default 100)\n";
    echo "  --idle-util <percent>     iostat util threshold (default 85)\n\n";
    echo "Other:\n";
    echo "  --show-last               Print the last run's human summary and exit\n";
    echo "  --help                    Show this help\n\n";
}

// Parameters
$targetDir = '/home';
$fileSize  = '500G';
$runtime   = 60;
$jsonLog   = '/var/log/pmss/benchmark-storage.jsonl';
$label     = '';
$testDevices = false;
$ddSize = '1G';
$devRuntime = 30;
$requireIdle=false; $idleLatencyMs=100; $idleUtilPct=85; $showLast=false;

function consumeCliValue(?string $value, ?string $next, int &$i): ?string
{
    if ($value !== null) {
        return $value;
    }
    if ($next !== null && strpos($next, '--') !== 0) {
        $i++;
        return $next;
    }
    return null;
}

$argvCount = count($argv);
for ($i=1; $i<$argvCount; $i++) {
    $arg = $argv[$i];
    $next = ($i+1 < $argvCount) ? $argv[$i+1] : null;
    $kv = null;
    if (strpos($arg, '=') !== false) {
        $kv = explode('=', $arg, 2);
    }
    $key = $kv ? $kv[0] : $arg;
    $val = $kv ? $kv[1] : null;
    switch ($key) {
        case '--target':
            $val = consumeCliValue($val, $next, $i);
            if ($val !== null) $targetDir = $val;
            break;
        case '--size':
            $val = consumeCliValue($val, $next, $i);
            if ($val !== null) $fileSize = $val;
            break;
        case '--runtime':
            $val = consumeCliValue($val, $next, $i);
            if ($val !== null) $runtime = (int)$val;
            break;
        case '--json':
            $val = consumeCliValue($val, $next, $i);
            if ($val !== null) $jsonLog = $val;
            break;
        case '--label':
            $val = consumeCliValue($val, $next, $i);
            if ($val !== null) $label = $val;
            break;
        case '--devices':
            $testDevices = true; break;
        case '--dd-size':
            $val = consumeCliValue($val, $next, $i);
            if ($val !== null) $ddSize = $val; break;
        case '--device-runtime':
            $val = consumeCliValue($val, $next, $i);
            if ($val !== null) $devRuntime = (int)$val; break;
        case '--require-idle':
            $requireIdle = true; break;
        case '--idle-latency-ms':
            $val = consumeCliValue($val, $next, $i);
            if ($val !== null) $idleLatencyMs = (int)$val; break;
        case '--idle-util':
            $val = consumeCliValue($val, $next, $i);
            if ($val !== null) $idleUtilPct = (int)$val; break;
        case '--show-last':
            $showLast = true; break;
        case '--help': case '-h':
            usage(); exit(0);
    }
}

function parseSizeSB(string $s): int { if(preg_match('/^([0-9]+)([KMGTP]i?B?)?$/i',$s,$m)){ $n=(int)$m[1]; $u=strtolower($m[2]??''); return $u==='k'||$u==='kb'||$u==='kib'?$n*1024:($u==='m'||$u==='mb'||$u==='mib'?$n*1024*1024:($u==='g'||$u==='gb'||$u==='gib'?$n*1024*1024*1024:$n)); } return 0; }
function appendLog(string $path,array $j){ @file_put_contents($path,json_encode($j,JSON_UNESCAPED_SLASHES).PHP_EOL,FILE_APPEND|LOCK_EX); }
function iopingAvg(?string $target): ?float { $bin=trim((string) shell_exec('command -v ioping 2>/dev/null')); if($bin==='') return null; $cmd=escapeshellcmd($bin).' -c 10 -i 0.1 -D '.escapeshellarg($target).' 2>&1 | tail -n1'; $out=trim((string) shell_exec($cmd)); if(preg_match('/min\/avg\/max\/mdev\s*=\s*[^\/]+\/\s*([0-9.]+)\s*(us|ms|s)\s*\//i',$out,$m)){ $v=(float)$m[1]; $u=strtolower($m[2]); return $u==='us'?$v/1000.0:($u==='s'?$v*1000.0:$v);} return null; }

if ($showLast) {
    if (!is_file($jsonLog)) { fwrite(STDERR,"No log at {$jsonLog}\n"); exit(1);} 
    $lines = file($jsonLog, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) ?: [];
    $entries=[]; $lastId=''; $lastTs='';
    foreach ($lines as $line) { $j=json_decode($line,true); if (!is_array($j)) continue; if (!empty($j['run_id'])) { $entries[]=$j; if (($j['run_ts']??'')>$lastTs){$lastTs=$j['run_ts'];$lastId=$j['run_id'];}} }
    if ($lastId===''){ echo "No runs found.\n"; exit(0);} $run=array_values(array_filter($entries, function ($e) use ($lastId) { return (($e['run_id'] ?? '') === $lastId); }));
    $labelStr = (isset($run[0]['label']) && $run[0]['label']!=='') ? ('  Label: '.$run[0]['label']) : '';
    echo "\n== Storage benchmark (last run) ==\nRun ID: {$lastId}  Time: ".$run[0]['run_ts'].$labelStr."\n\n";
    foreach ($run as $e) { if (($e['test']??'')==='preflight-idle'){ echo "Preflight: ioping=".($e['ioping_avg_ms']??'n/a')." ms util=".($e['iostat_util_pct']??'n/a')."%\n\n"; break; } }
    echo "File-backed tests\n"; echo "test\tread_MB/s\twrite_MB/s\tread_IOPS\twrite_IOPS\tread_p95\twrite_p95\n";
    foreach ($run as $e){ if (isset($e['test']) && empty($e['device']) && ($e['params']['rw']??'')!=='') { $m=$e['metrics']??[]; printf("%s\t%.2f\t%.2f\t%.1f\t%.1f\t%.2f\t%.2f\n",$e['test'],$m['read_bw_MBps']??0,$m['write_bw_MBps']??0,$m['read_iops']??0,$m['write_iops']??0,$m['read_p95_ms']??0,$m['write_p95_ms']??0); } }
    echo "\nPer-device tests\n"; $devs=[]; foreach($run as $e){ if(isset($e['device'])) $devs[$e['device']][]=$e; }
    foreach ($devs as $dev=>$arr){ echo $dev."\n"; foreach ($arr as $e){ $t=$e['test']; $m=$e['metrics']??[]; if($t==='device-seqread-dd') printf("  %-18s seq_MB/s=%.2f t=%.2fs\n",$t,$m['seqread_MBps']??0,$m['elapsed_s']??0); elseif(strpos($t,'dev-randread')===0) printf("  %-18s read_MB/s=%.2f IOPS=%.1f p95=%.2fms\n",$t,$m['read_bw_MBps']??0,$m['read_iops']??0,$m['read_p95_ms']??0); elseif($t==='device-ioping') printf("  %-18s avg_ms=%.2f\n",$t,$m['ioping_avg_ms']??0);} }
    exit(0);
}

if (trim((string) shell_exec('command -v fio 2>/dev/null'))===''){ fwrite(STDERR,"Error: 'fio' not found.\n"); exit(1);} 
if (!is_dir($targetDir)||!is_writable($targetDir)){ fwrite(STDERR,"Error: target not writable: {$targetDir}\n"); exit(1);} 
if (!is_dir(dirname($jsonLog))) @mkdir(dirname($jsonLog),0755,true);

$runId=date('YmdHis').'-'.bin2hex(random_bytes(3)); $runTs=date('c');
$fs=trim((string) shell_exec('stat -f -c %T '.escapeshellarg($targetDir))); $mntDev=trim((string) shell_exec('df -P '.escapeshellarg($targetDir).' | awk ' . escapeshellarg('NR==2 {print $1}') ));

// Preflight
$pre = ['timestamp'=>$runTs,'label'=>$label?:null,'target_dir'=>$targetDir,'test'=>'preflight-idle','run_id'=>$runId,'run_ts'=>$runTs,'ok'=>true];
$pre['ioping_avg_ms']=iopingAvg($targetDir); if(($pre['ioping_avg_ms']??0)>$idleLatencyMs){ $pre['ok']=false; $pre['warn']='ioping above threshold'; }
if (is_file('/var/run/pmss/iostat')){ $arr=@unserialize((string)@file_get_contents('/var/run/pmss/iostat')); if(is_array($arr) && isset($arr['diskUtil'])){ $pre['iostat_util_pct']=(float)$arr['diskUtil']; if($pre['iostat_util_pct']>$idleUtilPct){ $pre['ok']=false; $pre['warn_util']='iostat util high'; } } }
appendLog($jsonLog,$pre); if($requireIdle && !$pre['ok']){ fwrite(STDERR,"Busy system (--require-idle): aborting.\n"); exit(2);} 

// File-backed tests
$requested=parseSizeSB($fileSize); $free=(int)trim((string) shell_exec('df -PB1 '.escapeshellarg($targetDir).' | awk ' . escapeshellarg('NR==2 {print $4}') )); $use=(int) min($requested>0?$requested:PHP_INT_MAX, floor($free*0.8)); if($use<=0){ fwrite(STDERR,"Insufficient free space.\n"); exit(1);} 
$testFile=rtrim($targetDir,'/').'/pmss-fio-'.bin2hex(random_bytes(4)).'.dat'; if(trim((string) shell_exec('command -v fallocate 2>/dev/null'))!=='') runCommand('fallocate -l '.$use.' '.escapeshellarg($testFile));
$tests=[ ['name'=>'randmix-large-95r5w','rw'=>'randrw','rwmixread'=>95,'bssplit'=>'4k/2:64k/3:128k/5:256k/10:512k/20:768k/25:1024k/35','iodepth'=>32,'numjobs'=>4,'direct'=>1], ['name'=>'randread-large','rw'=>'randread','bs'=>'1M','iodepth'=>32,'numjobs'=>4,'direct'=>1], ['name'=>'randread-small','rw'=>'randread','bs'=>'4k','iodepth'=>64,'numjobs'=>4,'direct'=>1], ['name'=>'randwrite-small-short','rw'=>'randwrite','bs'=>'4k','iodepth'=>32,'numjobs'=>2,'direct'=>1,'runtime'=>max(15,(int)floor($runtime/3))], ['name'=>'seqread-large','rw'=>'read','bs'=>'1M','iodepth'=>32,'numjobs'=>2,'direct'=>1] ];
 function fioRun(string $file,int $size,int $runtime,array $job): array { $json=sys_get_temp_dir().'/fio-'.bin2hex(random_bytes(4)).'.json'; $opts=['--name='.escapeshellarg($job['name']),'--filename='.escapeshellarg($file),'--size='.$size,'--time_based=1','--runtime='.(int)($job['runtime']??$runtime),'--rw='.escapeshellarg($job['rw']),'--ioengine=libaio','--iodepth='.(int)$job['iodepth'],'--numjobs='.(int)$job['numjobs'],'--direct='.(int)$job['direct'],'--group_reporting=1']; if(isset($job['bs'])) $opts[]='--bs='.escapeshellarg($job['bs']); if(isset($job['bssplit'])) $opts[]='--bssplit='.escapeshellarg($job['bssplit']); if(isset($job['rwmixread'])) $opts[]='--rwmixread='.(int)$job['rwmixread']; $cmd='fio --output-format=json --output '.escapeshellarg($json).' '.implode(' ',$opts); $rc=runCommand($cmd,true); $payload=@file_get_contents($json); @unlink($json); if($rc!==0 || $payload===false || trim($payload)==='') return ['ok'=>false,'error'=>'fio failed']; $j=json_decode($payload,true); if(!is_array($j)||empty($j['jobs'][0])) return ['ok'=>false,'error'=>'invalid fio JSON']; $rbw=0;$wbw=0;$ri=0;$wi=0;$rp=[];$wp=[]; foreach($j['jobs'] as $jobj){ $rbw+=(int)($jobj['read']['bw_bytes']??0); $wbw+=(int)($jobj['write']['bw_bytes']??0); $ri+=(float)($jobj['read']['iops']??0); $wi+=(float)($jobj['write']['iops']??0); $p95r=$jobj['read']['clat_ns']['percentile']['95.000000']??null; $p95w=$jobj['write']['clat_ns']['percentile']['95.000000']??null; if($p95r!==null) $rp[]=(float)$p95r; if($p95w!==null) $wp[]=(float)$p95w; } $avg=function($a){return count($a)?array_sum($a)/count($a):0;}; return ['ok'=>true,'result'=>['read_bw_MBps'=>round($rbw/(1024*1024),2),'write_bw_MBps'=>round($wbw/(1024*1024),2),'read_iops'=>round($ri,1),'write_iops'=>round($wi,1),'read_p95_ms'=>round($avg($rp)/1000000,2),'write_p95_ms'=>round($avg($wp)/1000000,2),'raw'=>$j]]; }
$summary=[]; foreach($tests as $job){ $res=fioRun($testFile,$use,$runtime,$job); $entry=['timestamp'=>$runTs,'label'=>$label?:null,'run_id'=>$runId,'run_ts'=>$runTs,'target_dir'=>$targetDir,'device'=>$mntDev,'filesystem'=>$fs,'test'=>$job['name'],'params'=>['rw'=>$job['rw'],'rwmixread'=>$job['rwmixread']??null,'bs'=>$job['bs']??null,'bssplit'=>$job['bssplit']??null,'iodepth'=>$job['iodepth'],'numjobs'=>$job['numjobs'],'direct'=>$job['direct'],'runtime'=>(int)($job['runtime']??$runtime),'size_bytes'=>$use],'ok'=>$res['ok']]; if($res['ok']){$entry['metrics']=$res['result']; $summary[]=[ $job['name'],$res['result']['read_bw_MBps'],$res['result']['write_bw_MBps'],$res['result']['read_iops'],$res['result']['write_iops'],$res['result']['read_p95_ms'],$res['result']['write_p95_ms'] ];} else {$entry['error']=$res['error']??'unknown';} appendLog($jsonLog,$entry);} @unlink($testFile);
echo "\n== Storage benchmark summary (".($label!==''?$label.' ':'')."on {$targetDir}) ==\n"; echo "test\tread_MB/s\twrite_MB/s\tread_IOPS\twrite_IOPS\tread_p95_ms\twrite_p95_ms\n"; foreach($summary as $row){ printf("%s\t%.2f\t%.2f\t%.1f\t%.1f\t%.2f\t%.2f\n",...$row);} echo "\nJSON log: {$jsonLog}\n";

// Per-device read-only
if($testDevices){ echo "\n== Per-device read-only benchmarks ==\n"; $ls=shell_exec('lsblk -dn -o KNAME,TYPE,ROTA,MODEL,SERIAL,SIZE 2>/dev/null'); $devs=[]; if($ls){ foreach(preg_split('/\r?\n/',trim($ls)) as $line){ if($line==='')continue; $p=preg_split('/\s+/',trim($line)); if(count($p)<3)continue; $k=$p[0]; $t=$p[1]; $rota=(int)$p[2]; if($t!=='disk')continue; if(strpos($k,'loop')===0||strpos($k,'ram')===0)continue; $path='/dev/'.$k; $sizeStr=$p[count($p)-1]??''; $serial=$p[count($p)-2]??''; $model=implode(' ',array_slice($p,3,max(0,count($p)-5))); $devs[]=['path'=>$path,'kname'=>$k,'rota'=>$rota,'model'=>$model,'serial'=>$serial,'size'=>$sizeStr]; } }
  $peer=[]; foreach($devs as $meta){ $path=$meta['path']; if(!is_readable($path)) continue; $size=(int)trim((string) shell_exec('blockdev --getsize64 '.escapeshellarg($path))); $count=(int) floor((parseSizeSB($ddSize))/(1024*1024)); $skip=0; if($size>($count*1024*1024+4*1024*1024)) $skip=random_int(0,(int)floor(($size-$count*1024*1024)/(1024*1024))); $dd=sprintf('dd if=%s of=/dev/null bs=1M count=%d skip=%d iflag=direct 2>&1',escapeshellarg($path),$count,$skip); [$rc,$so,$se]=[runCommand($dd,true),$GLOBALS['PMSS_LAST_COMMAND_OUTPUT']['stdout']??'',$GLOBALS['PMSS_LAST_COMMAND_OUTPUT']['stderr']??'']; $line=trim($se!==''?$se:$so); $mbps=null; $secs=null; if(preg_match('/\s([0-9.]+)\s+s,\s+([0-9.]+)\s+MB\/s/',$line,$m)){ $secs=(float)$m[1]; $mbps=(float)$m[2]; } $e=['timestamp'=>$runTs,'label'=>$label?:null,'run_id'=>$runId,'run_ts'=>$runTs,'device'=>$path,'model'=>$meta['model'],'serial'=>$meta['serial'],'rota'=>$meta['rota'],'size'=>$meta['size'],'test'=>'device-seqread-dd','params'=>['bs'=>'1M','count'=>$count,'skip_blocks'=>$skip],'ok'=>($rc===0&&$mbps!==null)]; if($mbps!==null)$e['metrics']=['seqread_MBps'=>$mbps,'elapsed_s'=>$secs]; else $e['error']='dd parse failed'; appendLog($jsonLog,$e); printf("%s\tdd_seqread_MB/s=%s\n",$path,$mbps!==null?number_format($mbps,2):'n/a'); $iop=iopingAvg($path); appendLog($jsonLog,['timestamp'=>$runTs,'label'=>$label?:null,'run_id'=>$runId,'run_ts'=>$runTs,'device'=>$path,'model'=>$meta['model'],'serial'=>$meta['serial'],'rota'=>$meta['rota'],'size'=>$meta['size'],'test'=>'device-ioping','ok'=>($iop!==null),'metrics'=>['ioping_avg_ms'=>round($iop??0,2)]]); if($iop!==null) printf("%s\tioping_avg_ms=%.2f\n",$path,$iop);
    foreach([ ['name'=>'dev-randread-4k','rw'=>'randread','bs'=>'4k','iodepth'=>64], ['name'=>'dev-randread-1M','rw'=>'randread','bs'=>'1M','iodepth'=>32] ] as $job){ $res=fioRun($path,$size,$devRuntime,['name'=>$job['name'],'rw'=>$job['rw'],'bs'=>$job['bs'],'iodepth'=>$job['iodepth'],'numjobs'=>1,'direct'=>1]); $ej=['timestamp'=>$runTs,'label'=>$label?:null,'run_id'=>$runId,'run_ts'=>$runTs,'device'=>$path,'model'=>$meta['model'],'serial'=>$meta['serial'],'rota'=>$meta['rota'],'size'=>$meta['size'],'test'=>$job['name'],'params'=>['rw'=>$job['rw'],'bs'=>$job['bs'],'iodepth'=>$job['iodepth'],'numjobs'=>1,'runtime'=>$devRuntime],'ok'=>$res['ok']]; if($res['ok']){$ej['metrics']=$res['result']; printf("%s\t%s\tread_MB/s=%.2f\tread_IOPS=%.1f\tread_p95_ms=%.2f\n",$path,$job['name'],$res['result']['read_bw_MBps'],$res['result']['read_iops'],$res['result']['read_p95_ms']);} else {$ej['error']=$res['error']??'fio failed';} appendLog($jsonLog,$ej); if($res['ok'] && $job['name']==='dev-randread-4k'){ $peer[$path]['fio4k_mb']=$res['result']['read_bw_MBps']; } }
    $peer[$path]['dd_mb']=$mbps; $peer[$path]['iop_ms']=$iop; }
  // Loose peer checks
  $get=function($peer,$k){$arr=[]; foreach($peer as $p=>$v){ if(isset($v[$k])&&$v[$k]!==null)$arr[]=$v[$k]; } return $arr;}; $median=function($a){ sort($a); $n=count($a); if($n===0)return 0.0; $m=(int)floor(($n-1)/2); return $n%2? (float)$a[$m] : (($a[$m]+$a[$m+1])/2); };
  $medDd=$median($get($peer,'dd_mb')); $medIop=$median($get($peer,'iop_ms')); $med4k=$median($get($peer,'fio4k_mb'));
  foreach($peer as $p=>$r){ if($medDd>0 && isset($r['dd_mb']) && $r['dd_mb']!==null && $r['dd_mb']<0.6*$medDd) echo "WARN: {$p} seqread < 60% median\n"; if($medIop>0 && isset($r['iop_ms']) && $r['iop_ms']!==null && $r['iop_ms']>max(50,2*$medIop)) echo "WARN: {$p} ioping > 2x median\n"; if($med4k>0 && isset($r['fio4k_mb']) && $r['fio4k_mb']!==null && $r['fio4k_mb']<0.5*$med4k) echo "WARN: {$p} 4k randread < 50% median\n"; }
}
