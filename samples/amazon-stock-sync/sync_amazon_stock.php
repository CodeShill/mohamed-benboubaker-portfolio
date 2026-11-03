<?php
/**
 * Recurring stock sync: Dolibarr ERP  ->  Amazon (Selling Partner API, JSON_LISTINGS_FEED).
 *
 * - Only touches SKUs that are already listed on Amazon (read from the open-listings report),
 *   so catalogue-only products never get an offer created by accident.
 * - Stock = llx_product.stock (total across warehouses), negatives clamped to 0.
 * - Sends one PATCH message per SKU whose Amazon quantity differs from the ERP.
 * - Logs the result of the previous asynchronous feed on every run.
 *
 * Credentials are NOT in this file: they live outside the web root in amazon_sp_config.php
 * (see amazon_sp_config.sample.php).
 *
 * Run:  /cron/sync_amazon_stock.php?run=1        (add &dry=1 to build the feed without sending it)
 *
 * Author: Mohamed Benboubaker — published as a portfolio sample (company names and URLs removed).
 */
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
set_time_limit(900);
ignore_user_abort(true);
mysqli_report(MYSQLI_REPORT_OFF);

if (($_GET['run'] ?? '') !== '1') {
  http_response_code(400);
  echo json_encode(["error" => "pass ?run=1 to execute"]);
  exit;
}
$dry = ($_GET['dry'] ?? '') === '1';

$home = dirname(__DIR__, 2);
$cfgFile = $home . '/amazon_sp_config.php';
if (!is_file($cfgFile)) { http_response_code(500); die(json_encode(["error" => "missing config file"])); }
$cfg = require $cfgFile;
$stateFile = $home . '/amazon_sync_state.json';
$logFile = $home . '/amazon_sync.log';

const SP = 'https://sellingpartnerapi-eu.amazon.com';
const MARKETPLACE = 'A13V1IB3VIYZZH'; // amazon.fr (public marketplace id)

$report = ["started" => date('c'), "dry_run" => $dry, "errors" => []];

function out($report, $code = 200) {
  global $logFile;
  $report["finished"] = date('c');
  @file_put_contents($logFile, json_encode($report, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
  http_response_code($code);
  echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function http($method, $url, $headers = [], $body = null) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 120]);
  if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
  $res = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  if ($res === false) throw new RuntimeException("$method $url: $err");
  return [$code, $res];
}

function sp($method, $path, $token, $body = null) {
  [$code, $res] = http($method, SP . $path, ['x-amz-access-token: ' . $token, 'Content-Type: application/json'], $body === null ? null : json_encode($body));
  $j = json_decode($res, true);
  if ($code >= 300 || !is_array($j)) throw new RuntimeException("SP-API $method $path HTTP $code: " . substr($res, 0, 500));
  return $j;
}

function fetchDocument($token, $docId) {
  $info = sp('GET', "/reports/2021-06-30/documents/" . rawurlencode($docId), $token);
  [$code, $raw] = http('GET', $info['url']);
  if ($code !== 200) throw new RuntimeException("document download HTTP $code");
  if (($info['compressionAlgorithm'] ?? '') === 'GZIP') $raw = gzdecode($raw);
  return $raw;
}

