<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

// CORS headers to allow requests from Bitrix24
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

// Handle OPTIONS preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// Read raw POST body as JSON
$rawInput = file_get_contents('php://input');
$data = json_decode((string)$rawInput, true);

$domain = $data['domain'] ?? null;
$source = $data['source'] ?? null;
$successCount = isset($data['success_count']) ? (int)$data['success_count'] : 0;
$errorCount = isset($data['error_count']) ? (int)$data['error_count'] : 0;

if (!$domain || !$source) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing domain or source']);
    exit;
}

$dbFile = __DIR__ . '/portals.json';
$portals = [];
if (file_exists($dbFile)) {
    $raw = file_get_contents($dbFile);
    if ($raw !== false) {
        $portals = json_decode($raw, true) ?: [];
    }
}

// If this portal has never been seen before, create a skeleton entry.
// installed_at and installer are intentionally left null — only install.php
// sets those when the real ONAPPINSTALL event fires from Bitrix24.
// This keeps the app functional even if install.php fires after the first sync.
if (!isset($portals[$domain])) {
    $portals[$domain] = [
        'installed_at'  => null,   // Will be set by install.php on real install
        'installer'     => null,   // Will be set by install.php on real install
        'member_id'     => '',
        'language'      => 'en',
        'app_version'   => '1.0.0',
        'plan'          => 'free',
        'token_expires_at' => null,
        'status'        => 'active'
    ];
}

$portal = &$portals[$domain];

// 1. sources_configured
if (!isset($portal['sources_configured']) || !is_array($portal['sources_configured'])) {
    $portal['sources_configured'] = [];
}
if (!in_array($source, $portal['sources_configured'], true)) {
    $portal['sources_configured'][] = $source;
}

// 2. last_synced_at
if (!isset($portal['last_synced_at']) || !is_array($portal['last_synced_at'])) {
    $portal['last_synced_at'] = [];
}
$portal['last_synced_at'][$source] = gmdate('Y-m-d\TH:i:s\Z');

// 3. events_processed_total
if (!isset($portal['events_processed_total']) || !is_array($portal['events_processed_total'])) {
    $portal['events_processed_total'] = [];
}
$currentTotal = isset($portal['events_processed_total'][$source]) ? (int)$portal['events_processed_total'][$source] : 0;
$portal['events_processed_total'][$source] = $currentTotal + $successCount;

// 4. events_this_month
$portal['events_this_month'] = (isset($portal['events_this_month']) ? (int)$portal['events_this_month'] : 0) + $successCount;

// 5. errors_last_24h
$portal['errors_last_24h'] = (isset($portal['errors_last_24h']) ? (int)$portal['errors_last_24h'] : 0) + $errorCount;

// Save back to portals database
file_put_contents($dbFile, json_encode($portals, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

// Append event log
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
    'event' => 'sync_completed'
]);
if (count($events) > 100) {
    $events = array_slice($events, 0, 100);
}
file_put_contents($eventsFile, json_encode($events, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

echo json_encode(['success' => true]);
