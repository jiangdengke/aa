<?php
declare(strict_types=1);

const APP_BASE_DIR = __DIR__;
const APP_DATA_DIR = APP_BASE_DIR . '/data';
const APP_GALLERY_DIR = APP_DATA_DIR . '/gallery';
const APP_LOCK_DIR = APP_DATA_DIR . '/locks';
const APP_CLAIMS_FILE = APP_DATA_DIR . '/claims.json';
const APP_GALLERY_FILE = APP_DATA_DIR . '/gallery.json';
const APP_RATE_LIMITS_FILE = APP_DATA_DIR . '/rate_limits.json';

final class AppError extends RuntimeException
{
    public int $status;
    public string $errorCode;
    public ?array $details;

    public function __construct(int $status, string $errorCode, string $message, ?array $details = null)
    {
        parent::__construct($message);
        $this->status = $status;
        $this->errorCode = $errorCode;
        $this->details = $details;
    }
}

function app_env(string $key, ?string $default = null): ?string
{
    static $envFile = null;

    if ($envFile === null) {
        $envFile = [];
        $path = APP_BASE_DIR . '/.env';
        if (is_file($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$name, $value] = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value);
                if ($value !== '' && (
                    ($value[0] === '"' && substr($value, -1) === '"') ||
                    ($value[0] === "'" && substr($value, -1) === "'")
                )) {
                    $value = substr($value, 1, -1);
                }
                $envFile[$name] = $value;
            }
        }
    }

    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return $value;
    }

    if (array_key_exists($key, $_ENV) && $_ENV[$key] !== '') {
        return (string) $_ENV[$key];
    }

    if (array_key_exists($key, $_SERVER) && $_SERVER[$key] !== '') {
        return (string) $_SERVER[$key];
    }

    return $envFile[$key] ?? $default;
}

function app_config(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $config = [
        'port' => (int) (app_env('PORT', '3000') ?? '3000'),
        'base_url' => rtrim((string) app_env('BASE_URL', 'https://ai.laodog.top'), '/'),
        'admin_email' => trim((string) app_env('ADMIN_EMAIL', '')),
        'admin_password' => (string) app_env('ADMIN_PASSWORD', ''),
        'records_access_key' => trim((string) app_env('RECORDS_ACCESS_KEY', '')),
        'claim_amount' => (float) (app_env('CLAIM_AMOUNT', '10') ?? '10'),
        'claim_notes' => trim((string) app_env('CLAIM_NOTES', 'Self-service bonus claim')),
        'rate_limit_max' => (int) (app_env('RATE_LIMIT_MAX', '20') ?? '20'),
        'captcha_ttl_seconds' => (int) (app_env('CAPTCHA_TTL_SECONDS', '300') ?? '300'),
        'captcha_max_attempts' => (int) (app_env('CAPTCHA_MAX_ATTEMPTS', '5') ?? '5'),
        'captcha_rate_limit_max' => (int) (app_env('CAPTCHA_RATE_LIMIT_MAX', '40') ?? '40'),
        'gallery_upload_max_files' => (int) (app_env('GALLERY_UPLOAD_MAX_FILES', '8') ?? '8'),
        'gallery_upload_max_bytes' => (int) (app_env('GALLERY_UPLOAD_MAX_BYTES', '3145728') ?? '3145728'),
        'rate_limit_window_seconds' => 600,
        'captcha_chars' => '23456789ABCDEFGHJKLMNPQRSTUVWXYZ',
    ];

    return $config;
}

function app_start_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'use_strict_mode' => true,
        ]);
    }
}

function app_ensure_data_dirs(): void
{
    foreach ([APP_DATA_DIR, APP_GALLERY_DIR, APP_LOCK_DIR] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new AppError(500, 'storage_error', '无法初始化数据目录。');
        }
    }
}

function app_assert_config(): void
{
    $config = app_config();
    if ($config['base_url'] === '') {
        throw new AppError(500, 'config_error', 'BASE_URL 未配置。');
    }
    if ($config['admin_email'] === '' || $config['admin_password'] === '') {
        throw new AppError(500, 'config_error', 'ADMIN_EMAIL 或 ADMIN_PASSWORD 未配置。');
    }
    if (!is_numeric((string) $config['claim_amount']) || $config['claim_amount'] <= 0) {
        throw new AppError(500, 'config_error', 'CLAIM_AMOUNT 必须是大于 0 的数字。');
    }
}

