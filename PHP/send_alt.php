<?php
// CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  header('Access-Control-Allow-Origin: *');
  header('Access-Control-Allow-Headers: Content-Type');
  header('Access-Control-Allow-Methods: POST, OPTIONS');
  exit;
}

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=utf-8");

// ===== 계정/템플릿 설정 =====
$accountId    = 'cu03247';  // ★ account는 *문자열*
$authKey      = '625b430045660862ab66697ef05b78965a46c546602b782dea0d24ad37a77777';
$senderProfile= '@cu03247'; // ★ 카카오 채널(@ 포함)
$templateCode = 'ppur_2025082210453220034558875'; // 실제 템플릿코드

$TOKEN_URL = 'https://message.ppurio.com/v1/token';
$SEND_URL  = 'https://message.ppurio.com/v1/kakao';
$TOKEN_FILE = __DIR__ . '/token.json';
$TOKEN_TTL  = 55 * 60; // 55분 캐시

// ===== 유틸 =====
function formatPhone($num) {
  return preg_replace('/[^0-9]/', '', (string)$num);
}

// ===== 토큰 로드/발급 =====
function getNewToken($accountId, $authKey, $tokenFile, $TOKEN_URL) {
  $credentials = base64_encode($accountId . ':' . $authKey);

  $ch = curl_init($TOKEN_URL);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      "Authorization: Basic $credentials",
      "Content-Type: application/json; charset=utf-8",
    ],
    CURLOPT_POSTFIELDS => '{}',
  ]);
  $res = curl_exec($ch);
  $err = curl_error($ch);
  $code= curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($err) {
    http_response_code(500);
    echo json_encode(["code"=>"9500","description"=>"토큰 요청 오류","error"=>$err], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $result = json_decode($res, true);
  if ($code !== 200 || !isset($result['token'])) {
    http_response_code(500);
    echo json_encode(["code"=>"9501","description"=>"토큰 응답 오류","http"=>$code,"response"=>$res], JSON_UNESCAPED_UNICODE);
    exit;
  }

  file_put_contents($tokenFile, json_encode([
    'token' => $result['token'],
    'created_at' => time(),
  ], JSON_UNESCAPED_UNICODE));

  return $result['token'];
}

function loadToken($accountId, $authKey, $tokenFile, $TOKEN_URL, $ttl) {
  if (file_exists($tokenFile)) {
    $saved = json_decode(file_get_contents($tokenFile), true);
    $token = $saved['token'] ?? '';
    $created = (int)($saved['created_at'] ?? 0);
    if ($token && (time() - $created) < $ttl) return $token;
  }
  return getNewToken($accountId, $authKey, $tokenFile, $TOKEN_URL);
}

// ===== 입력 =====
$input = json_decode(file_get_contents("php://input"), true);
$name = trim($input['name'] ?? '');
$to   = trim($input['to'] ?? '');
$var1 = trim($input['var1'] ?? ''); // 제목
$var2 = trim($input['var2'] ?? ''); // 회신1 (앞 55 bytes)
$var3 = trim($input['var3'] ?? ''); // 발신직원
$var4 = trim($input['var4'] ?? ''); // 회신2 (뒤 50 bytes)

// 필수값 검증
if (!$name || !$to || !$var1 || !$var2 || !$var3) {
  echo json_encode(["code"=>"9000","description"=>"❌ 필수 입력 누락"], JSON_UNESCAPED_UNICODE);
  exit;
}

// ===== 템플릿 변수 매핑 (var1=제목, var2=회신1, var3=발신직원, var4=회신2) =====
$targets = [[
  "to" => formatPhone($to),
  "name" => $name,
  "changeWord" => [
    "var1" => $var1, // 제목
    "var2" => $var2, // 회신1
    "var3" => $var3, // 발신직원
    "var4" => $var4, // 회신2
  ]
]];

// ===== 발송 함수 =====
function sendAlimtalk($token, $targets, $accountId, $senderProfile, $templateCode, $SEND_URL) {
  $payload = [
    "account"       => (string)$accountId,   // ★ 반드시 문자열
    "messageType"   => "ALT",
    "senderProfile" => $senderProfile,
    "templateCode"  => $templateCode,
    "duplicateFlag" => "N",
    "isResend"      => "N",
    "targetCount"   => count($targets),
    "targets"       => $targets,
    "refKey"        => "notify_" . time()
  ];

  $ch = curl_init($SEND_URL);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      "Authorization: Bearer $token",
      "Content-Type: application/json; charset=utf-8"
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
  ]);
  $res = curl_exec($ch);
  curl_close($ch);
  return $res;
}

// ===== 실행 =====
$token = loadToken($accountId, $authKey, $TOKEN_FILE, $TOKEN_URL, $TOKEN_TTL);
$response = sendAlimtalk($token, $targets, $accountId, $senderProfile, $templateCode, $SEND_URL);

// 토큰 만료시 재시도(문구 기반)
if (is_string($response) && (stripos($response, 'jwt expired') !== false || stripos($response, 'Token issue failed') !== false)) {
  $token = getNewToken($accountId, $authKey, $TOKEN_FILE, $TOKEN_URL);
  $response = sendAlimtalk($token, $targets, $accountId, $senderProfile, $templateCode, $SEND_URL);
}

echo $response;
