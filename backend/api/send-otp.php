<?php
// send-otp.php
// POST JSON { "phone":"8250560727" }
// Response: {status:"success", message:"OTP sent", session_id:"..."}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

$raw = file_get_contents('php://input');
$input = $raw ? json_decode($raw, true) : $_POST;

$phone = $input['phone'] ?? '';
$phone_digits = preg_replace('/\D+/', '', $phone); // digits only

// Normalize: accept 10-digit Indian numbers, or full international e.g. 919XXXXXXXXX
if (strlen($phone_digits) === 10) {
    $phone_digits = '91' . $phone_digits;
} elseif (strlen($phone_digits) === 12 && substr($phone_digits,0,2) === '91') {
    // ok
} else {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'Invalid phone. Use 10-digit (India) or include country code.']);
    exit;
}

try {
    $otp = random_int(1000, 9999);
} catch (Exception $e) {
    $otp = rand(1000, 9999);
}

$session_id = bin2hex(random_bytes(8));
$base = __DIR__ . '/../data';
if (!is_dir($base)) mkdir($base, 0755, true);

// rate-limit: one send per phone per 30 seconds
$phoneFile = $base . '/phone_' . $phone_digits . '.json';
if (file_exists($phoneFile)) {
    $meta = json_decode(file_get_contents($phoneFile), true);
    if ($meta && isset($meta['last_sent']) && (time() - $meta['last_sent']) < 30) {
        http_response_code(429);
        echo json_encode(['status'=>'error','message'=>'Wait before requesting a new OTP (30s).']);
        exit;
    }
}

// save session file
$sessionFile = $base . '/' . $session_id . '.json';
$sessionData = [
    'phone' => $phone_digits,
    'otp'   => (string)$otp,
    'ts'    => time(),
    'tries' => 0
];
file_put_contents($sessionFile, json_encode($sessionData));

// update phone meta
$meta = ['last_sent' => time(), 'sent_count' => (isset($meta['sent_count']) ? $meta['sent_count']+1 : 1)];
file_put_contents($phoneFile, json_encode($meta));

// forward to Node forwarder
$forwarder = getenv('FORWARDER_URL') ?: 'http://localhost:3000/api/forward';
$payload = json_encode(['phone'=>$phone_digits, 'otp'=>$otp]);

$ch = curl_init($forwarder);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
$response = curl_exec($ch);
$curlErr = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// we return success even if forwarder is not ready — but attach forwarder result
$out = ['status'=>'success','message'=>'OTP created','session_id'=>$session_id];
if ($response === false) {
    $out['forwarder'] = 'error: '.$curlErr;
} else {
    $out['forwarder_http'] = $httpCode;
    $out['forwarder_response'] = json_decode($response, true);
}

echo json_encode($out);