function app_assert_records_access_configured(): void
{
    if (app_config()['records_access_key'] === '') {
        throw new AppError(500, 'config_error', 'RECORDS_ACCESS_KEY 未配置。');
    }
}

function app_with_lock(string $name, callable $callback)
{
    app_ensure_data_dirs();
    $path = APP_LOCK_DIR . '/' . $name . '.lock';
    $handle = fopen($path, 'c+');
    if ($handle === false) {
        throw new AppError(500, 'storage_error', '无法创建锁文件。');
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new AppError(500, 'storage_error', '无法获取锁。');
        }
        return $callback();
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function app_load_json_file(string $path, array $default): array
{
    app_ensure_data_dirs();
    if (!is_file($path)) {
        return $default;
    }

    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return $default;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $default;
}

function app_save_json_file(string $path, array $data): void
{
    app_ensure_data_dirs();
    $tempPath = $path . '.' . getmypid() . '.tmp';
    $payload = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        throw new AppError(500, 'storage_error', '无法编码 JSON。');
    }
    if (file_put_contents($tempPath, $payload) === false) {
        throw new AppError(500, 'storage_error', '无法写入临时文件。');
    }
    if (!rename($tempPath, $path)) {
        throw new AppError(500, 'storage_error', '无法保存数据文件。');
    }
}

function app_json_response(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function app_page_response(string $template): never
{
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    require APP_BASE_DIR . '/' . $template;
    exit;
}

function app_not_found(): never
{
    app_json_response(404, [
        'error' => 'not_found',
        'message' => 'Not found.',
    ]);
}

function app_client_ip(): string
{
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if (is_string($forwarded) && trim($forwarded) !== '') {
        $parts = explode(',', $forwarded);
        return trim($parts[0]);
    }
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function app_normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function app_is_valid_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function app_normalize_captcha_code(string $value): string
{
    return strtoupper(trim($value));
}

function app_require_records_access(): void
{
    app_assert_records_access_configured();
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', (string) $header, $matches)) {
        throw new AppError(401, 'unauthorized', '无权查看领取记录。');
    }
    $token = trim($matches[1]);
    if ($token === '' || $token !== app_config()['records_access_key']) {
        throw new AppError(401, 'unauthorized', '无权查看领取记录。');
    }
}

function app_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new AppError(400, 'invalid_json', '请求体必须是 JSON。');
    }
    return $decoded;
}

function app_read_rate_limits(): array
{
    return app_load_json_file(APP_RATE_LIMITS_FILE, ['buckets' => []]);
}

function app_check_rate_limit(string $scope, string $ip, int $max): void
{
    $window = app_config()['rate_limit_window_seconds'];
    $now = time();

    app_with_lock('rate_limits', function () use ($scope, $ip, $max, $window, $now): void {
        $store = app_read_rate_limits();
        $key = $scope . ':' . $ip;
        $hits = $store['buckets'][$key] ?? [];
        $hits = array_values(array_filter($hits, static fn ($ts) => is_int($ts) && $ts > ($now - $window)));
        if (count($hits) >= $max) {
            app_save_json_file(APP_RATE_LIMITS_FILE, ['buckets' => $store['buckets']]);
            throw new AppError(429, 'rate_limited', '请求过于频繁，请稍后再试。');
        }
        $hits[] = $now;
        $store['buckets'][$key] = $hits;
        app_save_json_file(APP_RATE_LIMITS_FILE, ['buckets' => $store['buckets']]);
    });
}

function app_random_text(int $length = 5): string
{
    $chars = app_config()['captcha_chars'];
    $max = strlen($chars) - 1;
    $text = '';
    for ($i = 0; $i < $length; $i++) {
        $text .= $chars[random_int(0, $max)];
    }
    return $text;
}

