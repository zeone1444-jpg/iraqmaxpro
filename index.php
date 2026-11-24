<?php
/**
 * =============================================================================
 * 🔥 سيرفر وبث ابو زينه النكحاوي | Ultra-Fast Stream Proxy Engine 🔥
 * =============================================================================
 * - كود PHP موحد وشامل (Single-File) فائق السرعة والخفة بدون أي مكتبات خارجية.
 * - محرك بروكسي قوي جداً وثابت 100% مع معالجة وتدفق قطع TS فورياً بدون تقطيع.
 * - واجهة مستخدم صامتة بالكامل، داكنة وأنيقة بدون أي توهجات أو ألوان فاقعة.
 * - شريط علوي بسيط ونظيف: نافذة جدول المباريات + زر تنزيل M3U فقط.
 * - شريط الأقسام السلس وتصفح القنوات وفتح واجهة نسخ الروابط المنفصلة مع زر رجوع.
 * - واجهة برمجية JSON متزامنة لحظياً لجدول المباريات والروابط (?api=matches).
 * =============================================================================
 */

@error_reporting(0);
@ini_set('display_errors', '0');
@set_time_limit(0);

// App Constants
define('APP_NAME', 'سيرفر وبث ابو زينه النكحاوي');
define('YACINE_API_KEY', 'c!xZj+N9&G@Ev@vw');
define('FIREBASE_PROJECT', 'ycntv-7a08e');
define('FIREBASE_APP_ID', '1:692330584196:android:68ea9f0c920aa17904cad1');
define('FIREBASE_API_KEY', 'AIzaSyDRKL14PPiXzk7qNUNLgV2IsjasxNpWLeU');
define('DEFAULT_HOST', 'https://def55.ycnapi.com');

// -----------------------------------------------------------------------------
// CORS & Preflight Immediate Handler
// -----------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
    header('Access-Control-Allow-Headers: *');
    header('Access-Control-Max-Age: 86400');
    exit;
}

// -----------------------------------------------------------------------------
// Helper Functions: URLs & Cache Management
// -----------------------------------------------------------------------------

function get_self_url() {
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? 80) == 443 || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    return $proto . $host . $script;
}

function get_cache_file() {
    $tmp = sys_get_temp_dir();
    $f = rtrim($tmp, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'abu_zeina_cache.json';
    if (@is_writable($tmp)) return $f;
    return __DIR__ . DIRECTORY_SEPARATOR . '.cache_yacine.json';
}

function load_local_cache() {
    $f = get_cache_file();
    if (file_exists($f)) {
        $c = @file_get_contents($f);
        if ($c) {
            $data = json_decode($c, true);
            if (is_array($data)) return $data;
        }
    }
    return ['active_host' => DEFAULT_HOST, 'last_sync' => 0, 'streams' => []];
}

function save_local_cache($data) {
    $f = get_cache_file();
    @file_put_contents($f, json_encode($data));
}

// -----------------------------------------------------------------------------
// Firebase Remote Config Host Resolution
// -----------------------------------------------------------------------------

function get_active_host($force_sync = false) {
    $cache = load_local_cache();
    $now = time();

    if (!$force_sync && !empty($cache['active_host']) && ($now - ($cache['last_sync'] ?? 0) < 900)) {
        return $cache['active_host'];
    }

    $active = DEFAULT_HOST;
    $ch = curl_init('https://firebaseinstallations.googleapis.com/v1/projects/' . FIREBASE_PROJECT . '/installations');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . FIREBASE_API_KEY],
        CURLOPT_POSTFIELDS => json_encode(['appId' => FIREBASE_APP_ID, 'authVersion' => 'FIS_v2', 'sdkVersion' => 'a:17.0.1']),
        CURLOPT_TIMEOUT => 7,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $res = curl_exec($ch);
    curl_close($ch);

    $inst = json_decode($res, true);
    $fid = $inst['fid'] ?? null;
    $token = $inst['authToken']['token'] ?? null;

    if ($fid && $token) {
        $ch2 = curl_init('https://firebaseremoteconfig.googleapis.com/v1/projects/' . FIREBASE_PROJECT . '/namespaces/firebase:fetch');
        curl_setopt_array($ch2, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . FIREBASE_API_KEY,
                'X-Goog-Api-Client: a/17.0.1 fire-rc/21.6.0'
            ],
            CURLOPT_POSTFIELDS => json_encode(['appId' => FIREBASE_APP_ID, 'appInstanceId' => $fid, 'appInstanceIdToken' => $token]),
            CURLOPT_TIMEOUT => 7,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $rcRes = curl_exec($ch2);
        curl_close($ch2);
        $rcData = json_decode($rcRes, true);
        $hostEntry = trim($rcData['entries']['defaults'] ?? '');
        if (!empty($hostEntry)) {
            $active = str_starts_with($hostEntry, 'http') ? $hostEntry : 'https://' . $hostEntry;
        }
    }

    $cache['active_host'] = $active;
    $cache['last_sync'] = $now;
    save_local_cache($cache);
    return $active;
}

// -----------------------------------------------------------------------------
// Yacine TV API Request & Decryption (XOR + Base64 with Timestamp T)
// -----------------------------------------------------------------------------

function fetch_yacine_api($path, $retry = true) {
    $host = get_active_host();
    $url = rtrim($host, '/') . '/' . ltrim($path, '/');

    $headerMap = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['User-Agent: okhttp/4.12.0', 'Accept: application/json'],
        CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$headerMap) {
            $len = strlen($header);
            $parts = explode(':', $header, 2);
            if (count($parts) == 2) {
                $headerMap[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return $len;
        },
        CURLOPT_TIMEOUT => 8,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$raw) {
        if ($retry) {
            get_active_host(true);
            return fetch_yacine_api($path, false);
        }
        return null;
    }

    $t = $headerMap['t'] ?? ($headerMap['date'] ?? '');
    $trimmed = trim($raw);
    if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
        return json_decode($trimmed, true);
    }
    $decoded = base64_decode($trimmed);
    if ($decoded === false) return null;

    $key = YACINE_API_KEY . $t;
    $keyLen = strlen($key);
    $decLen = strlen($decoded);
    $out = '';
    for ($i = 0; $i < $decLen; $i++) {
        $out .= chr(ord($decoded[$i]) ^ ord($key[$i % $keyLen]));
    }
    return json_decode($out, true);
}

// -----------------------------------------------------------------------------
// Unified Streams Resolver (Channels & Match Events)
// Preserves exact raw signature referers without stripping unicode watermarks
// -----------------------------------------------------------------------------

