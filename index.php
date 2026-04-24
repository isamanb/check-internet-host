<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Tehran');

$reverseToken = 'CHANGE_THIS_TOKEN';

$targets = [
    ['id'=>'google', 'name'=>'Google', 'host'=>'www.google.com', 'url'=>'https://www.google.com/', 'port'=>443],
    ['id'=>'github', 'name'=>'GitHub', 'host'=>'github.com', 'url'=>'https://github.com/', 'port'=>443],
    ['id'=>'facebook', 'name'=>'Facebook', 'host'=>'www.facebook.com', 'url'=>'https://www.facebook.com/', 'port'=>443],
    ['id'=>'bale_docs', 'name'=>'Bale Docs', 'host'=>'docs.bale.ai', 'url'=>'https://docs.bale.ai/', 'port'=>443],
    ['id'=>'bale_api', 'name'=>'Bale API', 'host'=>'tapi.bale.ai', 'url'=>'https://tapi.bale.ai/', 'port'=>443],
    ['id'=>'vps1', 'name'=>'VPS 1 SSH', 'host'=>'107.175.193.137', 'url'=>null, 'port'=>22],
    ['id'=>'vps2', 'name'=>'VPS 2 SSH', 'host'=>'5.175.136.3', 'url'=>null, 'port'=>22],
];

function findTarget(array $targets, string $id): ?array {
    foreach ($targets as $t) {
        if ($t['id'] === $id) return $t;
    }
    return null;
}

function dnsCheck(string $host): array {
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return ['ok'=>true, 'msg'=>'IP - DNS skipped', 'time'=>0];
    }

    $start = microtime(true);
    $ip = gethostbyname($host);
    $time = round((microtime(true) - $start) * 1000);

    return [
        'ok'=>$ip !== $host,
        'msg'=>$ip !== $host ? "Resolved: $ip" : 'DNS FAIL',
        'time'=>$time
    ];
}

function tcpCheck(string $host, int $port, int $timeout = 3): array {
    $start = microtime(true);
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    $time = round((microtime(true) - $start) * 1000);

    if ($fp) {
        fclose($fp);
        return ['ok'=>true, 'msg'=>'Connected', 'time'=>$time];
    }

    return ['ok'=>false, 'msg'=>"Error $errno - $errstr", 'time'=>$time];
}

function latencyCheck(string $host, int $port): array {
    $tries = 2;
    $times = [];
    $success = 0;

    for ($i = 0; $i < $tries; $i++) {
        $r = tcpCheck($host, $port, 2);
        if ($r['ok']) {
            $success++;
            $times[] = $r['time'];
        }
    }

    if (!$times) {
        return ['ok'=>false, 'msg'=>"0/$tries successful", 'avg'=>null, 'min'=>null, 'max'=>null];
    }

    return [
        'ok'=>true,
        'msg'=>"$success/$tries successful",
        'avg'=>round(array_sum($times) / count($times), 2),
        'min'=>min($times),
        'max'=>max($times),
    ];
}

function httpCheck(?string $url): array {
    if (!$url) return ['ok'=>false, 'msg'=>'Skipped', 'time'=>0];

    if (!function_exists('curl_init')) {
        return ['ok'=>false, 'msg'=>'cURL disabled', 'time'=>0];
    }

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => 'LiveHostChecker/1.0',
    ]);

    $start = microtime(true);
    curl_exec($ch);
    $time = round((microtime(true) - $start) * 1000);

    $err = curl_errno($ch);
    $errMsg = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($err) {
        return ['ok'=>false, 'msg'=>"cURL $err - $errMsg", 'time'=>$time];
    }

    return [
        'ok'=>$code >= 200 && $code < 500,
        'msg'=>"HTTP $code",
        'time'=>$time
    ];
}

function canExec(): bool {
    if (!function_exists('shell_exec')) return false;
    $disabled = ini_get('disable_functions');
    if (!$disabled) return true;
    return stripos($disabled, 'shell_exec') === false;
}

function tracerouteCheck(string $host): array {
    if (!canExec()) {
        return ['ok'=>false, 'msg'=>'shell_exec disabled', 'output'=>''];
    }

    $safeHost = escapeshellarg($host);
    $cmd = stripos(PHP_OS, 'WIN') === 0
        ? "tracert -d -h 5 $safeHost"
        : "timeout 8 traceroute -n -m 5 $safeHost 2>&1";

    $output = shell_exec($cmd);

    return [
        'ok'=>!empty($output),
        'msg'=>!empty($output) ? 'Traceroute executed' : 'Traceroute failed or not installed',
        'output'=>trim((string)$output),
    ];
}

