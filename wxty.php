<?php
/*
转发代码本身或转化代码为PY等均请注明出处（本频道链接：https://t.me/iptvorganization），否则生个孩子没屁眼
*/
date_default_timezone_set('Asia/Shanghai');

define('LOCAL_HTTPS_PROXY_PREFIX', getenv('KK_PROXY_PREFIX') !== false
    ? getenv('KK_PROXY_PREFIX')
    : '');

const LIVE_URL_CACHE_TTL = 600;
const LIVE_URL_MIN_LEFT  = 90;
const DONOR_SCAN_DAYS    = 7;

const FALLBACK_LIVE_URL  = 'https://cdn.jsdelivr.net/gh/jkwu5472/first/media.m3u8';

function kkFail($reason) {
    $GLOBALS['KK_FAIL_REASON'] = $reason;
    error_log('[wxty] ' . $reason);
}

function redirectToFallback($reason = '') {
    if ($reason === '') $reason = $GLOBALS['KK_FAIL_REASON'] ?? 'unknown';
    error_log('[wxty] fallback -> ' . FALLBACK_LIVE_URL . ' (reason: ' . $reason . ')');
    header('X-Fallback-Reason: ' . preg_replace('/[^A-Za-z0-9_\-]/', '', $reason));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Location: ' . FALLBACK_LIVE_URL, true, 302);
    exit;
}

const KANKAN_API_BASE    = 'https://kapi.kankanews.com';
const KANKAN_API_VERSION = '2.42.23';
const KANKAN_API_SECRET  = '28c8edde3d61a0411511d3b1866f0636';
const KANKAN_UA          = 'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/116.0.5845.97 Safari/537.36 SE 2.X MetaSr 1.0';

function toLocalProxyUrl($url) {
    if (LOCAL_HTTPS_PROXY_PREFIX === '') return $url;
    return stripos($url, 'https://') === 0
        ? LOCAL_HTTPS_PROXY_PREFIX . substr($url, 8)
        : $url;
}

function getCacheDir() {
    $dir = sys_get_temp_dir() . '/kankan-live-url-' . substr(sha1(__FILE__), 0, 12);
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}

function getLiveUrlCacheFile($channelId) {
    return getCacheDir() . '/channel-' . intval($channelId) . '.url';
}

function readCachedLiveUrl($channelId) {
    $file = getLiveUrlCacheFile($channelId);
    if (!is_file($file)) return false;
    if (time() - filemtime($file) >= LIVE_URL_CACHE_TTL) return false;
    $raw = trim((string) file_get_contents($file));
    if ($raw === '') return false;
    $url = $raw;
    $exp = 0;
    if ($raw[0] === '{') {
        $meta = json_decode($raw, true);
        if (!is_array($meta)) return false;
        $url = (string) ($meta['url'] ?? '');
        $exp = (int) ($meta['exp'] ?? 0);
    }
    if (stripos($url, 'https://') !== 0) return false;
    if ($exp > 0 && $exp - time() < LIVE_URL_MIN_LEFT) return false;
    return $url;
}

function writeCachedLiveUrl($channelId, $url) {
    $exp = urlExpiry($url);
    if ($exp <= 0) $exp = time() + 300;
    $file = getLiveUrlCacheFile($channelId);
    $payload = json_encode(['url' => $url, 'exp' => $exp], JSON_UNESCAPED_SLASHES);
    if (file_put_contents($file, $payload, LOCK_EX) !== false) @chmod($file, 0600);
}

function clearCachedLiveUrl($channelId) {
    $file = getLiveUrlCacheFile($channelId);
    if (is_file($file)) @unlink($file);
}

