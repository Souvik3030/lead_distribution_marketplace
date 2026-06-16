<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$secret = null;

// Load custom secret key from config.php if it exists
$configPath = __DIR__ . '/config.php';
if (file_exists($configPath)) {
    $config = include $configPath;
    if (is_array($config) && isset($config['secret_key'])) {
        $secret = $config['secret_key'];
    }
}

if (!$secret) {
    http_response_code(500);
    echo json_encode(['error' => 'Secret key configuration missing. Please configure config.php.']);
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

// Installer breakdown lists
$installersAllTime = [];
$installers30d = [];
$installers7d = [];

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

    // Install time checking — only count portals registered via install.php
    // (installed_at is null for skeleton entries created by log-sync).
    $installedAt = $portal['installed_at'] ?? null;
    $installedTs = $installedAt ? strtotime($installedAt) : false;
    $isRealInstall = ($installedTs !== false);

    if ($isRealInstall) {
        if ($installedTs >= $sevenDaysAgo) {
            $installs7d++;
        }
        if ($installedTs >= $thirtyDaysAgo) {
            $installs30d++;
        }
    }

    // Build installer record — only for portals confirmed via install.php
    if ($isRealInstall) {
        $installerInfo = [
            'domain'       => $domain,
            'installed_at' => $installedAt,
            'name'         => '',
            'email'        => '',
            'phone'        => '',
            'position'     => '',
        ];
        if (isset($portal['installer']) && is_array($portal['installer'])) {
            $installerInfo['name']     = trim(($portal['installer']['name'] ?? '') . ' ' . ($portal['installer']['last_name'] ?? ''));
            $installerInfo['email']    = $portal['installer']['email'] ?? '';
            $installerInfo['phone']    = $portal['installer']['phone'] ?? '';
            $installerInfo['position'] = $portal['installer']['position'] ?? '';
        }

        $installersAllTime[] = $installerInfo;
        if ($installedTs >= $thirtyDaysAgo) {
            $installers30d[] = $installerInfo;
        }
        if ($installedTs >= $sevenDaysAgo) {
            $installers7d[] = $installerInfo;
        }
    }

    // Construct safe portal output (no credentials!)
    $safePortal = [
        'domain'                 => $domain,
        'installed_at'           => $installedAt,
        'installer'              => null,
        'member_id'              => $portal['member_id'] ?? '',
        'language'               => $portal['language'] ?? 'en',
        'app_version'            => $portal['app_version'] ?? '1.0.0',
        'plan'                   => $plan,
        'status'                 => $status,
        'sources_configured'     => $portal['sources_configured'] ?? [],
        'last_synced_at'         => $portal['last_synced_at'] ?? (object) [],
        'events_processed_total' => $portal['events_processed_total'] ?? (object) [],
        'events_this_month'      => (int) ($portal['events_this_month'] ?? 0),
        'errors_last_24h'        => (int) ($portal['errors_last_24h'] ?? 0),
        'token_expires_at'       => $portal['token_expires_at'] ?? ''
    ];

    if (isset($portal['installer']) && is_array($portal['installer'])) {
        $safePortal['installer'] = [
            'name'     => $installerInfo['name'],
            'email'    => $installerInfo['email'],
            'position' => $installerInfo['position'],
            'phone'    => $installerInfo['phone']
        ];
    }

    $portalsList[] = $safePortal;
}

// Sort installer lists newest-first
$sortByDate = fn($a, $b) => strcmp($b['installed_at'], $a['installed_at']);
usort($installersAllTime, $sortByDate);
usort($installers30d,     $sortByDate);
usort($installers7d,      $sortByDate);

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
    'app'          => 'csv-lead-import-agent-assignment',
    'summary'      => [
        'total_portals'          => $totalPortals,
        'active_portals'         => $activePortals,
        'dead_portals'           => $deadPortals,
        'by_plan'                => $byPlan,
        'installs_last_7_days'   => $installs7d,
        'installs_last_30_days'  => $installs30d
    ],
    'installers' => [
        'all_time'     => ['count' => count($installersAllTime), 'users' => $installersAllTime],
        'last_30_days' => ['count' => count($installers30d),     'users' => $installers30d],
        'last_7_days'  => ['count' => count($installers7d),      'users' => $installers7d],
    ],
    'portals'       => $portalsList,
    'recent_events' => $recentEvents
];

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