function resolve_yacine_streams($id, $is_event = null) {
    $cleanId = preg_replace('/^(ev_|ch_)/', '', trim((string)$id));
    if ($is_event === null) {
        $is_event = str_starts_with((string)$id, 'ev_') || (strlen($cleanId) >= 8 && is_numeric($cleanId));
    }

    $cacheKey = ($is_event ? 'ev_' : 'ch_') . $cleanId;
    $cache = load_local_cache();
    $now = time();

    if (!empty($cache['streams'][$cacheKey]) && ($now - ($cache['streams'][$cacheKey]['time'] ?? 0) < 180)) {
        return $cache['streams'][$cacheKey]['data'];
    }

    $path = $is_event ? ('/api/event/' . $cleanId) : ('/api/channel/' . $cleanId);
    $resp = fetch_yacine_api($path);
    $data = $resp['data'] ?? [];

    if (empty($data)) {
        $altPath = $is_event ? ('/api/channel/' . $cleanId) : ('/api/event/' . $cleanId);
        $altResp = fetch_yacine_api($altPath);
        $data = $altResp['data'] ?? [];
        if (!empty($data)) $is_event = !$is_event;
    }

    if (empty($data)) return [];
    if (isset($data['url'])) $data = [$data];

    $streams = [];
    foreach ($data as $idx => $s) {
        $url = $s['url'] ?? '';
        if ($url && (str_starts_with($url, 'http://') || str_starts_with($url, 'https://'))) {
            $ref = $s['referer'] ?? ($s['headers']['Referer'] ?? 'http://yacinneeee.live');
            $streams[] = [
                'index' => $idx,
                'quality' => $s['name'] ?? ('جودة ' . ($idx + 1)),
                'url' => $url,
                'referer' => $ref,
                'user_agent' => $s['user_agent'] ?? ($s['headers']['User-Agent'] ?? 'Mozilla/5.0')
            ];
        }
    }

    if (!empty($streams)) {
        $cache['streams'][$cacheKey] = ['time' => $now, 'data' => $streams];
        save_local_cache($cache);
    }
    return $streams;
}

function url_join($base, $rel) {
    if (parse_url($rel, PHP_URL_SCHEME) != '') return $rel;
    if ($rel[0] == '/') {
        $p = parse_url($base);
        return $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . $rel;
    }
    $baseDir = preg_replace('/[^\/]+$/', '', $base);
    return $baseDir . $rel;
}

// -----------------------------------------------------------------------------
// Router: Ultra-Strong Live Proxy Engine, Segment Streaming & JSON API
// -----------------------------------------------------------------------------

$action = $_GET['action'] ?? '';
$selfUrl = get_self_url();

// 1. ⚡ Ultra-Stable Stream M3U8 Proxy Endpoint (?live=ID_INDEX)
if (isset($_GET['live'])) {
    $spec = $_GET['live'];
    $isEvent = str_starts_with($spec, 'ev_');
    $cleanSpec = preg_replace('/^(ev_|ch_)/', '', $spec);
    $parts = explode('_', $cleanSpec);
    $targetId = $parts[0];
    $qIndex = isset($parts[1]) && is_numeric($parts[1]) ? intval($parts[1]) : 0;

    if (!$isEvent && (strlen($targetId) >= 8 && is_numeric($targetId))) {
        $isEvent = true;
    }

    $streams = resolve_yacine_streams($targetId, $isEvent);
    if (empty($streams)) {
        header("HTTP/1.1 404 Not Found");
        echo "Stream currently unavailable";
        exit;
    }

    $target = null;
    foreach ($streams as $s) {
        if ($s['index'] === $qIndex) { $target = $s; break; }
    }
    if (!$target) $target = $streams[0];

    $upstreamUrl = $target['url'];
    $ref = $target['referer'];
    $ua = $target['user_agent'];

    // High-performance cURL fetch with IPv4, TCP_NODELAY and exact raw signature headers
    $headers = ['User-Agent: ' . $ua, 'Accept: */*'];
    if ($ref) $headers[] = 'Referer: ' . $ref;

    $ch = curl_init($upstreamUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_TCP_NODELAY => 1,
        CURLOPT_ENCODING => '',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true
    ]);
    $m3u8 = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$m3u8) {
        $cache = load_local_cache();
        unset($cache['streams'][($isEvent ? 'ev_' : 'ch_') . $targetId]);
        save_local_cache($cache);

        $streams = resolve_yacine_streams($targetId, $isEvent);
        if (!empty($streams)) {
            $target = $streams[0];
            $upstreamUrl = $target['url'];
            $ref = $target['referer'];
            $ua = $target['user_agent'];
            $headers = ['User-Agent: ' . $ua, 'Accept: */*'];
            if ($ref) $headers[] = 'Referer: ' . $ref;

            $ch2 = curl_init($upstreamUrl);
            curl_setopt_array($ch2, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                CURLOPT_TCP_NODELAY => 1,
                CURLOPT_ENCODING => '',
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_FOLLOWLOCATION => true
            ]);
            $m3u8 = curl_exec($ch2);
            curl_close($ch2);
        }
    }

    header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
    header('Access-Control-Allow-Headers: *');
    header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');

    $lines = explode("\n", (string)$m3u8);
    $rewritten = [];
    foreach ($lines as $line) {
        $lineStr = trim($line);
        if (!$lineStr) {
            $rewritten[] = $line;
            continue;
        }
        if (str_starts_with($lineStr, '#')) {
            if (str_starts_with($lineStr, '#EXT-X-KEY')) {
                if (preg_match('/URI="([^"]+)"/', $lineStr, $m)) {
                    $keyUri = $m[1];
                    $absKey = url_join($upstreamUrl, $keyUri);
                    $newKey = $selfUrl . '?ts=' . urlencode($absKey) . '&ref=' . urlencode($ref) . '&ua=' . urlencode($ua);
                    $lineStr = str_replace('URI="' . $keyUri . '"', 'URI="' . $newKey . '"', $lineStr);
                }
            }
            $rewritten[] = $lineStr;
        } else {
            $absSeg = url_join($upstreamUrl, $lineStr);
            if (str_contains($lineStr, '.m3u8')) {
                $newLine = $selfUrl . '?m3u8_sub=' . urlencode($absSeg) . '&ref=' . urlencode($ref) . '&ua=' . urlencode($ua);
            } else {
                $newLine = $selfUrl . '?ts=' . urlencode($absSeg) . '&ref=' . urlencode($ref) . '&ua=' . urlencode($ua);
            }
            $rewritten[] = $newLine;
        }
    }

    echo implode("\n", $rewritten);
    exit;
}

// 2. Sub-M3U8 Master / Variant Proxy (?m3u8_sub=...)
if (isset($_GET['m3u8_sub'])) {
    $targetUrl = $_GET['m3u8_sub'];
    $ref = $_GET['ref'] ?? '';
    $ua = $_GET['ua'] ?? 'Mozilla/5.0';

    $headers = ['User-Agent: ' . $ua, 'Accept: */*'];
    if ($ref) $headers[] = 'Referer: ' . $ref;

    $ch = curl_init($targetUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_TCP_NODELAY => 1,
        CURLOPT_ENCODING => '',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true
    ]);
    $subContent = curl_exec($ch);
    curl_close($ch);

    header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
    header('Access-Control-Allow-Headers: *');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    $lines = explode("\n", (string)$subContent);
    $rewritten = [];
    foreach ($lines as $line) {
        $lineStr = trim($line);
        if (!$lineStr || str_starts_with($lineStr, '#')) {
            $rewritten[] = $lineStr;
        } else {
            $absSeg = url_join($targetUrl, $lineStr);
            $rewritten[] = $selfUrl . '?ts=' . urlencode($absSeg) . '&ref=' . urlencode($ref) . '&ua=' . urlencode($ua);
        }
    }
    echo implode("\n", $rewritten);
    exit;
}

