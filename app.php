<?php
declare(strict_types=1);

const APP_BASE_DIR = __DIR__;
const APP_DATA_DIR = APP_BASE_DIR . '/data';
const APP_GALLERY_DIR = APP_DATA_DIR . '/gallery';
const APP_LOCK_DIR = APP_DATA_DIR . '/locks';
const APP_CLAIMS_FILE = APP_DATA_DIR . '/claims.json';
const APP_GALLERY_FILE = APP_DATA_DIR . '/gallery.json';
const APP_RATE_LIMITS_FILE = APP_DATA_DIR . '/rate_limits.json';
const APP_UPSTREAM_TOKEN_FILE = APP_DATA_DIR . '/upstream_token.json';
const APP_LOG_FILE = APP_DATA_DIR . '/app.log';

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
        'upstream_base_url' => rtrim((string) app_env('UPSTREAM_BASE_URL', ''), '/'),
        'upstream_host_header' => trim((string) app_env('UPSTREAM_HOST_HEADER', '')),
        'admin_email' => trim((string) app_env('ADMIN_EMAIL', '')),
        'admin_password' => (string) app_env('ADMIN_PASSWORD', ''),
        'records_access_key' => trim((string) app_env('RECORDS_ACCESS_KEY', '')),
        'db_driver' => strtolower(trim((string) app_env('DB_DRIVER', 'file'))),
        'db_host' => trim((string) app_env('DB_HOST', '')),
        'db_port' => trim((string) app_env('DB_PORT', '3306')),
        'db_database' => trim((string) app_env('DB_DATABASE', '')),
        'db_username' => trim((string) app_env('DB_USERNAME', '')),
        'db_password' => (string) app_env('DB_PASSWORD', ''),
        'db_charset' => trim((string) app_env('DB_CHARSET', 'utf8mb4')),
        'log_file' => trim((string) app_env('LOG_FILE', APP_LOG_FILE)),
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

function app_log(string $level, string $message, array $context = []): void
{
    app_ensure_data_dirs();
    $filtered = [];
    foreach ($context as $key => $value) {
        if (preg_match('/password|token|secret|key/i', (string) $key)) {
            $filtered[$key] = '[redacted]';
            continue;
        }
        $filtered[$key] = is_scalar($value) || $value === null ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $payload = [
        'time' => gmdate('c'),
        'level' => strtoupper($level),
        'message' => $message,
        'context' => $filtered,
    ];
    $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        $line = gmdate('c') . ' ' . strtoupper($level) . ' ' . $message;
    }

    $logFile = app_config()['log_file'] !== '' ? app_config()['log_file'] : APP_LOG_FILE;
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }
    @file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    error_log($line);
}

function app_read_logs(int $limit = 200): array
{
    $logFile = app_config()['log_file'] !== '' ? app_config()['log_file'] : APP_LOG_FILE;
    if (!is_file($logFile)) {
        return [];
    }

    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        throw new AppError(500, 'storage_error', '无法读取日志文件。');
    }

    $lines = array_slice($lines, -max(1, min($limit, 500)));
    $items = [];
    foreach (array_reverse($lines) as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $items[] = [
                'time' => $decoded['time'] ?? null,
                'level' => $decoded['level'] ?? '',
                'message' => $decoded['message'] ?? '',
                'context' => is_array($decoded['context'] ?? null) ? $decoded['context'] : [],
            ];
            continue;
        }
        $items[] = [
            'time' => null,
            'level' => 'INFO',
            'message' => $line,
            'context' => [],
        ];
    }

    return $items;
}

function app_uses_database(): bool
{
    $config = app_config();
    return $config['db_driver'] === 'mysql' || $config['db_host'] !== '' || $config['db_database'] !== '';
}