/*
|--------------------------------------------------------------------------
| Reverse endpoint
|--------------------------------------------------------------------------
*/

if (isset($_GET['reverse'])) {
    header('Content-Type: application/json; charset=utf-8');

    if (($_GET['token'] ?? '') !== $reverseToken) {
        http_response_code(403);
        echo json_encode(['ok'=>false, 'message'=>'Invalid token'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'ok'=>true,
        'message'=>'Reverse connection reached this host successfully',
        'time'=>date('Y-m-d H:i:s'),
        'remote_ip'=>$_SERVER['REMOTE_ADDR'] ?? null,
        'server_ip'=>$_SERVER['SERVER_ADDR'] ?? null,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

/*
|--------------------------------------------------------------------------
| AJAX test endpoint
|--------------------------------------------------------------------------
*/

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');

    $id = (string)($_GET['id'] ?? '');
    $trace = isset($_GET['trace']) && $_GET['trace'] === '1';

    $target = findTarget($targets, $id);

    if (!$target) {
        http_response_code(404);
        echo json_encode(['ok'=>false, 'message'=>'Target not found']);
        exit;
    }

    $dns = dnsCheck($target['host']);
    $tcp = tcpCheck($target['host'], (int)$target['port']);
    $latency = latencyCheck($target['host'], (int)$target['port']);
    $http = httpCheck($target['url']);

    $overall = $dns['ok'] && ($tcp['ok'] || $http['ok']);

    $result = [
        'id'=>$target['id'],
        'name'=>$target['name'],
        'host'=>$target['host'],
        'port'=>$target['port'],
        'dns'=>$dns,
        'tcp'=>$tcp,
        'latency'=>$latency,
        'http'=>$http,
        'overall'=>$overall,
    ];

    if ($trace) {
        $result['trace'] = tracerouteCheck($target['host']);
    }

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$currentUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'your-domain.com') . ($_SERVER['PHP_SELF'] ?? '/check.php');
$reverseUrl = $currentUrl . '?reverse=1&token=' . urlencode($reverseToken);

?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<title>تست لایو اتصال هاست</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body{font-family:tahoma,arial;background:#f4f6fa;color:#222;padding:20px;line-height:1.8}
.card{background:#fff;padding:18px;border-radius:14px;margin-bottom:20px;box-shadow:0 3px 14px rgba(0,0,0,.07)}
table{width:100%;border-collapse:collapse;margin-top:12px}
td,th{border:1px solid #ddd;padding:9px;vertical-align:top;font-size:14px}
th{background:#eef1f5}
.badge{display:inline-block;padding:3px 10px;border-radius:99px;color:#fff;font-size:12px;font-weight:bold}
.ok{background:#16a34a}
.fail{background:#dc2626}
.wait{background:#64748b}
.run{background:#2563eb}
code{direction:ltr;display:inline-block;background:#eef2f7;padding:2px 6px;border-radius:6px}
pre{direction:ltr;text-align:left;background:#111827;color:#e5e7eb;padding:12px;border-radius:10px;overflow:auto;font-size:13px}
.small{font-size:13px;color:#666}
button{padding:9px 14px;border:0;border-radius:8px;background:#2563eb;color:white;cursor:pointer}
button:hover{background:#1d4ed8}
.summary{font-size:18px;font-weight:bold}
</style>
</head>
<body>

<div class="card">
    <h2>تست لایو اتصال هاست</h2>

    <p class="summary" id="summary">در حال آماده‌سازی تست‌ها...</p>

    <button onclick="runAll(false)">اجرای تست سریع</button>
    <button onclick="runAll(true)">اجرای تست همراه Traceroute</button>

    <p class="small">
        تست سریع بلافاصله صفحه را نشان می‌دهد و نتیجه هر مقصد جداگانه داخل جدول پر می‌شود.
    </p>
</div>

<div class="card">
    <h2>نتایج زنده</h2>

    <table>
        <thead>
            <tr>
                <th>مقصد</th>
                <th>DNS</th>
                <th>TCP</th>
                <th>Latency</th>
                <th>HTTP</th>
                <th>نتیجه</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($targets as $t): ?>
            <tr id="row-<?php echo h($t['id']); ?>">
                <td>
                    <strong><?php echo h($t['name']); ?></strong><br>
                    <code><?php echo h($t['host']); ?>:<?php echo (int)$t['port']; ?></code>
                </td>
                <td id="dns-<?php echo h($t['id']); ?>"><span class="badge wait">WAIT</span></td>
                <td id="tcp-<?php echo h($t['id']); ?>"><span class="badge wait">WAIT</span></td>
                <td id="latency-<?php echo h($t['id']); ?>"><span class="badge wait">WAIT</span></td>
                <td id="http-<?php echo h($t['id']); ?>"><span class="badge wait">WAIT</span></td>
                <td id="overall-<?php echo h($t['id']); ?>"><span class="badge wait">WAIT</span></td>
            </tr>
            <tr id="trace-row-<?php echo h($t['id']); ?>" style="display:none">
                <td colspan="6">
                    <strong>Traceroute <?php echo h($t['name']); ?></strong>
                    <pre id="trace-<?php echo h($t['id']); ?>"></pre>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="card">
    <h2>Reverse Test: تست VPS به هاست ایران</h2>

    <p>روی VPS این دستور را بزن:</p>

    <pre>curl "<?php echo h($reverseUrl); ?>"</pre>

    <p>اگر خروجی شامل <code>"ok": true</code> بود، یعنی VPS به هاست ایران وصل می‌شود.</p>
</div>

<script>
const targets = <?php echo json_encode($targets, JSON_UNESCAPED_UNICODE); ?>;

function badge(ok, text) {
    return `<span class="badge ${ok ? 'ok' : 'fail'}">${text}</span>`;
}

function running() {
    return `<span class="badge run">RUNNING</span>`;
}

function waiting() {
    return `<span class="badge wait">WAIT</span>`;
}

function setCell(id, part, html) {
    document.getElementById(part + '-' + id).innerHTML = html;
}

function ms(t) {
    return `<br><span class="small">${t} ms</span>`;
}

async function testOne(target, trace = false) {
    const id = target.id;

    setCell(id, 'dns', running());
    setCell(id, 'tcp', running());
    setCell(id, 'latency', running());
    setCell(id, 'http', running());
    setCell(id, 'overall', running());

    const url = `?ajax=1&id=${encodeURIComponent(id)}&trace=${trace ? '1' : '0'}`;

    try {
        const res = await fetch(url, {cache: 'no-store'});
        const data = await res.json();

        setCell(id, 'dns',
            badge(data.dns.ok, data.dns.ok ? 'OK' : 'FAIL') +
            '<br>' + data.dns.msg + ms(data.dns.time)
        );

        setCell(id, 'tcp',
            badge(data.tcp.ok, data.tcp.ok ? 'OK' : 'FAIL') +
            '<br>' + data.tcp.msg + ms(data.tcp.time)
        );

        let latencyText = data.latency.msg;
        if (data.latency.ok) {
            latencyText += `<br>Avg: ${data.latency.avg} ms<br>Min: ${data.latency.min} ms<br>Max: ${data.latency.max} ms`;
        }

        setCell(id, 'latency',
            badge(data.latency.ok, data.latency.ok ? 'OK' : 'FAIL') +
            '<br>' + latencyText
        );

        setCell(id, 'http',
            badge(data.http.ok, data.http.ok ? 'OK' : 'FAIL') +
            '<br>' + data.http.msg + ms(data.http.time)
        );

        setCell(id, 'overall',
            badge(data.overall, data.overall ? 'OK' : 'FAIL')
        );

        if (trace && data.trace) {
            document.getElementById('trace-row-' + id).style.display = '';
            document.getElementById('trace-' + id).textContent =
                data.trace.msg + "\n\n" + (data.trace.output || '');
        }

        return data.overall === true;

    } catch (e) {
        setCell(id, 'dns', badge(false, 'ERROR'));
        setCell(id, 'tcp', badge(false, 'ERROR'));
        setCell(id, 'latency', badge(false, 'ERROR'));
        setCell(id, 'http', badge(false, 'ERROR'));
        setCell(id, 'overall', badge(false, 'ERROR'));
        return false;
    }
}

async function runAll(trace = false) {
    let success = 0;
    let done = 0;

    document.getElementById('summary').textContent = 'تست‌ها شروع شدند...';

    for (const t of targets) {
        setCell(t.id, 'dns', waiting());
        setCell(t.id, 'tcp', waiting());
        setCell(t.id, 'latency', waiting());
        setCell(t.id, 'http', waiting());
        setCell(t.id, 'overall', waiting());
        document.getElementById('trace-row-' + t.id).style.display = 'none';
    }

    const promises = targets.map(async (t) => {
        const ok = await testOne(t, trace);
        done++;
        if (ok) success++;

        document.getElementById('summary').textContent =
            `انجام شده: ${done} از ${targets.length} | موفق: ${success}`;

        return ok;
    });

    await Promise.all(promises);

    document.getElementById('summary').textContent =
        `پایان تست | موفق: ${success} از ${targets.length}`;
}

window.addEventListener('load', () => {
    runAll(false);
});
</script>

</body>
</html>