function getnonce($len = 8) {
    $alphabet = '0123456789abcdefghijklmnopqrstuvwxyz';
    $nonce = '';
    for ($i = 0; $i < $len; $i++) {
        $nonce .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $nonce;
}

function getuuid($len = 21) {
    return substr(strtr(base64_encode(random_bytes(16)), '+/', '-_'), 0, $len);
}

function stableUuid() {
    static $uuid = null;
    if ($uuid !== null) return $uuid;
    $file = getCacheDir() . '/uuid';
    $cur = is_file($file) ? trim((string) file_get_contents($file)) : '';
    if ($cur === '' || !preg_match('/^[A-Za-z0-9_-]{16,32}$/', $cur)) {
        $cur = getuuid();
        @file_put_contents($file, $cur, LOCK_EX);
        @chmod($file, 0600);
    }
    return $uuid = $cur;
}

function kankanPublicKeyPem() {
    return "-----BEGIN PUBLIC KEY-----\n"
        . "MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQDP5hzPUW5RFeE2xBT1ERB3hHZI\n"
        . "Votn/qatWhgc1eZof09qKjElFN6Nma461ZAwGpX4aezKP8Adh4WJj4u2O54xCXDt\n"
        . "wzKRqZO2oNZkuNmF2Va8kLgiEQAAcxYc8JgTN+uQQNpsep4n/o1sArTJooZIF17E\n"
        . "tSqSgXDcJ7yDj5rc7wIDAQAB\n"
        . "-----END PUBLIC KEY-----";
}

function rsaAsn1Integers($der) {
    $ints = [];
    $len = strlen($der);
    for ($i = 0; $i + 2 < $len;) {
        if ($der[$i] !== "\x02") { $i++; continue; }
        $l = ord($der[$i + 1]);
        $start = $i + 2;
        if ($l & 0x80) {
            $n = $l & 0x7f;
            $l = 0;
            for ($j = 0; $j < $n; $j++) $l = ($l << 8) | ord($der[$start + $j]);
            $start += $n;
        }
        if ($start + $l <= $len) {
            $ints[] = bin2hex(ltrim(substr($der, $start, $l), "\x00"));
            $i = $start + $l;
        } else {
            $i++;
        }
    }
    return $ints;
}

function rsaHexToDec($hex) {
    $dec = '0';
    $hex = ltrim(strtolower($hex), '0');
    if ($hex === '') return '0';
    for ($i = 0; $i < strlen($hex); $i++) {
        $dec = bcadd(bcmul($dec, '16'), (string) hexdec($hex[$i]));
    }
    return $dec;
}

function rsaDecToHex($dec) {
    if (bccomp($dec, '0') <= 0) return '0';
    $hex = '';
    while (bccomp($dec, '0') > 0) {
        $hex = dechex((int) bcmod($dec, '16')) . $hex;
        $dec = bcdiv($dec, '16', 0);
    }
    return $hex;
}

function rsaPublicDecryptChunk($chunkBin, $nHex, $eHex) {
    $cHex = bin2hex($chunkBin);
    if (function_exists('gmp_init') && function_exists('gmp_powm')) {
        $m = gmp_powm(gmp_init($cHex, 16), gmp_init($eHex, 16), gmp_init($nHex, 16));
        $mHex = gmp_strval($m, 16);
    } else {
        $m = bcpowmod(rsaHexToDec($cHex), rsaHexToDec($eHex), rsaHexToDec($nHex));
        $mHex = rsaDecToHex($m);
    }
    $block = hex2bin(str_pad($mHex, 256, '0', STR_PAD_LEFT));
    if ($block === false || strlen($block) !== 128) return '';
    if ($block[0] !== "\x00" || $block[1] !== "\x01") return '';
    $j = 2;
    while ($j < 128 && $block[$j] === "\xFF") $j++;
    if ($j >= 128 || $block[$j] !== "\x00") return '';
    return substr($block, $j + 1);
}

function rsaDecrypt($str) {
    if (!is_string($str) || $str === '') return false;
    $encData = base64_decode($str, true);
    if ($encData === false || strlen($encData) < 128) return false;

    if (function_exists('gmp_powm') || function_exists('bcpowmod')) {
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', kankanPublicKeyPem()));
        $ints = rsaAsn1Integers($der);
        if (count($ints) >= 2) {
            list($nHex, $eHex) = $ints;
            $result = '';
            for ($i = 0; $i + 128 <= strlen($encData); $i += 128) {
                $result .= rsaPublicDecryptChunk(substr($encData, $i, 128), $nHex, $eHex);
            }
            if ($result !== '') return $result;
        }
    }

    if (function_exists('openssl_public_decrypt')) {
        $pkey = @openssl_pkey_get_public(kankanPublicKeyPem());
        if ($pkey !== false) {
            $result = '';
            for ($i = 0; $i + 128 <= strlen($encData); $i += 128) {
                $out = '';
                if (@openssl_public_decrypt(substr($encData, $i, 128), $out, $pkey, OPENSSL_PKCS1_PADDING)) {
                    $result .= $out;
                }
            }
            if ($result !== '') return $result;
        }
    }

    return false;
}

function kankanApiGet($path, $params = []) {
    $t = time();
    $nonce = getnonce(8);
    $uuid = stableUuid();

    $all = $params;
    $all['Api-Version'] = 'v1';
    $all['nonce'] = $nonce;
    $all['platform'] = 'pc';
    $all['timestamp'] = $t;
    $all['version'] = KANKAN_API_VERSION;
    ksort($all);

    $signStr = '';
    foreach ($all as $k => $v) {
        if ($v === null) continue;
        if (is_array($v)) {
            $v = json_encode($v, JSON_UNESCAPED_SLASHES);
            $v = str_replace('/', '\\/', $v);
        }
        $signStr .= $k . '=' . $v . '&';
    }
    $sign = md5(md5($signStr . KANKAN_API_SECRET));

    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $clientIp = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    } elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $clientIp = $_SERVER['HTTP_CLIENT_IP'];
    }

    $headers = [
        'Api-Version: v1', "Nonce: {$nonce}", "M-Uuid: {$uuid}",
        'Platform: pc', 'Version: ' . KANKAN_API_VERSION, "Timestamp: {$t}", "Sign: {$sign}",
        'Accept: application/json, text/plain, */*',
        'Origin: https://live.kankanews.com', 'Referer: https://live.kankanews.com/',
        'User-Agent: ' . KANKAN_UA,
    ];
    if ($clientIp !== '') {
        $headers[] = "X-Forwarded-For: {$clientIp}";
        $headers[] = "Client-Ip: {$clientIp}";
    }

    $url = toLocalProxyUrl(KANKAN_API_BASE . $path . '?' . http_build_query($params));
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    if ($resp === false) return null;
    $data = json_decode($resp, true);
    return is_array($data) ? $data : null;
}