try {
  // 0) LWA access token
  [$code, $res] = http('POST', 'https://api.amazon.com/auth/o2/token', ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
    'grant_type' => 'refresh_token', 'refresh_token' => $cfg['refresh_token'],
    'client_id' => $cfg['client_id'], 'client_secret' => $cfg['client_secret'],
  ]));
  $token = json_decode($res, true)['access_token'] ?? null;
  if (!$token) throw new RuntimeException("LWA token failed HTTP $code");

  // 1) outcome of the previous run's feed (Amazon processes feeds asynchronously)
  $state = is_file($stateFile) ? json_decode(file_get_contents($stateFile), true) : [];
  if (!empty($state['last_feed_id'])) {
    $feed = sp('GET', "/feeds/2021-06-30/feeds/" . rawurlencode($state['last_feed_id']), $token);
    $report["previous_feed"] = ["id" => $state['last_feed_id'], "status" => $feed['processingStatus'] ?? null];
    if (!empty($feed['resultFeedDocumentId'])) {
      $info = sp('GET', "/feeds/2021-06-30/documents/" . rawurlencode($feed['resultFeedDocumentId']), $token);
      [, $raw] = http('GET', $info['url']);
      if (($info['compressionAlgorithm'] ?? '') === 'GZIP') $raw = gzdecode($raw);
      $result = json_decode($raw, true);
      $report["previous_feed"]["summary"] = $result['summary'] ?? null;
      $errs = [];
      foreach (($result['issues'] ?? []) as $iss) {
        if (($iss['severity'] ?? '') === 'ERROR' && count($errs) < 20) $errs[] = $iss;
      }
      if ($errs) $report["previous_feed"]["errors_sample"] = $errs;
    }
  }

  // 2) SKUs currently listed on Amazon (report polled for up to ~10 min)
  $r = sp('POST', '/reports/2021-06-30/reports', $token, ['reportType' => 'GET_FLAT_FILE_OPEN_LISTINGS_DATA', 'marketplaceIds' => [MARKETPLACE]]);
  $reportId = $r['reportId'];
  $docId = null;
  for ($i = 0; $i < 60; $i++) {
    sleep(10);
    $st = sp('GET', "/reports/2021-06-30/reports/" . rawurlencode($reportId), $token);
    if (($st['processingStatus'] ?? '') === 'DONE') { $docId = $st['reportDocumentId']; break; }
    if (in_array($st['processingStatus'] ?? '', ['CANCELLED', 'FATAL'], true)) throw new RuntimeException("listings report " . $st['processingStatus']);
  }
  if (!$docId) throw new RuntimeException("listings report not ready after 10 min");

  $lines = preg_split('/\r?\n/', trim(fetchDocument($token, $docId)));
  $header = str_getcsv(array_shift($lines), "\t");
  $iSku = array_search('sku', $header, true);
  $iQty = array_search('quantity', $header, true);
  if ($iSku === false) throw new RuntimeException("no sku column in listings report");
  $amazonQty = [];
  foreach ($lines as $l) {
    $c = str_getcsv($l, "\t");
    if (!empty($c[$iSku])) $amazonQty[$c[$iSku]] = ($iQty !== false && isset($c[$iQty]) && $c[$iQty] !== '') ? (int)$c[$iQty] : null;
  }
  $report["amazon_skus"] = count($amazonQty);
  if (!$amazonQty) out($report);

  // 3) ERP stock for those SKUs.
  // Amazon SKUs are limited to 40 characters, so kit SKUs are sent with the prefix dropped after each "+":
  //   PX-A100+PX-B200+PX-C300  ->  PX-A100+B200+C300
  // Re-adding the prefix after every "+" gives back the exact ERP reference.
  $toRef = [];
  foreach (array_keys($amazonQty) as $amzSku) $toRef[$amzSku] = preg_replace('/\+(?!PX-)/', '+PX-', $amzSku);
  $conn = new mysqli($cfg['db_host'], $cfg['db_user'], $cfg['db_pass'], $cfg['db_name']);
  if ($conn->connect_error) throw new RuntimeException("DB connection failed");
  $conn->set_charset('utf8mb4');
  $refStock = [];
  foreach (array_chunk(array_values($toRef), 500) as $chunk) {
    $in = implode(',', array_map(fn($s) => "'" . $conn->real_escape_string($s) . "'", $chunk));
    $res = $conn->query("SELECT ref, stock FROM llx_product WHERE ref IN ($in)");
    while ($row = $res->fetch_assoc()) $refStock[$row['ref']] = max(0, (int)floor((float)$row['stock']));
  }
  $stock = []; // keyed by Amazon SKU
  foreach ($toRef as $amzSku => $ref) if (isset($refStock[$ref])) $stock[$amzSku] = $refStock[$ref];
  $report["matched_in_erp"] = count($stock);
  $report["not_in_erp"] = array_values(array_diff(array_keys($amazonQty), array_keys($stock)));

  // 4) one PATCH message per SKU whose Amazon quantity differs from the ERP
  $leadTimes = $cfg['lead_time_days'] ?? [];
  $messages = [];
  foreach ($stock as $sku => $qty) {
    if ($amazonQty[$sku] === $qty) continue;
    $fa = ['fulfillment_channel_code' => 'DEFAULT', 'quantity' => $qty];
    if (isset($leadTimes[$toRef[$sku]])) $fa['lead_time_to_ship_max_days'] = (int)$leadTimes[$toRef[$sku]];
    $messages[] = [
      'messageId' => count($messages) + 1,
      'sku' => $sku,
      'operationType' => 'PATCH',
      'productType' => 'PRODUCT',
      'patches' => [['op' => 'replace', 'path' => '/attributes/fulfillment_availability', 'value' => [$fa]]],
    ];
  }
  $report["changed"] = count($messages);
  if (!$messages || $dry) out($report);

  // 5) upload + submit the feed
  $feedBody = json_encode([
    'header' => ['sellerId' => $cfg['seller_id'], 'version' => '2.0', 'issueLocale' => 'fr_FR'],
    'messages' => $messages,
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $doc = sp('POST', '/feeds/2021-06-30/documents', $token, ['contentType' => 'application/json; charset=UTF-8']);
  [$code, $res] = http('PUT', $doc['url'], ['Content-Type: application/json; charset=UTF-8'], $feedBody);
  if ($code !== 200) throw new RuntimeException("feed upload HTTP $code");
  $feed = sp('POST', '/feeds/2021-06-30/feeds', $token, ['feedType' => 'JSON_LISTINGS_FEED', 'marketplaceIds' => [MARKETPLACE], 'inputFeedDocumentId' => $doc['feedDocumentId']]);
  $report["feed_id"] = $feed['feedId'];
  file_put_contents($stateFile, json_encode(['last_feed_id' => $feed['feedId'], 'at' => date('c')]));
  out($report);

} catch (Throwable $e) {
  $report["errors"][] = $e->getMessage();
  out($report, 500);
}