function app_create_captcha_svg(string $text): string
{
    $palette = ['#59eeff', '#ff4fb1', '#5b7cff', '#eef8ff'];
    $lines = [];
    for ($i = 0; $i < 6; $i++) {
        $color = $palette[array_rand($palette)];
        $opacity = number_format(0.14 + random_int(0, 18) / 100, 2, '.', '');
        $lines[] = sprintf(
            '<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="%s" stroke-opacity="%s" stroke-width="1.2" />',
            random_int(0, 160),
            random_int(0, 56),
            random_int(0, 160),
            random_int(0, 56),
            $color,
            $opacity
        );
    }

    $dots = [];
    for ($i = 0; $i < 14; $i++) {
        $color = $palette[array_rand($palette)];
        $opacity = number_format(0.08 + random_int(0, 20) / 100, 2, '.', '');
        $radius = number_format(0.6 + random_int(0, 18) / 10, 1, '.', '');
        $dots[] = sprintf(
            '<circle cx="%d" cy="%d" r="%s" fill="%s" fill-opacity="%s" />',
            random_int(0, 160),
            random_int(0, 56),
            $radius,
            $color,
            $opacity
        );
    }

    $chars = [];
    foreach (str_split($text) as $index => $char) {
        $x = 18 + $index * 26 + random_int(0, 5);
        $y = 32 + random_int(0, 9);
        $rotate = random_int(-15, 15);
        $color = $palette[$index % 3];
        $chars[] = sprintf(
            '<text x="%d" y="%d" fill="%s" font-size="28" font-family="Verdana, Arial, sans-serif" font-weight="700" transform="rotate(%d %d %d)">%s</text>',
            $x,
            $y,
            $color,
            $rotate,
            $x,
            $y,
            htmlspecialchars($char, ENT_QUOTES, 'UTF-8')
        );
    }

    return trim(
        '<svg xmlns="http://www.w3.org/2000/svg" width="160" height="56" viewBox="0 0 160 56" role="img" aria-label="验证码">' .
        '<rect width="160" height="56" rx="8" fill="#08101d" />' .
        '<rect x="1" y="1" width="158" height="54" rx="7" fill="none" stroke="rgba(89,238,255,0.32)" />' .
        '<path d="M0 18 H160" stroke="rgba(255,255,255,0.06)" />' .
        '<path d="M0 40 H160" stroke="rgba(255,255,255,0.05)" />' .
        implode('', $lines) .
        implode('', $dots) .
        implode('', $chars) .
        '</svg>'
    );
}

function app_captcha_store(): array
{
    app_start_session();
    if (!isset($_SESSION['captcha_challenges']) || !is_array($_SESSION['captcha_challenges'])) {
        $_SESSION['captcha_challenges'] = [];
    }
    return $_SESSION['captcha_challenges'];
}

function app_save_captcha_store(array $store): void
{
    $_SESSION['captcha_challenges'] = $store;
}

function app_cleanup_captchas(array $store): array
{
    $now = time();
    $maxAttempts = app_config()['captcha_max_attempts'];
    foreach ($store as $token => $challenge) {
        if (!is_array($challenge)) {
            unset($store[$token]);
            continue;
        }
        if (($challenge['expires_at'] ?? 0) <= $now || ($challenge['attempts'] ?? 0) >= $maxAttempts) {
            unset($store[$token]);
        }
    }
    return $store;
}

function app_issue_captcha(string $ip): array
{
    $store = app_cleanup_captchas(app_captcha_store());
    $token = bin2hex(random_bytes(16));
    $answer = app_random_text();
    $store[$token] = [
        'answer' => $answer,
        'ip' => $ip,
        'attempts' => 0,
        'expires_at' => time() + app_config()['captcha_ttl_seconds'],
    ];
    app_save_captcha_store($store);

    return [
        'token' => $token,
        'image' => 'data:image/svg+xml;base64,' . base64_encode(app_create_captcha_svg($answer)),
    ];
}