function pickProgramAddress($programDetail) {
    $ci = $programDetail['result']['channel_info'] ?? [];
    $live  = (string) ($ci['live_address'] ?? '');
    $shift = (string) ($ci['shift_address'] ?? '');
    if ($live !== '') return ['addr' => $live, 'kind' => 'live'];
    if ($shift !== '') return ['addr' => $shift, 'kind' => 'shift'];
    return null;
}

function fetchPrograms($channelId, $date) {
    $res = kankanApiGet('/content/pc/tv/programs', ['channel_id' => $channelId, 'date' => $date]);
    return $res['result']['programs'] ?? [];
}

function fetchProgramDetail($programId) {
    return kankanApiGet('/content/pc/tv/program/detail', ['channel_program_id' => $programId]);
}

function fetchPlayAddress($channelId) {

    $detail = kankanApiGet('/content/pc/tv/channel/detail', ['channel_id' => $channelId]);
    $chanLive = (string) ($detail['result']['live_address'] ?? '');
    if ($chanLive !== '') {
        return ['addr' => $chanLive, 'kind' => 'live', 'source' => 'channel'];
    }

    $today = date('Y-m-d');
    $list = fetchPrograms($channelId, $today);
    $now = time();
    $current = null;
    foreach ($list as $p) {
        $s = (int) ($p['start_time'] ?? 0);
        $e = (int) ($p['end_time'] ?? 0);
        if ($s <= $now && $now < $e) { $current = $p; break; }
    }
    if ($current) {
        $pd = fetchProgramDetail($current['id']);
        $pick = pickProgramAddress($pd);
        if ($pick) {
            return ['addr' => $pick['addr'], 'kind' => $pick['kind'],
                    'source' => 'current-program', 'program' => $current];
        }
    }

    for ($off = 0; $off < DONOR_SCAN_DAYS; $off++) {
        $date = date('Y-m-d', strtotime("-{$off} day"));
        if ($off === 0 && $list) {
            $dayList = $list;
        } else {
            $dayList = fetchPrograms($channelId, $date);
        }
        if (!$dayList) continue;

        $candidates = [];
        foreach ($dayList as $p) {
            $reviewable = ((int) ($p['can_review'] ?? 0) === 1) || ((int) ($p['is_review'] ?? 0) === 1);
            if (!$reviewable) continue;
            $candidates[] = $p;
        }

        usort($candidates, function ($a, $b) {
            $sa = (strpos((string) ($a['name'] ?? ''), '体育') !== false) ? 0 : 1;
            $sb = (strpos((string) ($b['name'] ?? ''), '体育') !== false) ? 0 : 1;
            return $sa <=> $sb;
        });

        foreach ($candidates as $p) {
            $pd = fetchProgramDetail($p['id']);
            $pick = pickProgramAddress($pd);

            if ($pick && $pick['kind'] === 'shift') {
                return ['addr' => $pick['addr'], 'kind' => 'shift',
                        'source' => 'donor', 'program' => $p];
            }
            if ($pick && !isset($fallbackShift)) {
                $fallbackShift = ['addr' => $pick['addr'], 'kind' => $pick['kind'],
                                  'source' => 'donor', 'program' => $p];
            }
        }
    }
    if (isset($fallbackShift)) return $fallbackShift;

    return null;
}

