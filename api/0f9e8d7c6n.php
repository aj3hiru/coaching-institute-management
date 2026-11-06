<?php
date_default_timezone_set('Asia/Kolkata');
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

$pid = (int)($_POST['id'] ?? 0);
if (!$pid) exit;

$cDir = $_SERVER['DOCUMENT_ROOT'] . '/cj_smart_cache';
if (!file_exists($cDir)) mkdir($cDir, 0755, true);

$cFile = $cDir . '/blog_views.json';
$tFile = $cDir . '/views_tracking.json';
$dFile = $cDir . '/daily_performance.json';
$fFile = $cDir . '/flush_trigger.json';

function secure_json_modify($filepath, callable $callback) {
    $fp = fopen($filepath, 'c+');
    if (!$fp) return [];
    
    if (flock($fp, LOCK_EX)) {
        $size = fstat($fp)['size'];
        $content = $size > 0 ? fread($fp, $size) : '{}';
        $data = json_decode($content, true);
        if (!is_array($data)) $data = [];
        
        $data = $callback($data);
        
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
    return $data;
}

$vData = secure_json_modify($cFile, function($data) use ($pid) {
    if (!isset($data[$pid])) $data[$pid] = 0;
    $data[$pid]++;
    return $data;
});

$fData = secure_json_modify($fFile, function($data) {
    if (!isset($data['last_flush'])) $data['last_flush'] = 0;
    return $data;
});

$now = time();
$lastFlush = $fData['last_flush'];
$hrsSince = ($now - $lastFlush) / 3600;
$currentViews = $vData[$pid] ?? 0;

if ($currentViews >= 10 || $hrsSince >= 24) {
    
    if ($currentViews >= 10) {
        try {
            $stmt = $pdo->prepare("INSERT INTO post_views (views, post_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE views = views + VALUES(views)");
            $stmt->execute([$currentViews, $pid]);
            
            secure_json_modify($cFile, function($data) use ($pid) {
                unset($data[$pid]);
                return $data;
            });
        } catch (PDOException $e) {
            error_log($e->getMessage());
        }
    }

    if ($hrsSince >= 24) {
        $finalData = secure_json_modify($cFile, function($data) {
            return $data; 
        });

        if (!empty($finalData)) {
            $stmt = $pdo->prepare("INSERT INTO post_views (views, post_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE views = views + VALUES(views)");
            $flushedKeys = [];
            
            foreach ($finalData as $p => $cnt) {
                if ($cnt > 0) {
                    try {
                        $stmt->execute([$cnt, $p]);
                        $flushedKeys[] = $p;
                    } catch (PDOException $e) {
                        if ($e->errorInfo[1] == 1452) $flushedKeys[] = $p;
                    }
                }
            }

            if (!empty($flushedKeys)) {
                secure_json_modify($cFile, function($data) use ($flushedKeys) {
                    foreach ($flushedKeys as $k) unset($data[$k]);
                    return $data;
                });
            }
        }

        secure_json_modify($fFile, function($data) use ($now) {
            $data['last_flush'] = $now;
            return $data;
        });
    }
}

secure_json_modify($tFile, function($data) use ($pid) {
    $hr = date('Y-m-d H:00:00');
    if (!isset($data[$hr])) $data[$hr] = [];
    if (!isset($data[$hr][$pid])) $data[$hr][$pid] = 0;
    $data[$hr][$pid]++;
    
    $cutoff = strtotime('-24 hours');
    foreach ($data as $h => $val) {
        if (strtotime($h) < $cutoff) unset($data[$h]);
    }
    return $data;
});

secure_json_modify($dFile, function($data) use ($pid) {
    $dt = date('Y-m-d');
    if (!isset($data[$dt])) $data[$dt] = [];
    if (!isset($data[$dt][$pid])) $data[$dt][$pid] = 0;
    $data[$dt][$pid]++;
    
    $cutoff = strtotime('-7 days');
    foreach ($data as $d => $val) {
        if (strtotime($d) < $cutoff) unset($data[$d]);
    }
    return $data;
});

// Per-day source/country stats + unique visitors (Dashboard & Analytics).
try {
    require_once INCLUDES_PATH . '/stats.php';
    edu_stats_record($pdo, $pid, (string)($_POST['r'] ?? ''), (string)($_POST['q'] ?? ''));
} catch (Throwable $e) {
    error_log('stats: ' . $e->getMessage());
}