function app_database(bool $initialize = false): PDO
{
    static $pdo = null;
    static $initialized = false;

    if ($pdo !== null) {
        if ($initialize && !$initialized) {
            app_log('info', 'Initializing database', [
                'host' => app_config()['db_host'],
                'port' => app_config()['db_port'],
                'database' => app_config()['db_database'],
            ]);
            app_initialize_database($pdo);
            $initialized = true;
            app_log('info', 'Database initialized', [
                'database' => app_config()['db_database'],
            ]);
        }
        return $pdo;
    }

    $config = app_config();
    if (!app_uses_database()) {
        throw new AppError(500, 'config_error', '数据库未配置。');
    }
    if ($config['db_database'] === '' || $config['db_username'] === '') {
        throw new AppError(500, 'config_error', 'DB_DATABASE 或 DB_USERNAME 未配置。');
    }

    $host = $config['db_host'] !== '' ? $config['db_host'] : '127.0.0.1';
    $charset = $config['db_charset'] !== '' ? $config['db_charset'] : 'utf8mb4';
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $host,
        $config['db_port'] !== '' ? $config['db_port'] : '3306',
        $config['db_database'],
        $charset
    );

    try {
        $pdo = new PDO($dsn, $config['db_username'], $config['db_password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $error) {
        app_log('error', 'Database connection failed', [
            'host' => $host,
            'port' => $config['db_port'],
            'database' => $config['db_database'],
            'error' => $error->getMessage(),
        ]);
        throw new AppError(500, 'database_error', '数据库连接失败：' . $error->getMessage());
    }

    if ($initialize && !$initialized) {
        app_log('info', 'Initializing database', [
            'host' => $host,
            'port' => $config['db_port'],
            'database' => $config['db_database'],
        ]);
        app_initialize_database($pdo);
        $initialized = true;
        app_log('info', 'Database initialized', [
            'database' => $config['db_database'],
        ]);
    }

    return $pdo;
}

function app_initialize_database(PDO $pdo): void
{
    app_log('info', 'Ensuring database tables');
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS claims (
            id VARCHAR(64) PRIMARY KEY,
            type VARCHAR(32) NOT NULL DEFAULT 'auto_claim',
            user_id VARCHAR(128) NOT NULL DEFAULT '',
            email VARCHAR(255) NOT NULL DEFAULT '',
            normalized_email VARCHAR(255) NOT NULL DEFAULT '',
            amount DECIMAL(18, 4) NOT NULL DEFAULT 0,
            status VARCHAR(32) NOT NULL DEFAULT 'pending',
            auto_email_key VARCHAR(255) NULL,
            auto_user_key VARCHAR(128) NULL,
            remote_ip VARCHAR(64) NOT NULL DEFAULT '',
            notes TEXT NULL,
            created_at VARCHAR(40) NOT NULL,
            awarded_at VARCHAR(40) NULL,
            failed_at VARCHAR(40) NULL,
            error_message TEXT NULL,
            INDEX idx_claims_email (normalized_email),
            INDEX idx_claims_user (user_id),
            INDEX idx_claims_type_status (type, status),
            INDEX idx_claims_created_at (created_at),
            INDEX idx_claims_awarded_at (awarded_at),
            UNIQUE KEY uniq_claims_auto_email (auto_email_key),
            UNIQUE KEY uniq_claims_auto_user (auto_user_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    app_ensure_database_column($pdo, 'claims', 'auto_email_key', 'VARCHAR(255) NULL');
    app_ensure_database_column($pdo, 'claims', 'auto_user_key', 'VARCHAR(128) NULL');
    app_ensure_database_index($pdo, 'claims', 'uniq_claims_auto_email', 'UNIQUE KEY uniq_claims_auto_email (auto_email_key)');
    app_ensure_database_index($pdo, 'claims', 'uniq_claims_auto_user', 'UNIQUE KEY uniq_claims_auto_user (auto_user_key)');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS gallery_images (
            id VARCHAR(128) PRIMARY KEY,
            title VARCHAR(255) NOT NULL DEFAULT '',
            alt VARCHAR(255) NOT NULL DEFAULT '',
            content_type VARCHAR(128) NOT NULL DEFAULT 'application/octet-stream',
            original_name VARCHAR(255) NOT NULL DEFAULT '',
            sort_order INT NOT NULL DEFAULT 0,
            created_at VARCHAR(40) NOT NULL,
            INDEX idx_gallery_sort (sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS rate_limit_hits (
            scope_name VARCHAR(64) NOT NULL,
            ip VARCHAR(128) NOT NULL,
            hit_at INT NOT NULL,
            INDEX idx_rate_limit_lookup (scope_name, ip, hit_at),
            INDEX idx_rate_limit_hit_at (hit_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    app_import_json_to_database($pdo);
}

function app_ensure_database_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
    );
    $stmt->execute([
        'table' => $table,
        'column' => $column,
    ]);
    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->exec(sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', $table, $column, $definition));
    }
}

function app_ensure_database_index(PDO $pdo, string $table, string $indexName, string $definition): void
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :index_name'
    );
    $stmt->execute([
        'table' => $table,
        'index_name' => $indexName,
    ]);
    if ((int) $stmt->fetchColumn() === 0) {
        try {
            $pdo->exec(sprintf('ALTER TABLE `%s` ADD %s', $table, $definition));
        } catch (PDOException $error) {
            app_log('warning', 'Unable to create database index', [
                'table' => $table,
                'index' => $indexName,
                'error' => $error->getMessage(),
            ]);
        }
    }
}

function app_import_json_to_database(PDO $pdo): void
{
    $claimCount = (int) $pdo->query('SELECT COUNT(*) FROM claims')->fetchColumn();
    if ($claimCount === 0 && is_file(APP_CLAIMS_FILE)) {
        $store = app_load_json_file(APP_CLAIMS_FILE, ['claims' => []]);
        $claims = is_array($store['claims'] ?? null) ? $store['claims'] : [];
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO claims (
                id, type, user_id, email, normalized_email, amount, status, auto_email_key, auto_user_key,
                remote_ip, notes, created_at, awarded_at, failed_at, error_message
            ) VALUES (
                :id, :type, :user_id, :email, :normalized_email, :amount, :status, :auto_email_key, :auto_user_key,
                :remote_ip, :notes, :created_at, :awarded_at, :failed_at, :error_message
            )'
        );
        $seenAutoEmails = [];
        $seenAutoUsers = [];
        foreach ($claims as $claim) {
            if (!is_array($claim)) {
                continue;
            }
            $type = (string) ($claim['type'] ?? 'auto_claim');
            $status = (string) ($claim['status'] ?? 'pending');
            $normalizedEmail = (string) ($claim['normalizedEmail'] ?? app_normalize_email((string) ($claim['email'] ?? '')));
            $userId = (string) ($claim['userId'] ?? '');
            $activeAuto = $type === 'auto_claim' && $status !== 'failed';
            $autoEmailKey = null;
            $autoUserKey = null;
            if ($activeAuto) {
                if ($normalizedEmail !== '' && !isset($seenAutoEmails[$normalizedEmail])) {
                    $autoEmailKey = $normalizedEmail;
                    $seenAutoEmails[$normalizedEmail] = true;
                }
                if ($userId !== '' && !isset($seenAutoUsers[$userId])) {
                    $autoUserKey = $userId;
                    $seenAutoUsers[$userId] = true;
                }
            }
            $stmt->execute([
                'id' => (string) ($claim['id'] ?? bin2hex(random_bytes(16))),
                'type' => $type,
                'user_id' => $userId,
                'email' => (string) ($claim['email'] ?? ''),
                'normalized_email' => $normalizedEmail,
                'amount' => (float) ($claim['amount'] ?? 0),
                'status' => $status,
                'auto_email_key' => $autoEmailKey,
                'auto_user_key' => $autoUserKey,
                'remote_ip' => (string) ($claim['remoteIp'] ?? ''),
                'notes' => (string) ($claim['notes'] ?? ''),
                'created_at' => (string) ($claim['createdAt'] ?? gmdate('c')),
                'awarded_at' => $claim['awardedAt'] ?? null,
                'failed_at' => $claim['failedAt'] ?? null,
                'error_message' => $claim['errorMessage'] ?? null,
            ]);
        }
        app_log('info', 'Imported claims from JSON', [
            'count' => count($claims),
        ]);
    }

    $galleryCount = (int) $pdo->query('SELECT COUNT(*) FROM gallery_images')->fetchColumn();
    if ($galleryCount === 0 && is_file(APP_GALLERY_FILE)) {
        $store = app_load_json_file(APP_GALLERY_FILE, ['images' => []]);
        $images = is_array($store['images'] ?? null) ? $store['images'] : [];
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO gallery_images (id, title, alt, content_type, original_name, sort_order, created_at)
             VALUES (:id, :title, :alt, :content_type, :original_name, :sort_order, :created_at)'
        );
        foreach ($images as $index => $image) {
            if (!is_array($image) || trim((string) ($image['id'] ?? '')) === '') {
                continue;
            }
            $stmt->execute([
                'id' => (string) $image['id'],
                'title' => (string) ($image['title'] ?? ''),
                'alt' => (string) ($image['alt'] ?? ''),
                'content_type' => (string) ($image['contentType'] ?? 'application/octet-stream'),
                'original_name' => (string) ($image['originalName'] ?? ''),
                'sort_order' => $index,
                'created_at' => (string) ($image['createdAt'] ?? gmdate('c')),
            ]);
        }
        app_log('info', 'Imported gallery images from JSON', [
            'count' => count($images),
        ]);
    }
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
    if (($config['upstream_base_url'] !== '') && !preg_match('#^https?://#i', $config['upstream_base_url'])) {
        throw new AppError(500, 'config_error', 'UPSTREAM_BASE_URL 必须以 http:// 或 https:// 开头。');
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

function app_asset_url(string $relativePath): string
{
    $clean = '/' . ltrim($relativePath, '/');
    $fullPath = APP_BASE_DIR . $clean;
    $version = is_file($fullPath) ? (string) filemtime($fullPath) : (string) time();
    return $clean . '?v=' . rawurlencode($version);
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

function app_admin_is_authenticated(): bool
{
    return ($_SESSION['admin_authenticated'] ?? false) === true;
}

function app_admin_authenticate(string $accessKey): void
{
    app_assert_records_access_configured();
    if (!hash_equals(app_config()['records_access_key'], trim($accessKey))) {
        throw new AppError(401, 'unauthorized', '访问密钥错误。');
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['admin_authenticated'] = true;
    $_SESSION['admin_authenticated_at'] = time();
}

function app_admin_logout(): void
{
    unset($_SESSION['admin_authenticated'], $_SESSION['admin_authenticated_at']);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

function app_require_records_access(): void
{
    app_assert_records_access_configured();
    if (app_admin_is_authenticated()) {
        return;
    }

    $requestHeaders = function_exists('apache_request_headers') ? apache_request_headers() : [];
    $token = trim((string) (
        $_SERVER['HTTP_X_ACCESS_KEY']
        ?? $requestHeaders['X-Access-Key']
        ?? ''
    ));

    if ($token === '') {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? $requestHeaders['Authorization']
            ?? '';
        if (!preg_match('/^Bearer\s+(.+)$/i', (string) $header, $matches)) {
            throw new AppError(401, 'unauthorized', '访问密钥错误或未填写。');
        }
        $token = trim($matches[1]);
    }

    if ($token === '' || $token !== app_config()['records_access_key']) {
        throw new AppError(401, 'unauthorized', '访问密钥错误或未填写。');
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

    if (app_uses_database()) {
        $pdo = app_database();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('DELETE FROM rate_limit_hits WHERE hit_at <= :cutoff');
            $stmt->execute(['cutoff' => $now - $window]);

            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM rate_limit_hits WHERE scope_name = :scope AND ip = :ip AND hit_at > :cutoff'
            );
            $stmt->execute([
                'scope' => $scope,
                'ip' => $ip,
                'cutoff' => $now - $window,
            ]);
            if ((int) $stmt->fetchColumn() >= $max) {
                $pdo->commit();
                throw new AppError(429, 'rate_limited', '请求过于频繁，请稍后再试。');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO rate_limit_hits (scope_name, ip, hit_at) VALUES (:scope, :ip, :hit_at)'
            );
            $stmt->execute([
                'scope' => $scope,
                'ip' => $ip,
                'hit_at' => $now,
            ]);
            $pdo->commit();
            return;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

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
    $method = strtoupper($method);
    $hasConnectionHeader = false;
    $hasUserAgentHeader = false;
    $headerLines = [];
    foreach ($headers as $name => $value) {
        if (strcasecmp($name, 'Connection') === 0) {
            $hasConnectionHeader = true;
        }
        if (strcasecmp($name, 'User-Agent') === 0) {
            $hasUserAgentHeader = true;
        }
        $headerLines[] = $name . ': ' . $value;
    }
    if (!$hasConnectionHeader) {
        $headerLines[] = 'Connection: close';
    }
    if (!$hasUserAgentHeader) {
        $headerLines[] = 'User-Agent: laodog-bonus-claim/1.0';
    }

    if (function_exists('curl_init')) {
        $lastError = '';
        $parts = parse_url($url);
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $ch = curl_init($url);
            if ($ch === false) {
                break;
            }

            $options = [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headerLines,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            ];
            if ($body !== null) {
                $options[CURLOPT_POSTFIELDS] = $body;
            }
            curl_setopt_array($ch, $options);
            $raw = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);

            if ($raw !== false && $status > 0) {
                $raw = (string) $raw;
                $headerBlock = substr($raw, 0, $headerSize);
                $responseBody = substr($raw, $headerSize);
                $responseHeaders = array_values(array_filter(
                    preg_split('/\r\n|\n|\r/', trim($headerBlock)) ?: [],
                    static fn ($line) => $line !== ''
                ));
                return [
                    'status' => $status,
                    'body' => $responseBody,
                    'headers' => $responseHeaders,
                ];
            }

            $lastError = $error !== '' ? $error : ('curl errno ' . $errno);
            app_log('warning', 'Upstream curl attempt failed', [
                'method' => $method,
                'host' => $parts['host'] ?? '',
                'path' => $parts['path'] ?? '',
                'attempt' => $attempt,
                'error' => $lastError,
            ]);
            usleep(200000);
        }

        app_log('warning', 'Upstream curl failed, falling back to PHP stream', [
            'method' => $method,
            'host' => $parts['host'] ?? '',
            'path' => $parts['path'] ?? '',
            'error' => $lastError,
        ]);
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headerLines),
            'content' => $body ?? '',
            'ignore_errors' => true,
            'protocol_version' => 1.1,
            'timeout' => 20,
        ],
    ]);

    error_clear_last();
    $result = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    if ($result === false && $responseHeaders === []) {
        $error = error_get_last();
        $parts = parse_url($url);
        app_log('error', 'Upstream connection failed', [
            'method' => $method,
            'host' => $parts['host'] ?? '',
            'path' => $parts['path'] ?? '',
            'error' => is_array($error) ? ($error['message'] ?? '') : '',
        ]);
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

function app_load_upstream_token(): ?string
{
    $store = app_load_json_file(APP_UPSTREAM_TOKEN_FILE, []);
    $token = (string) ($store['access_token'] ?? '');
    $expiresAt = (int) ($store['expires_at'] ?? 0);
    if ($token === '' || $expiresAt <= time() + 60) {
        return null;
    }
    return $token;
}

function app_save_upstream_token(string $token, int $expiresIn): void
{
    app_save_json_file(APP_UPSTREAM_TOKEN_FILE, [
        'access_token' => $token,
        'expires_at' => time() + max(60, $expiresIn),
        'created_at' => gmdate('c'),
    ]);
}

function app_clear_upstream_token(): void
{
    if (is_file(APP_UPSTREAM_TOKEN_FILE) && !unlink(APP_UPSTREAM_TOKEN_FILE)) {
        app_log('warning', 'Unable to clear upstream token cache');
    }
}

function app_upstream_json(string $method, string $path, array $query = [], ?array $body = null, ?string $token = null)
{
    $config = app_config();
    $baseUrl = $config['upstream_base_url'] !== '' ? $config['upstream_base_url'] : $config['base_url'];
    $url = $baseUrl . $path;
    if ($query !== []) {
        $url .= '?' . http_build_query(array_filter(
            $query,
            static fn ($value) => $value !== null && $value !== ''
        ));
    }

    $headers = ['Accept' => 'application/json'];
    if ($config['upstream_host_header'] !== '') {
        $headers['Host'] = $config['upstream_host_header'];
    }
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

    if (is_array($payload) && array_key_exists('code', $payload) && (int) $payload['code'] !== 0) {
        $upstreamCode = (int) $payload['code'];
        $status = ($upstreamCode >= 400 && $upstreamCode < 600) ? $upstreamCode : 502;
        throw new AppError(
            $status,
            'upstream_error',
            app_extract_upstream_message($payload, '上游接口请求失败。'),
            ['upstreamCode' => $upstreamCode]
        );
    }

    return $payload;
}

function app_admin_login_token(bool $forceRefresh = false): string
{
    app_assert_config();
    if (!$forceRefresh) {
        $cached = app_load_upstream_token();
        if ($cached !== null) {
            app_log('info', 'Using cached upstream token');
            return $cached;
        }
    }

    $config = app_config();
    app_log('info', 'Requesting upstream admin token');
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

    app_save_upstream_token(
        $payload['access_token'],
        isset($payload['expires_in']) ? (int) $payload['expires_in'] : 3600
    );
    app_log('info', 'Upstream admin token cached');

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

function app_add_user_balance(string $token, int|string $userId, float $amount, string $notes): void
{
    app_upstream_json(
        'POST',
        '/api/v1/admin/users/' . rawurlencode((string) $userId) . '/balance',
        [],
        [
            'balance' => $amount,
            'operation' => 'add',
            'notes' => $notes,
        ],
        $token
    );
}

function app_with_upstream_token(callable $callback)
{
    $token = app_admin_login_token();
    try {
        return $callback($token);
    } catch (AppError $error) {
        if ($error->status !== 401) {
            throw $error;
        }
        app_clear_upstream_token();
        app_log('warning', 'Refreshing upstream token after unauthorized response');
        $token = app_admin_login_token(true);
        return $callback($token);
    }
}

function app_find_user_by_email_using_admin(string $email): ?array
{
    return app_with_upstream_token(static fn (string $token): ?array => app_find_user_by_email($token, $email));
}

function app_add_user_balance_using_admin(int|string $userId, float $amount, string $notes): void
{
    app_with_upstream_token(static function (string $token) use ($userId, $amount, $notes): void {
        app_add_user_balance($token, $userId, $amount, $notes);
    });
}

function app_load_claims_store(): array
{
    return app_load_json_file(APP_CLAIMS_FILE, ['version' => 1, 'claims' => []]);
}

function app_save_claims_store(array $store): void
{
    app_save_json_file(APP_CLAIMS_FILE, $store);
}

function app_db_claim_from_row(array $row): array
{
    return [
        'id' => $row['id'] ?? '',
        'type' => $row['type'] ?? 'auto_claim',
        'userId' => $row['user_id'] ?? '',
        'email' => $row['email'] ?? '',
        'normalizedEmail' => $row['normalized_email'] ?? '',
        'amount' => isset($row['amount']) ? (float) $row['amount'] : 0,
        'status' => $row['status'] ?? '',
        'remoteIp' => $row['remote_ip'] ?? '',
        'notes' => $row['notes'] ?? '',
        'createdAt' => $row['created_at'] ?? null,
        'awardedAt' => $row['awarded_at'] ?? null,
        'failedAt' => $row['failed_at'] ?? null,
        'errorMessage' => $row['error_message'] ?? null,
    ];
}

function app_db_insert_claim(PDO $pdo, array $record): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO claims (
            id, type, user_id, email, normalized_email, amount, status, auto_email_key, auto_user_key, remote_ip, notes,
            created_at, awarded_at, failed_at, error_message
        ) VALUES (
            :id, :type, :user_id, :email, :normalized_email, :amount, :status, :auto_email_key, :auto_user_key, :remote_ip, :notes,
            :created_at, :awarded_at, :failed_at, :error_message
        )'
    );
    $type = (string) ($record['type'] ?? 'auto_claim');
    $status = (string) ($record['status'] ?? 'pending');
    $activeAuto = $type === 'auto_claim' && $status !== 'failed';
    $stmt->execute([
        'id' => $record['id'],
        'type' => $type,
        'user_id' => (string) ($record['userId'] ?? ''),
        'email' => (string) ($record['email'] ?? ''),
        'normalized_email' => (string) ($record['normalizedEmail'] ?? ''),
        'amount' => (float) ($record['amount'] ?? 0),
        'status' => $status,
        'auto_email_key' => $activeAuto ? (string) ($record['normalizedEmail'] ?? '') : null,
        'auto_user_key' => $activeAuto ? (string) ($record['userId'] ?? '') : null,
        'remote_ip' => (string) ($record['remoteIp'] ?? ''),
        'notes' => (string) ($record['notes'] ?? ''),
        'created_at' => (string) ($record['createdAt'] ?? gmdate('c')),
        'awarded_at' => $record['awardedAt'] ?? null,
        'failed_at' => $record['failedAt'] ?? null,
        'error_message' => $record['errorMessage'] ?? null,
    ]);
}

function app_db_update_claim_status(PDO $pdo, string $id, string $status, ?string $awardedAt, ?string $failedAt, ?string $errorMessage): array
{
    $stmt = $pdo->prepare(
        'UPDATE claims
         SET status = :status,
             auto_email_key = CASE WHEN type = "auto_claim" AND :status_email <> "failed" THEN normalized_email ELSE NULL END,
             auto_user_key = CASE WHEN type = "auto_claim" AND :status_user <> "failed" THEN user_id ELSE NULL END,
             awarded_at = :awarded_at,
             failed_at = :failed_at,
             error_message = :error_message
         WHERE id = :id'
    );
    $stmt->execute([
        'id' => $id,
        'status' => $status,
        'status_email' => $status,
        'status_user' => $status,
        'awarded_at' => $awardedAt,
        'failed_at' => $failedAt,
        'error_message' => $errorMessage,
    ]);

    $stmt = $pdo->prepare('SELECT * FROM claims WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        throw new AppError(500, 'storage_error', '无法读取余额记录。');
    }
    return app_db_claim_from_row($row);
}

function app_db_find_active_auto_claim(PDO $pdo, string $field, string $value): ?array
{
    return app_db_find_active_claim_by_type($pdo, 'auto_claim', $field, $value);
}

function app_db_find_active_manual_balance(PDO $pdo, string $field, string $value): ?array
{
    return app_db_find_active_claim_by_type($pdo, 'manual_balance', $field, $value);
}

function app_db_find_active_claim_by_type(PDO $pdo, string $type, string $field, string $value): ?array
{
    $column = match ($field) {
        'normalizedEmail' => 'normalized_email',
        'userId' => 'user_id',
        default => throw new AppError(500, 'internal_error', '未知查重字段。'),
    };
    $stmt = $pdo->prepare(
        "SELECT * FROM claims
         WHERE type = :type AND status <> 'failed' AND {$column} = :value
         ORDER BY created_at DESC
         LIMIT 1"
    );
    $stmt->execute([
        'type' => $type,
        'value' => $value,
    ]);
    $row = $stmt->fetch();
    return is_array($row) ? app_db_claim_from_row($row) : null;
}

function app_find_active_claim(array $claims, callable $predicate): ?array
{
    return app_find_active_claim_by_type($claims, 'auto_claim', $predicate);
}

function app_find_active_claim_by_type(array $claims, string $type, callable $predicate): ?array
{
    foreach ($claims as $claim) {
        if ((string) ($claim['type'] ?? '') === $type && ($claim['status'] ?? '') !== 'failed' && $predicate($claim)) {
            return $claim;
        }
    }
    return null;
}

function app_process_claim_db(string $email, string $remoteIp): array
{
    $pdo = app_database();
    $pdo->beginTransaction();

    try {
        $existingByEmail = app_db_find_active_auto_claim($pdo, 'normalizedEmail', $email);
        if (($existingByEmail['status'] ?? '') === 'completed') {
            throw new AppError(409, 'already_claimed', '该邮箱已经领取过 10 刀。');
        }
        if (($existingByEmail['status'] ?? '') === 'pending') {
            throw new AppError(409, 'claim_pending', '该邮箱的领取请求正在处理中，请稍后再试。');
        }

        $user = app_find_user_by_email_using_admin($email);
        if ($user === null) {
            throw new AppError(404, 'user_not_found', '无该账户。');
        }

        $userId = (string) ($user['id'] ?? '');
        $existingByUserId = app_db_find_active_auto_claim($pdo, 'userId', $userId);
        if (($existingByUserId['status'] ?? '') === 'completed') {
            throw new AppError(409, 'already_claimed', '该账户已经领取过 10 刀。');
        }
        if (($existingByUserId['status'] ?? '') === 'pending') {
            throw new AppError(409, 'claim_pending', '该账户的领取请求正在处理中，请稍后再试。');
        }

        $record = [
            'id' => bin2hex(random_bytes(16)),
            'type' => 'auto_claim',
            'userId' => $userId,
            'email' => (string) ($user['email'] ?? $email),
            'normalizedEmail' => $email,
            'amount' => app_config()['claim_amount'],
            'status' => 'pending',
            'remoteIp' => $remoteIp,
            'notes' => app_config()['claim_notes'],
            'createdAt' => gmdate('c'),
            'awardedAt' => null,
            'failedAt' => null,
            'errorMessage' => null,
        ];
        try {
            app_db_insert_claim($pdo, $record);
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? null) === 1062) {
                throw new AppError(409, 'already_claimed', '该邮箱或账户已经提交过领取请求。');
            }
            throw $error;
        }
        $pdo->commit();
        app_log('info', 'Auto claim record created', [
            'id' => $record['id'],
            'email' => $record['normalizedEmail'],
            'userId' => $record['userId'],
            'amount' => $record['amount'],
        ]);

        try {
            $notes = app_config()['claim_notes'] . ' [claim:' . $record['id'] . ']';
            app_add_user_balance_using_admin($userId, (float) app_config()['claim_amount'], $notes);
            $record = app_db_update_claim_status($pdo, $record['id'], 'completed', gmdate('c'), null, null);
            app_log('info', 'Auto claim completed', [
                'id' => $record['id'],
                'email' => $record['normalizedEmail'],
                'userId' => $record['userId'],
                'amount' => $record['amount'],
            ]);
            return [
                'user' => [
                    'id' => $user['id'],
                    'email' => $user['email'],
                ],
                'amount' => app_config()['claim_amount'],
                'awardedAt' => $record['awardedAt'],
            ];
        } catch (Throwable $error) {
            app_db_update_claim_status(
                $pdo,
                $record['id'],
                'failed',
                null,
                gmdate('c'),
                $error instanceof AppError ? $error->getMessage() : '未知错误'
            );
            app_log('error', 'Auto claim failed', [
                'id' => $record['id'],
                'email' => $record['normalizedEmail'],
                'userId' => $record['userId'],
                'error' => $error->getMessage(),
            ]);
            throw $error;
        }
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($error instanceof AppError) {
            throw $error;
        }
        throw new AppError(500, 'internal_error', '发放额度时发生异常。');
    }
}

function app_process_claim(string $email, string $remoteIp): array
{
    if (app_uses_database()) {
        return app_process_claim_db($email, $remoteIp);
    }

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

        $user = app_find_user_by_email_using_admin($email);
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
            'type' => 'auto_claim',
            'userId' => $userId,
            'email' => (string) ($user['email'] ?? $email),
            'normalizedEmail' => $email,
            'amount' => app_config()['claim_amount'],
            'status' => 'pending',
            'remoteIp' => $remoteIp,
            'notes' => app_config()['claim_notes'],
            'createdAt' => gmdate('c'),
            'awardedAt' => null,
            'failedAt' => null,
            'errorMessage' => null,
        ];

        $store['claims'][] = $record;
        app_save_claims_store($store);
        app_log('info', 'Auto claim record created', [
            'id' => $record['id'],
            'email' => $record['normalizedEmail'],
            'userId' => $record['userId'],
            'amount' => $record['amount'],
        ]);

        try {
            $notes = app_config()['claim_notes'] . ' [claim:' . $record['id'] . ']';
            app_add_user_balance_using_admin($userId, (float) app_config()['claim_amount'], $notes);
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
            app_log('info', 'Auto claim completed', [
                'id' => $record['id'],
                'email' => $record['normalizedEmail'],
                'userId' => $record['userId'],
                'amount' => $record['amount'],
            ]);

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
            app_log('error', 'Auto claim failed', [
                'id' => $record['id'],
                'email' => $record['normalizedEmail'],
                'userId' => $record['userId'],
                'error' => $error->getMessage(),
            ]);
            if ($error instanceof AppError) {
                throw $error;
            }
            throw new AppError(500, 'internal_error', '发放额度时发生异常。');
        }
    });
}

