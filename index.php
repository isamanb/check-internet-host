<?php
declare(strict_types=1);

/**
 * Internet Connectivity Tester for cPanel Hosts
 * Targets:
 * - Google
 * - GitHub
 * - Facebook
 * - Bale Docs
 * - Bale API Host
 *
 * نکته:
 * این اسکریپت فقط اتصال شبکه را بررسی می‌کند.
 * اگر یک مقصد fail شود، همیشه به معنی قطع بودن اینترنت نیست؛
 * ممکن است آن دامنه از سمت هاست، فایروال، DNS یا provider محدود شده باشد.
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Tehran');

$targets = [
    [
        'name' => 'Google',
        'host' => 'www.google.com',
        'url'  => 'https://www.google.com/',
        'port' => 443,
    ],
    [
        'name' => 'GitHub',
        'host' => 'github.com',
        'url'  => 'https://github.com/',
        'port' => 443,
    ],
    [
        'name' => 'Facebook',
        'host' => 'www.facebook.com',
        'url'  => 'https://www.facebook.com/',
        'port' => 443,
    ],
    [
        'name' => 'Bale Docs',
        'host' => 'docs.bale.ai',
        'url'  => 'https://docs.bale.ai/',
        'port' => 443,
    ],
    [
        'name' => 'Bale API Host',
        'host' => 'tapi.bale.ai',
        'url'  => 'https://tapi.bale.ai/',
        'port' => 443,
    ],
];

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function boolBadge(bool $ok): string
{
    return $ok
        ? '<span class="badge ok">OK</span>'
        : '<span class="badge fail">FAIL</span>';
}

function dnsResolve(string $host): array
{
    $start = microtime(true);
    $ip = gethostbyname($host);
    $timeMs = (int) round((microtime(true) - $start) * 1000);

    $ok = ($ip !== $host);

    return [
        'ok' => $ok,
        'message' => $ok ? "Resolved to {$ip}" : 'DNS resolution failed',
        'time_ms' => $timeMs,
        'ip' => $ok ? $ip : null,
    ];
}

function tcpConnect(string $host, int $port = 443, int $timeout = 8): array
{
    $errno = 0;
    $errstr = '';

    $start = microtime(true);
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    $timeMs = (int) round((microtime(true) - $start) * 1000);

    if ($fp) {
        fclose($fp);
        return [
            'ok' => true,
            'message' => "TCP connection to {$host}:{$port} succeeded",
            'time_ms' => $timeMs,
        ];
    }

    return [
        'ok' => false,
        'message' => "TCP failed ({$errno}) {$errstr}",
        'time_ms' => $timeMs,
    ];
}

function httpCheck(string $url, int $timeout = 12): array
{
    if (!function_exists('curl_init')) {
        return [
            'ok' => false,
            'message' => 'cURL extension is not enabled on this host',
            'time_ms' => 0,
            'http_code' => null,
            'final_url' => null,
        ];
    }

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_NOBODY => true,              // HEAD request
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'HostConnectivityChecker/1.0',
        CURLOPT_HEADER => false,
    ]);

    $start = microtime(true);
    curl_exec($ch);
    $timeMs = (int) round((microtime(true) - $start) * 1000);

    $errNo = curl_errno($ch);
    $errMsg = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

    curl_close($ch);

    if ($errNo !== 0) {
        return [
            'ok' => false,
            'message' => "cURL error ({$errNo}) {$errMsg}",
            'time_ms' => $timeMs,
            'http_code' => null,
            'final_url' => $finalUrl ?: null,
        ];
    }

    $ok = ($httpCode >= 200 && $httpCode < 500);
    // 403 / 405 / 301 / 302 هم برای تست اتصال می‌تواند نشانه reachable بودن باشد.

    return [
        'ok' => $ok,
        'message' => "HTTP response code: {$httpCode}",
        'time_ms' => $timeMs,
        'http_code' => $httpCode,
        'final_url' => $finalUrl ?: null,
    ];
}

function testTarget(array $target): array
{
    $dns = dnsResolve($target['host']);
    $tcp = tcpConnect($target['host'], (int)$target['port']);
    $http = httpCheck($target['url']);

    $overall = $dns['ok'] && ($tcp['ok'] || $http['ok']);

    return [
        'target' => $target,
        'dns' => $dns,
        'tcp' => $tcp,
        'http' => $http,
        'overall' => $overall,
    ];
}

$results = [];
foreach ($targets as $target) {
    $results[] = testTarget($target);
}

$successCount = 0;
foreach ($results as $row) {
    if ($row['overall']) {
        $successCount++;
    }
}

$internetLikelyOk = $successCount >= 2;
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>تست اتصال اینترنت هاست</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body{
            font-family: Tahoma, Arial, sans-serif;
            background:#f5f7fb;
            color:#222;
            margin:0;
            padding:20px;
        }
        .container{
            max-width:1100px;
            margin:0 auto;
        }
        .card{
            background:#fff;
            border-radius:14px;
            box-shadow:0 4px 18px rgba(0,0,0,.08);
            padding:18px;
            margin-bottom:20px;
        }
        h1,h2,h3{
            margin-top:0;
        }
        .summary{
            font-size:18px;
            line-height:1.9;
        }
        .ok-text{color:#0a8a42;font-weight:bold;}
        .fail-text{color:#c62828;font-weight:bold;}
        table{
            width:100%;
            border-collapse:collapse;
            margin-top:10px;
            background:#fff;
        }
        th, td{
            border:1px solid #e5e7eb;
            padding:10px;
            text-align:right;
            vertical-align:top;
            font-size:14px;
        }
        th{
            background:#f0f4f8;
        }
        .badge{
            display:inline-block;
            padding:4px 10px;
            border-radius:999px;
            color:#fff;
            font-size:12px;
            font-weight:bold;
        }
        .badge.ok{background:#16a34a;}
        .badge.fail{background:#dc2626;}
        .muted{
            color:#666;
            font-size:13px;
        }
        code{
            background:#eef2f7;
            padding:2px 6px;
            border-radius:6px;
            direction:ltr;
            display:inline-block;
        }
    </style>
</head>
<body>
<div class="container">

    <div class="card">
        <h1>تست اتصال اینترنت هاست</h1>
        <div class="summary">
            زمان اجرا:
            <strong><?php echo h(date('Y-m-d H:i:s')); ?></strong>
            <br><br>

            نتیجه کلی:
            <?php if ($internetLikelyOk): ?>
                <span class="ok-text">به احتمال زیاد هاست به اینترنت دسترسی دارد.</span>
            <?php else: ?>
                <span class="fail-text">اتصال اینترنت هاست ضعیف است یا برقرار نیست.</span>
            <?php endif; ?>

            <br><br>
            تعداد مقصدهای موفق:
            <strong><?php echo (int)$successCount; ?></strong>
            از
            <strong><?php echo count($results); ?></strong>
        </div>
    </div>

    <div class="card">
        <h2>جزئیات بررسی</h2>

        <table>
            <thead>
            <tr>
                <th>سرویس</th>
                <th>DNS</th>
                <th>TCP</th>
                <th>HTTP/HTTPS</th>
                <th>وضعیت نهایی</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($results as $item): ?>
                <tr>
                    <td>
                        <strong><?php echo h($item['target']['name']); ?></strong><br>
                        <span class="muted"><?php echo h($item['target']['host']); ?></span><br>
                        <span class="muted"><?php echo h($item['target']['url']); ?></span>
                    </td>
                    <td>
                        <?php echo boolBadge($item['dns']['ok']); ?><br>
                        <?php echo h($item['dns']['message']); ?><br>
                        <span class="muted"><?php echo (int)$item['dns']['time_ms']; ?> ms</span>
                    </td>
                    <td>
                        <?php echo boolBadge($item['tcp']['ok']); ?><br>
                        <?php echo h($item['tcp']['message']); ?><br>
                        <span class="muted"><?php echo (int)$item['tcp']['time_ms']; ?> ms</span>
                    </td>
                    <td>
                        <?php echo boolBadge($item['http']['ok']); ?><br>
                        <?php echo h($item['http']['message']); ?><br>
                        <?php if (!empty($item['http']['final_url'])): ?>
                            <span class="muted">Final URL: <?php echo h($item['http']['final_url']); ?></span><br>
                        <?php endif; ?>
                        <span class="muted"><?php echo (int)$item['http']['time_ms']; ?> ms</span>
                    </td>
                    <td>
                        <?php echo boolBadge($item['overall']); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <p class="muted" style="margin-top:15px;">
            توجه: Fail شدن Facebook یا بعضی سرویس‌ها لزوماً به معنی نداشتن اینترنت نیست؛
            بعضی هاست‌ها، دیتاسنترها یا فایروال‌ها برخی دامنه‌ها را محدود می‌کنند.
        </p>
    </div>

    <div class="card">
        <h2>اطلاعات محیط PHP</h2>
        <table>
            <tbody>
            <tr>
                <th>PHP Version</th>
                <td><?php echo h(PHP_VERSION); ?></td>
            </tr>
            <tr>
                <th>cURL enabled</th>
                <td><?php echo function_exists('curl_init') ? 'Yes' : 'No'; ?></td>
            </tr>
            <tr>
                <th>OpenSSL</th>
                <td><?php echo defined('OPENSSL_VERSION_TEXT') ? h(OPENSSL_VERSION_TEXT) : 'Unknown'; ?></td>
            </tr>
            <tr>
                <th>Server IP</th>
                <td><?php echo h($_SERVER['SERVER_ADDR'] ?? 'Unknown'); ?></td>
            </tr>
            <tr>
                <th>Server Name</th>
                <td><?php echo h($_SERVER['SERVER_NAME'] ?? 'Unknown'); ?></td>
            </tr>
            </tbody>
        </table>
    </div>

</div>
</body>
</html>
