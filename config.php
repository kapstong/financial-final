<?php
/**
 * ATIERA Financial Management System Configuration
 * Loads environment variables and provides configuration management
 */

// Load production config if on production domain
if (isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] === 'financial.atierahotelandrestaurant.com') {
    require_once __DIR__ . '/config_production.php';
}

function setEnvValue($name, $value, $override = false) {
    $hasValue = array_key_exists($name, $_SERVER) || array_key_exists($name, $_ENV) || getenv($name) !== false;
    if ($override || !$hasValue) {
        putenv(sprintf('%s=%s', $name, $value));
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

function loadEnv($path, $override = false) {
    if (!file_exists($path)) {
        return false;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }

        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);

        if ($value !== '' && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
            $value = substr($value, 1, -1);
        }

        setEnvValue($name, $value, $override);
    }

    return true;
}

function isPlaceholderValue($value) {
    if (!is_string($value)) {
        return false;
    }

    $trimmed = trim($value);
    if ($trimmed === '') {
        return true;
    }

    $markers = [
        'change-in-production',
        'your-random-key',
        'your-api-key-here',
        'your-database',
        'your-username',
        'your-password'
    ];

    $lower = strtolower($trimmed);
    foreach ($markers as $marker) {
        if (strpos($lower, $marker) !== false) {
            return true;
        }
    }

    return false;
}

function resolveAppKey() {
    $envKey = getenv('APP_KEY');
    if (is_string($envKey) && !isPlaceholderValue($envKey)) {
        return trim($envKey);
    }

    $keyPath = __DIR__ . '/config/app.key';
    if (is_file($keyPath)) {
        $stored = trim((string) file_get_contents($keyPath));
        if (!isPlaceholderValue($stored)) {
            setEnvValue('APP_KEY', $stored, true);
            return $stored;
        }
    }

    $generated = 'base64:' . base64_encode(random_bytes(32));
    $dir = dirname($keyPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $writeResult = @file_put_contents($keyPath, $generated . PHP_EOL, LOCK_EX);
    if ($writeResult !== false && function_exists('chmod')) {
        @chmod($keyPath, 0600);
    } elseif ($writeResult === false) {
        error_log('Unable to persist generated APP_KEY to ' . $keyPath . '. Set APP_KEY in the environment for stable encryption.');
    }

    setEnvValue('APP_KEY', $generated, true);
    return $generated;
}

if ((getenv('APP_ENV') ?: '') === 'production') {
    loadEnv(__DIR__ . '/.env.production');
}
loadEnv(__DIR__ . '/.env');
resolveAppKey();

// Set session configuration BEFORE any session is started
if (session_status() === PHP_SESSION_NONE) {
    $sessionLifetime = getenv('SESSION_LIFETIME') ?: 7200;
    ini_set('session.gc_maxlifetime', $sessionLifetime);
    ini_set('session.cookie_lifetime', $sessionLifetime);

    // Set session cookie parameters for proper cookie handling
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 1 : 0);
    ini_set('session.cookie_samesite', 'Lax');
}