function urlExpiry($url) {
    $parts = parse_url($url);
    if (empty($parts['query'])) return 0;
    parse_str($parts['query'], $q);

    $cands = [];
    foreach (['volcTime', 'txTime', 'wsTime'] as $k) {
        if (!empty($q[$k])) {
            $v = (string) $q[$k];
            $cands[] = ctype_xdigit($v) && !ctype_digit($v) ? hexdec($v) : (int) $v;
        }
    }
    if (!empty($q['token'])) {
        $parts2 = explode('.', (string) $q['token']);
        if (count($parts2) >= 2) {
            $payload = json_decode(base64_decode(strtr($parts2[1], '-_', '+/')), true);
            if (is_array($payload) && !empty($payload['exp'])) $cands[] = (int) $payload['exp'];
        }
    }
    $cands = array_filter($cands, function ($v) { return $v > 1600000000; });
    return $cands ? min($cands) : 0;
}

function normalizeLiveUrl($url) {
    $parts = parse_url($url);
    if (empty($parts['scheme']) || empty($parts['host'])) return $url;
    $query = $parts['query'] ?? '';
    if ($query !== '') {
        $keep = [];
        foreach (explode('&', $query) as $kv) {
            if ($kv === '') continue;
            $key = explode('=', $kv, 2)[0];
            if (in_array(strtolower($key), ['start', 'end', 'starttime', 'endtime'], true)) continue;
            $keep[] = $kv;
        }
        $query = implode('&', $keep);
    }
    $out = $parts['scheme'] . '://' . $parts['host'];
    if (isset($parts['port'])) $out .= ':' . $parts['port'];
    $out .= $parts['path'] ?? '';
    if ($query !== '') $out .= '?' . $query;
    return $out;
}