function app_verify_captcha(string $token, string $code, string $ip): void
{
    $token = trim($token);
    $code = app_normalize_captcha_code($code);
    if ($token === '' || $code === '') {
        throw new AppError(400, 'captcha_required', '请输入验证码。');
    }

    $store = app_cleanup_captchas(app_captcha_store());
    if (!isset($store[$token])) {
        app_save_captcha_store($store);
        throw new AppError(400, 'captcha_expired', '验证码已过期，请刷新后重试。');
    }

    $challenge = $store[$token];
    if (($challenge['ip'] ?? '') !== $ip) {
        unset($store[$token]);
        app_save_captcha_store($store);
        throw new AppError(400, 'captcha_invalid', '验证码无效，请刷新后重试。');
    }

    if (($challenge['expires_at'] ?? 0) <= time()) {
        unset($store[$token]);
        app_save_captcha_store($store);
        throw new AppError(400, 'captcha_expired', '验证码已过期，请刷新后重试。');
    }

    $store[$token]['attempts'] = ($store[$token]['attempts'] ?? 0) + 1;
    if (strtoupper((string) ($challenge['answer'] ?? '')) !== $code) {
        if ($store[$token]['attempts'] >= app_config()['captcha_max_attempts']) {
            unset($store[$token]);
        }
        app_save_captcha_store($store);
        throw new AppError(400, 'captcha_invalid', '验证码错误，请重试。');
    }

    unset($store[$token]);
    app_save_captcha_store($store);
}

function app_http_request(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $headerLines = [];
    foreach ($headers as $name => $value) {
        $headerLines[] = $name . ': ' . $value;
    }

    $context = stream_context_create([
        'http' => [
            'method' => strtoupper($method),
            'header' => implode("\r\n", $headerLines),
            'content' => $body ?? '',
            'ignore_errors' => true,
            'timeout' => 20,
        ],
    ]);

    $result = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    if ($result === false && $responseHeaders === []) {
        throw new AppError(502, 'upstream_error', '无法连接上游服务。');
    }

    $statusLine = $responseHeaders[0] ?? 'HTTP/1.1 500';
    if (!preg_match('#\s(\d{3})\s#', $statusLine, $matches)) {
        throw new AppError(502, 'upstream_error', '无法解析上游响应状态。');
    }

    return [
        'status' => (int) $matches[1],
        'body' => $result === false ? '' : $result,
        'headers' => $responseHeaders,
    ];
}

function app_extract_upstream_message($payload, string $fallback): string
{
    if (!is_array($payload)) {
        return $fallback;
    }
    foreach (['detail', 'message', 'error'] as $key) {
        if (isset($payload[$key]) && is_string($payload[$key]) && trim($payload[$key]) !== '') {
            return trim($payload[$key]);
        }
    }
    if (isset($payload['data']) && is_array($payload['data'])) {
        return app_extract_upstream_message($payload['data'], $fallback);
    }
    return $fallback;
}

function app_unwrap_api_payload($payload)
{
    if (is_array($payload) && ($payload['code'] ?? null) === 0 && array_key_exists('data', $payload)) {
        return $payload['data'];
    }
    return $payload;
}

