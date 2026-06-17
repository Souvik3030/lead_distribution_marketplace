<?php
// CORS Headers for Bitrix24 cross-origin requests
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Global error handler: return PHP errors as JSON instead of blank 500
set_error_handler(function($severity, $message, $file, $line) {
    http_response_code(500);
    header("Content-Type: application/json");
    echo json_encode([
        "error"    => "PHP Error",
        "message"  => $message,
        "severity" => $severity,
        "file"     => basename($file),
        "line"     => $line
    ]);
    exit;
});
set_exception_handler(function($e) {
    http_response_code(500);
    header("Content-Type: application/json");
    echo json_encode([
        "error"   => "PHP Exception",
        "message" => $e->getMessage(),
        "file"    => basename($e->getFile()),
        "line"    => $e->getLine()
    ]);
    exit;
});

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Handle API POST requests from the client app
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Read JSON input first so action can be resolved from body
    $input = file_get_contents("php://input");
    $data  = is_string($input) ? json_decode($input, true) : null;

    // Action can come from ?action= (query string) OR from the JSON body field.
    // Guard against PHP 8 TypeError: null['action'] is illegal in PHP 8.0+
    $bodyAction = (is_array($data) && isset($data['action'])) ? $data['action'] : null;
    $action     = $_GET['action'] ?? $bodyAction;

    // Set JSON content type now that we know this is an API call path
    header("Content-Type: application/json");

    if ($action) {
    if (!is_array($data) || empty($data['domain'])) {
        http_response_code(400);
        echo json_encode(["error" => "Bad Request: Missing required fields"]);
        exit;
    }

    
    if ($action === 'register') {
        $dbFile = __DIR__ . "/registrations.json";
        
        // Read existing registrations
        $registrations = [];
        if (file_exists($dbFile)) {
            $content = file_get_contents($dbFile);
            $registrations = json_decode($content, true) ?: [];
        }
        
        $domain = strtolower(trim($data['domain']));
        
        // Build registration entry
        $entry = [
            "domain" => $domain,
            "name" => trim($data['name'] ?? 'Unknown'),
            "email" => trim($data['email'] ?? ''),
            "phone" => trim($data['phone'] ?? ''),
            "position" => trim($data['position'] ?? ''),
            "installed_at" => $data['installed_at'] ?? date(DATE_ATOM),
            "updated_at" => date(DATE_ATOM)
        ];
        
        // Update or insert
        $found = false;
        foreach ($registrations as &$reg) {
            if (strtolower($reg['domain']) === $domain) {
                $reg = array_merge($reg, $entry);
                $found = true;
                break;
            }
        }
        if (!$found) {
            $registrations[] = $entry;
        }
        
        // Save back
        if (@file_put_contents($dbFile, json_encode($registrations, JSON_PRETTY_PRINT)) !== false) {
            echo json_encode(["success" => true]);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Failed to write registrations.json — check directory permissions"]);
        }
        exit;
    } 
    elseif ($action === 'log-sync') {
        $dbFile = __DIR__ . "/usage_logs.json";
        
        // Read existing logs
        $logs = [];
        if (file_exists($dbFile)) {
            $content = file_get_contents($dbFile);
            $logs = json_decode($content, true) ?: [];
        }
        
        // Build log entry
        $entry = [
            "domain" => trim($data['domain']),
            "source" => trim($data['source'] ?? 'unknown'),
            "success_count" => (int)($data['success_count'] ?? 0),
            "error_count" => (int)($data['error_count'] ?? 0),
            "timestamp" => date(DATE_ATOM)
        ];
        
        // Append to log (keep last 1000 items to prevent file bloat)
        array_unshift($logs, $entry);
        if (count($logs) > 1000) {
            $logs = array_slice($logs, 0, 1000);
        }
        
        // Save back
        if (@file_put_contents($dbFile, json_encode($logs, JSON_PRETTY_PRINT)) !== false) {
            echo json_encode(["success" => true]);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Failed to write usage_logs.json — check directory permissions"]);
        }
        exit;
    }
    
    http_response_code(400);
    echo json_encode(["error" => "Invalid action"]);
    exit;
    } // end if ($action)
}

// Simple Session-based Admin Authentication
session_start();