function getLiveUrl($channelId, $forceRefresh = false, &$cacheHit = null, &$meta = null) {
    $cacheHit = false;
    $meta = ['source' => 'cache'];
    if (!$forceRefresh) {
        $cached = readCachedLiveUrl($channelId);
        if ($cached !== false) {
            $cacheHit = true;
            return $cached;
        }
    }
    $found = fetchPlayAddress($channelId);
    if (!$found) {
        kkFail('address-failed: 未取到可用播放地址(is_shield 屏蔽且无供体)');
        return false;
    }
    $plain = rsaDecrypt($found['addr']);
    if (!$plain) {
        kkFail('decrypt-failed: RSA 解密失败 (source=' . $found['source'] . ')');
        return false;
    }
    $liveUrl = normalizeLiveUrl($plain);
    if (!$liveUrl) {
        kkFail('bad-url: 解密结果不是合法 URL');
        return false;
    }
    $meta = [
        'source'      => $found['source'],
        'kind'        => $found['kind'],
        'program'     => $found['program']['name'] ?? null,
        'program_id'  => $found['program']['id'] ?? null,
        'shift_url'   => $plain,
    ];
    writeCachedLiveUrl($channelId, $liveUrl);
    return $liveUrl;
}

function fetchContent($url) {
    $headers = [
        'User-Agent: ' . KANKAN_UA,
        'Accept: */*', 'Accept-Language: zh-CN,zh;q=0.9',
        'Origin: https://live.kankanews.com', 'Referer: https://live.kankanews.com/',
    ];

    for ($attempt = 0; $attempt < 2; $attempt++) {
        $ch = curl_init(toLocalProxyUrl($url));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($data !== false && $data !== '' && $code === 200) return $data;
    }
    return null;
}

function toAbsoluteUrl($base, $relative) {
    $relative = (string) $relative;
    if ($relative === '') return $base;
    if (preg_match('/^https?:\/\//i', $relative)) return $relative;
    $parts = parse_url($base);
    $baseDir = dirname($parts['path'] ?? '/') . '/';
    if ($relative[0] === '/') $relative = ltrim($relative, '/');
    return $parts['scheme'] . '://' . $parts['host'] . $baseDir . $relative;
}

function rewritePlaylistTagUris($line, $base) {
    return preg_replace_callback('/URI="([^"]+)"/i', function ($matches) use ($base) {
        return 'URI="' . toAbsoluteUrl($base, $matches[1]) . '"';
    }, $line);
}

function getFlatM3u8($url, $depth = 0) {
    if ($depth > 5) return '';
    $content = fetchContent($url);
    if (!$content) return '';
    $lines = preg_split('/\r\n|\n|\r/', $content);
    $output = [];
    $count = count($lines);
    $i = 0;
    while ($i < $count) {
        $line = trim($lines[$i]);
        if (strpos($line, '#EXT-X-STREAM-INF') === 0) {

            $i++;
            if ($i >= $count) break;
            $subUrl = trim($lines[$i]);
            if ($subUrl === '' || $subUrl[0] === '#') { $i++; continue; }
            $subContent = getFlatM3u8(toAbsoluteUrl($url, $subUrl), $depth + 1);
            if ($subContent) return $subContent;
        } elseif (strpos($line, '#EXTINF:') === 0) {
            $output[] = $line;
            $i++;
            if ($i >= $count) break;
            $tsUrl = trim($lines[$i]);
            if ($tsUrl !== '' && $tsUrl[0] !== '#') {
                $output[] = toAbsoluteUrl($url, $tsUrl);
            } else {
                $output[] = $tsUrl;
            }
        } elseif ($line !== '') {
            $output[] = rewritePlaylistTagUris($line, $url);
        }
        $i++;
    }
    $joined = implode("\n", $output);

    return strpos($joined, '#EXTINF') !== false ? $joined : '';
}

$channelMap = [
    'dfws'   => 1,
    'shxwzh' => 2,
    'shds'   => 4,
    'dycj'   => 5,
    'hhxd'   => 9,
    'wxty'   => 10,
    'mdy'    => 11,
    'jsrw'   => 12,
];

