<?php
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);

if (!$body) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$entry = [
    'timestamp'   => date('Y-m-d H:i:s'),
    'ip'          => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '',
    'userAgent'   => $body['userAgent'] ?? '',
    'isFacebook'  => $body['isFacebook'] ?? false,
    'isInstagram' => $body['isInstagram'] ?? false,
    'isMessenger' => $body['isMessenger'] ?? false,
    'isAllowed'   => $body['isAllowed'] ?? false,
    'referrer'    => $body['referrer'] ?? null,
    'url'         => $body['url'] ?? '',
    'fbclid'      => $body['fbclid'] ?? null,
    'utmSource'   => $body['utmSource'] ?? '',
    'utmMedium'   => $body['utmMedium'] ?? '',
    'utmCampaign' => $body['utmCampaign'] ?? '',
    'utmContent'  => $body['utmContent'] ?? '',
    'utmTerm'     => $body['utmTerm'] ?? '',
    'screenWidth' => $body['screenWidth'] ?? null,
    'screenHeight'=> $body['screenHeight'] ?? null,
    'language'    => $body['language'] ?? '',
    'platform'    => $body['platform'] ?? '',
];

$logDir  = __DIR__ . '/../logs';
$logFile = $logDir . '/access.log';

if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

file_put_contents($logFile, json_encode($entry) . PHP_EOL, FILE_APPEND | LOCK_EX);

echo json_encode(['success' => true]);