function app_process_manual_balance_db(string $email, float $amount, string $notes, string $remoteIp): array
{
    $pdo = app_database();
    $user = app_find_user_by_email_using_admin($email);
    if ($user === null) {
        throw new AppError(404, 'user_not_found', '无该账户。');
    }

    $userId = (string) ($user['id'] ?? '');
    if ($userId === '') {
        throw new AppError(502, 'unexpected_users_response', '用户信息缺少 ID。');
    }

    $existingByEmail = app_db_find_active_manual_balance($pdo, 'normalizedEmail', $email);
    if (($existingByEmail['status'] ?? '') === 'completed') {
        throw new AppError(409, 'already_claimed', '该邮箱已经加过余额。');
    }
    if (($existingByEmail['status'] ?? '') === 'pending') {
        throw new AppError(409, 'claim_pending', '该邮箱的加余额请求正在处理中，请稍后再试。');
    }

    $existingByUserId = app_db_find_active_manual_balance($pdo, 'userId', $userId);
    if (($existingByUserId['status'] ?? '') === 'completed') {
        throw new AppError(409, 'already_claimed', '该账户已经加过余额。');
    }
    if (($existingByUserId['status'] ?? '') === 'pending') {
        throw new AppError(409, 'claim_pending', '该账户的加余额请求正在处理中，请稍后再试。');
    }

    $recordNotes = trim($notes) !== '' ? trim($notes) : '管理员手动加余额';
    $record = [
        'id' => bin2hex(random_bytes(16)),
        'type' => 'manual_balance',
        'userId' => $userId,
        'email' => (string) ($user['email'] ?? $email),
        'normalizedEmail' => $email,
        'amount' => $amount,
        'status' => 'pending',
        'remoteIp' => $remoteIp,
        'notes' => $recordNotes,
        'createdAt' => gmdate('c'),
        'awardedAt' => null,
        'failedAt' => null,
        'errorMessage' => null,
    ];

    app_db_insert_claim($pdo, $record);
    app_log('info', 'Manual balance record created', [
        'id' => $record['id'],
        'email' => $record['normalizedEmail'],
        'userId' => $record['userId'],
        'amount' => $record['amount'],
    ]);

    try {
        app_add_user_balance_using_admin($userId, $amount, $recordNotes . ' [manual:' . $record['id'] . ']');
        $record = app_db_update_claim_status($pdo, $record['id'], 'completed', gmdate('c'), null, null);
        app_log('info', 'Manual balance completed', [
            'id' => $record['id'],
            'email' => $record['normalizedEmail'],
            'userId' => $record['userId'],
            'amount' => $record['amount'],
        ]);
        return [
            'record' => $record,
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
            ],
            'amount' => $amount,
        ];
    } catch (Throwable $error) {
        app_db_update_claim_status(
            $pdo,
            $record['id'],
            'failed',
            null,
            gmdate('c'),
            $error instanceof AppError ? $error->getMessage() : '未知错误'
        );
        app_log('error', 'Manual balance failed', [
            'id' => $record['id'],
            'email' => $record['normalizedEmail'],
            'userId' => $record['userId'],
            'error' => $error->getMessage(),
        ]);
        if ($error instanceof AppError) {
            throw $error;
        }
        throw new AppError(500, 'internal_error', '添加余额时发生异常。');
    }
}