function app_upstream_json(string $method, string $path, array $query = [], ?array $body = null, ?string $token = null)
{
    $config = app_config();
    $url = $config['base_url'] . $path;
    if ($query !== []) {
        $url .= '?' . http_build_query(array_filter(
            $query,
            static fn ($value) => $value !== null && $value !== ''
        ));
    }

    $headers = ['Accept' => 'application/json'];
    $encodedBody = null;
    if ($body !== null) {
        $headers['Content-Type'] = 'application/json';
        $encodedBody = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if ($token !== null && $token !== '') {
        $headers['Authorization'] = 'Bearer ' . $token;
    }

    $response = app_http_request($method, $url, $headers, $encodedBody);
    $payload = null;
    if ($response['body'] !== '') {
        $decoded = json_decode($response['body'], true);
        $payload = is_array($decoded) ? $decoded : $response['body'];
    }

    if ($response['status'] < 200 || $response['status'] >= 300) {
        throw new AppError(
            $response['status'],
            'upstream_error',
            app_extract_upstream_message(is_array($payload) ? $payload : null, '上游接口请求失败。')
        );
    }

    return $payload;
}

function app_admin_login_token(): string
{
    app_assert_config();
    $config = app_config();
    $payload = app_unwrap_api_payload(app_upstream_json(
        'POST',
        '/api/v1/auth/login',
        [],
        [
            'email' => $config['admin_email'],
            'password' => $config['admin_password'],
        ]
    ));

    if (!is_array($payload) || !isset($payload['access_token']) || !is_string($payload['access_token'])) {
        throw new AppError(502, 'admin_login_failed', '管理员登录失败，请确认账号密码是否正确。');
    }

    return $payload['access_token'];
}

function app_normalize_users_list($payload): array
{
    $data = app_unwrap_api_payload($payload);
    if (is_array($data) && array_is_list($data)) {
        return ['items' => $data, 'total' => count($data)];
    }
    if (is_array($data) && isset($data['items']) && is_array($data['items'])) {
        return [
            'items' => $data['items'],
            'total' => (int) ($data['total'] ?? count($data['items'])),
        ];
    }
    throw new AppError(502, 'unexpected_users_response', '用户列表接口返回格式异常。');
}

function app_find_user_by_email(string $token, string $email): ?array
{
    $pageSize = 100;
    for ($page = 1; $page <= 3; $page++) {
        $result = app_normalize_users_list(app_upstream_json(
            'GET',
            '/api/v1/admin/users',
            [
                'page' => $page,
                'page_size' => $pageSize,
                'search' => $email,
            ],
            null,
            $token
        ));

        foreach ($result['items'] as $item) {
            if (is_array($item) && app_normalize_email((string) ($item['email'] ?? '')) === $email) {
                return $item;
            }
        }

        if (count($result['items']) < $pageSize || ($page * $pageSize) >= $result['total']) {
            break;
        }
    }
    return null;
}

function app_add_user_balance(string $token, int|string $userId, float $amount, string $claimId): void
{
    $config = app_config();
    app_upstream_json(
        'POST',
        '/api/v1/admin/users/' . rawurlencode((string) $userId) . '/balance',
        [],
        [
            'balance' => $amount,
            'operation' => 'add',
            'notes' => $config['claim_notes'] . ' [claim:' . $claimId . ']',
        ],
        $token
    );
}

function app_load_claims_store(): array
{
    return app_load_json_file(APP_CLAIMS_FILE, ['version' => 1, 'claims' => []]);
}

function app_save_claims_store(array $store): void
{
    app_save_json_file(APP_CLAIMS_FILE, $store);
}

function app_find_active_claim(array $claims, callable $predicate): ?array
{
    foreach ($claims as $claim) {
        if (($claim['status'] ?? '') !== 'failed' && $predicate($claim)) {
            return $claim;
        }
    }
    return null;
}

function app_process_claim(string $email, string $remoteIp): array
{
    return app_with_lock('claims', function () use ($email, $remoteIp): array {
        $store = app_load_claims_store();
        $claims = $store['claims'] ?? [];

        $existingByEmail = app_find_active_claim($claims, static fn ($claim) => ($claim['normalizedEmail'] ?? '') === $email);
        if (($existingByEmail['status'] ?? '') === 'completed') {
            throw new AppError(409, 'already_claimed', '该邮箱已经领取过 10 刀。');
        }
        if (($existingByEmail['status'] ?? '') === 'pending') {
            throw new AppError(409, 'claim_pending', '该邮箱的领取请求正在处理中，请稍后再试。');
        }

        $token = app_admin_login_token();
        $user = app_find_user_by_email($token, $email);
        if ($user === null) {
            throw new AppError(404, 'user_not_found', '无该账户。');
        }

        $userId = (string) ($user['id'] ?? '');
        $existingByUserId = app_find_active_claim($claims, static fn ($claim) => (string) ($claim['userId'] ?? '') === $userId);
        if (($existingByUserId['status'] ?? '') === 'completed') {
            throw new AppError(409, 'already_claimed', '该账户已经领取过 10 刀。');
        }
        if (($existingByUserId['status'] ?? '') === 'pending') {
            throw new AppError(409, 'claim_pending', '该账户的领取请求正在处理中，请稍后再试。');
        }

        $record = [
            'id' => bin2hex(random_bytes(16)),
            'userId' => $userId,
            'email' => (string) ($user['email'] ?? $email),
            'normalizedEmail' => $email,
            'amount' => app_config()['claim_amount'],
            'status' => 'pending',
            'remoteIp' => $remoteIp,
            'createdAt' => gmdate('c'),
            'awardedAt' => null,
            'failedAt' => null,
            'errorMessage' => null,
        ];

        $store['claims'][] = $record;
        app_save_claims_store($store);

        try {
            app_add_user_balance($token, $userId, (float) app_config()['claim_amount'], $record['id']);
            foreach ($store['claims'] as &$claim) {
                if (($claim['id'] ?? '') === $record['id']) {
                    $claim['status'] = 'completed';
                    $claim['awardedAt'] = gmdate('c');
                    $record = $claim;
                    break;
                }
            }
            unset($claim);
            app_save_claims_store($store);

            return [
                'user' => [
                    'id' => $user['id'],
                    'email' => $user['email'],
                ],
                'amount' => app_config()['claim_amount'],
                'awardedAt' => $record['awardedAt'],
            ];
        } catch (Throwable $error) {
            foreach ($store['claims'] as &$claim) {
                if (($claim['id'] ?? '') === $record['id']) {
                    $claim['status'] = 'failed';
                    $claim['failedAt'] = gmdate('c');
                    $claim['errorMessage'] = $error instanceof AppError ? $error->getMessage() : '未知错误';
                    break;
                }
            }
            unset($claim);
            app_save_claims_store($store);
            if ($error instanceof AppError) {
                throw $error;
            }
            throw new AppError(500, 'internal_error', '发放额度时发生异常。');
        }
    });
}

function app_compare_values($a, $b, string $direction): int
{
    $multiplier = $direction === 'asc' ? 1 : -1;
    if ($a === $b) {
        return 0;
    }
    if ($a === null) {
        return 1 * $multiplier;
    }
    if ($b === null) {
        return -1 * $multiplier;
    }
    return ($a <=> $b) * $multiplier;
}

function app_list_claims(array $options = []): array
{
    $store = app_load_claims_store();
    $items = $store['claims'] ?? [];
    $search = app_normalize_email((string) ($options['search'] ?? ''));
    $status = (string) ($options['status'] ?? '');
    $sortBy = (string) ($options['sortBy'] ?? 'awardedAt');
    $sortOrder = (string) ($options['sortOrder'] ?? 'desc');

    if ($search !== '') {
        $items = array_values(array_filter($items, static fn ($item) => str_contains((string) ($item['normalizedEmail'] ?? ''), $search)));
    }

    if ($status !== '') {
        $items = array_values(array_filter($items, static fn ($item) => (string) ($item['status'] ?? '') === $status));
    }

    $allowedSorts = ['email', 'createdAt', 'awardedAt', 'status', 'amount'];
    if (!in_array($sortBy, $allowedSorts, true)) {
        $sortBy = 'awardedAt';
    }
    $sortOrder = $sortOrder === 'asc' ? 'asc' : 'desc';

    usort($items, static function (array $left, array $right) use ($sortBy, $sortOrder): int {
        $a = $left[$sortBy] ?? null;
        $b = $right[$sortBy] ?? null;
        if ($sortBy === 'email' || $sortBy === 'status') {
            $a = (string) $a;
            $b = (string) $b;
        }
        return app_compare_values($a, $b, $sortOrder);
    });

    return array_map(static fn ($claim) => [
        'id' => $claim['id'] ?? '',
        'email' => $claim['email'] ?? '',
        'userId' => $claim['userId'] ?? '',
        'amount' => $claim['amount'] ?? 0,
        'status' => $claim['status'] ?? '',
        'createdAt' => $claim['createdAt'] ?? null,
        'awardedAt' => $claim['awardedAt'] ?? null,
        'failedAt' => $claim['failedAt'] ?? null,
        'remoteIp' => $claim['remoteIp'] ?? '',
        'errorMessage' => $claim['errorMessage'] ?? null,
    ], $items);
}

function app_load_gallery_store(): array
{
    return app_load_json_file(APP_GALLERY_FILE, ['version' => 1, 'images' => []]);
}

function app_save_gallery_store(array $store): void
{
    app_save_json_file(APP_GALLERY_FILE, $store);
}

function app_guess_upload_extension(array $file): string
{
    $name = (string) ($file['name'] ?? '');
    $type = strtolower((string) ($file['type'] ?? ''));
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
        return '.' . $ext;
    }
    return match (true) {
        str_contains($type, 'png') => '.png',
        str_contains($type, 'jpeg'), str_contains($type, 'jpg') => '.jpg',
        str_contains($type, 'gif') => '.gif',
        str_contains($type, 'webp') => '.webp',
        default => '.bin',
    };
}

