<?php
// proxy.php - استریم پایدار با تفکیک هدر و رفع خطای headers already sent
@ini_set('memory_limit', '512M');
@set_time_limit(0);

// هدرهای پایه CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, HEAD, OPTIONS");
header("Access-Control-Allow-Headers: Range, Content-Type, Accept-Encoding");
header("Access-Control-Expose-Headers: Content-Range, Content-Length, Accept-Ranges");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$videoUrl = $_GET['url'] ?? '';
if (!$videoUrl || !filter_var($videoUrl, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    exit('Invalid URL');
}

$parsed = parse_url($videoUrl);
$host = $parsed['host'] ?? '';
$scheme = $parsed['scheme'] ?? 'https';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $videoUrl);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_AUTOREFERER, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
curl_setopt($ch, CURLOPT_TIMEOUT, 0);
curl_setopt($ch, CURLOPT_BUFFERSIZE, 131072);

$headers = [
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    'Accept: */*',
    'Accept-Encoding: identity',
    'Referer: ' . $scheme . '://' . $host . '/'
];

$hasRange = false;
if (isset($_SERVER['HTTP_RANGE'])) {
    $headers[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
    $hasRange = true;
}

curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

// پردازش هدرهای مبدأ و ست کردن کد وضعیت قبل از ارسال بادی
curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $header) use ($hasRange) {
    $len = strlen($header);
    $trimmed = trim($header);

    // دریافت اولین خط هدر و ست کردن مستقیم HTTP Code
    if (preg_match('#^HTTP/[\d.]+\s+(\d+)#i', $trimmed, $matches)) {
        $code = (int)$matches[1];
        if ($hasRange && $code === 200) {
            $code = 206; // مپ به Partial Content برای باز شدن تایم‌لاین مرورگر
        }
        http_response_code($code);
        return $len;
    }

    $parts = explode(':', $header, 2);
    if (count($parts) === 2) {
        $name = strtolower(trim($parts[0]));
        $val = trim($parts[1]);

        if ($name === 'content-type') {
            header("Content-Type: video/mp4");
            return $len;
        }

        if (in_array($name, ['content-length', 'content-range', 'accept-ranges', 'content-disposition'])) {
            header("$name: $val");
        }
    }
    return $len;
});

header("Accept-Ranges: bytes");

curl_exec($ch);
curl_close($ch);