// 3. 🚀 High-Throughput Video Segment & Key Streaming (?ts=...)
if (isset($_GET['ts'])) {
    $tsUrl = $_GET['ts'];
    $ref = $_GET['ref'] ?? '';
    $ua = $_GET['ua'] ?? 'Mozilla/5.0';

    while (ob_get_level() > 0) ob_end_clean();

    $isKey = str_contains($tsUrl, 'key') || str_ends_with(strtolower(parse_url($tsUrl, PHP_URL_PATH) ?? ''), '.key');
    if ($isKey) {
        header('Content-Type: application/octet-stream');
    } else {
        header('Content-Type: video/mp2t');
    }
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
    header('Access-Control-Allow-Headers: *');
    header('Cache-Control: public, max-age=86400, immutable');

    $headers = ['User-Agent: ' . $ua, 'Accept: */*'];
    if ($ref) $headers[] = 'Referer: ' . $ref;

    $ch = curl_init($tsUrl);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_BUFFERSIZE => 131072,
        CURLOPT_TCP_NODELAY => 1,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_WRITEFUNCTION => function($c, $data) {
            echo $data;
            @flush();
            return strlen($data);
        }
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Fallback if 403 or blocked
    if ($code === 403 && !empty($ref)) {
        $ch2 = curl_init($tsUrl);
        curl_setopt_array($ch2, [
            CURLOPT_HTTPHEADER => ['User-Agent: ' . $ua, 'Accept: */*'],
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_BUFFERSIZE => 131072,
            CURLOPT_TCP_NODELAY => 1,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_WRITEFUNCTION => function($c, $data) {
                echo $data;
                @flush();
                return strlen($data);
            }
        ]);
        curl_exec($ch2);
        curl_close($ch2);
    }
    exit;
}