function app_list_gallery(): array
{
    $store = app_load_gallery_store();
    $images = $store['images'] ?? [];
    $items = [];
    foreach ($images as $index => $item) {
        $items[] = [
            'id' => $item['id'] ?? '',
            'title' => $item['title'] ?? '',
            'alt' => $item['alt'] ?? '',
            'createdAt' => $item['createdAt'] ?? null,
            'order' => $index,
            'url' => '/media/' . rawurlencode((string) ($item['id'] ?? '')),
        ];
    }
    return $items;
}

function app_store_gallery_image(array $file, array $fields): array
{
    app_assert_records_access_configured();
    app_ensure_data_dirs();
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new AppError(400, 'file_required', '请上传图片文件。');
    }
    if (!isset($file['size']) || (int) $file['size'] <= 0) {
        throw new AppError(400, 'file_required', '请上传图片文件。');
    }
    if ((int) $file['size'] > app_config()['gallery_upload_max_bytes']) {
        throw new AppError(413, 'payload_too_large', '上传文件过大。');
    }

    $title = trim((string) ($fields['title'] ?? ''));
    if ($title === '') {
        $title = basename((string) ($file['name'] ?? 'image'));
    }
    $alt = trim((string) ($fields['alt'] ?? ''));
    if ($alt === '') {
        $alt = $title;
    }

    $id = bin2hex(random_bytes(16)) . app_guess_upload_extension($file);
    $target = APP_GALLERY_DIR . '/' . $id;
    if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
        throw new AppError(500, 'storage_error', '无法保存上传图片。');
    }

    $record = [
        'id' => $id,
        'title' => $title,
        'alt' => $alt,
        'contentType' => (string) ($file['type'] ?? 'application/octet-stream'),
        'createdAt' => gmdate('c'),
        'originalName' => (string) ($file['name'] ?? $title),
    ];

    app_with_lock('gallery', static function () use ($record): void {
        $store = app_load_gallery_store();
        $images = $store['images'] ?? [];
        array_unshift($images, $record);
        $images = array_slice($images, 0, app_config()['gallery_upload_max_files']);
        $store['images'] = $images;
        app_save_gallery_store($store);
    });

    return [
        'id' => $record['id'],
        'title' => $record['title'],
        'alt' => $record['alt'],
        'createdAt' => $record['createdAt'],
        'url' => '/media/' . rawurlencode($record['id']),
    ];
}

