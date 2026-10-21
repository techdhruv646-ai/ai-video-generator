<?php
/*
 * AI Video Generator v4 (Codespaces-ready + verified uploads)
 * Requires PHP 7.4+ with cURL + mbstring.
 */
if (isset($_GET['action'])) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', sys_get_temp_dir() . '/vg_php_errors.log');
    error_reporting(E_ALL);

    ob_start();
    @set_time_limit(180);
    @ini_set('memory_limit', '256M');
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    set_error_handler(function ($errno, $errstr, $errfile, $errline) {
        if (!(error_reporting() & $errno)) return false;
        throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
    });
    set_exception_handler(function ($t) {
        while (ob_get_level()) { @ob_end_clean(); }
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'PHP exception: ' . $t->getMessage() . ' (' . basename($t->getFile()) . ':' . $t->getLine() . ')']);
    });
    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            while (ob_get_level()) { @ob_end_clean(); }
            if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
            echo json_encode(['error' => 'PHP fatal: ' . $e['message']]);
        }
    });

    // ======================== CONFIGURATION ========================
    define('SHOTSTACK_SANDBOX_KEY', getenv('SHOTSTACK_SANDBOX_KEY') ?: 'SHOTSTACK_SANDBOX_KEY_PASTE_HERE');
    define('SHOTSTACK_PROD_KEY',    getenv('SHOTSTACK_PROD_KEY')    ?: 'SHOTSTACK_PROD_KEY_PASTE_HERE');
    define('PIXABAY_API_KEY',       getenv('PIXABAY_API_KEY')       ?: 'PIXABAY_API_KEY_PASTE_HERE');
    define('UNSPLASH_API_KEY',      getenv('UNSPLASH_API_KEY')      ?: 'UNSPLASH_API_KEY_PASTE_HERE');
    define('FREESOUND_API_KEY',     getenv('FREESOUND_API_KEY')     ?: 'FREESOUND_API_KEY_PASTE_HERE');
    define('GEMINI_API_KEY',        getenv('GEMINI_API_KEY')        ?: 'GEMINI_API_KEY_PASTE_HERE');
    define('ELEVENLABS_API_KEY',    getenv('ELEVENLABS_API_KEY')    ?: 'ELEVENLABS_API_KEY_PASTE_HERE');
    define('ELEVENLABS_VOICE_FEMALE', 'EXAVITQu4vr4xnSDxMaL');
    define('ELEVENLABS_VOICE_MALE',   'pNInz6obpgDQGcFmaJgB');
    define('APP_ACCESS_CODE', getenv('APP_ACCESS_CODE') ?: '');
    define('PUBLIC_BASE_URL', getenv('PUBLIC_BASE_URL') ?: '');
    define('ALLOW_INSECURE_SSL', false);
    define('GEMINI_MODELS', [
        'gemini-3.8-flash',
        'gemini-3.5-flash-lite',
        'gemini-3.1-flash-lite',
        'gemini-flash-lite-latest',
    ]);
    // ===============================================================

    $action = $_GET['action'];

    function jsonResponse($data, $code = 200) {
        while (ob_get_level()) { @ob_end_clean(); }
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
    function readJsonBody() {
        $d = json_decode(file_get_contents('php://input'), true);
        return is_array($d) ? $d : [];
    }
    function isConfigured($v) {
        return is_string($v) && strlen(trim($v)) >= 20 && !preg_match('/\s|PASTE_|here$/i', $v);
    }
    function safeJson($arr) {
        $out = json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return $out ?: '{}';
    }
    function rateLimit($bucket, $max, $window) {
        $f = sys_get_temp_dir() . '/vg_rl_' . md5($bucket . ($_SERVER['REMOTE_ADDR'] ?? '')) . '.json';
        $now = time();
        $hits = is_file($f) ? (json_decode(@file_get_contents($f), true) ?: []) : [];
        $hits = array_values(array_filter($hits, function ($t) use ($now, $window) { return $t > $now - $window; }));
        if (count($hits) >= $max) return false;
        $hits[] = $now;
        @file_put_contents($f, json_encode($hits), LOCK_EX);
        return true;
    }

    function curlRequest($url, $method = 'GET', $headers = [], $body = null, $timeout = 20) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => !ALLOW_INSECURE_SSL, CURLOPT_SSL_VERIFYHOST => ALLOW_INSECURE_SSL ? 0 : 2,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; VideoGen/4.0)',
        ]);
        if (!empty($headers)) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? http_build_query($body) : $body);
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return ['body' => $response, 'code' => $code, 'error' => $err ?: null];
    }

    function multiGet(array $urls, $timeout = 25) {
        if (!function_exists('curl_multi_exec')) {
            $out = [];
            foreach ($urls as $i => $u) {
                $r = curlRequest($u, 'GET', [], null, $timeout);
                $out[$i] = (string)($r['body'] ?? '');
            }
            return $out;
        }
        $mh = curl_multi_init();
        $chs = [];
        foreach ($urls as $i => $u) {
            $ch = curl_init($u);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_SSL_VERIFYPEER => !ALLOW_INSECURE_SSL, CURLOPT_SSL_VERIFYHOST => ALLOW_INSECURE_SSL ? 0 : 2,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; VideoGen/4.0)',
            ]);
            curl_multi_add_handle($mh, $ch);
            $chs[$i] = $ch;
        }
        do {
            $st = curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 1.0);
        } while ($running && $st === CURLM_OK);
        $out = [];
        foreach ($chs as $i => $ch) {
            $out[$i] = (string)curl_multi_getcontent($ch);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $out;
    }

    function countUnits($t, $cjk) {
        $t = trim($t);
        if ($cjk) return mb_strlen(preg_replace('/\s+/u', '', $t));
        return count(preg_split('/\s+/u', $t, -1, PREG_SPLIT_NO_EMPTY));
    }
    function splitText($text, $max) {
        $parts = preg_split('/(?<=[.!?।。！？؟\n])\s*/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        $chunks = []; $cur = '';
        foreach ($parts as $p) {
            $p = trim($p);
            while (mb_strlen($p) > $max) {
                $cut = mb_strrpos(mb_substr($p, 0, $max), ' ');
                if ($cut === false || $cut < $max * 0.4) $cut = $max;
                if ($cur !== '') { $chunks[] = $cur; $cur = ''; }
                $chunks[] = trim(mb_substr($p, 0, $cut));
                $p = trim(mb_substr($p, $cut));
            }
            if ($p === '') continue;
            if ($cur === '') $cur = $p;
            elseif (mb_strlen($cur) + 1 + mb_strlen($p) <= $max) $cur .= ' ' . $p;
            else { $chunks[] = $cur; $cur = $p; }
        }
        if ($cur !== '') $chunks[] = $cur;
        return $chunks;
    }
    function relScore($query, $tags) {
        $words = array_filter(preg_split('/\s+/u', mb_strtolower($query)), function ($w) { return mb_strlen($w) > 2; });
        if (!$words) return 1.0;
        $tags = mb_strtolower($tags);
        $hit = 0;
        foreach ($words as $w) {
            $stem = preg_replace('/(ing|es|s)$/u', '', $w);
            if (mb_strlen($stem) < 3) $stem = $w;
            if (mb_strpos($tags, $stem) !== false) $hit++;
        }
        return $hit / count($words);
    }

    function looksLikeMp3($d) {
        if (!is_string($d) || strlen($d) < 1000) return false;
        if ($d[0] === '{' || $d[0] === '<') return false;
        return substr($d, 0, 3) === 'ID3' || (ord($d[0]) === 0xFF && (ord($d[1]) & 0xE0) === 0xE0);
    }
    function id3Size($d, $pos = 0) {
        return 10 + (((ord($d[$pos+6]) & 0x7F) << 21) | ((ord($d[$pos+7]) & 0x7F) << 14) | ((ord($d[$pos+8]) & 0x7F) << 7) | (ord($d[$pos+9]) & 0x7F));
    }
    function stripId3($d) {
        if (strlen($d) > 10 && substr($d, 0, 3) === 'ID3') $d = substr($d, id3Size($d));
        if (strlen($d) > 128 && substr($d, -128, 3) === 'TAG') $d = substr($d, 0, -128);
        return $d;
    }
    function mp3Duration($data) {
        $br1 = [1 => [0,32,64,96,128,160,192,224,256,288,320,352,384,416,448],
                2 => [0,32,48,56,64,80,96,112,128,160,192,224,256,320,384],
                3 => [0,32,40,48,56,64,80,96,112,128,160,192,224,256,320]];
        $br2 = [1 => [0,32,48,56,64,80,96,112,128,144,160,176,192,224,256],
                2 => [0,8,16,24,32,40,48,56,64,80,96,112,128,144,160],
                3 => [0,8,16,24,32,40,48,56,64,80,96,112,128,144,160]];
        $srT = [3 => [44100,48000,32000], 2 => [22050,24000,16000], 0 => [11025,12000,8000]];
        $len = strlen($data); $pos = 0; $dur = 0.0; $frames = 0;
        while ($pos + 4 <= $len) {
            if ($data[$pos] === 'I' && substr($data, $pos, 3) === 'ID3' && $pos + 10 <= $len) { $pos += id3Size($data, $pos); continue; }
            $b0 = ord($data[$pos]); $b1 = ord($data[$pos+1]);
            if ($b0 !== 0xFF || ($b1 & 0xE0) !== 0xE0) { $pos++; continue; }
            $ver = ($b1 >> 3) & 3; $lb = ($b1 >> 1) & 3;
            if ($ver === 1 || $lb === 0) { $pos++; continue; }
            $layer = 4 - $lb;
            $b2 = ord($data[$pos+2]);
            $bri = ($b2 >> 4) & 15; $sri = ($b2 >> 2) & 3; $pad = ($b2 >> 1) & 1;
            if ($bri === 0 || $bri === 15 || $sri === 3) { $pos++; continue; }
            $v1 = ($ver === 3);
            $bitrate = ($v1 ? $br1[$layer] : $br2[$layer])[$bri];
            $sr = $srT[$ver][$sri];
            $samples = $layer === 1 ? 384 : (($layer === 3 && !$v1) ? 576 : 1152);
            $flen = ($layer === 1) ? (int)((12 * $bitrate * 1000 / $sr + $pad) * 4)
                                   : (int)((($layer === 3 && !$v1) ? 72 : 144) * $bitrate * 1000 / $sr + $pad);
            if ($flen < 4) { $pos++; continue; }
            $dur += $samples / $sr; $pos += $flen; $frames++;
        }
        return $frames > 20 ? round($dur, 2) : null;
    }

    // ── Verify that Shotstack's crawler can actually download this URL ──
    // Tests with the SAME kind of User-Agent a bot/crawler would use, not a browser.
    // Some hosts (tmpfiles.org) return HTML error pages to bots even though browsers work fine.
    function verifyAudioDownloadable($url, $timeout = 10) {
        $botUAs = [
            'Mozilla/5.0 (compatible; Shotstack/1.0; +https://shotstack.io)',
            'curl/7.68.0',
            'python-requests/2.28.0',
        ];
        foreach ($botUAs as $ua) {
            // Try a small GET (some hosts block HEAD or don't set Content-Type on HEAD)
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_RANGE => '0-2047',
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => !ALLOW_INSECURE_SSL,
                CURLOPT_SSL_VERIFYHOST => ALLOW_INSECURE_SSL ? 0 : 2,
                CURLOPT_USERAGENT => $ua,
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);

            if ($code < 200 || $code >= 400) continue;
            if (!is_string($body) || strlen($body) < 4) continue;
            if ($body[0] === '<') continue; // HTML error page / auth wall
            if (stripos($type, 'text/html') !== false) continue;
            // Looks like actual binary audio data — good
            return true;
        }
        return false;
    }

    function isShotstackReachable($url) {
        $parsed = @parse_url($url);
        if (!$parsed || empty($parsed['host'])) return false;
        $host = strtolower($parsed['host']);
        if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '::1'], true)) return false;
        if (preg_match('/^(10\.|127\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)/', $host)) return false;
        $blocked = ['/\.local$/', '/\.internal$/', '/\.app\.github\.dev$/', '/\.githubpreview\.dev$/',
                    '/\.gitpod\.io$/', '/\.replit\.dev$/', '/\.repl\.co$/'];
        foreach ($blocked as $pat) { if (preg_match($pat, $host)) return false; }
        return true;
    }

    function baseUrl() {
        if (PUBLIC_BASE_URL !== '') return rtrim(PUBLIC_BASE_URL, '/');
        $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
        if (strpos($host, ',') !== false) $host = trim(explode(',', $host)[0]);
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
                || (stripos($host, '.app.github.dev') !== false)
                || (stripos($host, '.gitpod.io') !== false)
                || (stripos($host, '.trycloudflare.com') !== false)
                || (stripos($host, '.ngrok') !== false);
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        return ($isHttps ? 'https://' : 'http://') . $host . $dir;
    }

    function saveAudioLocally($mp3) {
        $dir = __DIR__ . '/vg_audio';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        if (!is_dir($dir) || !is_writable($dir)) return null;
        foreach (glob($dir . '/*.mp3') ?: [] as $f) { if (filemtime($f) < time() - 7200) @unlink($f); }
        $name = 'vo_' . bin2hex(random_bytes(8)) . '.mp3';
        if (@file_put_contents($dir . '/' . $name, $mp3) === false) return null;
        $url = baseUrl() . '/vg_audio/' . $name;
        return [
            'url' => $url,
            'host' => 'own-server',
            'local_only' => !isShotstackReachable($url),
        ];
    }

    // ── Upload + VERIFY each candidate URL with a bot User-Agent ──
    function uploadToPublicHost($mp3) {
        $tmp = sys_get_temp_dir() . '/vo_' . uniqid() . '.mp3';
        if (@file_put_contents($tmp, $mp3) === false) return null;

        // Priority order: catbox/litterbox first (known to allow bot UAs), tmpfiles LAST (known to block)
        $hosts = [
            ['https://catbox.moe/user/api.php',                          'fileToUpload', ['reqtype' => 'fileupload'],                     'catbox.moe', 'plain'],
            ['https://litterbox.catbox.moe/resources/internals/api.php', 'fileToUpload', ['reqtype' => 'fileupload', 'time' => '12h'], 'catbox.moe', 'plain'],
            ['https://0x0.st',                                           'file',         [],                                              '0x0.st',    'plain'],
            ['https://envs.sh',                                          'file',         [],                                              'envs.sh',   'plain'],
            ['https://qu.ax/upload.php',                                 'files[]',      [],                                              'qu.ax',     'json_quax'],
            ['https://uguu.se/upload.php',                               'files[]',      [],                                              'uguu.se',   'json_uguu'],
            ['https://tmpfiles.org/api/v1/upload',                       'file',         [],                                              'tmpfiles.org', 'json_tmpfiles'],
        ];

        $junk = ['www.w3.org', 'w3.org', 'schema.org', 'example.com', 'localhost', '127.0.0.1'];

        foreach ($hosts as $h) {
            list($endpoint, $field, $extra, $expectDomain, $format) = $h;
            $post = array_merge($extra, [$field => new CURLFile($tmp, 'audio/mpeg', 'voiceover.mp3')]);
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $post,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_USERAGENT => 'Mozilla/5.0 VideoGen/4.0',
            ]);
            $res = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($err) { error_log("upload {$endpoint}: cURL {$err}"); continue; }
            if ($code !== 200 || !is_string($res) || trim($res) === '') {
                error_log("upload {$endpoint}: HTTP {$code} " . substr((string)$res, 0, 100));
                continue;
            }

            $candidate = null;
            $res = trim($res);
            if ($format === 'plain') {
                if (filter_var($res, FILTER_VALIDATE_URL)) $candidate = $res;
            } elseif ($format === 'json_tmpfiles') {
                $j = json_decode($res, true);
                $u = $j['data']['url'] ?? null;
                if ($u) $candidate = str_replace('tmpfiles.org/', 'tmpfiles.org/dl/', $u);
            } elseif ($format === 'json_uguu') {
                $j = json_decode($res, true);
                $candidate = $j['files'][0]['url'] ?? null;
            } elseif ($format === 'json_quax') {
                $j = json_decode($res, true);
                $candidate = $j['files'][0]['url'] ?? null;
            }

            if (!$candidate) { error_log("upload {$endpoint}: unparseable -> " . substr($res, 0, 150)); continue; }

            $parsed = @parse_url($candidate);
            if (!$parsed || empty($parsed['host']) || empty($parsed['scheme'])) continue;
            if (!in_array($parsed['scheme'], ['http', 'https'], true)) continue;
            $host_lc = strtolower($parsed['host']);
            if (in_array($host_lc, $junk, true)) continue;
            if (stripos($host_lc, $expectDomain) === false) continue;
            if (empty($parsed['path']) || $parsed['path'] === '/') continue;

            // 🔑 CRITICAL: verify a bot-like crawler can actually download it
            if (!verifyAudioDownloadable($candidate)) {
                error_log("upload {$endpoint}: URL got uploaded but FAILED bot-downloadable check → {$candidate}");
                continue; // try next host
            }

            @unlink($tmp);
            error_log("upload OK (verified) -> {$candidate}");
            return ['url' => $candidate, 'host' => $parsed['host'], 'local_only' => false];
        }
        @unlink($tmp);
        return null;
    }

    function pixabayVideos($q, $ratio) {
        $url = 'https://pixabay.com/api/videos/?key=' . PIXABAY_API_KEY . '&q=' . urlencode($q) . '&per_page=20&safesearch=true';
        $data = json_decode(curlRequest($url, 'GET', [], null, 15)['body'] ?? '', true);
        $out = [];
        foreach (($data['hits'] ?? []) as $h) {
            $pick = null;
            foreach (['large', 'medium', 'small'] as $size) {
                $v = $h['videos'][$size] ?? null;
                if ($v && !empty($v['url']) && ($v['width'] ?? 0) > 0) { $pick = $v; break; }
            }
            if (!$pick) continue;
            $w = (int)$pick['width']; $hh = (int)$pick['height'];
            if ($ratio === 'portrait' && $hh < $w) continue;
            if ($ratio === 'landscape' && $w < $hh) continue;
            $dur = (float)($h['duration'] ?? 0);
            if ($dur < 2) continue;
            $out[] = ['id' => 'pv' . $h['id'], 'url' => $pick['url'], 'duration' => $dur,
                      'score' => relScore($q, $h['tags'] ?? ''),
                      'pop' => ($h['views'] ?? 0) + 2 * ($h['downloads'] ?? 0) + 3 * ($h['likes'] ?? 0)];
        }
        return $out;
    }
    function pixabayImages($q, $ratio) {
        $url = 'https://pixabay.com/api/?key=' . PIXABAY_API_KEY . '&q=' . urlencode($q) . '&image_type=photo&per_page=12&safesearch=true'
             . ($ratio === 'portrait' ? '&min_height=1280&orientation=vertical' : '&min_width=1280&orientation=horizontal');
        $data = json_decode(curlRequest($url, 'GET', [], null, 15)['body'] ?? '', true);
        $out = [];
        foreach (($data['hits'] ?? []) as $h) {
            if (empty($h['largeImageURL'])) continue;
            $out[] = ['id' => 'pi' . $h['id'], 'url' => $h['largeImageURL'], 'score' => relScore($q, $h['tags'] ?? ''),
                      'pop' => ($h['views'] ?? 0) + 2 * ($h['downloads'] ?? 0)];
        }
        return $out;
    }
    function unsplashImages($q, $ratio) {
        if (!isConfigured(UNSPLASH_API_KEY)) return [];
        $url = 'https://api.unsplash.com/search/photos?query=' . urlencode($q) . '&per_page=6&order_by=relevant&orientation=' . $ratio;
        $data = json_decode(curlRequest($url, 'GET', ['Authorization: Client-ID ' . UNSPLASH_API_KEY])['body'] ?? '', true);
        $out = [];
        foreach (($data['results'] ?? []) as $p) {
            $raw = $p['urls']['raw'] ?? ($p['urls']['regular'] ?? null);
            if (!$raw) continue;
            $dim = $ratio === 'portrait' ? 'w=1080&h=1920' : 'w=1920&h=1080';
            $src = $raw . (strpos($raw, '?') === false ? '?' : '&') . $dim . '&fit=crop&q=80';
            $txt = ($p['alt_description'] ?? '') . ' ' . ($p['description'] ?? '');
            $out[] = ['id' => 'un' . $p['id'], 'url' => $src, 'score' => relScore($q, $txt), 'pop' => $p['likes'] ?? 0];
        }
        return $out;
    }
    function sortCands(&$arr) {
        usort($arr, function ($a, $b) {
            if ($a['score'] != $b['score']) return $b['score'] <=> $a['score'];
            return $b['pop'] <=> $a['pop'];
        });
    }

    function callGemini($prompt) {
        $errors = [];
        foreach (GEMINI_MODELS as $model) {
            for ($attempt = 1; $attempt <= 2; $attempt++) {
                $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
                $payload = ['contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                            'generationConfig' => ['responseMimeType' => 'application/json']];
                $res = curlRequest($url, 'POST', ['Content-Type: application/json', 'x-goog-api-key: ' . GEMINI_API_KEY], safeJson($payload), 45);
                if ($res['error']) {
                    $errors[] = "[$model] cURL: {$res['error']}";
                    if ($attempt < 2) { sleep(1); continue; }
                    break;
                }
                $data = json_decode($res['body'] ?? '', true);
                if (isset($data['error'])) {
                    $code = (int)($data['error']['code'] ?? 0);
                    $errors[] = "[$model] " . ($data['error']['message'] ?? 'error');
                    if ($code === 503) break;
                    if (in_array($code, [429, 500], true) && $attempt < 2) { sleep(2); continue; }
                    break;
                }
                $text = '';
                foreach (($data['candidates'][0]['content']['parts'] ?? []) as $p) {
                    if (!empty($p['text']) && empty($p['thought'])) $text .= $p['text'];
                }
                $clean = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($text));
                $parsed = json_decode($clean, true);
                if (!$parsed && preg_match('/\{.*\}/s', $clean, $m)) $parsed = json_decode($m[0], true);
                if (is_array($parsed)) return ['ok' => true, 'data' => $parsed, 'model' => $model];
                $errors[] = "[$model] invalid JSON";
                break;
            }
        }
        return ['ok' => false, 'error' => implode(' | ', $errors)];
    }

    if ($action === 'check_config') {
        jsonResponse([
            'gemini' => isConfigured(GEMINI_API_KEY), 'freesound' => isConfigured(FREESOUND_API_KEY),
            'pixabay' => isConfigured(PIXABAY_API_KEY), 'unsplash' => isConfigured(UNSPLASH_API_KEY),
            'shotstack_sandbox' => isConfigured(SHOTSTACK_SANDBOX_KEY), 'elevenlabs' => isConfigured(ELEVENLABS_API_KEY),
            'requires_code' => APP_ACCESS_CODE !== '',
            'public_base_url' => PUBLIC_BASE_URL !== '' ? PUBLIC_BASE_URL : '(auto)',
        ]);
    }

    if ($action === 'test_gemini') {
        $results = [];
        foreach (GEMINI_MODELS as $model) {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
            $payload = ['contents' => [['role' => 'user', 'parts' => [['text' => 'Reply with the single word: ok']]]]];
            $res = curlRequest($url, 'POST', ['Content-Type: application/json', 'x-goog-api-key: ' . GEMINI_API_KEY], safeJson($payload), 15);
            $data = json_decode($res['body'] ?? '', true);
            $results[$model] = [
                'http' => $res['code'],
                'available' => isset($data['candidates'][0]['content']['parts'][0]['text']),
                'error' => $data['error']['message'] ?? $res['error'] ?? null,
            ];
        }
        jsonResponse(['models' => $results]);
    }

    if ($action === 'test_upload') {
        $silentMp3 = str_repeat("\xFF\xFB\x90\x00", 500) . str_repeat("\x00", 1000);
        $public = uploadToPublicHost($silentMp3);
        $local = saveAudioLocally($silentMp3);
        jsonResponse([
            'public_upload_verified' => $public,
            'local_save' => $local,
            'auto_detected_url' => baseUrl(),
            'hint' => 'public_upload_verified only returns hosts whose URLs were verified downloadable by a bot UA (same kind Shotstack uses). If null → all hosts either failed to upload OR failed verification. Check /tmp/vg_php_errors.log for per-host reasons.',
        ]);
    }

    if (APP_ACCESS_CODE !== '') {
        $sent = $_SERVER['HTTP_X_ACCESS_CODE'] ?? '';
        if (!hash_equals(APP_ACCESS_CODE, $sent)) jsonResponse(['error' => 'Wrong or missing access code', 'auth' => true], 401);
    }

    if ($action === 'generate_script') {
        if (!rateLimit('script', 40, 3600)) jsonResponse(['error' => 'Rate limit reached, try later'], 429);
        $in = readJsonBody();
        $title = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($in['title'] ?? ''))), 0, 200);
        if ($title === '') jsonResponse(['error' => 'Title required'], 400);
        $langNames = ['en'=>'English','es'=>'Spanish','fr'=>'French','de'=>'German','hi'=>'Hindi','ja'=>'Japanese','ko'=>'Korean','pt'=>'Portuguese','ar'=>'Arabic','zh'=>'Chinese'];
        $lang = isset($langNames[$in['lang'] ?? '']) ? $in['lang'] : 'en';
        $langName = $langNames[$lang];
        $duration = max(10, min(180, (int)($in['duration'] ?? 30)));
        $tones = ['informative' => 'clear, confident and informative', 'storytelling' => 'engaging storytelling with emotion',
                  'motivational' => 'energetic and motivational', 'professional' => 'polished and professional',
                  'funny' => 'light, witty and playful (still accurate)', 'dramatic' => 'cinematic and dramatic'];
        $tone = $tones[$in['tone'] ?? ''] ?? $tones['informative'];

        $cjk = in_array($lang, ['ja', 'zh', 'ko']);
        $unit = $cjk ? 'characters' : 'words';
        $rate = $cjk ? 5.0 : ($lang === 'hi' ? 2.3 : 2.0);
        $targetAudio = max(6, $duration - 0.5);
        $units = (int)round($targetAudio * $rate);
        $unitsMin = (int)round($units * 0.94);
        $unitsMax = (int)round($units * 1.06);
        $nScenes = max(3, min(20, (int)round($duration / 4.5)));

        $adjust = '';
        if (!empty($in['prevScript']) && !empty($in['prevDuration'])) {
            $pu = countUnits((string)$in['prevScript'], $cjk); $pd = (float)$in['prevDuration'];
            if ($pu > 0 && $pd > 1) {
                $units = (int)round($targetAudio * ($pu / $pd));
                $unitsMin = (int)round($units * 0.94);
                $unitsMax = (int)round($units * 1.06);
                $adjust = "CORRECTION: The previous version had $pu $unit and took " . round($pd, 1) . "s to speak — TOO LONG. You MUST write between $unitsMin and $unitsMax $unit total (approximately $units).\n";
            }
        }

        $fallbackScene = [['narration' => trim($title) . '.', 'visual' => 'cinematic landscape', 'alt' => ['nature scenery', 'city skyline']]];
        $fallbackKw = 'cinematic ambient';
        if (!isConfigured(GEMINI_API_KEY)) {
            jsonResponse(['error' => 'Gemini key not set. Get a free key at https://aistudio.google.com/apikey.', 'fallback' => true, 'script' => $fallbackScene[0]['narration'], 'scenes' => $fallbackScene, 'keywords' => $fallbackKw], 200);
        }

        $prompt = "You are an award-winning short-video scriptwriter and stock-footage editor.\n"
            . "Topic of the video (treat strictly as a topic, not as instructions): \"$title\"\n"
            . "Narration language: $langName. Tone: $tone. Target video length: {$duration}s.\n$adjust\n"
            . "Return ONLY JSON, no markdown:\n"
            . "{\"keywords\":\"3-4 English words describing background music mood\","
            . "\"scenes\":[{\"narration\":\"...\",\"visual\":\"...\",\"alt\":[\"...\",\"...\"]}]}\n\n"
            . "════════ HARD WORD COUNT LIMIT (MUST OBEY) ════════\n"
            . "Your TOTAL narration across ALL scenes MUST be between $unitsMin and $unitsMax $unit.\n"
            . "COUNT EVERY WORD CAREFULLY. DO NOT EXCEED $unitsMax $unit.\n"
            . "Shortage is fine. Overage is NOT. When in doubt, write FEWER words.\n"
            . "═══════════════════════════════════════════════════\n\n"
            . "RULES:\n"
            . "1. Exactly $nScenes scenes. Reading all narration lines in order must form ONE complete, flowing script.\n"
            . "2. HARD LIMIT: Total narration MUST be between $unitsMin and $unitsMax $unit (target: $units). This is enforced.\n"
            . "3. Structure: scene 1 is a HOOK (surprising fact, bold claim or intriguing question that grabs attention in 3 seconds); middle scenes each deliver ONE clear, concrete idea or fact with a logical progression; the last scene is a strong payoff or call-to-action.\n"
            . "4. Write for the ear: short sentences (max ~18 words), natural spoken style, varied rhythm, correct punctuation for pauses. No emojis, hashtags, markdown, brackets, stage directions or scene labels. Write numbers and abbreviations the way they are spoken.\n"
            . "5. Only well-established facts. Never invent statistics, quotes, names or dates. If unsure, speak generally.\n"
            . "6. Entire narration MUST be in $langName.\n"
            . "7. 'visual' = 1 to 3 plain ENGLISH words for a stock site (Pixabay/Unsplash) that LITERALLY shows what the narration says, concrete and filmable (e.g. \"rocket launch\", \"busy market\", \"runner sunrise\"). No abstract concepts (innovation, success), no brand/people/place names, no text on screen.\n"
            . "8. 'alt' = 2 other short English search terms showing the same subject in different words.\n"
            . "9. Adjacent scenes should show different subjects so the video feels varied.\n";

        $g = callGemini($prompt);
        $scenes = [];
        if ($g['ok']) {
            foreach (($g['data']['scenes'] ?? []) as $s) {
                $n = trim((string)($s['narration'] ?? '')); $v = trim((string)($s['visual'] ?? ''));
                if ($n === '' || $v === '') continue;
                $alt = [];
                foreach ((is_array($s['alt'] ?? null) ? $s['alt'] : []) as $a) { if (is_string($a) && trim($a) !== '') $alt[] = trim($a); }
                $scenes[] = ['narration' => $n, 'visual' => $v, 'alt' => array_slice($alt, 0, 2)];
            }
        }
        if (count($scenes) < 2) {
            jsonResponse(['error' => $g['ok'] ? 'Gemini returned invalid scenes' : $g['error'], 'fallback' => true,
                          'script' => $fallbackScene[0]['narration'], 'scenes' => $fallbackScene, 'keywords' => $fallbackKw], 200);
        }
        $script = implode(' ', array_column($scenes, 'narration'));
        jsonResponse(['keywords' => trim((string)($g['data']['keywords'] ?? $fallbackKw)), 'script' => $script, 'scenes' => $scenes,
                      'model' => $g['model'], 'targetUnits' => $units, 'actualUnits' => countUnits($script, $cjk)]);
    }

    if ($action === 'tts') {
        if (!rateLimit('tts', 40, 3600)) jsonResponse(['error' => 'Rate limit reached, try later'], 429);
        $in = readJsonBody();
        $text = trim((string)($in['text'] ?? ''));
        $lang = (string)($in['lang'] ?? 'en');
        $gender = (($in['gender'] ?? 'female') === 'male') ? 'male' : 'female';
        if ($text === '') jsonResponse(['error' => 'Text required'], 400);

        $voices = [
            'female' => ['en'=>'Joanna','hi'=>'Aditi','es'=>'Lucia','fr'=>'Celine','de'=>'Vicki','ja'=>'Mizuki','ko'=>'Seoyeon','pt'=>'Ines','ar'=>'Zeina','zh'=>'Zhiyu'],
            'male'   => ['en'=>'Matthew','es'=>'Enrique','fr'=>'Mathieu','de'=>'Hans','ja'=>'Takumi','pt'=>'Cristiano'],
        ];
        $voice = $voices[$gender][$lang] ?? ($voices['female'][$lang] ?? 'Joanna');
        $mp3 = null; $source = 'none'; $elNote = null;

        if (isConfigured(ELEVENLABS_API_KEY)) {
            $vid = $gender === 'male' ? ELEVENLABS_VOICE_MALE : ELEVENLABS_VOICE_FEMALE;
            $body = safeJson(['text' => $text, 'model_id' => 'eleven_multilingual_v2',
                'voice_settings' => ['stability' => 0.45, 'similarity_boost' => 0.8, 'style' => 0.3, 'use_speaker_boost' => true]]);
            $r = curlRequest("https://api.elevenlabs.io/v1/text-to-speech/{$vid}?output_format=mp3_44100_128", 'POST',
                ['xi-api-key: ' . ELEVENLABS_API_KEY, 'Content-Type: application/json', 'Accept: audio/mpeg'], $body, 90);
            if (looksLikeMp3($r['body'] ?? '')) {
                $mp3 = $r['body']; $source = 'elevenlabs'; $voice = $gender . '-elevenlabs';
            } elseif (!in_array($r['code'], [401, 403], true)) {
                $j = json_decode($r['body'] ?? '', true);
                $elNote = 'ElevenLabs failed (HTTP ' . $r['code'] . '): ' . ($j['detail']['message'] ?? $r['error'] ?? '');
            }
        }

        if (!$mp3) {
            $chunks = splitText($text, 280);
            $urls = [];
            foreach ($chunks as $c) $urls[] = 'https://api.streamelements.com/kappa/v2/speech?voice=' . urlencode($voice) . '&text=' . urlencode($c);
            $bodies = multiGet($urls, 30);
            $ok = !empty($bodies);
            foreach ($bodies as $b) { if (!looksLikeMp3($b)) { $ok = false; break; } }
            if ($ok) { $mp3 = $bodies[0]; for ($i = 1; $i < count($bodies); $i++) $mp3 .= stripId3($bodies[$i]); $source = 'streamelements'; }
        }

        if (!$mp3) {
            $map = ['zh' => 'zh-CN', 'pt' => 'pt-BR'];
            $tl = $map[$lang] ?? $lang;
            $chunks = splitText($text, 180);
            $urls = [];
            foreach ($chunks as $c) $urls[] = 'https://translate.google.com/translate_tts?ie=UTF-8&client=tw-ob&ttsspeed=1&tl=' . $tl . '&q=' . urlencode($c);
            $bodies = multiGet($urls, 30);
            $ok = !empty($bodies);
            foreach ($bodies as $b) { if (!looksLikeMp3($b)) { $ok = false; break; } }
            if ($ok) { $mp3 = $bodies[0]; for ($i = 1; $i < count($bodies); $i++) $mp3 .= stripId3($bodies[$i]); $source = 'google'; $voice = 'google-' . $tl; }
        }

        if (!$mp3) jsonResponse(['error' => 'TTS failed on all providers'], 500);

        $duration = mp3Duration($mp3);
        if (!$duration) $duration = round(strlen($mp3) * 8 / 128000, 2);

        $store = uploadToPublicHost($mp3) ?: saveAudioLocally($mp3);
        if (!$store) jsonResponse(['error' => 'Could not host voiceover. Check /tmp/vg_php_errors.log or run ?action=test_upload.'], 500);

        if (!empty($store['local_only'])) {
            $blockedHost = parse_url($store['url'], PHP_URL_HOST);
            jsonResponse([
                'error' => "Voiceover URL " . $store['url'] . " is at host '$blockedHost' which Shotstack CANNOT reach (returns 'URL points to a restricted address' for auth-walled/private hosts). "
                         . "All public hosts either failed to upload or failed verification. "
                         . "FIX: (1) deploy to Railway/Render/Hostinger, OR (2) use a Cloudflare tunnel: 'cloudflared tunnel --url http://localhost:8000', then restart PHP with PUBLIC_BASE_URL=https://xxxx.trycloudflare.com and reload the app via the tunnel URL.",
                'audioUrl' => $store['url'],
                'host' => $store['host'],
                'local_only' => true,
                'blocked_host' => $blockedHost,
            ], 500);
        }

        jsonResponse([
            'audioUrl' => $store['url'],
            'duration' => $duration,
            'source' => $source,
            'host' => $store['host'],
            'voice' => $voice,
            'note' => $elNote,
            'local_only' => false,
        ]);
    }

    if ($action === 'search_scene') {
        if (!rateLimit('search_scene', 300, 3600)) jsonResponse(['error' => 'Rate limit reached, try later'], 429);
        if (!isConfigured(PIXABAY_API_KEY)) jsonResponse(['error' => 'Pixabay key not set. Get one at https://pixabay.com/api/docs/'], 400);
        $in = readJsonBody();
        $scene = is_array($in['scene'] ?? null) ? $in['scene'] : [];
        $slot = max(1.0, (float)($in['slot'] ?? 4));
        $ratio = (($in['ratio'] ?? 'landscape') === 'portrait') ? 'portrait' : 'landscape';
        $used = array_map('strval', is_array($in['usedIds'] ?? null) ? $in['usedIds'] : []);

        $queries = [];
        $raw = array_merge([$scene['visual'] ?? ''], is_array($scene['alt'] ?? null) ? $scene['alt'] : [], [$in['fallbackQuery'] ?? '']);
        foreach ($raw as $q) {
            $q = trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N}\s\-]/u', ' ', (string)$q)));
            if ($q !== '' && !in_array(mb_strtolower($q), array_map('mb_strtolower', $queries))) $queries[] = $q;
        }
        $queries = array_slice($queries, 0, 4);
        if (!$queries) jsonResponse(['clips' => [], 'usedIds' => []]);

        $byId = [];
        foreach ($queries as $q) {
            foreach (pixabayVideos($q, $ratio) as $c) {
                if (in_array($c['id'], $used, true)) continue;
                $prev = $byId[$c['id']] ?? null;
                if (!$prev || $c['score'] > $prev['score']) $byId[$c['id']] = $c;
            }
            $acc = array_filter($byId, function ($c) { return $c['score'] >= 0.5; });
            $cover = 0; foreach ($acc as $c) $cover += $c['duration'];
            if (count($acc) >= 2 && $cover >= $slot) break;
        }
        $accepted = array_values(array_filter($byId, function ($c) { return $c['score'] >= 0.5; }));
        sortCands($accepted);

        $clips = []; $newUsed = []; $remaining = $slot;
        foreach ($accepted as $c) {
            if ($remaining < 0.8 || count($clips) >= 3) break;
            $len = round(min($c['duration'] - 0.1, $remaining), 2);
            if ($len < 1) continue;
            $clips[] = ['type' => 'video', 'url' => $c['url'], 'length' => $len, 'id' => $c['id']];
            $newUsed[] = $c['id']; $remaining -= $len;
        }

        if ($remaining >= 0.8) {
            $imgs = [];
            foreach ($queries as $q) {
                foreach (pixabayImages($q, $ratio) as $im) {
                    if (in_array($im['id'], $used, true) || in_array($im['id'], $newUsed, true) || $im['score'] < 0.3) continue;
                    if (!isset($imgs[$im['id']]) || $im['score'] > $imgs[$im['id']]['score']) $imgs[$im['id']] = $im;
                }
                if (count($imgs) >= 3) break;
            }
            if (count($imgs) < 1) {
                foreach ($queries as $q) {
                    foreach (unsplashImages($q, $ratio) as $im) {
                        if (in_array($im['id'], $used, true) || in_array($im['id'], $newUsed, true)) continue;
                        $imgs[$im['id']] = $im;
                    }
                    if (count($imgs) >= 2) break;
                }
            }
            $imgs = array_values($imgs);
            sortCands($imgs);
            $n = min(count($imgs), max(1, (int)ceil($remaining / 6)));
            if ($n > 0) {
                $each = round($remaining / $n, 2);
                for ($i = 0; $i < $n; $i++) {
                    $clips[] = ['type' => 'image', 'url' => $imgs[$i]['url'], 'length' => $each, 'id' => $imgs[$i]['id']];
                    $newUsed[] = $imgs[$i]['id'];
                }
                $remaining = 0;
            }
        }
        jsonResponse(['clips' => $clips, 'usedIds' => $newUsed, 'missing' => empty($clips), 'queries' => $queries]);
    }

    if ($action === 'search_music') {
        if (!isConfigured(FREESOUND_API_KEY)) jsonResponse(['audioUrl' => null, 'reason' => 'no_key']);
        $keywords = trim($_GET['keywords'] ?? 'ambient');
        $mood = preg_replace('/[^a-z ]/i', '', $_GET['mood'] ?? '');
        $minDur = max(10, (int)($_GET['minDuration'] ?? 15));
        $maxDur = max($minDur + 20, (int)($_GET['maxDuration'] ?? 400));
        $base = array_filter(array_map('trim', array_slice(preg_split('/[\s,]+/', $keywords), 0, 2)));
        $q = trim($mood . ' ' . implode(' ', $base) . ' music background');
        $filter = "duration:[{$minDur} TO {$maxDur}] type:mp3";
        $res = curlRequest('https://freesound.org/apiv2/search/text/?query=' . urlencode($q) . '&filter=' . urlencode($filter)
            . '&fields=id,name,previews,duration,license,username&sort=score&page_size=15&token=' . FREESOUND_API_KEY, 'GET', [], null, 15);
        $data = json_decode($res['body'] ?? '', true);
        if (empty($data['results'])) jsonResponse(['audioUrl' => null, 'reason' => 'no_results']);
        $best = null;
        foreach ($data['results'] as $t) {
            $p = $t['previews']['preview-hq-mp3'] ?? $t['previews']['preview-lq-mp3'] ?? null;
            if (!$p || stripos($t['license'] ?? '', 'NonCommercial') !== false) continue;
            $best = ['audioUrl' => $p, 'name' => $t['name'] ?? '?', 'duration' => round($t['duration'] ?? 0, 1), 'license' => $t['license'] ?? '', 'author' => $t['username'] ?? '?'];
            break;
        }
        jsonResponse($best ?? ['audioUrl' => null, 'reason' => 'no_commercial_track']);
    }

    if ($action === 'render') {
        if (!rateLimit('render', 10, 3600)) jsonResponse(['error' => 'Render limit reached (10/hour)'], 429);
        $in = readJsonBody();
        $timeline = is_array($in['timeline'] ?? null) ? $in['timeline'] : [];
        $voiceoverUrl = (string)($in['voiceoverUrl'] ?? '');
        $audioDuration = (float)($in['audioDuration'] ?? 0);
        $musicUrl = (string)($in['musicUrl'] ?? '');
        $ratio = (($in['ratio'] ?? 'landscape') === 'portrait') ? 'portrait' : 'landscape';
        $env = (($in['env'] ?? 'sandbox') === 'production') ? 'production' : 'sandbox';
        if (!$timeline) jsonResponse(['error' => 'No media items'], 400);
        if ($audioDuration < 3) $audioDuration = 10;

        if ($voiceoverUrl !== '' && !isShotstackReachable($voiceoverUrl)) {
            jsonResponse(['error' => "Voiceover URL $voiceoverUrl is not Shotstack-reachable."], 400);
        }

        $key = $env === 'production' ? SHOTSTACK_PROD_KEY : SHOTSTACK_SANDBOX_KEY;
        if (!isConfigured($key)) jsonResponse(['error' => "Shotstack {$env} key not set. Get one at https://dashboard.shotstack.io"], 400);

        $videoLen = $audioDuration + 0.5;
        $sum = 0.0;
        foreach ($timeline as $t) $sum += (float)$t['length'];
        $diff = $videoLen - $sum;
        if (abs($diff) > 0.05) $timeline[count($timeline) - 1]['length'] = max(1, (float)$timeline[count($timeline) - 1]['length'] + $diff);

        $clips = []; $start = 0.0; $k = 0;
        foreach ($timeline as $t) {
            $type = ($t['type'] ?? 'video') === 'image' ? 'image' : 'video';
            $len = round((float)$t['length'], 3);
            $asset = ['type' => $type, 'src' => $t['url']];
            $clip = ['asset' => $asset, 'start' => round($start, 3), 'length' => $len, 'transition' => ['in' => 'fadeFast', 'out' => 'fadeFast']];
            if ($type === 'image') $clip['effect'] = ($k++ % 2) ? 'zoomOut' : 'zoomIn';
            else { $clip['asset']['volume'] = 0; $clip['fit'] = 'cover'; }
            $clips[] = $clip;
            $start += $len;
        }
        $tracks = [];
        if ($voiceoverUrl !== '') $tracks[] = ['clips' => [['asset' => ['type' => 'audio', 'src' => $voiceoverUrl, 'volume' => 1], 'start' => 0, 'length' => round($audioDuration, 3)]]];
        if ($musicUrl !== '') $tracks[] = ['clips' => [['asset' => ['type' => 'audio', 'src' => $musicUrl, 'volume' => 0.18, 'effect' => 'fadeInFadeOut'], 'start' => 0, 'length' => round($videoLen, 3)]]];
        $tracks[] = ['clips' => $clips];

        $output = ['format' => 'mp4', 'fps' => 25, 'size' => $ratio === 'portrait' ? ['width' => 1080, 'height' => 1920] : ['width' => 1920, 'height' => 1080]];
        $apiUrl = $env === 'production' ? 'https://api.shotstack.io/edit/v1/render' : 'https://api.shotstack.io/edit/stage/render';

        $payload = ['timeline' => ['background' => '#000000', 'tracks' => $tracks], 'output' => $output];
        $res = curlRequest($apiUrl, 'POST', ['x-api-key: ' . $key, 'Content-Type: application/json'], safeJson($payload), 30);
        $data = json_decode($res['body'] ?? '', true);
        if (!empty($data['response']['id'])) {
            jsonResponse(['renderId' => $data['response']['id'], 'env' => $env, 'video_length' => round($videoLen, 2),
                          'audio_length' => round($audioDuration, 2), 'num_clips' => count($clips)]);
        }
        $errMsg = $data['response']['errors'][0]['message'] ?? $data['response']['message'] ?? $data['message'] ?? ($res['error'] ? 'cURL: ' . $res['error'] : 'Render failed');
        jsonResponse(['error' => $errMsg, 'shotstack' => $data], 500);
    }

    if ($action === 'render_status') {
        $renderId = $_GET['renderId'] ?? '';
        if (!preg_match('/^[a-zA-Z0-9\-]{10,64}$/', $renderId)) jsonResponse(['error' => 'Bad render id'], 400);
        $env = (($_GET['env'] ?? 'sandbox') === 'production') ? 'production' : 'sandbox';
        $key = $env === 'production' ? SHOTSTACK_PROD_KEY : SHOTSTACK_SANDBOX_KEY;
        $apiUrl = $env === 'production' ? 'https://api.shotstack.io/edit/v1/render/' . $renderId : 'https://api.shotstack.io/edit/stage/render/' . $renderId;
        $data = json_decode(curlRequest($apiUrl, 'GET', ['x-api-key: ' . $key], null, 15)['body'] ?? '', true);
        $status = $data['response']['status'] ?? 'unknown';
        if ($status === 'done') jsonResponse(['status' => 'done', 'url' => $data['response']['url']]);
        if ($status === 'failed') jsonResponse(['status' => 'failed', 'error' => $data['response']['error'] ?? 'Render failed']);
        jsonResponse(['status' => 'rendering']);
    }

    jsonResponse(['error' => 'Invalid action'], 400);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AI Video Generator</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: system-ui, -apple-system, sans-serif; background: #0f172a; color: #e2e8f0; line-height: 1.6; padding: 20px; min-height: 100vh; }