$id = $_GET['id'] ?? 'wxty';
if (!isset($channelMap[$id])) {
    http_response_code(400);
    die('无效频道ID');
}
$channelId = $channelMap[$id];

if (isset($_GET['ts_url'])) {
    $ts_url = urldecode((string) $_GET['ts_url']);
    if (!preg_match('/^https?:\/\//i', $ts_url)) {
        http_response_code(400);
        exit('Invalid url');
    }
    $path = parse_url($ts_url, PHP_URL_PATH);
    $filename = basename((string) $path);

    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.(ts|m4s|mp4|aac|key|vtt)$/', $filename)
        || strpos($filename, '..') !== false) {
        http_response_code(400);
        exit('Invalid filename');
    }
    $cache_dir = __DIR__ . '/ts_cache';
    if (!is_dir($cache_dir)) @mkdir($cache_dir, 0755, true);
    $cache_file = $cache_dir . '/' . $filename;
    $cache_ttl = 86400;

    if (is_file($cache_file) && (time() - filemtime($cache_file)) < $cache_ttl) {
        $fp = fopen($cache_file, 'rb');
        if ($fp && flock($fp, LOCK_SH)) {
            header('Content-Type: video/MP2T');
            header('Cache-Control: max-age=' . $cache_ttl);
            fpassthru($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
            exit;
        }
        if ($fp) fclose($fp);
    }

    $headers = [
        'User-Agent: ' . KANKAN_UA,
        'Accept: */*', 'Accept-Language: zh-CN,zh;q=0.9',
        'Origin: https://live.kankanews.com', 'Referer: https://live.kankanews.com/',
        'Connection: keep-alive',
    ];
    $ch = curl_init(toLocalProxyUrl($ts_url));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $data = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($data === false || $httpCode !== 200) {
        http_response_code(502);
        echo '无法获取 TS 分片';
        exit;
    }

    $fp = fopen($cache_file, 'wb');
    if ($fp && flock($fp, LOCK_EX)) {
        fwrite($fp, $data);
        flock($fp, LOCK_UN);
        fclose($fp);
    } elseif ($fp) {
        fclose($fp);
    }

    header('Content-Type: video/MP2T');
    header('Cache-Control: max-age=' . $cache_ttl);
    echo $data;
    exit;
}

$forceRefresh = isset($_GET['refresh']) && $_GET['refresh'] !== '0';

$cacheHit = false;
$meta = null;
$liveUrl = getLiveUrl($channelId, $forceRefresh, $cacheHit, $meta);
if (!$liveUrl) {

    redirectToFallback();
}

$finalM3u8 = getFlatM3u8($liveUrl);
if (!$finalM3u8 && $cacheHit) {

    clearCachedLiveUrl($channelId);
    $liveUrl = getLiveUrl($channelId, true, $cacheHit, $meta);
    if ($liveUrl) $finalM3u8 = getFlatM3u8($liveUrl);
}
if (!$finalM3u8) {

    redirectToFallback('playlist-fetch-failed: 拉取播放列表失败');
}

if (isset($_GET['info'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'id'          => $id,
        'channel_id'  => $channelId,
        'source'      => $meta['source'] ?? null,
        'kind'        => $meta['kind'] ?? null,
        'program'     => $meta['program'] ?? null,
        'program_id'  => $meta['program_id'] ?? null,
        'live_url'    => $liveUrl,
        'cache'       => $cacheHit ? 'HIT' : 'MISS',
        'expires_in'  => max(0, urlExpiry($liveUrl) - time()),
        'segment_count' => substr_count($finalM3u8, '#EXTINF'),
        'fallback_url'  => FALLBACK_LIVE_URL,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

header('Content-Type: application/vnd.apple.mpegurl');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Live-Url-Cache: ' . ($cacheHit ? 'HIT' : 'MISS'));
header('X-Live-Url-Source: ' . ($meta['source'] ?? '-'));
echo $finalM3u8;
