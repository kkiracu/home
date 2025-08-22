<?php
// CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  header('Access-Control-Allow-Origin: *');
  header('Access-Control-Allow-Headers: Content-Type');
  header('Access-Control-Allow-Methods: POST, OPTIONS');
  exit;
}
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

// ===== 계정/템플릿 =====
$accountId     = 'cu03247';                  // 문자열
$authKey       = '625b430045660862ab66697ef05b78965a46c546602b782dea0d24ad37a77777';
$senderProfile = '@cu03247';
$templateCode  = 'ppur_2025082210453220034558875';

$TOKEN_URL = 'https://message.ppurio.com/v1/token';
$SEND_URL  = 'https://message.ppurio.com/v1/kakao';
$TOKEN_FILE = __DIR__ . '/token.json';

// ===== 유틸 =====
function formatPhone($num){ return preg_replace('/[^0-9]/','',(string)$num); }

function getNewToken($accountId, $authKey, $TOKEN_URL, $TOKEN_FILE){
  $credentials = base64_encode($accountId . ':' . $authKey);
  $ch = curl_init($TOKEN_URL);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      "Authorization: Basic $credentials",
      "Content-Type: application/json; charset=utf-8"
    ],
    CURLOPT_POSTFIELDS => '{}' // 빈 바디 대신 {} 권장
  ]);
  $res = curl_exec($ch);
  curl_close($ch);
  $json = json_decode($res, true);
  if (!isset($json['token'])) return false;
  file_put_contents($TOKEN_FILE, json_encode(['token'=>$json['token']], JSON_UNESCAPED_UNICODE));
  return $json['token'];
}

function loadToken($accountId, $authKey, $TOKEN_URL, $TOKEN_FILE){
  if (!file_exists($TOKEN_FILE)) return getNewToken($accountId, $authKey, $TOKEN_URL, $TOKEN_FILE);
  $saved = json_decode(file_get_contents($TOKEN_FILE), true);
  return $saved['token'] ?? getNewToken($accountId, $authKey, $TOKEN_URL, $TOKEN_FILE);
}

// ===== 입력 =====
$input = json_decode(file_get_contents('php://input'), true);
$name = trim($input['name'] ?? '');
$to   = trim($input['to'] ?? '');
$var1 = trim($input['var1'] ?? ''); // 제목
$var2 = trim($input['var2'] ?? ''); // 내용1
$var3 = trim($input['var3'] ?? ''); // 내용2
$var4 = trim($input['var4'] ?? ''); // 발신직원

if (!$name || !$to || !$var1 || !$var2 || !$var4) {
  echo json_encode(["code"=>"9000","description"=>"❌ 필수 입력 누락"], JSON_UNESCAPED_UNICODE);
  exit;
}

// ===== 템플릿 변수 매핑 =====
$targets = [[
  "to" => formatPhone($to),
  "name" => $name,
  "changeWord" => [
    "var1" => $var1, // 제목
    "var2" => $var2, // 내용1
    "var3" => $var3, // 내용2
    "var4" => $var4, // 직원
  ]
]];

function sendAlimtalk($token, $targets, $accountId, $senderProfile, $templateCode, $SEND_URL){
  $payload = [
    "account"       => (string)$accountId,
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
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)
  ]);
  $res = curl_exec($ch);
  curl_close($ch);
  return $res;
}

// ===== 전송 =====
$token = loadToken($accountId, $authKey, $TOKEN_URL, $TOKEN_FILE);
$response = sendAlimtalk($token, $targets, $accountId, $senderProfile, $templateCode, $SEND_URL);

// 토큰 만료시 1회 재시도(문구 기반)
if (is_string($response) && (stripos($response, 'jwt expired') !== false || stripos($response, 'Token issue failed') !== false)) {
  $token = getNewToken($accountId, $authKey, $TOKEN_URL, $TOKEN_FILE);
  $response = sendAlimtalk($token, $targets, $accountId, $senderProfile, $templateCode, $SEND_URL);
}

echo $response;