$configPath = __DIR__ . "/config.php";
$config = [];
if (file_exists($configPath)) {
    $config = require $configPath;
} else {
    $configPathExample = __DIR__ . "/config.example.php";
    if (file_exists($configPathExample)) {
        $config = require $configPathExample;
    }
}

$secretKey = $config['secret_key'] ?? 'YOUR_SUPER_SECRET_KEY_HERE';

if (isset($_GET['logout'])) {
    unset($_SESSION['admin_key']);
    header("Location: admin-stats.php");
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['access_key'])) {
    if ($_POST['access_key'] === $secretKey) {
        $_SESSION['admin_key'] = $secretKey;
        header("Location: admin-stats.php");
        exit;
    } else {
        $error = "Invalid Secret Key!";
    }
}

$authorized = false;
if (isset($_SESSION['admin_key']) && $_SESSION['admin_key'] === $secretKey) {
    $authorized = true;
} elseif (isset($_GET['key']) && $_GET['key'] === $secretKey) {
    $_SESSION['admin_key'] = $secretKey;
    $authorized = true;
}

if (!$authorized):
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VortexWeb Telemetry Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-slate-950 min-h-screen flex items-center justify-center p-6 text-slate-100">
    <div class="w-full max-w-md bg-slate-900 border border-slate-800 rounded-2xl p-8 shadow-2xl relative overflow-hidden">
        <div class="absolute -top-16 -right-16 w-32 h-32 bg-blue-600 rounded-full blur-3xl opacity-30"></div>
        <div class="absolute -bottom-16 -left-16 w-32 h-32 bg-indigo-600 rounded-full blur-3xl opacity-30"></div>

        <div class="text-center mb-8">
            <h1 class="text-2xl font-bold tracking-tight bg-clip-text text-transparent bg-gradient-to-r from-blue-400 to-indigo-400">VortexWeb Telemetry</h1>
            <p class="text-slate-400 text-xs mt-2">Enter secret access key to view app statistics</p>
        </div>

        <?php if ($error): ?>
            <div class="mb-6 bg-red-500/10 border border-red-500/20 text-red-400 text-sm p-4 rounded-xl text-center">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="admin-stats.php" class="space-y-6">
            <div>
                <label for="access_key" class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Secret Key</label>
                <input type="password" name="access_key" id="access_key" required placeholder="••••••••••••••••"
                       class="w-full px-4 py-3 bg-slate-950 border border-slate-800 rounded-xl text-slate-200 placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all font-mono">
            </div>
            <button type="submit" class="w-full py-3 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white rounded-xl font-semibold shadow-lg shadow-blue-500/20 transition-all focus:outline-none focus:ring-2 focus:ring-blue-500">
                Access Dashboard
            </button>
        </form>
    </div>
</body>
</html>
<?php
exit;
endif;

// Dashboard Data Loading
$regFile = __DIR__ . "/registrations.json";
$registrations = [];
if (file_exists($regFile)) {
    $registrations = json_decode(file_get_contents($regFile), true) ?: [];
}

$logFile = __DIR__ . "/usage_logs.json";
$logs = [];
if (file_exists($logFile)) {
    $logs = json_decode(file_get_contents($logFile), true) ?: [];
}

// Calculations
$totalInstalls = count($registrations);
$totalLogs = count($logs);

$totalSuccess = 0;
$totalErrors = 0;
foreach ($logs as $log) {
    $totalSuccess += $log['success_count'] ?? 0;
    $totalErrors += $log['error_count'] ?? 0;
}