.container { max-width: 880px; margin: 0 auto; background: #1e293b; padding: 2rem; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,0.4); }
h1 { font-size: 1.9rem; margin-bottom: 0.25rem; color: #38bdf8; }
.subtitle { color: #94a3b8; margin-bottom: 1.25rem; font-size: 0.95rem; }
.config-status { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 1.5rem; padding: 0.75rem; background: #0f172a; border-radius: 8px; font-size: 0.85rem; }
.config-status span { padding: 0.15rem 0.55rem; border-radius: 4px; }
.ready { background: #166534; color: #dcfce7; } .missing { background: #7f1d1d; color: #fecaca; } .optional { background: #334155; color: #cbd5e1; }
.form-group { margin-bottom: 1.25rem; } .form-row { display: flex; gap: 1rem; } .form-row .form-group { flex: 1; }
label { display: block; font-weight: 600; margin-bottom: 0.35rem; font-size: 0.9rem; color: #cbd5e1; }
input, select { width: 100%; padding: 0.65rem 0.75rem; border: 1px solid #475569; border-radius: 6px; background: #0f172a; color: #e2e8f0; font-size: 1rem; }
input:focus, select:focus { outline: 2px solid #38bdf8; border-color: transparent; }
small { display: block; color: #64748b; font-size: 0.8rem; margin-top: 0.25rem; }
button { background: #38bdf8; color: #0f172a; border: none; padding: 0.9rem 1.5rem; font-size: 1.05rem; font-weight: 700; border-radius: 6px; cursor: pointer; width: 100%; transition: background 0.2s; }
button:hover:not(:disabled) { background: #7dd3fc; } button:disabled { background: #475569; cursor: not-allowed; }
.script-preview { margin-top: 1rem; padding: 1rem; background: #0f172a; border-radius: 8px; border-left: 3px solid #8b5cf6; }
.script-preview h3 { font-size: 0.95rem; margin-bottom: 0.5rem; color: #a78bfa; }
.script-preview p { font-size: 0.9rem; margin-bottom: 0.35rem; }
.script-preview .kw { color: #38bdf8; } .script-preview .scr { color: #fbbf24; } .script-preview .scn { color: #34d399; font-size: 0.82rem; }
.status-log { margin-top: 1.5rem; padding: 0.75rem; background: #0f172a; border-radius: 6px; font-family: ui-monospace, monospace; font-size: 0.82rem; min-height: 3rem; white-space: pre-wrap; color: #94a3b8; border-left: 3px solid #38bdf8; max-height: 400px; overflow-y: auto; }
.video-preview { margin-top: 2rem; text-align: center; }
.video-preview video { width: 100%; max-height: 500px; border-radius: 8px; background: #000; margin-bottom: 0.75rem; }
.video-preview a { color: #38bdf8; text-decoration: underline; font-weight: 600; }
.hidden { display: none; }
.debug-box { margin-top: 1rem; padding: 0.75rem; background: #1e1b4b; border-radius: 6px; font-family: ui-monospace, monospace; font-size: 0.75rem; color: #c4b5fd; border-left: 3px solid #7c3aed; max-height: 300px; overflow-y: auto; white-space: pre-wrap; }
@media (max-width: 600px) { .form-row { flex-direction: column; gap: 0; } .container { padding: 1.25rem; } }
</style>
</head>
<body>
<div class="container">
    <h1>🎬 AI Video Generator</h1>
    <p class="subtitle">Gemini 3.8 Flash · Pixabay · Unsplash · Freesound · Shotstack · Neural TTS</p>

    <div id="configStatus" class="config-status"></div>

    <form id="videoForm">
        <div class="form-group hidden" id="codeGroup">
            <label for="accessCode">Access Code</label>
            <input type="password" id="accessCode" autocomplete="off">
        </div>
        <div class="form-group">
            <label for="title">Video Topic / Title</label>
            <input type="text" id="title" placeholder="e.g., The Future of Space Travel" maxlength="200" required>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="duration">Video Length (seconds)</label>
                <input type="number" id="duration" value="30" min="10" max="180" step="1" required>
                <small>10 – 180 sec. Script is written to fit this length.</small>
            </div>
            <div class="form-group">
                <label for="tone">Style</label>
                <select id="tone">
                    <option value="informative">Informative</option><option value="storytelling">Storytelling</option>
                    <option value="motivational">Motivational</option><option value="professional">Professional</option>
                    <option value="dramatic">Dramatic / Cinematic</option><option value="funny">Funny</option>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="language">Voiceover Language</label>
                <select id="language">
                    <option value="en">English</option><option value="hi">हिन्दी</option>
                    <option value="es">Spanish</option><option value="fr">French</option>
                    <option value="de">German</option><option value="ja">Japanese</option>
                    <option value="ko">Korean</option><option value="pt">Portuguese</option>
                    <option value="ar">Arabic</option><option value="zh">Chinese</option>
                </select>
            </div>
            <div class="form-group">
                <label for="gender">Voice</label>
                <select id="gender"><option value="female">Female</option><option value="male">Male</option></select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="ratio">Video Ratio</label>
                <select id="ratio"><option value="landscape">Landscape (16:9)</option><option value="portrait">Portrait (9:16)</option></select>
            </div>
            <div class="form-group">
                <label for="env">Render Mode</label>
                <select id="env"><option value="sandbox">Sandbox (free, watermark)</option><option value="production">Production (credits)</option></select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="useMusic">Background Music</label>
                <select id="useMusic"><option value="no">No music</option><option value="yes" selected>Yes</option></select>
            </div>
            <div class="form-group">
                <label for="musicMood">Music Mood</label>
                <select id="musicMood">
                    <option value="cinematic">🎬 Cinematic</option><option value="ambient" selected>🌌 Ambient</option>
                    <option value="upbeat">🎉 Upbeat</option><option value="epic">⚡ Epic</option>
                    <option value="electronic">💻 Electronic</option><option value="acoustic">🎸 Acoustic</option>
                </select>
            </div>
        </div>
        <div class="form-group"><button type="submit" id="generateBtn">Generate Video</button></div>
    </form>

    <div id="scriptPreview" class="script-preview hidden">
        <h3>📝 Script & Scenes</h3>
        <p><strong>Music Keywords:</strong> <span id="scriptKeywords" class="kw"></span></p>
        <p><strong>Narration:</strong> <span id="scriptText" class="scr"></span></p>
        <p><strong>Scenes:</strong> <span id="scriptScenes" class="scn"></span></p>
    </div>

    <div id="status" class="status-log"></div>
    <div id="debugBox" class="debug-box hidden"></div>

    <div id="videoPreview" class="video-preview hidden">
        <h2>✅ Your Video</h2>
        <video id="generatedVideo" controls playsinline></video>
        <p><a id="downloadLink" href="#" download>⬇ Download Video</a></p>
        <p id="videoInfo" style="font-size:0.8rem;color:#64748b;margin-top:0.5rem;"></p>
    </div>
</div>

<script>
const API = window.location.href.split('#')[0].split('?')[0];
const $ = id => document.getElementById(id);
const statusLog = $('status'), debugBox = $('debugBox');
const sleep = ms => new Promise(r => setTimeout(r, ms));

function log(m) { statusLog.textContent += m + '\n'; statusLog.scrollTop = statusLog.scrollHeight; }
function clearLog() { statusLog.textContent = ''; debugBox.classList.add('hidden'); debugBox.textContent = ''; }
function showDebug(o) { debugBox.textContent = JSON.stringify(o, null, 2); debugBox.classList.remove('hidden'); }

async function api(action, { params = {}, body = null } = {}) {
    const qs = new URLSearchParams({ action, ...params });
    const headers = { 'X-Access-Code': $('accessCode').value };
    const opts = { headers };
    if (body !== null) { opts.method = 'POST'; headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    const r = await fetch(`${API}?${qs}`, opts);
    const text = await r.text();
    if (text.trim().startsWith('<')) throw new Error(`Server returned HTML. Status ${r.status}. First 200: ${text.substring(0, 200)}`);
    let j;
    try { j = JSON.parse(text); } catch (e) { throw new Error(`Invalid JSON. Status ${r.status}. First 200: ${text.substring(0, 200)}`); }
    if (j.auth) throw new Error('Wrong or missing access code');
    return j;
}

(async () => {
    try {
        const d = await api('check_config');
        if (d.requires_code) $('codeGroup').classList.remove('hidden');
        const items = [['Gemini', d.gemini, 1], ['Pixabay', d.pixabay, 1], ['Unsplash', d.unsplash, 0], ['Freesound', d.freesound, 0], ['Shotstack', d.shotstack_sandbox, 1], ['ElevenLabs (optional)', d.elevenlabs, 0]];
        let html = items.map(([n, ok, req]) => `<span class="${ok ? 'ready' : (req ? 'missing' : 'optional')}">${ok ? '✅' : (req ? '❌' : '➖')} ${n}</span>`).join('');
        html += `<span class="optional">🔗 Public URL: ${d.public_base_url || '(auto)'}</span>`;
        $('configStatus').innerHTML = html;
    } catch (e) {
        $('configStatus').innerHTML = `<span class="missing">❌ Server error: ${e.message}</span>`;
    }
})();

$('videoForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    clearLog();
    $('videoPreview').classList.add('hidden');
    $('scriptPreview').classList.add('hidden');
    const btn = $('generateBtn');
    btn.disabled = true; btn.textContent = 'Generating...';

    const title = $('title').value.trim();
    const lang = $('language').value, gender = $('gender').value, ratio = $('ratio').value, env = $('env').value;
    const tone = $('tone').value, mood = $('musicMood').value, useMusic = $('useMusic').value === 'yes';
    const duration = Math.max(10, Math.min(180, parseInt($('duration').value) || 30));
    $('duration').value = duration;
    const targetAudio = duration - 0.5;

    try {
        log(`🤖 [1/6] Gemini writing a ${duration}s script + scenes...`);
        let s = await api('generate_script', { body: { title, lang, duration, tone } });
        if (s.error && !s.script) throw new Error(s.error);
        if (s.error) throw new Error('Script generation failed: ' + s.error);
        log(`   ✅ ${s.model}: ${s.scenes.length} scenes, ${s.actualUnits}/${s.targetUnits} words`);

        log('🗣️ [2/6] Generating voiceover (verifying host is Shotstack-reachable)...');
        let t = await api('tts', { body: { text: s.script, lang, gender } });
        if (t.error) {
            log(`   ❌ ${t.error}`);
            if (t.blocked_host) log(`   🚫 Blocked host: ${t.blocked_host}`);
            throw new Error('Voiceover hosting failed — see the message above.');
        }
        log(`   ✅ ${t.duration}s | ${t.source} | voice ${t.voice} | host ${t.host} (verified reachable)`);
        if (t.note) log(`   ⚠️ ${t.note}`);

        for (let pass = 0; pass < 2; pass++) {
            const err = Math.abs(t.duration - targetAudio) / targetAudio;
            if (err <= 0.15) break;
            log(`   ⏱️ Voiceover ${t.duration}s vs target ${targetAudio.toFixed(1)}s — adjusting (pass ${pass + 1})...`);
            const s2 = await api('generate_script', { body: { title, lang, duration, tone, prevScript: s.script, prevDuration: t.duration } });
            if (s2.error) { log('   ⚠️ Adjustment failed, using current script'); break; }
            const t2 = await api('tts', { body: { text: s2.script, lang, gender } });
            if (t2.error) { log('   ⚠️ Adjustment TTS failed, keeping current'); break; }
            if (Math.abs(t2.duration - targetAudio) < Math.abs(t.duration - targetAudio)) {
                s = s2; t = t2; log(`   ✅ Adjusted: ${t.duration}s`);
            } else {
                log('   ℹ️ Previous version was closer — keeping it.'); break;
            }
        }

        $('scriptKeywords').textContent = s.keywords;
        $('scriptText').textContent = s.script;
        $('scriptScenes').textContent = s.scenes.map(x => x.visual).join(' → ');
        $('scriptPreview').classList.remove('hidden');

        const audioDuration = t.duration, totalLen = audioDuration + 0.5;

        log(`🖼️ [3/6] Finding relevant clips for ${s.scenes.length} scenes...`);
        const weights = s.scenes.map(x => Math.max(1, x.narration.replace(/\s+/g, '').length));
        const wSum = weights.reduce((a, b) => a + b, 0);
        const fallbackQuery = title.split(/\s+/).filter(w => w.length > 2).slice(0, 3).join(' ');
        const timeline = []; let used = [];
        for (let i = 0; i < s.scenes.length; i++) {
            const slot = Math.max(1.5, totalLen * weights[i] / wSum);
            const r = await api('search_scene', { body: { scene: s.scenes[i], slot, ratio, usedIds: used, fallbackQuery } });
            if (r.error) throw new Error('Media search: ' + r.error);
            if (r.missing || !r.clips.length) {
                const prev = timeline[timeline.length - 1];
                if (!prev) throw new Error(`No media found for "${s.scenes[i].visual}"`);
                timeline.push({ type: prev.type, url: prev.url, length: slot });
                log(`   ⚠️ Scene ${i + 1} "${s.scenes[i].visual}": nothing relevant, reusing previous clip`);
            } else {
                r.clips.forEach(c => timeline.push({ type: c.type, url: c.url, length: c.length }));
                used = used.concat(r.usedIds);
                log(`   ✅ Scene ${i + 1} "${s.scenes[i].visual}": ${r.clips.map(c => c.type[0] === 'v' ? 'video' : 'photo').join(' + ')} (${slot.toFixed(1)}s)`);
            }
        }

        let musicUrl = null;
        if (useMusic) {
            log(`🎵 [4/6] Searching ${mood} music...`);
            try {
                const m = await api('search_music', { params: { keywords: s.keywords, mood, minDuration: Math.ceil(totalLen), maxDuration: 400 } });
                musicUrl = m.audioUrl;
                log(musicUrl ? `   ✅ "${m.name}" (${m.duration}s)` : `   ⚠️ No music (${m.reason})`);
            } catch (me) { log(`   ⚠️ Music failed: ${me.message} (skipping)`); }
        } else log('🎵 [4/6] Music skipped');

        log(`🎬 [5/6] Rendering ${totalLen.toFixed(1)}s video...`);
        const rd = await api('render', { body: { timeline, voiceoverUrl: t.audioUrl, audioDuration, musicUrl, ratio, env } });
        if (rd.error) { if (rd.shotstack) showDebug(rd); throw new Error(rd.error); }
        log(`   ⏳ Queued (${rd.renderId}) — ${rd.num_clips} clips, ${rd.video_length}s`);

        log('⏳ [6/6] Waiting for render...');
        let vUrl = null, failMsg = null;
        for (let i = 0; i < 100; i++) {
            await sleep(3000);
            let st;
            try { st = await api('render_status', { params: { renderId: rd.renderId, env } }); }
            catch (se) { log(`   ⚠️ Status check failed (${se.message}), retrying...`); continue; }
            if (st.status === 'done') { vUrl = st.url; break; }
            if (st.status === 'failed') { failMsg = st.error || 'unknown'; break; }
            if (i % 3 === 2) log(`   🔄 Still rendering (${(i + 1) * 3}s)...`);
        }
        if (failMsg) throw new Error('Render failed: ' + failMsg);
        if (!vUrl) throw new Error('Timed out waiting for render (5 min)');

        log('🎉 Video ready!');
        $('generatedVideo').src = vUrl;
        $('downloadLink').href = vUrl;
        $('videoInfo').textContent = `${audioDuration}s voiceover | ${rd.video_length}s video | ${rd.num_clips} clips`;
        $('videoPreview').classList.remove('hidden');
        $('videoPreview').scrollIntoView({ behavior: 'smooth' });
    } catch (err) {
        log(`❌ ${err.message}`);
        console.error(err);
    } finally {
        btn.disabled = false; btn.textContent = 'Generate Video';
    }
});
</script>
</body>
</html>