function app_delete_gallery_image(string $id): void
{
    app_with_lock('gallery', static function () use ($id): void {
        $store = app_load_gallery_store();
        $images = $store['images'] ?? [];
        $index = null;
        foreach ($images as $i => $item) {
            if (($item['id'] ?? '') === $id) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            throw new AppError(404, 'not_found', '图片不存在。');
        }
        $removed = $images[$index];
        array_splice($images, $index, 1);
        $store['images'] = array_values($images);
        app_save_gallery_store($store);
        $file = APP_GALLERY_DIR . '/' . $removed['id'];
        if (is_file($file)) {
            @unlink($file);
        }
    });
}

function app_move_gallery_image(string $id, string $direction): void
{
    app_with_lock('gallery', static function () use ($id, $direction): void {
        $store = app_load_gallery_store();
        $images = $store['images'] ?? [];
        $index = null;
        foreach ($images as $i => $item) {
            if (($item['id'] ?? '') === $id) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            throw new AppError(404, 'not_found', '图片不存在。');
        }

        $target = $direction === 'up' ? $index - 1 : $index + 1;
        if ($target < 0 || $target >= count($images)) {
            return;
        }

        $item = $images[$index];
        array_splice($images, $index, 1);
        array_splice($images, $target, 0, [$item]);
        $store['images'] = array_values($images);
        app_save_gallery_store($store);
    });
}

function app_serve_media(string $id, string $method = 'GET'): never
{
    $safeId = basename($id);
    $file = APP_GALLERY_DIR . '/' . $safeId;
    if (!is_file($file)) {
        app_not_found();
    }

    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $contentType = match ($ext) {
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        default => 'application/octet-stream',
    };

    http_response_code(200);
    header('Content-Type: ' . $contentType);
    header('Cache-Control: no-store');
    header('Content-Length: ' . (string) filesize($file));
    if (strtoupper($method) !== 'HEAD') {
        readfile($file);
    }
    exit;
}

