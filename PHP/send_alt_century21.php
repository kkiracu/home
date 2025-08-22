<?php
header('Content-Type: application/json; charset=utf-8');

/* =====================================================
 *  Ppurio 알림톡(ALT) 발송 - senderProfile 방식
 *  - 토큰:   POST https://message.ppurio.com/v1/token   (Basic: Base64(accountId:authKey))
 *  - 발송:   POST https://message.ppurio.com/v1/kakao   (Bearer: token)
 *  - 요구:   payload에 senderProfile 필수 (예: '@cu03247')
 *  - 변수:   targets[].changeWord.{var1..varN}
 * ===================================================== */

$ACCOUNT_ID    = 'cu03247';
$AUTH_KEY      = '625b430045660862ab66697ef05b78965a46c546602b782dea0d24ad37a77777';
$SENDER_PROFILE= '@cu03247'; // ★ 카카오 채널 아이디(@포함)
$TEMPLATE_CODE = 'ppur_2025082208155432533063175';

$TOKEN_FILE    = __DIR__ . '/token.cache.json';
$TOKEN_TTL_SEC = 55 * 60; // 55분 캐시

$TOKEN_URL = 'https://message.ppurio.com/v1/token';
$SEND_URL  = 'https://message.ppurio.com/v1/kakao';

function formatPhone($number){
  $num = preg_replace('/[^0-9]/', '', (string)$number);
  if ($num && $num[0] !== '0') $num = '0' . $num;
  return $num;
}
function formatKRW($v){
  if ($v === null || $v === '') return '';
  if (is_numeric($v)) return number_format((float)$v) . '원';
  $n = preg_replace('/[^0-9.]/', '', (string)$v);
  if ($n === '') return (string)$v;
  return number_format((float)$n) . '원';
}

function getPpurioToken($accountId, $authKey, $tokenFile, $ttlSec, $tokenUrl){
  if (file_exists($tokenFile)){
    $data = json_decode(file_get_contents($tokenFile), true);
    if (!empty($data['token']) && !empty($data['created_at'])){
      if (time() - strtotime($data['created_at']) < $ttlSec){
        return $data['token'];
      }
    }
  }
  $basic = base64_encode($accountId . ':' . $authKey);
  $ch = curl_init($tokenUrl);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [ 'Authorization: Basic ' . $basic, 'Content-Type: application/json; charset=utf-8' ],
    CURLOPT_POSTFIELDS     => '{}',
  ]);
  $res  = curl_exec($ch);
  $err  = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($err) throw new Exception('토큰 요청 오류: ' . $err);
  $json = json_decode($res, true);
  if ($code !== 200 || empty($json['token'])){
    throw new Exception('토큰 응답 오류(' . $code . '): ' . $res);
  }
  file_put_contents($tokenFile, json_encode([
    'token'      => $json['token'],
    'created_at' => date('Y-m-d H:i:s')
  ], JSON_UNESCAPED_UNICODE));
  return $json['token'];
}

// ===== 입력 =====
$input = json_decode(file_get_contents('php://input'), true);
$name      = trim($input['name']      ?? '');
$to        = trim($input['to']        ?? '');
$bookingId = trim($input['bookingId'] ?? '');
$date      = trim($input['date']      ?? '');
$time      = trim($input['time']      ?? '');
$course    = trim($input['course']    ?? '');
$fee       = trim($input['fee']       ?? '');
$note      = trim($input['note']      ?? '');

if (!$name || !$to || !$bookingId || !$date || !$time || !$course){
  http_response_code(400);
  echo json_encode(['error' => '필수값 누락(name,to,bookingId,date,time,course)'], JSON_UNESCAPED_UNICODE);
  exit;
}

$targets = [];
$targets[] = [
  'to'   => formatPhone($to),
  'name' => $name,
  // ALT: changeWord에 var1..varN 사용
  'changeWord' => [
    'var1' => $bookingId,
    'var2' => $date,
    'var3' => $time,
    'var4' => $course,
    'var5' => formatKRW($fee),
    'var6' => ($note !== '' ? $note : '-')
  ]
];

// 평일 08~22시 관리자 동보
$now = new DateTime('now', new DateTimeZone('Asia/Seoul'));
$day = (int)$now->format('N');
$hour= (int)$now->format('G');
if ($day >= 1 && $day <= 5 && $hour >= 8 && $hour < 22){
  $targets[] = [
    'to'   => formatPhone('01071186639'),
    'name' => '[책임자] ' . $name,
    'changeWord' => [
      'var1' => $bookingId,
      'var2' => $date,
      'var3' => $time,
      'var4' => $course,
      'var5' => formatKRW($fee),
      'var6' => ($note !== '' ? $note : '-')
    ]
  ];
}

// ===== 토큰 =====
try {
  $token = getPpurioToken($ACCOUNT_ID, $AUTH_KEY, $TOKEN_FILE, $TOKEN_TTL_SEC, $TOKEN_URL);
} catch (Exception $e){
  http_response_code(500);
  echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}

// ===== 발송 페이로드 (senderProfile 필수) =====
$payload = [
  'account'       => $ACCOUNT_ID,
  'messageType'   => 'ALT',            // Ppurio 문서 기준 알림톡 코드
  'senderProfile' => $SENDER_PROFILE,  // 예: '@cu03247'
  'templateCode'  => $TEMPLATE_CODE,
  'duplicateFlag' => 'Y',
  'isResend'      => 'N',
  'targetCount'   => count($targets),
  'targets'       => $targets,
  'refKey'        => 'alt_' . time(),
];

$ch = curl_init($SEND_URL);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST           => true,
  CURLOPT_HTTPHEADER     => [
    'Authorization: Bearer ' . $token,
    'Content-Type: application/json; charset=utf-8'
  ],
  CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
]);
$response = curl_exec($ch);
$err      = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($err) { http_response_code(500); echo json_encode(['error'=>$err], JSON_UNESCAPED_UNICODE); exit; }
http_response_code($httpCode);
echo $response;
