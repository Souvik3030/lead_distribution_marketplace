<?php
declare(strict_types=1);

// Disable error display in production
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

// The installation handler receives the ONAPPINSTALL event.
// Bitrix24 sends parameters via POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$domain = $_POST['auth']['domain'] ?? null;
$accessToken = $_POST['auth']['access_token'] ?? null;
$memberId = $_POST['auth']['member_id'] ?? null;

if (!$domain || !$accessToken) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing auth parameters']);
    exit;
}

// Extract language from POST if available, default to 'en'
$lang = $_POST['auth']['lang'] ?? 'en';

// App version constant
define('APP_VERSION', '1.0.0');

// Fetch user.current using curl
$installer = null;
try {
    $url = 'https://' . $domain . '/rest/user.current?auth=' . $accessToken;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $response = curl_exec($ch);
    if ($response === false) {
        $errorMsg = curl_error($ch);
        error_log('install.php: user.current request failed: ' . $errorMsg);
    } else {
        $resData = json_decode((string)$response, true);
        if (isset($resData['result'])) {
            $user = $resData['result'];
            $installer = [
                'id' => (string)($user['ID'] ?? ''),
                'name' => (string)($user['NAME'] ?? ''),
                'last_name' => (string)($user['LAST_NAME'] ?? ''),
                'email' => (string)($user['EMAIL'] ?? ''),
                'position' => (string)($user['WORK_POSITION'] ?? ''),
                'phone' => (string)($user['PERSONAL_PHONE'] ?? $user['WORK_PHONE'] ?? '')
            ];
        } else {
            $errorDesc = $resData['error_description'] ?? 'unknown error';
            error_log('install.php: user.current API error: ' . $errorDesc);
        }
    }
    curl_close($ch);
} catch (Throwable $e) {
    error_log('install.php: Exception fetching user.current: ' . $e->getMessage());
}

// Determine backend base URL dynamically
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? '';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$backendBaseUrl = $protocol . $host . dirname($scriptName) . '/';

// Save backend URL to Bitrix24 application options
try {
    $url = 'https://' . $domain . '/rest/app.option.set?auth=' . $accessToken;
    $postData = http_build_query([
        'options' => [
            'backend_url' => $backendBaseUrl
        ]
    ]);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $response = curl_exec($ch);
    if ($response === false) {
        $errorMsg = curl_error($ch);
        error_log('install.php: app.option.set request failed: ' . $errorMsg);
    } else {
        $resData = json_decode((string)$response, true);
        if (!isset($resData['result'])) {
            $errorDesc = $resData['error_description'] ?? 'unknown error';
            error_log('install.php: app.option.set API error: ' . $errorDesc);
        }
    }
    curl_close($ch);
} catch (Throwable $e) {
    error_log('install.php: Exception setting app option: ' . $e->getMessage());
}


if ($installer === null) {
    $installer = [
        'id' => null,
        'name' => null,
        'last_name' => null,
        'email' => null,
        'position' => null,
        'phone' => null
    ];
}

// Read existing portals database (JSON file)
$dbFile = __DIR__ . '/portals.json';
$portals = [];
if (file_exists($dbFile)) {
    $raw = file_get_contents($dbFile);
    if ($raw !== false) {
        $portals = json_decode($raw, true) ?: [];
    }
}

// Update or create portal entry
$portals[$domain] = [
    'installed_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'installer' => $installer,
    'member_id' => $memberId,
    'language' => $lang,
    'app_version' => APP_VERSION,
    'plan' => 'free', // Default plan
    'token_expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + (int)($_POST['auth']['expires_in'] ?? 3600)),
    'status' => 'active'
];

// Append install event to recent events log
$eventsFile = __DIR__ . '/events.json';
$events = [];
if (file_exists($eventsFile)) {
    $raw = file_get_contents($eventsFile);
    if ($raw !== false) {
        $events = json_decode($raw, true) ?: [];
    }
}
array_unshift($events, [
    'ts' => gmdate('Y-m-d\TH:i:s\Z'),
    'domain' => $domain,
    'event' => 'installed'
]);
// Limit recent events list to last 100 entries
if (count($events) > 100) {
    $events = array_slice($events, 0, 100);
}

// Save back to JSON files (thread-safe lock)
file_put_contents($dbFile, json_encode($portals, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
file_put_contents($eventsFile, json_encode($events, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

echo json_encode(['success' => true]);
