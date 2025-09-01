<?php
// verify-otp.php
// POST JSON { "session_id":"...", "otp":"1234" }
// Response: {status:"success", message:"OTP verified"}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

$raw = file_get_contents('php://input');
$input = $raw ? json_decode($raw, true) : $_POST;

$session_id = $input['session_id'] ?? '';
$otp_submitted = isset($input['otp']) ? (string)$input['otp'] : '';

if (!$session_id || !$otp_submitted) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'session_id and otp required']);
    exit;
}

$base = __DIR__ . '/../data';
$sessionFile = $base . '/' . $session_id . '.json';
if (!file_exists($sessionFile)) {
    http_response_code(404);
    echo json_encode(['status'=>'error','message'=>'Session not found or expired']);
    exit;
}

$session = json_decode(file_get_contents($sessionFile), true);
if (!$session) {
    unlink($sessionFile);
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'Session corrupted']);
    exit;
}

// expiration (5 minutes)
if (time() - intval($session['ts']) > 300) {
    unlink($sessionFile);
    http_response_code(410);
    echo json_encode(['status'=>'error','message'=>'OTP expired']);
    exit;
}

// tries limit
$session['tries'] = isset($session['tries']) ? intval($session['tries']) : 0;
if ($session['tries'] >= 5) {
    unlink($sessionFile);
    http_response_code(429);
    echo json_encode(['status'=>'error','message'=>'Too many attempts']);
    exit;
}

if (hash_equals((string)$session['otp'], $otp_submitted)) {
    // success -> remove session file to prevent reuse
    unlink($sessionFile);
    echo json_encode(['status'=>'success','message'=>'OTP verified']);
    exit;
} else {
    $session['tries']++;
    file_put_contents($sessionFile, json_encode($session));
    http_response_code(401);
    echo json_encode(['status'=>'error','message'=>'Incorrect OTP']);
    exit;
}