// Sort registrations by installed_at desc
usort($registrations, function($a, $b) {
    return strcmp($b['installed_at'] ?? '', $a['installed_at'] ?? '');
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VortexWeb Telemetry Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-slate-950 text-slate-200 min-h-screen pb-12">
    <nav class="border-b border-slate-900 bg-slate-950/80 backdrop-blur sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-6 h-16 flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <div class="w-8 h-8 rounded-lg bg-gradient-to-tr from-blue-600 to-indigo-600 flex items-center justify-center font-bold text-white shadow-md shadow-blue-500/20">V</div>
                <span class="font-bold text-lg text-white tracking-tight">VortexWeb Telemetry Dashboard</span>
            </div>
            <a href="admin-stats.php?logout=1" class="text-xs font-semibold text-slate-400 hover:text-white px-4 py-2 border border-slate-800 rounded-lg hover:bg-slate-900 transition-all">Logout</a>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto px-6 mt-8">
        <!-- Stats Summary cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="bg-slate-900 border border-slate-900 p-6 rounded-2xl shadow-sm relative overflow-hidden">
                <div class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Total Installations</div>
                <div class="text-3xl font-bold text-white mt-2"><?= $totalInstalls ?></div>
                <div class="absolute right-4 bottom-4 text-slate-800 font-bold text-5xl select-none opacity-20">📥</div>
            </div>
            <div class="bg-slate-900 border border-slate-900 p-6 rounded-2xl shadow-sm relative overflow-hidden">
                <div class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Sync Log Count</div>
                <div class="text-3xl font-bold text-white mt-2"><?= $totalLogs ?></div>
                <div class="absolute right-4 bottom-4 text-slate-800 font-bold text-5xl select-none opacity-20">📊</div>
            </div>
            <div class="bg-slate-900 border border-slate-900 p-6 rounded-2xl shadow-sm relative overflow-hidden">
                <div class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Successful Imports</div>
                <div class="text-3xl font-bold text-green-500 mt-2"><?= $totalSuccess ?></div>
                <div class="absolute right-4 bottom-4 text-slate-800 font-bold text-5xl select-none opacity-20">✓</div>
            </div>
            <div class="bg-slate-900 border border-slate-900 p-6 rounded-2xl shadow-sm relative overflow-hidden">
                <div class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Failed Imports</div>
                <div class="text-3xl font-bold text-red-500 mt-2"><?= $totalErrors ?></div>
                <div class="absolute right-4 bottom-4 text-slate-800 font-bold text-5xl select-none opacity-20">✗</div>
            </div>
        </div>

        <!-- Section Selection tabs -->
        <div class="border-b border-slate-900 mb-6 flex justify-between items-center flex-wrap gap-4">
            <nav class="flex space-x-6 -mb-px">
                <button onclick="switchTab('installs')" id="tab-installs" class="border-b-2 border-blue-500 text-blue-500 font-semibold px-1 py-4 text-sm transition-all focus:outline-none">Installer Profiles</button>
                <button onclick="switchTab('logs')" id="tab-logs" class="border-b-2 border-transparent text-slate-400 hover:text-slate-200 font-semibold px-1 py-4 text-sm transition-all focus:outline-none">Usage Activity Logs</button>
            </nav>
            <div class="flex items-center space-x-2 pb-2">
                <input type="text" id="table-search" placeholder="Search data..." oninput="handleSearch()"
                       class="px-4 py-2 bg-slate-900 border border-slate-800 rounded-lg text-sm placeholder-slate-500 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 w-64 text-slate-200">
            </div>
        </div>

        <!-- Installs Section -->
        <div id="section-installs" class="bg-slate-900 rounded-2xl border border-slate-900 overflow-hidden shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-950 text-left">
                    <thead class="bg-slate-950 text-xs font-semibold uppercase tracking-wider text-slate-400">
                        <tr>
                            <th class="px-6 py-4">Domain</th>
                            <th class="px-6 py-4">User Details</th>
                            <th class="px-6 py-4">Position</th>
                            <th class="px-6 py-4">First Installed At</th>
                            <th class="px-6 py-4">Last Sync</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-950 search-rows">
                        <?php if (empty($registrations)): ?>
                            <tr>
                                <td colspan="5" class="px-6 py-12 text-center text-slate-500">No installations registered yet.</td>
                            </tr>
                        <?php else: foreach ($registrations as $reg): ?>
                            <tr class="hover:bg-slate-950/40 transition-colors search-row">
                                <td class="px-6 py-4 text-sm font-semibold text-white search-val"><?= htmlspecialchars($reg['domain']) ?></td>
                                <td class="px-6 py-4 text-sm search-val">
                                    <div class="font-medium text-slate-200"><?= htmlspecialchars($reg['name']) ?></div>
                                    <div class="text-xs text-slate-400"><?= htmlspecialchars($reg['email']) ?></div>
                                    <?php if (!empty($reg['phone'])): ?>
                                        <div class="text-xs text-slate-400">📞 <?= htmlspecialchars($reg['phone']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 text-sm text-slate-300 search-val"><?= htmlspecialchars($reg['position'] ?: '-') ?></td>
                                <td class="px-6 py-4 text-xs text-slate-400"><?= isset($reg['installed_at']) ? date("Y-m-d H:i:s", strtotime($reg['installed_at'])) : '-' ?></td>
                                <td class="px-6 py-4 text-xs text-slate-400"><?= isset($reg['updated_at']) ? date("Y-m-d H:i:s", strtotime($reg['updated_at'])) : '-' ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Logs Section -->
        <div id="section-logs" class="bg-slate-900 rounded-2xl border border-slate-900 overflow-hidden shadow-sm hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-950 text-left">
                    <thead class="bg-slate-950 text-xs font-semibold uppercase tracking-wider text-slate-400">
                        <tr>
                            <th class="px-6 py-4">Domain (Hashed)</th>
                            <th class="px-6 py-4">Source/SPA ID</th>
                            <th class="px-6 py-4">Import Successes</th>
                            <th class="px-6 py-4">Import Failures</th>
                            <th class="px-6 py-4">Sync Time</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-950 search-rows">
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="5" class="px-6 py-12 text-center text-slate-500">No usage logs recorded yet.</td>
                            </tr>
                        <?php else: foreach ($logs as $log): ?>
                            <tr class="hover:bg-slate-950/40 transition-colors search-row">
                                <td class="px-6 py-4 text-sm text-slate-300 font-mono text-xs search-val"><?= htmlspecialchars($log['domain']) ?></td>
                                <td class="px-6 py-4 text-sm text-slate-300 search-val"><?= htmlspecialchars($log['source']) ?></td>
                                <td class="px-6 py-4 text-sm text-green-500 font-semibold"><?= (int)$log['success_count'] ?></td>
                                <td class="px-6 py-4 text-sm text-red-500 font-semibold"><?= (int)$log['error_count'] ?></td>
                                <td class="px-6 py-4 text-xs text-slate-400"><?= isset($log['timestamp']) ? date("Y-m-d H:i:s", strtotime($log['timestamp'])) : '-' ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <script>
        let currentTab = 'installs';

        function switchTab(tab) {
            currentTab = tab;
            const tabInstalls = document.getElementById('tab-installs');
            const tabLogs = document.getElementById('tab-logs');
            const secInstalls = document.getElementById('section-installs');
            const secLogs = document.getElementById('section-logs');

            if (tab === 'installs') {
                tabInstalls.className = "border-b-2 border-blue-500 text-blue-500 font-semibold px-1 py-4 text-sm transition-all focus:outline-none";
                tabLogs.className = "border-b-2 border-transparent text-slate-400 hover:text-slate-200 font-semibold px-1 py-4 text-sm transition-all focus:outline-none";
                secInstalls.classList.remove('hidden');
                secLogs.classList.add('hidden');
            } else {
                tabLogs.className = "border-b-2 border-blue-500 text-blue-500 font-semibold px-1 py-4 text-sm transition-all focus:outline-none";
                tabInstalls.className = "border-b-2 border-transparent text-slate-400 hover:text-slate-200 font-semibold px-1 py-4 text-sm transition-all focus:outline-none";
                secLogs.classList.remove('hidden');
                secInstalls.classList.add('hidden');
            }
            
            // Re-apply search on active tab
            handleSearch();
        }

        function handleSearch() {
            const query = document.getElementById('table-search').value.toLowerCase().trim();
            const targetSection = currentTab === 'installs' ? 'section-installs' : 'section-logs';
            const rows = document.querySelectorAll('#' + targetSection + ' .search-row');

            rows.forEach(row => {
                let textMatch = false;
                const vals = row.querySelectorAll('.search-val');
                vals.forEach(val => {
                    if (val.textContent.toLowerCase().includes(query)) {
                        textMatch = true;
                    }
                });
                if (!query || textMatch) {
                    row.classList.remove('hidden');
                } else {
                    row.classList.add('hidden');
                }
            });
        }
    </script>
</body>
</html>