// Configuration class
if (!class_exists('Config')) {
class Config {
    private static $config = [];

    public static function get($key, $default = null) {
        if (empty(self::$config)) {
            self::loadConfig();
        }

        $keys = explode('.', $key);
        $value = self::$config;

        foreach ($keys as $k) {
            if (isset($value[$k])) {
                $value = $value[$k];
            } else {
                return $default;
            }
        }

        return $value;
    }

    public static function set($key, $value) {
        if (empty(self::$config)) {
            self::loadConfig();
        }

        $keys = explode('.', $key);
        $config = &self::$config;

        foreach ($keys as $k) {
            if (!isset($config[$k]) || !is_array($config[$k])) {
                $config[$k] = [];
            }
            $config = &$config[$k];
        }

        $config = $value;
    }

    private static function loadConfig() {
        self::$config = [
            'app' => [
                'env' => getenv('APP_ENV') ?: 'development',
                'name' => getenv('APP_NAME') ?: 'ATIERA Finance',
                'url' => getenv('APP_URL') ?: 'http://localhost',
                'key' => resolveAppKey(),
                'debug' => getenv('APP_ENV') === 'development',
            ],

            'database' => [
                'host' => getenv('DB_HOST') ?: 'localhost',
                'name' => getenv('DB_NAME') ?: 'fina_financialmngmnt',
                'user' => getenv('DB_USER') ?: 'root',
                'pass' => getenv('DB_PASS') ?: '',
                'charset' => 'utf8mb4',
            ],

            'mail' => [
                'mailer' => getenv('MAIL_MAILER') ?: 'smtp',
                'host' => getenv('MAIL_HOST') ?: 'smtp.gmail.com',
                'port' => getenv('MAIL_PORT') ?: 587,
                'username' => getenv('MAIL_USERNAME') ?: '',
                'password' => getenv('MAIL_PASSWORD') ?: '',
                'encryption' => getenv('MAIL_ENCRYPTION') ?: 'tls',
                'from_address' => getenv('MAIL_FROM_ADDRESS') ?: 'noreply@atiera.com',
                'from_name' => getenv('MAIL_FROM_NAME') ?: 'ATIERA Finance',
            ],

            'upload' => [
                'path' => getenv('UPLOAD_PATH') ?: 'uploads/',
                'max_size' => getenv('MAX_FILE_SIZE') ?: 10485760, // 10MB
                'allowed_extensions' => explode(',', getenv('ALLOWED_EXTENSIONS') ?: 'pdf,doc,docx,jpg,jpeg,png'),
            ],

            'security' => [
                'session_lifetime' => getenv('SESSION_LIFETIME') ?: 7200, // 2 hours
                'csrf_lifetime' => getenv('CSRF_TOKEN_LIFETIME') ?: 3600, // 1 hour
                'login_attempts_max' => getenv('LOGIN_ATTEMPTS_MAX') ?: 5,
                'lockout_duration' => getenv('LOCKOUT_DURATION') ?: 300, // 5 minutes
                'twofa_attempts_max' => getenv('TWOFA_ATTEMPTS_MAX') ?: 5,
                'twofa_lockout_hours' => getenv('TWOFA_LOCKOUT_HOURS') ?: 1,
                'allow_sso_get_tokens' => filter_var(getenv('ALLOW_SSO_GET_TOKENS') ?: '0', FILTER_VALIDATE_BOOLEAN),
            ],

            'api' => [
                'rate_limit' => getenv('API_RATE_LIMIT') ?: 100,
                'key' => getenv('API_KEY') ?: '',
                'allow_query_key' => filter_var(getenv('API_ALLOW_QUERY_KEY') ?: '0', FILTER_VALIDATE_BOOLEAN),
            ],

            'logging' => [
                'level' => getenv('LOG_LEVEL') ?: 'error',
                'file' => getenv('LOG_FILE') ?: 'logs/app.log',
            ],

            'backup' => [
                'path' => getenv('BACKUP_PATH') ?: 'backups/',
                'retention_days' => getenv('BACKUP_RETENTION_DAYS') ?: 30,
            ],

            'currency' => [
                'default' => getenv('DEFAULT_CURRENCY') ?: 'PHP',
                'symbol' => getenv('CURRENCY_SYMBOL') ?: '₱',
            ],

            'company' => [
                'name' => getenv('COMPANY_NAME') ?: 'ATIERA Hotel & Restaurant',
                'address' => getenv('COMPANY_ADDRESS') ?: '',
                'phone' => getenv('COMPANY_PHONE') ?: '',
                'email' => getenv('COMPANY_EMAIL') ?: 'info@atiera.com',
            ],
        ];
    }

    public static function isProduction() {
        return self::get('app.env') === 'production';
    }

    public static function isDevelopment() {
        return self::get('app.env') === 'development';
    }
}
}

// Include required files
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/auth.php';

// Initialize configuration
Config::get('app.name'); // Trigger config loading

// Optional: central customer service integration
// Configure via environment variables in .env or system env:
// CUSTOMER_SERVICE_URL, CUSTOMER_SERVICE_API_KEY
if (getenv('CUSTOMER_SERVICE_URL')) {
    define('CUSTOMER_SERVICE_URL', getenv('CUSTOMER_SERVICE_URL'));
} else {
    define('CUSTOMER_SERVICE_URL', '');
}

    if (getenv('CUSTOMER_SERVICE_API_KEY')) {
        define('CUSTOMER_SERVICE_API_KEY', getenv('CUSTOMER_SERVICE_API_KEY'));
    } else {
        define('CUSTOMER_SERVICE_API_KEY', '');
    }

// reCAPTCHA configuration
if (getenv('RECAPTCHA_SITE_KEY')) {
    define('RECAPTCHA_SITE_KEY', getenv('RECAPTCHA_SITE_KEY'));
} else {
    define('RECAPTCHA_SITE_KEY', '');
}

if (getenv('RECAPTCHA_SECRET_KEY')) {
    define('RECAPTCHA_SECRET_KEY', getenv('RECAPTCHA_SECRET_KEY'));
} else {
    define('RECAPTCHA_SECRET_KEY', '');
}

// Set PHP configuration based on environment
if (Config::isDevelopment()) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(E_ERROR | E_PARSE);
}



// Create necessary directories
$dirs = [
    Config::get('upload.path'),
    Config::get('logging.file') ? dirname(Config::get('logging.file')) : 'logs',
    Config::get('backup.path'),
];

foreach ($dirs as $dir) {
    if ($dir && !is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}
?>

