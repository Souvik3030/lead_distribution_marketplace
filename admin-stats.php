<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

// Load environment variables from .env
require_once __DIR__ . '/env-loader.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$secret = getenv('ADMIN_STATS_SECRET') ?: '';
if ($secret === '') {
    http_response_code(503);
    echo json_encode(['error' => 'not configured']);
    exit;
}

$key = $_GET['key'] ?? '';
if (!hash_equals($secret, $key)) {
    http_response_code(401);
    @error_log(date('c') . ' admin-stats unauth from ' . ($_SERVER['REMOTE_ADDR'] ?? '?') . "\n", 3, '/var/log/csv-lead-import-agent-assignment-admin-stats.log');
    echo json_encode(['error' => 'unauthorized']);
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

$totalPortals = 0;
$activePortals = 0;
$deadPortals = 0;
$byPlan = [];
$installs7d = 0;
$installs30d = 0;

$now = time();
$sevenDaysAgo = $now - (7 * 24 * 60 * 60);
$thirtyDaysAgo = $now - (30 * 24 * 60 * 60);

$portalsList = [];

foreach ($portals as $domain => $portal) {
    $totalPortals++;

    // Status check
    $status = $portal['status'] ?? 'active';
    if ($status === 'active') {
        $activePortals++;
    } else {
        $deadPortals++;
    }

    // Plan counts
    $plan = $portal['plan'] ?? 'free';
    $byPlan[$plan] = ($byPlan[$plan] ?? 0) + 1;

    // Install time checking
    $installedAt = $portal['installed_at'] ?? '';
    if ($installedAt) {
        $installedTs = strtotime($installedAt);
        if ($installedTs !== false) {
            if ($installedTs >= $sevenDaysAgo) {
                $installs7d++;
            }
            if ($installedTs >= $thirtyDaysAgo) {
                $installs30d++;
            }
        }
    }

    // Construct safe portal output (no credentials!)
    $safePortal = [
        'domain' => $domain,
        'installed_at' => $portal['installed_at'] ?? '',
        'installer' => null,
        'member_id' => $portal['member_id'] ?? '',
        'language' => $portal['language'] ?? 'en',
        'app_version' => $portal['app_version'] ?? '1.0.0',
        'plan' => $plan,
        'status' => $status,
        'sources_configured' => $portal['sources_configured'] ?? [],
        'last_synced_at' => $portal['last_synced_at'] ?? (object) [],
        'events_processed_total' => $portal['events_processed_total'] ?? (object) [],
        'events_this_month' => (int) ($portal['events_this_month'] ?? 0),
        'errors_last_24h' => (int) ($portal['errors_last_24h'] ?? 0),
        'token_expires_at' => $portal['token_expires_at'] ?? ''
    ];

    if (isset($portal['installer']) && is_array($portal['installer'])) {
        $safePortal['installer'] = [
            'name' => trim(($portal['installer']['name'] ?? '') . ' ' . ($portal['installer']['last_name'] ?? '')),
            'email' => $portal['installer']['email'] ?? '',
            'position' => $portal['installer']['position'] ?? '',
            'phone' => $portal['installer']['phone'] ?? ''
        ];
    }

    $portalsList[] = $safePortal;
}

// Recent events
$recentEvents = [];
$eventsFile = __DIR__ . '/events.json';
if (file_exists($eventsFile)) {
    $rawEvents = file_get_contents($eventsFile);
    if ($rawEvents !== false) {
        $recentEvents = json_decode($rawEvents, true) ?: [];
    }
}

$response = [
    'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'app' => 'csv-lead-import-agent-assignment',
    'summary' => [
        'total_portals' => $totalPortals,
        'active_portals' => $activePortals,
        'dead_portals' => $deadPortals,
        'by_plan' => $byPlan,
        'installs_last_7_days' => $installs7d,
        'installs_last_30_days' => $installs30d
    ],
    'portals' => $portalsList,
    'recent_events' => $recentEvents
];

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