// 4. Live Matches JSON API Endpoint (?api=matches or ?action=matches_api)
if ($action === 'api_matches' || $action === 'matches_api' || (isset($_GET['api']) && $_GET['api'] === 'matches')) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');

    $resp = fetch_yacine_api('/api/events');
    $events = $resp['data'] ?? [];
    if (isset($events['id'])) $events = [$events];

    $matches = [];
    foreach ($events as $idx => $ev) {
        $evId = (string)($ev['id'] ?? $idx);
        $t1 = $ev['team_1'] ?? [];
        $t2 = $ev['team_2'] ?? [];
        $t1Name = $t1['name'] ?? 'فريق 1';
        $t2Name = $t2['name'] ?? 'فريق 2';
        $matchTitle = "{$t1Name} × {$t2Name}";
        $champ = $ev['champions'] ?? ($ev['championship'] ?? 'مباراة اليوم');
        $channel = $ev['channel'] ?? 'beIN SPORTS';
        $comm = $ev['commentary'] ?? 'معلق القمة';
        $time = $ev['match_time'] ?? ($ev['time'] ?? 'مباشر');
        $date = $ev['match_date'] ?? ($ev['date'] ?? date('Y-m-d'));

        // Streams for this match
        $streams = resolve_yacine_streams($evId, true);
        $streamList = [];
        foreach ($streams as $s) {
            $streamList[] = [
                'index' => $s['index'],
                'quality' => $s['quality'] ?: 'HD',
                'proxy_url' => $selfUrl . '?live=ev_' . $evId . '_' . $s['index'],
                'direct_cdn_url' => $s['url'],
                'headers' => [
                    'Referer' => $s['referer'],
                    'User-Agent' => $s['user_agent']
                ]
            ];
        }

        $matches[] = [
            'id' => $evId,
            'title' => $matchTitle,
            'championship' => $champ,
            'status' => 'مباشر الآن',
            'time' => $time,
            'date' => $date,
            'channel' => $channel,
            'commentary' => $comm,
            'team_1' => [
                'name' => $t1Name,
                'logo' => $t1['logo'] ?? ''
            ],
            'team_2' => [
                'name' => $t2Name,
                'logo' => $t2['logo'] ?? ''
            ],
            'streams_count' => count($streamList),
            'streams' => $streamList
        ];
    }

    echo json_encode([
        'status' => 'success',
        'server' => APP_NAME,
        'timestamp' => date('Y-m-d H:i:s'),
        'total_matches' => count($matches),
        'matches' => $matches
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// 5. Dynamic M3U Playlist Generation (?m3u=1 or ?playlist=1)
if (isset($_GET['m3u']) || isset($_GET['playlist'])) {
    $cats = fetch_yacine_api('/api/categories');
    $m3u = [
        "#EXTM3U",
        "# PLAYLIST: " . APP_NAME . " - بروكسي دائم ومحدث تلقائياً"
    ];

    if (!empty($cats['data'])) {
        foreach ($cats['data'] as $c) {
            $cid = $c['id'];
            $cname = $c['name'];
            $childCount = $c['child_count'] ?? 0;

            $subcategories = [];
            if ($childCount > 0) {
                $subResp = fetch_yacine_api('/api/categories/' . $cid);
                if (!empty($subResp['data'])) $subcategories = $subResp['data'];
            } else {
                $subcategories = [['id' => $cid, 'name' => $cname, 'logo' => $c['logo'] ?? '']];
            }

            foreach ($subcategories as $sub) {
                $sid = $sub['id'];
                $sname = $sub['name'];
                $group = ($cname !== $sname) ? "$cname - $sname" : $cname;

                $chResp = fetch_yacine_api('/api/categories/' . $sid . '/channels');
                if (empty($chResp['data'])) continue;

                foreach ($chResp['data'] as $ch) {
                    $chid = $ch['id'];
                    $chname = $ch['name'];
                    $chlogo = $ch['logo'] ?? '';
                    $liveUrl = $selfUrl . '?live=' . $chid;
                    $m3u[] = '#EXTINF:-1 tvg-id="' . $chid . '" tvg-name="' . $chname . '" tvg-logo="' . $chlogo . '" group-title="' . $group . '",' . $chname;
                    $m3u[] = $liveUrl;
                }
            }
        }
    }

    header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
    header('Content-Disposition: attachment; filename="abu_zeina_channels.m3u"');
    header('Access-Control-Allow-Origin: *');
    echo implode("\n", $m3u);
    exit;
}

// 6. JSON Endpoints for Frontend Dashboard
if ($action === 'categories') {
    header('Content-Type: application/json; charset=utf-8');
    $resp = fetch_yacine_api('/api/categories');
    $cats = $resp['data'] ?? [];

    usort($cats, function($a, $b) {
        $aBein = (stripos($a['name'], 'bein') !== false || stripos($a['name'], 'بي ان') !== false);
        $bBein = (stripos($b['name'], 'bein') !== false || stripos($b['name'], 'بي ان') !== false);
        if ($aBein && !$bBein) return -1;
        if (!$aBein && $bBein) return 1;
        return 0;
    });

    echo json_encode(['success' => !empty($cats), 'categories' => $cats]);
    exit;
}

if ($action === 'subcategories') {
    header('Content-Type: application/json; charset=utf-8');
    $catId = $_GET['id'] ?? '';
    $resp = fetch_yacine_api('/api/categories/' . $catId);
    echo json_encode(['success' => !empty($resp['data']), 'subcategories' => $resp['data'] ?? []]);
    exit;
}

if ($action === 'channels') {
    header('Content-Type: application/json; charset=utf-8');
    $catOrSubId = $_GET['id'] ?? '';
    $resp = fetch_yacine_api('/api/categories/' . $catOrSubId . '/channels');
    $channels = $resp['data'] ?? [];

    usort($channels, function($a, $b) {
        $aName = $a['name'] ?? '';
        $bName = $b['name'] ?? '';

        $aBein = (stripos($aName, 'bein') !== false || stripos($aName, 'بي ان') !== false);
        $bBein = (stripos($bName, 'bein') !== false || stripos($bName, 'بي ان') !== false);

        if ($aBein && !$bBein) return -1;
        if (!$aBein && $bBein) return 1;

        if ($aBein && $bBein) {
            preg_match('/(\d+)/', $aName, $ma);
            preg_match('/(\d+)/', $bName, $mb);
            $numA = isset($ma[1]) ? intval($ma[1]) : 999;
            $numB = isset($mb[1]) ? intval($mb[1]) : 999;
            if ($numA !== $numB) return $numA - $numB;
        }
        return strnatcasecmp($aName, $bName);
    });

    echo json_encode(['success' => !empty($channels), 'channels' => $channels]);
    exit;
}

if ($action === 'streams') {
    header('Content-Type: application/json; charset=utf-8');
    $id = $_GET['id'] ?? '';
    $isMatch = isset($_GET['is_match']) && $_GET['is_match'] == '1';
    $streams = resolve_yacine_streams($id, $isMatch);

    $out = [];
    foreach ($streams as $s) {
        $idx = $s['index'];
        $url = $s['url'];
        $ref = $s['referer'];
        $ua = $s['user_agent'];

        $prefix = $isMatch ? "ev_{$id}" : $id;
        $proxyUrl = $selfUrl . '?live=' . ($idx > 0 ? "{$prefix}_{$idx}" : $prefix);

        // Prepared clean commands
        $vlcCmd = 'vlc "' . $url . '"';
        if ($ref) $vlcCmd .= ' --http-referrer="' . $ref . '"';
        if ($ua) $vlcCmd .= ' --http-user-agent="' . $ua . '"';

        $mpvCmd = 'mpv "' . $url . '"';
        $hl = [];
        if ($ref) $hl[] = "Referer: $ref";
        if ($ua) $hl[] = "User-Agent: $ua";
        if ($hl) $mpvCmd .= ' --http-header-fields="' . implode(',', $hl) . '"';

        $curlCmd = 'curl -i';
        if ($ref) $curlCmd .= ' -H "Referer: ' . $ref . '"';
        if ($ua) $curlCmd .= ' -H "User-Agent: ' . $ua . '"';
        $curlCmd .= ' "' . $url . '"';

        $out[] = [
            'index' => $idx,
            'quality' => $s['quality'] ?: ('جودة ' . ($idx + 1)),
            'proxy_url' => $proxyUrl,
            'direct_url' => $url,
            'headers' => [
                'Referer' => $ref,
                'User-Agent' => $ua
            ],
            'commands' => [
                'vlc' => $vlcCmd,
                'mpv' => $mpvCmd,
                'curl' => $curlCmd
            ]
        ];
    }

    echo json_encode([
        'success' => !empty($out),
        'id' => $id,
        'streams' => $out
    ]);
    exit;
}

if ($action === 'matches') {
    header('Content-Type: application/json; charset=utf-8');
    $resp = fetch_yacine_api('/api/events');
    $events = $resp['data'] ?? [];
    if (isset($events['id'])) $events = [$events];

    $matches = [];
    foreach ($events as $idx => $ev) {
        $evId = (string)($ev['id'] ?? $idx);
        $t1 = $ev['team_1'] ?? [];
        $t2 = $ev['team_2'] ?? [];
        $matches[] = [
            'id' => $evId,
            'championship' => $ev['champions'] ?? ($ev['championship'] ?? 'مباراة اليوم'),
            'channel' => $ev['channel'] ?? 'beIN SPORTS',
            'commentary' => $ev['commentary'] ?? 'معلق القمة',
            'time' => $ev['match_time'] ?? ($ev['time'] ?? 'مباشر'),
            'team1' => ['name' => $t1['name'] ?? 'فريق 1', 'logo' => $t1['logo'] ?? ''],
            'team2' => ['name' => $t2['name'] ?? 'فريق 2', 'logo' => $t2['logo'] ?? '']
        ];
    }

    echo json_encode(['success' => true, 'matches' => $matches]);
    exit;
}

// -----------------------------------------------------------------------------
// Frontend HTML Dashboard (Quiet Matte Dark Theme - Zero Glow)
// -----------------------------------------------------------------------------
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title><?= APP_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/hls.js@1.5.15/dist/hls.min.js"></script>
    <style>
        :root {
            --bg-pitch: #09090b;
            --bg-card: #121216;
            --bg-card-hover: #181820;
            --bg-modal: #0e0e12;
            --card-border: #22222a;
            --red-matte: #c01823;
            --red-matte-dark: #8c1018;
            --green: #10b981;
            --text-main: #f4f4f5;
            --text-muted: #9ca3af;
            --text-sub: #71717a;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Cairo', sans-serif;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            background-color: var(--bg-pitch);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            overflow-x: hidden;
        }

        /* -------------------------------------------------------------
           Clean, Ultra-Simple Header (Matches Button + M3U Download only)
           ------------------------------------------------------------- */
        header {
            background: #0d0d11;
            border-bottom: 1.5px solid var(--card-border);
            padding: 12px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .brand-box {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .brand-badge {
            width: 34px;
            height: 34px;
            background: var(--red-matte);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            color: #fff;
        }

        .brand-titles h1 {
            font-size: 15px;
            font-weight: 800;
            color: var(--text-main);
            line-height: 1.2;
        }

        .brand-titles p {
            font-size: 11px;
            color: var(--text-sub);
            font-weight: 600;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-header {
            background: #17171f;
            border: 1px solid var(--card-border);
            color: var(--text-main);
            padding: 7px 14px;
            border-radius: 8px;
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s ease;
            text-decoration: none;
        }

        .btn-header:hover {
            border-color: var(--red-matte);
            color: #fff;
        }

        .btn-header-red {
            background: var(--red-matte);
            border: 1px solid var(--red-matte-dark);
            color: #fff;
        }

        .btn-header-red:hover {
            background: #d3202c;
        }

        /* Container */
        .main-container {
            max-width: 1300px;
            margin: 0 auto;
            padding: 16px 14px 60px;
            width: 100%;
            flex: 1;
        }

        /* -------------------------------------------------------------
           Categories Bar & Flow
           ------------------------------------------------------------- */
        .sec-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
            padding-bottom: 6px;
            border-bottom: 1px solid var(--card-border);
        }

        .sec-title {
            font-size: 14px;
            font-weight: 800;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .cats-scroll {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            padding-bottom: 8px;
            margin-bottom: 10px;
            scrollbar-width: none;
            -webkit-overflow-scrolling: touch;
        }

        .cats-scroll::-webkit-scrollbar { display: none; }

        .cat-chip {
            flex: 0 0 auto;
            background: var(--bg-card);
            border: 1px solid var(--card-border);
            color: var(--text-muted);
            padding: 7px 15px;
            border-radius: 8px;
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .cat-chip:hover {
            border-color: #3b3b48;
            color: #fff;
        }

        .cat-chip.active {
            background: var(--red-matte);
            border-color: var(--red-matte);
            color: #fff;
        }

        /* Subcategories chips */
        .subs-scroll {
            display: flex;
            gap: 6px;
            overflow-x: auto;
            padding: 4px 0 10px;
            margin-bottom: 12px;
            scrollbar-width: none;
            -webkit-overflow-scrolling: touch;
        }

        .subs-scroll::-webkit-scrollbar { display: none; }

        .sub-chip {
            flex: 0 0 auto;
            background: #14141a;
            border: 1px solid var(--card-border);
            color: var(--text-muted);
            padding: 5px 12px;
            border-radius: 6px;
            font-size: 11.5px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .sub-chip:hover, .sub-chip.active {
            border-color: var(--red-matte);
            color: #fff;
        }

        /* Search input */
        .search-row {
            margin-bottom: 14px;
            position: relative;
        }

        .search-row input {
            width: 100%;
            background: var(--bg-card);
            border: 1px solid var(--card-border);
            border-radius: 8px;
            padding: 10px 38px 10px 14px;
            color: #fff;
            font-size: 13px;
            outline: none;
            transition: 0.15s;
        }

        .search-row input:focus {
            border-color: #3f3f4e;
        }

        .search-row i {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-sub);
            font-size: 13px;
        }

        /* -------------------------------------------------------------
           Clean Channels Grid
           ------------------------------------------------------------- */
        .channels-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 10px;
        }

        .channel-card {
            background: var(--bg-card);
            border: 1px solid var(--card-border);
            border-radius: 10px;
            padding: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .channel-card:hover {
            background: var(--bg-card-hover);
            border-color: #383846;
        }

        .ch-card-main {
            display: flex;
            align-items: center;
            gap: 10px;
            flex: 1;
            min-width: 0;
        }

        .ch-logo-box {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: #09090b;
            border: 1px solid var(--card-border);
            display: grid;
            place-items: center;
            overflow: hidden;
            padding: 4px;
            flex-shrink: 0;
        }

        .ch-logo-box img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .ch-title-box {
            flex: 1;
            min-width: 0;
        }

        .ch-title-box h3 {
            font-size: 13px;
            font-weight: 800;
            color: var(--text-main);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-bottom: 2px;
        }

        .ch-title-box p {
            font-size: 11px;
            color: var(--text-sub);
            font-weight: 600;
        }

        .ch-card-arrow {
            width: 28px;
            height: 28px;
            border-radius: 6px;
            background: #17171f;
            border: 1px solid var(--card-border);
            color: var(--text-muted);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            flex-shrink: 0;
        }

        .channel-card:hover .ch-card-arrow {
            color: #fff;
            border-color: var(--red-matte);
        }

        /* -------------------------------------------------------------
           Modals (Details & Matches)
           ------------------------------------------------------------- */
        .modal-overlay {
            position: fixed;
            inset: 0;
            z-index: 5000;
            background: rgba(0, 0, 0, 0.9);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 12px;
            overflow-y: auto;
        }

        .modal-overlay.open {
            display: flex;
        }

        .modal-box {
            width: 100%;
            max-width: 860px;
            background: var(--bg-modal);
            border: 1.5px solid var(--card-border);
            border-radius: 12px;
            display: flex;
            flex-direction: column;
            max-height: 94vh;
            overflow: hidden;
        }

        .modal-top-bar {
            padding: 12px 14px;
            background: #121217;
            border-bottom: 1px solid var(--card-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .btn-back {
            background: var(--red-matte);
            border: 1px solid var(--red-matte-dark);
            color: #fff;
            padding: 6px 14px;
            border-radius: 6px;
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s ease;
        }

        .btn-back:hover {
            background: #d3202c;
        }

        .modal-title-box {
            display: flex;
            align-items: center;
            gap: 10px;
            flex: 1;
            min-width: 0;
        }

        .modal-title-box img {
            width: 26px;
            height: 26px;
            object-fit: contain;
            border-radius: 4px;
        }

        .modal-title-box h2 {
            font-size: 14.5px;
            font-weight: 800;
            color: var(--text-main);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .modal-close-btn {
            background: transparent;
            border: none;
            color: var(--text-sub);
            font-size: 18px;
            cursor: pointer;
            padding: 4px;
        }

        .modal-close-btn:hover { color: #fff; }

        .modal-body {
            padding: 14px;
            overflow-y: auto;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        /* In-Modal Widescreen Video Player */
        .video-container {
            width: 100%;
            aspect-ratio: 16 / 9;
            background: #000;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid var(--card-border);
            display: none;
        }

        .video-container video {
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
        }

        /* Stream Block */
        .stream-block {
            background: var(--bg-card);
            border: 1px solid var(--card-border);
            border-radius: 8px;
            padding: 12px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .stream-block-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid var(--card-border);
            padding-bottom: 8px;
        }

        .q-badge {
            background: #1b1b22;
            border: 1px solid var(--card-border);
            color: var(--text-main);
            padding: 3px 10px;
            border-radius: 6px;
            font-size: 11.5px;
            font-weight: 700;
        }

        .btn-play {
            background: var(--red-matte);
            border: none;
            color: #fff;
            padding: 5px 12px;
            border-radius: 6px;
            font-size: 11.5px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .field-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .field-label {
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .input-with-copy {
            display: flex;
            align-items: stretch;
            background: #09090b;
            border: 1px solid var(--card-border);
            border-radius: 6px;
            overflow: hidden;
        }

        .input-with-copy:focus-within {
            border-color: #3f3f4e;
        }

        .input-with-copy input {
            flex: 1;
            background: transparent;
            border: none;
            padding: 7px 10px;
            color: #e4e4e7;
            font-size: 11.5px;
            font-family: monospace;
            outline: none;
            direction: ltr;
            text-align: left;
        }

        .copy-btn {
            background: #181820;
            border: none;
            border-right: 1px solid var(--card-border);
            color: var(--text-main);
            padding: 0 12px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
            transition: all 0.15s ease;
        }

        .copy-btn:hover {
            background: #23232f;
        }

        .copy-btn.copied {
            background: var(--green) !important;
            color: #000 !important;
        }

        .proxy-highlight-box {
            border: 1px solid #332124;
            background: #141012;
            border-radius: 6px;
            padding: 8px;
        }

        /* -------------------------------------------------------------
           Matches Modal Inner Cards
           ------------------------------------------------------------- */
        .match-row-card {
            background: var(--bg-card);
            border: 1px solid var(--card-border);
            border-radius: 8px;
            padding: 12px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .match-header-info {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 11px;
            font-weight: 700;
            color: var(--text-sub);
            border-bottom: 1px dashed var(--card-border);
            padding-bottom: 5px;
        }

        .match-teams-box {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 4px 0;
        }

        .team-part {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 1;
            min-width: 0;
        }

        .team-part.right { justify-content: flex-start; text-align: right; }
        .team-part.left { justify-content: flex-end; text-align: left; }

        .team-img {
            width: 28px;
            height: 28px;
            object-fit: contain;
            border-radius: 50%;
        }

        .team-name {
            font-size: 12px;
            font-weight: 800;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .vs-badge {
            font-size: 10px;
            font-weight: 800;
            color: var(--red-matte);
            background: #1b1315;
            border: 1px solid #332124;
            padding: 2px 6px;
            border-radius: 4px;
            margin: 0 4px;
        }

        .match-meta-line {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 10.5px;
            color: var(--text-sub);
            background: #0d0d11;
            padding: 4px 8px;
            border-radius: 4px;
        }

        /* Floating Toast */
        #toast {
            position: fixed;
            bottom: 24px;
            left: 50%;
            transform: translateX(-50%) translateY(50px);
            background: #16161d;
            border: 1px solid var(--card-border);
            color: #fff;
            padding: 8px 18px;
            border-radius: 20px;
            font-size: 12.5px;
            font-weight: 700;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.6);
            opacity: 0;
            pointer-events: none;
            transition: all 0.2s ease;
            z-index: 9999;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        #toast.show {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }

        @media (max-width: 640px) {
            header { padding: 10px 12px; }
            .brand-titles h1 { font-size: 13.5px; }
            .channels-grid { grid-template-columns: 1fr; }
            .modal-box { max-height: 98vh; border-radius: 8px; }
            .modal-top-bar { padding: 10px 12px; }
            .btn-back { padding: 5px 12px; font-size: 11.5px; }
        }
    </style>
</head>
<body>

    <!-- Clean Header (Matches Button + M3U Download only) -->
    <header>
        <div class="brand-box">
            <div class="brand-badge"><i class="fa-solid fa-play"></i></div>
            <div class="brand-titles">
                <h1><?= APP_NAME ?></h1>
                <p>بث مباشر • بروكسي فائق السرعة</p>
            </div>
        </div>

        <div class="header-actions">
            <button type="button" class="btn-header btn-header-red" onclick="openMatchesModal()" title="جدول مباريات اليوم">
                <i class="fa-solid fa-futbol"></i> <span>جدول المباريات</span>
            </button>
            <a href="<?= $selfUrl ?>?m3u=1" class="btn-header" title="تحميل ملف M3U الشامل">
                <i class="fa-solid fa-download"></i> <span>تنزيل M3U</span>
            </a>
        </div>
    </header>

    <!-- Main Content Flow -->
    <div class="main-container">

        <!-- Categories Flow Bar -->
        <div class="sec-head">
            <div class="sec-title">
                <i class="fa-solid fa-layer-group"></i>
                <span id="catSectionTitle">أقسام وباقات البث المباشر</span>
            </div>
        </div>

        <!-- Horizontal Sections Bar -->
        <div class="cats-scroll" id="categoriesScroll"></div>

        <!-- Subcategories Bar (if available) -->
        <div class="subs-scroll" id="subcategoriesScroll" style="display: none;"></div>

        <!-- Search Input -->
        <div class="search-row">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" id="searchInput" placeholder="ابحث عن أي قناة (مثل: beIN SPORTS 1, MBC, الكأس, SSC...)" oninput="onSearch()">
        </div>

        <!-- Clean Channels List -->
        <div class="channels-grid" id="channelsContainer">
            <div style="color:var(--text-sub);font-size:12.5px;padding:12px">
                <i class="fa-solid fa-spinner fa-spin"></i> جاري تحميل القنوات...
            </div>
        </div>

    </div>

    <!-- ===================================================================
         📺 Dedicated Channel Details Modal ("واجهة نسخ الروابط مع زر رجوع")
         =================================================================== -->
    <div class="modal-overlay" id="detailsModal" onclick="closeDetailsOnBg(event)">
        <div class="modal-box">
            <div class="modal-top-bar">
                <button type="button" class="btn-back" onclick="closeDetailsModal()">
                    <i class="fa-solid fa-arrow-right"></i> <span>رجوع للقنوات</span>
                </button>

                <div class="modal-title-box">
                    <img id="modalLogo" src="" alt="Logo" style="display:none;">
                    <h2 id="modalTitle">تفاصيل القناة والبث</h2>
                </div>

                <button type="button" class="modal-close-btn" onclick="closeDetailsModal()" title="إغلاق">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <div class="modal-body">
                <!-- Video Player Container -->
                <div class="video-container" id="videoPlayerBox">
                    <video id="videoPlayer" controls playsinline></video>
                </div>

                <!-- Streams List Container -->
                <div id="streamsContent">
                    <div style="color:var(--text-sub);font-size:12.5px;padding:20px;text-align:center">
                        <i class="fa-solid fa-spinner fa-spin"></i> جاري استخراج مصادر وجودات البث المباشر...
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ===================================================================
         ⚽ Match Schedule Modal ("نافذة جدول المباريات مع نسخ كامل")
         =================================================================== -->
    <div class="modal-overlay" id="matchesModal" onclick="closeMatchesOnBg(event)">
        <div class="modal-box">
            <div class="modal-top-bar">
                <button type="button" class="btn-back" onclick="closeMatchesModal()">
                    <i class="fa-solid fa-arrow-right"></i> <span>رجوع</span>
                </button>

                <div class="modal-title-box">
                    <i class="fa-solid fa-futbol" style="color:var(--red-matte);font-size:16px"></i>
                    <h2>جدول مباريات اليوم المباشرة</h2>
                </div>

                <button type="button" class="btn-header" style="padding:4px 10px;font-size:11.5px" onclick="copyMatchesApiUrl()" title="نسخ رابط API المباريات">
                    <i class="fa-solid fa-code"></i> <span>نسخ رابط JSON API</span>
                </button>

                <button type="button" class="modal-close-btn" onclick="closeMatchesModal()" title="إغلاق">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <div class="modal-body" id="matchesListContainer">
                <div style="color:var(--text-sub);font-size:12.5px;padding:20px;text-align:center">
                    <i class="fa-solid fa-spinner fa-spin"></i> جاري جلب مباريات اليوم...
                </div>
            </div>
        </div>
    </div>

    <!-- Toast Notification -->
    <div id="toast">
        <i class="fa-solid fa-check" style="color:var(--green)"></i>
        <span id="toastMsg">تم النسخ بنجاح!</span>
    </div>

    <script>
        const API_BASE = "<?= $selfUrl ?>";
        let hlsPlayer = null;
        let categories = [];
        let allChannels = [];

        // ---------------------------------------------------------------------
        // 1. Matches Modal Loader
        // ---------------------------------------------------------------------
        async function openMatchesModal() {
            const modal = document.getElementById('matchesModal');
            const container = document.getElementById('matchesListContainer');
            modal.classList.add('open');
            document.body.style.overflow = 'hidden';

            container.innerHTML = '<div style="color:var(--text-sub);font-size:12.5px;padding:20px;text-align:center"><i class="fa-solid fa-spinner fa-spin"></i> جاري جلب مباريات اليوم...</div>';

            try {
                const res = await fetch(API_BASE + '?action=matches');
                const data = await res.json();
                if (!data.success || !data.matches.length) {
                    container.innerHTML = '<div style="color:var(--text-sub);font-size:12.5px;padding:20px;text-align:center">لا توجد مباريات مسجلة اليوم حالياً</div>';
                    return;
                }

                container.innerHTML = data.matches.map(m => `
                    <div class="match-row-card">
                        <div class="match-header-info">
                            <span><i class="fa-solid fa-trophy"></i> ${escapeHtml(m.championship)}</span>
                            <span><i class="fa-regular fa-clock"></i> ${escapeHtml(m.time)}</span>
                        </div>

                        <div class="match-teams-box">
                            <div class="team-part right">
                                <img class="team-img" src="${m.team1.logo || 'https://via.placeholder.com/28'}" onerror="this.src='https://via.placeholder.com/28'">
                                <span class="team-name">${escapeHtml(m.team1.name)}</span>
                            </div>
                            <span class="vs-badge">VS</span>
                            <div class="team-part left">
                                <span class="team-name">${escapeHtml(m.team2.name)}</span>
                                <img class="team-img" src="${m.team2.logo || 'https://via.placeholder.com/28'}" onerror="this.src='https://via.placeholder.com/28'">
                            </div>
                        </div>

                        <div class="match-meta-line">
                            <span><i class="fa-solid fa-tv"></i> ${escapeHtml(m.channel)}</span>
                            <span><i class="fa-solid fa-microphone"></i> ${escapeHtml(m.commentary)}</span>
                        </div>

                        <button type="button" class="btn-header" style="justify-content:center;padding:6px;font-size:12px;margin-top:4px" onclick="closeMatchesModal(); openDetails('${m.id}', '${escapeHtml(m.team1.name + ' × ' + m.team2.name)}', '${m.team1.logo || ''}', true)">
                            <i class="fa-solid fa-bolt"></i> عرض ونسخ مصادر وجودات المباراة
                        </button>
                    </div>
                `).join('');
            } catch (err) {
                container.innerHTML = `<div style="color:#ef4444;font-size:12.5px;padding:12px">تعذر جلب جدول المباريات: ${err.message}</div>`;
            }
        }

        function closeMatchesModal() {
            document.getElementById('matchesModal').classList.remove('open');
            document.body.style.overflow = '';
        }

        function closeMatchesOnBg(e) {
            if (e.target.id === 'matchesModal') closeMatchesModal();
        }

        // ---------------------------------------------------------------------
        // 2. Categories & Channels Flow
        // ---------------------------------------------------------------------
        async function loadCategories() {
            const scroll = document.getElementById('categoriesScroll');
            try {
                const res = await fetch(API_BASE + '?action=categories');
                const data = await res.json();
                if (!data.success || !data.categories.length) {
                    scroll.innerHTML = '<div style="color:var(--text-sub);font-size:12.5px">لا توجد أقسام متاحة</div>';
                    return;
                }

                categories = data.categories;
                scroll.innerHTML = categories.map((c, idx) => `
                    <div class="cat-chip ${idx === 0 ? 'active' : ''}" id="cat-chip-${c.id}" onclick="selectCategory('${c.id}')">
                        ${escapeHtml(c.name)}
                    </div>
                `).join('');

                selectCategory(categories[0].id);
            } catch (err) {
                scroll.innerHTML = `<div style="color:#ef4444;font-size:12.5px">خطأ في جلب الأقسام: ${err.message}</div>`;
            }
        }

        async function selectCategory(catId) {
            document.querySelectorAll('.cat-chip').forEach(el => el.classList.remove('active'));
            const chip = document.getElementById('cat-chip-' + catId);
            if (chip) chip.classList.add('active');

            const cat = categories.find(c => c.id == catId);
            const subScroll = document.getElementById('subcategoriesScroll');

            if (cat && cat.child_count > 0) {
                subScroll.style.display = 'flex';
                subScroll.innerHTML = '<div style="color:var(--text-sub);padding:4px;font-size:11.5px">جارٍ جلب الباقات الفرعية...</div>';
                try {
                    const res = await fetch(API_BASE + '?action=subcategories&id=' + catId);
                    const data = await res.json();
                    if (data.success && data.subcategories.length) {
                        subScroll.innerHTML = data.subcategories.map((s, idx) => `
                            <div class="sub-chip ${idx === 0 ? 'active' : ''}" id="sub-chip-${s.id}" onclick="selectSubcategory('${s.id}')">
                                ${escapeHtml(s.name)}
                            </div>
                        `).join('');
                        selectSubcategory(data.subcategories[0].id);
                    } else {
                        subScroll.style.display = 'none';
                        loadChannels(catId);
                    }
                } catch (e) {
                    subScroll.style.display = 'none';
                    loadChannels(catId);
                }
            } else {
                subScroll.style.display = 'none';
                loadChannels(catId);
            }
        }

        function selectSubcategory(subId) {
            document.querySelectorAll('.sub-chip').forEach(el => el.classList.remove('active'));
            const chip = document.getElementById('sub-chip-' + subId);
            if (chip) chip.classList.add('active');
            loadChannels(subId);
        }

        async function loadChannels(id) {
            const container = document.getElementById('channelsContainer');
            container.innerHTML = '<div style="color:var(--text-sub);font-size:12.5px;padding:12px"><i class="fa-solid fa-spinner fa-spin"></i> جاري تحميل القنوات...</div>';

            try {
                const res = await fetch(API_BASE + '?action=channels&id=' + id);
                const data = await res.json();
                if (!data.success || !data.channels.length) {
                    container.innerHTML = '<div style="color:var(--text-sub);font-size:12.5px;padding:12px">لا توجد قنوات متاحة في هذا القسم</div>';
                    return;
                }

                allChannels = data.channels;
                renderChannels(allChannels);
            } catch (err) {
                container.innerHTML = `<div style="color:#ef4444;font-size:12.5px;padding:12px">خطأ في جلب القنوات: ${err.message}</div>`;
            }
        }

        function renderChannels(chs) {
            const container = document.getElementById('channelsContainer');
            if (!chs.length) {
                container.innerHTML = '<div style="color:var(--text-sub);font-size:12.5px;padding:12px">لا توجد قنوات مطابقة لبحثك</div>';
                return;
            }

            container.innerHTML = chs.map(ch => `
                <div class="channel-card" onclick="openDetails('${ch.id}', '${escapeHtml(ch.name)}', '${ch.logo || ''}', false)">
                    <div class="ch-card-main">
                        <div class="ch-logo-box">
                            <img src="${ch.logo || 'https://via.placeholder.com/40'}" onerror="this.src='https://via.placeholder.com/40'">
                        </div>
                        <div class="ch-title-box">
                            <h3>${escapeHtml(ch.name)}</h3>
                            <p>بث مباشر • اضغط لفتح الروابط والمشاهدة</p>
                        </div>
                    </div>
                    <div class="ch-card-arrow">
                        <i class="fa-solid fa-play"></i>
                    </div>
                </div>
            `).join('');
        }

        function onSearch() {
            const q = document.getElementById('searchInput').value.trim().toLowerCase();
            if (!q) {
                renderChannels(allChannels);
                return;
            }
            const filtered = allChannels.filter(c => c.name.toLowerCase().includes(q));
            renderChannels(filtered);
        }

        // ---------------------------------------------------------------------
        // 3. Dedicated Channel Details & Copy Modal ("واجهة نسخ الروابط مع زر رجوع")
        // ---------------------------------------------------------------------
        async function openDetails(id, title, logo, isMatch) {
            const modal = document.getElementById('detailsModal');
            const modalTitle = document.getElementById('modalTitle');
            const modalLogo = document.getElementById('modalLogo');
            const streamsContent = document.getElementById('streamsContent');

            stopPlayer();

            modalTitle.textContent = title;
            if (logo) {
                modalLogo.src = logo;
                modalLogo.style.display = 'block';
            } else {
                modalLogo.style.display = 'none';
            }

            streamsContent.innerHTML = '<div style="color:var(--text-sub);font-size:12.5px;padding:24px;text-align:center"><i class="fa-solid fa-spinner fa-spin"></i> جاري فك تشفير المصادر واستخراج الجودات والروابط...</div>';
            modal.classList.add('open');
            document.body.style.overflow = 'hidden';

            try {
                const url = API_BASE + '?action=streams&id=' + id + (isMatch ? '&is_match=1' : '');
                const res = await fetch(url);
                const data = await res.json();

                if (!data.success || !data.streams.length) {
                    streamsContent.innerHTML = '<div style="color:#ef4444;font-size:12.5px;padding:20px;text-align:center">البث المباشر غير متوفر حالياً لهذه القناة أو مغلق من المصدر</div>';
                    return;
                }

                renderStreamsDetails(data.streams);
            } catch (err) {
                streamsContent.innerHTML = `<div style="color:#ef4444;font-size:12.5px;padding:20px;text-align:center">تعذر استخراج الروابط: ${err.message}</div>`;
            }
        }

        function renderStreamsDetails(streams) {
            const container = document.getElementById('streamsContent');
            container.innerHTML = streams.map(s => {
                const idx = s.index;
                const fProxy = `f_proxy_${idx}`;
                const fDirect = `f_direct_${idx}`;
                const fRef = `f_ref_${idx}`;
                const fUa = `f_ua_${idx}`;
                const fJson = `f_json_${idx}`;
                const fVlc = `f_vlc_${idx}`;
                const fMpv = `f_mpv_${idx}`;
                const fCurl = `f_curl_${idx}`;

                return `
                    <div class="stream-block">
                        <div class="stream-block-header">
                            <span class="q-badge"><i class="fa-solid fa-tv"></i> الجودة: ${escapeHtml(s.quality)}</span>
                            <button type="button" class="btn-play" onclick="playInModal('${escapeHtml(s.proxy_url)}')">
                                <i class="fa-solid fa-play"></i> تشغيل فوري بالمشغل
                            </button>
                        </div>

                        <!-- ⚡ Ultra-Fast Permanent Unified Stream Proxy (No 302, 100% Reliable) -->
                        <div class="proxy-highlight-box">
                            <div class="field-group">
                                <div class="field-label">
                                    <span style="color:#f87171;font-weight:800"><i class="fa-solid fa-bolt"></i> رابط البروكسي المباشر الدائم (شغال 100% بدون هيدرز ولا يقطع):</span>
                                    <span style="color:var(--green)">يعمل بكل المشغلات والشاشات</span>
                                </div>
                                <div class="input-with-copy">
                                    <input type="text" readonly value="${escapeHtml(s.proxy_url)}" id="${fProxy}">
                                    <button type="button" class="copy-btn" onclick="copyInput('${fProxy}', this)">
                                        <i class="fa-solid fa-copy"></i> نسخ البروكسي
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Direct CDN Raw URL -->
                        <div class="field-group">
                            <div class="field-label">رابط البث الأصلي المباشر (Direct CDN URL):</div>
                            <div class="input-with-copy">
                                <input type="text" readonly value="${escapeHtml(s.direct_url)}" id="${fDirect}">
                                <button type="button" class="copy-btn" onclick="copyInput('${fDirect}', this)">
                                    <i class="fa-solid fa-link"></i> نسخ الرابط الأصلي
                                </button>
                            </div>
                        </div>

                        <!-- Referer Header -->
                        <div class="field-group">
                            <div class="field-label">ترويسة Referer:</div>
                            <div class="input-with-copy">
                                <input type="text" readonly value="${escapeHtml(s.headers.Referer || '')}" id="${fRef}">
                                <button type="button" class="copy-btn" onclick="copyInput('${fRef}', this)">
                                    <i class="fa-solid fa-shield"></i> نسخ Referer
                                </button>
                            </div>
                        </div>

                        <!-- User-Agent Header -->
                        <div class="field-group">
                            <div class="field-label">ترويسة User-Agent:</div>
                            <div class="input-with-copy">
                                <input type="text" readonly value="${escapeHtml(s.headers['User-Agent'] || '')}" id="${fUa}">
                                <button type="button" class="copy-btn" onclick="copyInput('${fUa}', this)">
                                    <i class="fa-solid fa-globe"></i> نسخ User-Agent
                                </button>
                            </div>
                        </div>

                        <!-- JSON Headers -->
                        <div class="field-group">
                            <div class="field-label">الهيدرز كاملة بصيغة JSON:</div>
                            <div class="input-with-copy">
                                <input type="text" readonly value="${escapeHtml(JSON.stringify(s.headers))}" id="${fJson}">
                                <button type="button" class="copy-btn" onclick="copyInput('${fJson}', this)">
                                    <i class="fa-solid fa-code"></i> نسخ JSON
                                </button>
                            </div>
                        </div>

                        <!-- VLC Command -->
                        <div class="field-group">
                            <div class="field-label">أمر تشغيل VLC جاهز:</div>
                            <div class="input-with-copy">
                                <input type="text" readonly value="${escapeHtml(s.commands.vlc)}" id="${fVlc}">
                                <button type="button" class="copy-btn" onclick="copyInput('${fVlc}', this)">
                                    <i class="fa-solid fa-terminal"></i> نسخ أمر VLC
                                </button>
                            </div>
                        </div>

                        <!-- MPV Command -->
                        <div class="field-group">
                            <div class="field-label">أمر تشغيل MPV:</div>
                            <div class="input-with-copy">
                                <input type="text" readonly value="${escapeHtml(s.commands.mpv)}" id="${fMpv}">
                                <button type="button" class="copy-btn" onclick="copyInput('${fMpv}', this)">
                                    <i class="fa-solid fa-terminal"></i> نسخ أمر MPV
                                </button>
                            </div>
                        </div>

                        <!-- cURL Command -->
                        <div class="field-group">
                            <div class="field-label">أمر cURL:</div>
                            <div class="input-with-copy">
                                <input type="text" readonly value="${escapeHtml(s.commands.curl)}" id="${fCurl}">
                                <button type="button" class="copy-btn" onclick="copyInput('${fCurl}', this)">
                                    <i class="fa-solid fa-terminal"></i> نسخ أمر cURL
                                </button>
                            </div>
                        </div>
                    </div>
                `;
            }).join('');
        }

        // ---------------------------------------------------------------------
        // 4. In-Modal Widescreen Video Player
        // ---------------------------------------------------------------------
        function playInModal(proxyUrl) {
            const playerBox = document.getElementById('videoPlayerBox');
            const video = document.getElementById('videoPlayer');
            playerBox.style.display = 'block';

            stopPlayer();

            if (Hls.isSupported()) {
                hlsPlayer = new Hls({
                    enableWorker: true,
                    lowLatencyMode: false,
                    backBufferLength: 30
                });
                hlsPlayer.loadSource(proxyUrl);
                hlsPlayer.attachMedia(video);
                hlsPlayer.on(Hls.Events.MANIFEST_PARSED, () => {
                    video.play().catch(() => {});
                });
            } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
                video.src = proxyUrl;
                video.play().catch(() => {});
            }

            document.querySelector('#detailsModal .modal-body').scrollTo({ top: 0, behavior: 'smooth' });
        }

        function stopPlayer() {
            const video = document.getElementById('videoPlayer');
            if (video) {
                video.pause();
                video.removeAttribute('src');
                video.load();
            }
            if (hlsPlayer) {
                hlsPlayer.destroy();
                hlsPlayer = null;
            }
            document.getElementById('videoPlayerBox').style.display = 'none';
        }

        function closeDetailsModal() {
            stopPlayer();
            document.getElementById('detailsModal').classList.remove('open');
            document.body.style.overflow = '';
        }

        function closeDetailsOnBg(e) {
            if (e.target.id === 'detailsModal') {
                closeDetailsModal();
            }
        }

        // ---------------------------------------------------------------------
        // 5. Copy & Utilities
        // ---------------------------------------------------------------------
        function copyInput(id, btn) {
            const el = document.getElementById(id);
            if (!el) return;
            navigator.clipboard.writeText(el.value).then(() => {
                showToast('تم النسخ بنجاح! ✓');
                if (btn) {
                    const orig = btn.innerHTML;
                    btn.classList.add('copied');
                    btn.innerHTML = '<i class="fa-solid fa-check"></i> تم النسخ';
                    setTimeout(() => {
                        btn.classList.remove('copied');
                        btn.innerHTML = orig;
                    }, 1800);
                }
            }).catch(() => {
                showToast('تعذر النسخ التلقائي');
            });
        }

        function copyMatchesApiUrl() {
            navigator.clipboard.writeText(API_BASE + '?api=matches').then(() => {
                showToast('تم نسخ رابط JSON API لجدول المباريات! ✓');
            });
        }

        function showToast(msg) {
            const t = document.getElementById('toast');
            document.getElementById('toastMsg').textContent = msg;
            t.classList.add('show');
            setTimeout(() => t.classList.remove('show'), 2500);
        }

        function escapeHtml(s) {
            if (!s) return '';
            return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        window.addEventListener('DOMContentLoaded', () => {
            loadCategories();
        });
    </script>
</body>
</html>
