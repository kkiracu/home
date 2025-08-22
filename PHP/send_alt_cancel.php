<?php
header("Content-Type: application/json; charset=utf-8");

/** ====== 환경설정 ====== */
$accountId     = 'cu03247';
$authKey       = '625b430045660862ab66697ef05b78965a46c546602b782dea0d24ad37a77777';
$templateCode  = 'ppur_2025082209041932533280948';  // 취소 알림톡 템플릿
$senderProfile = '@cu03247';
$tokenFile     = __DIR__ . '/token_cancel.json';
$tokenExpireMinutes = 55;

/** ====== 토큰 재사용 ====== */
function getValidToken($accountId, $authKey, $tokenFile, $tokenExpireMinutes = 55) {
  if (file_exists($tokenFile)) {
    $data = json_decode(file_get_contents($tokenFile), true);
    $token = $data['token'] ?? '';
    $createdAt = strtotime($data['created_at'] ?? '');
    if ($token && $createdAt && (time() - $createdAt) < ($tokenExpireMinutes * 60)) {
      return $token;
    }
  }

  $url = 'https://message.ppurio.com/v1/token';
  $credentials = base64_encode("$accountId:$authKey");

  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Basic $credentials",
    "Content-Type: application/json"
  ]);
  curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
  $response = curl_exec($ch);
  curl_close($ch);

  $result = json_decode($response, true);
  if (!isset($result['token'])) {
    echo json_encode(["error" => "토큰 오류", "response" => $response]);
    exit;
  }

  file_put_contents($tokenFile, json_encode([
    'token'      => $result['token'],
    'created_at' => date("Y-m-d H:i:s")
  ]));

  return $result['token'];
}

function formatPhone($number) {
  $num = preg_replace('/[^0-9]/', '', $number);
  if ($num && $num[0] !== '0') $num = '0' . $num;
  return $num;
}

function formatKRW($v) {
  if ($v === null || $v === '') return '';
  if (is_numeric($v)) return number_format((float)$v) . '원';
  $n = preg_replace('/[^0-9.]/', '', $v);
  if ($n === '') return $v;
  return number_format((float)$n) . '원';
}

/** ====== 입력 ======
 * name, to, bookingId, reserver, schedule, course, fee, cancelAt
 */
$input = json_decode(file_get_contents("php://input"), true);
$name      = trim($input['name']      ?? '');
$to        = trim($input['to']        ?? '');
$bookingId = trim($input['bookingId'] ?? '');
$reserver  = trim($input['reserver']  ?? '');
$schedule  = trim($input['schedule']  ?? '');
$course    = trim($input['course']    ?? '');
$fee       = trim($input['fee']       ?? '');
$cancelAt  = trim($input['cancelAt']  ?? '');

if (!$name || !$to || !$bookingId || !$reserver || !$schedule) {
  echo json_encode(["error" => "필수값 누락"]);
  exit;
}

/** ====== 수신자(회원) ====== */
$targets = [[
  "to" => formatPhone($to),
  "name" => $name,
  "changeWord" => [
    "var1" => $bookingId,          // [*1*] 예약번호
    "var2" => $reserver,           // [*2*] 예약자
    "var3" => $schedule,           // [*3*] 일정
    "var4" => $course,             // [*4*] 코스
    "var5" => formatKRW($fee),     // [*5*] 그린피
    "var6" => $cancelAt            // [*6*] 취소일시
  ]
  ],
  [
  "to" => formatPhone("01071186639"),
      "name" => "[관리자 알림] $name",
  "changeWord" => [
    "var1" => $bookingId,          // [*1*] 예약번호
    "var2" => $reserver,           // [*2*] 예약자
    "var3" => $schedule,           // [*3*] 일정
    "var4" => $course,             // [*4*] 코스
    "var5" => formatKRW($fee),     // [*5*] 그린피
    "var6" => $cancelAt            // [*6*] 취소일시
  ]
  ]
];

/** ====== 전송 ====== */
$token = getValidToken($accountId, $authKey, $tokenFile, $tokenExpireMinutes);
$payload = [
  "account"       => $accountId,
  "messageType"   => "ALT",
  "senderProfile" => $senderProfile,
  "templateCode"  => $templateCode,
  "duplicateFlag" => "Y",
  "isResend"      => "N",
  "targetCount"   => count($targets),
  "targets"       => $targets,
  "refKey"        => "cancel_" . time()
];

$ch = curl_init("https://message.ppurio.com/v1/kakao");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
  "Authorization: Bearer $token",
  "Content-Type: application/json; charset=utf-8"
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
$response = curl_exec($ch);
$err = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($err) { http_response_code(500); echo json_encode(["error"=>$err]); exit; }
http_response_code($httpCode);
echo $response;
