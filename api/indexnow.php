<?php
/**
 * IndexNow Auto-Submit API
 * Called whenever new content is published to notify Bing + other IndexNow engines
 * Usage: POST /api/indexnow.php with JSON body {"urls": ["https://snapcaptioner.com/..."]}
 * Or GET /api/indexnow.php?submit=all to submit all pages
 */

header('Content-Type: application/json');

$INDEXNOW_KEY = '925e0fb1b52b4447a37399ace037960d';
$SITE_HOST = 'snapcaptioner.com';

$ALL_URLS = [
    'https://snapcaptioner.com/',
    'https://snapcaptioner.com/index.html',
    'https://snapcaptioner.com/pricing.html',
    'https://snapcaptioner.com/blog.html',
    'https://snapcaptioner.com/app.html',
    'https://snapcaptioner.com/login.html',
    'https://snapcaptioner.com/signup.html',
    'https://snapcaptioner.com/chrome-extension.html',
    'https://snapcaptioner.com/contact.html',
    'https://snapcaptioner.com/privacy.html',
    'https://snapcaptioner.com/terms.html',
    'https://snapcaptioner.com/analytics.html',
];

function submitToIndexNow($urls, $key, $host) {
    $payload = json_encode([
        'host' => $host,
        'key' => $key,
        'keyLocation' => "https://{$host}/{$key}.txt",
        'urlList' => $urls
    ]);

    $ch = curl_init('https://api.indexnow.org/indexnow');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json; charset=utf-8',
            'Content-Length: ' . strlen($payload)
        ],
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['http_code' => $httpCode, 'response' => $response];
}

// Handle GET ?submit=all
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['submit']) && $_GET['submit'] === 'all') {
    $result = submitToIndexNow($ALL_URLS, $INDEXNOW_KEY, $SITE_HOST);
    echo json_encode([
        'success' => in_array($result['http_code'], [200, 202]),
        'http_code' => $result['http_code'],
        'urls_submitted' => count($ALL_URLS),
        'message' => $result['http_code'] === 200 || $result['http_code'] === 202
            ? 'Successfully submitted all URLs to IndexNow'
            : 'Submission failed: HTTP ' . $result['http_code']
    ]);
    exit;
}

// Handle POST with custom URLs
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $urls = $body['urls'] ?? [];
    if (empty($urls)) {
        http_response_code(400);
        echo json_encode(['error' => 'No URLs provided']);
        exit;
    }
    $result = submitToIndexNow($urls, $INDEXNOW_KEY, $SITE_HOST);
    echo json_encode([
        'success' => in_array($result['http_code'], [200, 202]),
        'http_code' => $result['http_code'],
        'urls_submitted' => count($urls)
    ]);
    exit;
}

echo json_encode(['error' => 'Invalid request. Use GET?submit=all or POST with {"urls":[...]}']);