function app_process_manual_balance(string $email, float $amount, string $notes, string $remoteIp): array
{
    if (app_uses_database()) {
        return app_process_manual_balance_db($email, $amount, $notes, $remoteIp);
    }

    return app_with_lock('claims', function () use ($email, $amount, $notes, $remoteIp): array {
        $store = app_load_claims_store();

        $user = app_find_user_by_email_using_admin($email);
        if ($user === null) {
            throw new AppError(404, 'user_not_found', '无该账户。');
        }

        $userId = (string) ($user['id'] ?? '');
        if ($userId === '') {
            throw new AppError(502, 'unexpected_users_response', '用户信息缺少 ID。');
        }

        $existingByEmail = app_find_active_claim_by_type($store['claims'] ?? [], 'manual_balance', static fn ($claim) => ($claim['normalizedEmail'] ?? '') === $email);
        if (($existingByEmail['status'] ?? '') === 'completed') {
            throw new AppError(409, 'already_claimed', '该邮箱已经加过余额。');
        }
        if (($existingByEmail['status'] ?? '') === 'pending') {
            throw new AppError(409, 'claim_pending', '该邮箱的加余额请求正在处理中，请稍后再试。');
        }

        $existingByUserId = app_find_active_claim_by_type($store['claims'] ?? [], 'manual_balance', static fn ($claim) => (string) ($claim['userId'] ?? '') === $userId);
        if (($existingByUserId['status'] ?? '') === 'completed') {
            throw new AppError(409, 'already_claimed', '该账户已经加过余额。');
        }
        if (($existingByUserId['status'] ?? '') === 'pending') {
            throw new AppError(409, 'claim_pending', '该账户的加余额请求正在处理中，请稍后再试。');
        }

        $recordNotes = trim($notes) !== '' ? trim($notes) : '管理员手动加余额';
        $record = [
            'id' => bin2hex(random_bytes(16)),
            'type' => 'manual_balance',
            'userId' => $userId,
            'email' => (string) ($user['email'] ?? $email),
            'normalizedEmail' => $email,
            'amount' => $amount,
            'status' => 'pending',
            'remoteIp' => $remoteIp,
            'notes' => $recordNotes,
            'createdAt' => gmdate('c'),
            'awardedAt' => null,
            'failedAt' => null,
            'errorMessage' => null,
        ];

        $store['claims'][] = $record;
        app_save_claims_store($store);
        app_log('info', 'Manual balance record created', [
            'id' => $record['id'],
            'email' => $record['normalizedEmail'],
            'userId' => $record['userId'],
            'amount' => $record['amount'],
        ]);

        try {
            app_add_user_balance_using_admin($userId, $amount, $recordNotes . ' [manual:' . $record['id'] . ']');
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
            app_log('info', 'Manual balance completed', [
                'id' => $record['id'],
                'email' => $record['normalizedEmail'],
                'userId' => $record['userId'],
                'amount' => $record['amount'],
            ]);

            return [
                'record' => $record,
                'user' => [
                    'id' => $user['id'],
                    'email' => $user['email'],
                ],
                'amount' => $amount,
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
            app_log('error', 'Manual balance failed', [
                'id' => $record['id'],
                'email' => $record['normalizedEmail'],
                'userId' => $record['userId'],
                'error' => $error->getMessage(),
            ]);
            if ($error instanceof AppError) {
                throw $error;
            }
            throw new AppError(500, 'internal_error', '添加余额时发生异常。');
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
    if (app_uses_database()) {
        $search = app_normalize_email((string) ($options['search'] ?? ''));
        $status = (string) ($options['status'] ?? '');
        $sortBy = (string) ($options['sortBy'] ?? 'awardedAt');
        $sortOrder = (string) ($options['sortOrder'] ?? 'desc');
        $allowedSorts = [
            'email' => 'email',
            'createdAt' => 'created_at',
            'awardedAt' => 'awarded_at',
            'type' => 'type',
            'status' => 'status',
            'amount' => 'amount',
        ];
        $sortColumn = $allowedSorts[$sortBy] ?? 'awarded_at';
        $direction = $sortOrder === 'asc' ? 'ASC' : 'DESC';
        $where = [];
        $params = [];

        if ($search !== '') {
            $where[] = 'normalized_email LIKE :search';
            $params['search'] = '%' . $search . '%';
        }
        if ($status !== '') {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }

        $sql = 'SELECT * FROM claims';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= " ORDER BY {$sortColumn} {$direction}, created_at DESC";

        $stmt = app_database()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        return array_map(static fn (array $row) => app_db_claim_from_row($row), $rows);
    }

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

    $allowedSorts = ['email', 'createdAt', 'awardedAt', 'type', 'status', 'amount'];
    if (!in_array($sortBy, $allowedSorts, true)) {
        $sortBy = 'awardedAt';
    }
    $sortOrder = $sortOrder === 'asc' ? 'asc' : 'desc';

    usort($items, static function (array $left, array $right) use ($sortBy, $sortOrder): int {
        $a = $left[$sortBy] ?? null;
        $b = $right[$sortBy] ?? null;
        if ($sortBy === 'email' || $sortBy === 'type' || $sortBy === 'status') {
            $a = (string) $a;
            $b = (string) $b;
        }
        return app_compare_values($a, $b, $sortOrder);
    });

    return array_map(static fn ($claim) => [
        'id' => $claim['id'] ?? '',
        'type' => $claim['type'] ?? 'auto_claim',
        'email' => $claim['email'] ?? '',
        'userId' => $claim['userId'] ?? '',
        'amount' => $claim['amount'] ?? 0,
        'status' => $claim['status'] ?? '',
        'createdAt' => $claim['createdAt'] ?? null,
        'awardedAt' => $claim['awardedAt'] ?? null,
        'failedAt' => $claim['failedAt'] ?? null,
        'remoteIp' => $claim['remoteIp'] ?? '',
        'notes' => $claim['notes'] ?? '',
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
    if (app_uses_database()) {
        $stmt = app_database()->query('SELECT * FROM gallery_images ORDER BY sort_order ASC, created_at DESC');
        $rows = $stmt->fetchAll();
        return array_map(static fn (array $item, int $index) => [
            'id' => $item['id'] ?? '',
            'title' => $item['title'] ?? '',
            'alt' => $item['alt'] ?? '',
            'createdAt' => $item['created_at'] ?? null,
            'order' => $index,
            'url' => '/media/' . rawurlencode((string) ($item['id'] ?? '')),
        ], $rows, array_keys($rows));
    }

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

    if (app_uses_database()) {
        $pdo = app_database();
        $pdo->beginTransaction();
        try {
            $pdo->exec('UPDATE gallery_images SET sort_order = sort_order + 1');
            $stmt = $pdo->prepare(
                'INSERT INTO gallery_images (id, title, alt, content_type, original_name, sort_order, created_at)
                 VALUES (:id, :title, :alt, :content_type, :original_name, 0, :created_at)'
            );
            $stmt->execute([
                'id' => $record['id'],
                'title' => $record['title'],
                'alt' => $record['alt'],
                'content_type' => $record['contentType'],
                'original_name' => $record['originalName'],
                'created_at' => $record['createdAt'],
            ]);

            $limit = app_config()['gallery_upload_max_files'];
            $stmt = $pdo->prepare('SELECT id FROM gallery_images ORDER BY sort_order ASC, created_at DESC LIMIT 18446744073709551615 OFFSET :limit');
            $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $removedIds = array_map(static fn (array $row) => (string) $row['id'], $stmt->fetchAll());
            if ($removedIds !== []) {
                $placeholders = implode(',', array_fill(0, count($removedIds), '?'));
                $delete = $pdo->prepare("DELETE FROM gallery_images WHERE id IN ({$placeholders})");
                $delete->execute($removedIds);
            }
            $pdo->commit();
            foreach ($removedIds as $removedId) {
                $removedFile = APP_GALLERY_DIR . '/' . basename($removedId);
                if (is_file($removedFile)) {
                    @unlink($removedFile);
                }
            }
            app_log('info', 'Gallery image uploaded', [
                'id' => $record['id'],
                'title' => $record['title'],
                'removed' => count($removedIds),
            ]);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (is_file($target)) {
                @unlink($target);
            }
            app_log('error', 'Gallery image upload failed', [
                'id' => $record['id'],
                'title' => $record['title'],
                'error' => $error->getMessage(),
            ]);
            throw $error;
        }

        return [
            'id' => $record['id'],
            'title' => $record['title'],
            'alt' => $record['alt'],
            'createdAt' => $record['createdAt'],
            'url' => '/media/' . rawurlencode($record['id']),
        ];
    }

    app_with_lock('gallery', static function () use ($record): void {
        $store = app_load_gallery_store();
        $images = $store['images'] ?? [];
        array_unshift($images, $record);
        $images = array_slice($images, 0, app_config()['gallery_upload_max_files']);
        $store['images'] = $images;
        app_save_gallery_store($store);
    });
    app_log('info', 'Gallery image uploaded', [
        'id' => $record['id'],
        'title' => $record['title'],
    ]);

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
    if (app_uses_database()) {
        $pdo = app_database();
        $stmt = $pdo->prepare('SELECT * FROM gallery_images WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $item = $stmt->fetch();
        if (!is_array($item)) {
            throw new AppError(404, 'not_found', '图片不存在。');
        }
        $delete = $pdo->prepare('DELETE FROM gallery_images WHERE id = :id');
        $delete->execute(['id' => $id]);
        $file = APP_GALLERY_DIR . '/' . basename((string) $item['id']);
        if (is_file($file)) {
            @unlink($file);
        }
        app_log('info', 'Gallery image deleted', [
            'id' => $id,
        ]);
        return;
    }

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
    app_log('info', 'Gallery image deleted', [
        'id' => $id,
    ]);
}

function app_move_gallery_image(string $id, string $direction): void
{
    if (app_uses_database()) {
        $pdo = app_database();
        $items = $pdo->query('SELECT id FROM gallery_images ORDER BY sort_order ASC, created_at DESC')->fetchAll();
        $ids = array_map(static fn (array $row) => (string) $row['id'], $items);
        $index = array_search($id, $ids, true);
        if ($index === false) {
            throw new AppError(404, 'not_found', '图片不存在。');
        }
        $target = $direction === 'up' ? $index - 1 : $index + 1;
        if ($target < 0 || $target >= count($ids)) {
            return;
        }
        [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('UPDATE gallery_images SET sort_order = :sort_order WHERE id = :id');
            foreach ($ids as $sortOrder => $imageId) {
                $stmt->execute([
                    'sort_order' => $sortOrder,
                    'id' => $imageId,
                ]);
            }
            $pdo->commit();
            app_log('info', 'Gallery image moved', [
                'id' => $id,
                'direction' => $direction,
            ]);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
        return;
    }

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
    app_log('info', 'Gallery image moved', [
        'id' => $id,
        'direction' => $direction,
    ]);
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

        if ($method === 'GET' && ($path === '/admin.html' || $path === '/admin.php' || $path === '/admin-login.html' || $path === '/admin-login.php')) {
            app_page_response(app_admin_is_authenticated() ? 'admin.php' : 'admin-login.php');
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

        if ($method === 'GET' && $path === '/api/admin/session') {
            app_json_response(200, ['authenticated' => app_admin_is_authenticated()]);
        }

        if ($method === 'POST' && $path === '/api/admin/login') {
            $body = app_read_json_body();
            app_admin_authenticate((string) ($body['accessKey'] ?? ''));
            app_log('info', 'Admin session created', [
                'remoteIp' => $ip,
            ]);
            app_json_response(200, ['ok' => true]);
        }

        if ($method === 'POST' && $path === '/api/admin/logout') {
            app_admin_logout();
            app_json_response(200, ['ok' => true]);
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

        if ($method === 'GET' && $path === '/api/admin/logs') {
            app_require_records_access();
            $limit = (int) ($_GET['limit'] ?? 200);
            $items = app_read_logs($limit);
            app_json_response(200, ['items' => $items, 'total' => count($items)]);
        }

        if ($method === 'POST' && $path === '/api/admin/balance') {
            app_require_records_access();
            app_assert_config();
            $body = app_read_json_body();
            $email = app_normalize_email((string) ($body['email'] ?? ''));
            $amount = (float) ($body['amount'] ?? 0);
            $notes = trim((string) ($body['notes'] ?? ''));

            if (!app_is_valid_email($email)) {
                throw new AppError(400, 'invalid_email', '请输入有效邮箱。');
            }
            if (!is_numeric((string) ($body['amount'] ?? '')) || $amount <= 0) {
                throw new AppError(400, 'invalid_amount', '金额必须是大于 0 的数字。');
            }

            $result = app_process_manual_balance($email, $amount, $notes, $ip);
            app_json_response(200, [
                'ok' => true,
                'message' => '已为 ' . $result['user']['email'] . ' 添加 ' . $result['amount'] . ' 余额。',
                'record' => $result['record'],
                'user' => $result['user'],
                'amount' => $result['amount'],
            ]);
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
        app_log('warning', 'Request failed', [
            'method' => $method,
            'path' => $path,
            'status' => $error->status,
            'errorCode' => $error->errorCode,
            'message' => $error->getMessage(),
        ]);
        app_json_response($error->status, [
            'error' => $error->errorCode,
            'message' => $error->getMessage(),
        ]);
    } catch (Throwable $error) {
        app_log('error', 'Request crashed', [
            'method' => $method,
            'path' => $path,
            'error' => $error->getMessage(),
        ]);
        app_json_response(500, [
            'error' => 'internal_error',
            'message' => '服务异常，请稍后再试。',
        ]);
    }
}