function app_dispatch(string $method, string $path): never
{
    $config = app_config();
    $ip = app_client_ip();

    try {
        if ($method === 'GET' && ($path === '/' || $path === '/index.php')) {
            app_page_response('index.php');
        }

        if ($method === 'GET' && ($path === '/admin.html' || $path === '/admin.php')) {
            app_page_response('admin.php');
        }

        if ($method === 'GET' && $path === '/api/health') {
            $configOk = true;
            try {
                app_assert_config();
            } catch (Throwable) {
                $configOk = false;
            }
            app_json_response(200, ['ok' => true, 'configOk' => $configOk]);
        }

        if ($method === 'GET' && $path === '/api/config') {
            app_json_response(200, [
                'claimAmount' => $config['claim_amount'],
                'title' => 'dogcoding 额度领取',
                'captchaRequired' => true,
            ]);
        }

        if ($method === 'GET' && $path === '/api/gallery') {
            $items = app_list_gallery();
            app_json_response(200, ['items' => $items, 'total' => count($items)]);
        }

        if ($method === 'GET' && $path === '/api/captcha') {
            app_check_rate_limit('captcha', $ip, $config['captcha_rate_limit_max']);
            app_json_response(200, app_issue_captcha($ip));
        }

        if ($method === 'POST' && $path === '/api/claim') {
            app_check_rate_limit('claim', $ip, $config['rate_limit_max']);
            app_assert_config();
            $body = app_read_json_body();
            $email = app_normalize_email((string) ($body['email'] ?? ''));
            if (!app_is_valid_email($email)) {
                throw new AppError(400, 'invalid_email', '请输入有效邮箱。');
            }
            app_verify_captcha((string) ($body['captchaToken'] ?? ''), (string) ($body['captchaCode'] ?? ''), $ip);
            $result = app_process_claim($email, $ip);
            app_json_response(200, [
                'ok' => true,
                'message' => '已为 ' . $result['user']['email'] . ' 发放 ' . $result['amount'] . ' 刀。',
                'user' => $result['user'],
                'amount' => $result['amount'],
                'awardedAt' => $result['awardedAt'],
            ]);
        }

        if ($method === 'GET' && $path === '/api/admin/claims') {
            app_require_records_access();
            $items = app_list_claims([
                'search' => $_GET['search'] ?? '',
                'status' => $_GET['status'] ?? '',
                'sortBy' => $_GET['sortBy'] ?? 'awardedAt',
                'sortOrder' => $_GET['sortOrder'] ?? 'desc',
            ]);
            app_json_response(200, ['items' => $items, 'total' => count($items)]);
        }

        if ($method === 'POST' && $path === '/api/gallery/upload') {
            app_require_records_access();
            if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
                throw new AppError(400, 'file_required', '请上传图片文件。');
            }
            $item = app_store_gallery_image($_FILES['image'], $_POST);
            app_json_response(200, ['ok' => true, 'item' => $item]);
        }

        if ($method === 'DELETE' && preg_match('#^/api/gallery/([^/]+)$#', $path, $matches)) {
            app_require_records_access();
            app_delete_gallery_image(rawurldecode($matches[1]));
            app_json_response(200, ['ok' => true]);
        }

        if ($method === 'POST' && preg_match('#^/api/gallery/([^/]+)/move$#', $path, $matches)) {
            app_require_records_access();
            $body = app_read_json_body();
            $direction = (($body['direction'] ?? 'down') === 'up') ? 'up' : 'down';
            app_move_gallery_image(rawurldecode($matches[1]), $direction);
            app_json_response(200, ['ok' => true]);
        }

        if (($method === 'GET' || $method === 'HEAD') && preg_match('#^/media/([^/]+)$#', $path, $matches)) {
            app_serve_media(rawurldecode($matches[1]), $method);
        }

        app_not_found();
    } catch (AppError $error) {
        app_json_response($error->status, [
            'error' => $error->errorCode,
            'message' => $error->getMessage(),
        ]);
    } catch (Throwable $error) {
        error_log((string) $error);
        app_json_response(500, [
            'error' => 'internal_error',
            'message' => '服务异常，请稍后再试。',
        ]);
    }
}
