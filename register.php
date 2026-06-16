<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$rawInput = file_get_contents('php://input');
$data = json_decode((string)$rawInput, true);

$domain   = $data['domain']   ?? null;
$memberId = $data['member_id'] ?? '';
$lang     = $data['language']  ?? 'en';
$installer = $data['installer'] ?? null; // {name, last_name, email, position, phone}

if (!$domain) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing domain']);
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

// Create skeleton if portal has never been seen at all
if (!isset($portals[$domain])) {
    $portals[$domain] = [
        'installed_at'  => null,
        'installer'     => null,
        'member_id'     => $memberId,
        'language'      => $lang,
        'app_version'   => '1.0.0',
        'plan'          => 'free',
        'token_expires_at' => null,
        'status'        => 'active'
    ];
}

// Only fill in installed_at and installer if they are still null
// (skeleton from log-sync, or install.php never fired).
// Never overwrite an already-set installed_at — that would corrupt history.
if ($portals[$domain]['installed_at'] === null) {
    $portals[$domain]['installed_at'] = gmdate('Y-m-d\TH:i:s\Z');
    $portals[$domain]['installer']    = $installer;
    $portals[$domain]['member_id']    = $memberId;
    $portals[$domain]['language']     = $lang;
}

// Always update status to active (in case it was marked dead)
$portals[$domain]['status'] = 'active';

try {
    @file_put_contents($dbFile, json_encode($portals, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
} catch (Throwable $e) {
    error_log('register.php: Failed to write portals.json: ' . $e->getMessage());
}

echo json_encode(['success' => true, 'registered' => ($portals[$domain]['installed_at'] !== null)]);
