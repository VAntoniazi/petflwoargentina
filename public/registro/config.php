<?php
declare(strict_types=1);

/**
 * config.php — completo e resiliente (MySQL + Postgres + Pagar.me)
 * ---------------------------------------------------------------
 * Expõe:
 *   - $pdo   (PDO MySQL)
 *   - $pdoPg (PDO Postgres)
 *
 * Objetivo: evitar erro por ENV ausente, aspas na senha, "#" cortando valor, etc.
 * Recomendado: setar tudo no Environment do Dokploy (melhor que env_file).
 */

// ======================================
// Configurações globais
// ======================================
date_default_timezone_set(getenv('APP_TZ') ?: 'America/Sao_Paulo');

ini_set('display_errors', getenv('APP_DEBUG') === '1' ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');

function env_str(string $key, ?string $default = null): ?string {
    $v = getenv($key);
    if ($v === false || $v === null) return $default;

    $v = trim((string)$v);

    // remove aspas se vierem junto (ex: 'senha' ou "senha")
    if (strlen($v) >= 2) {
        $first = $v[0];
        $last  = $v[strlen($v) - 1];
        if (($first === "'" && $last === "'") || ($first === '"' && $last === '"')) {
            $v = substr($v, 1, -1);
        }
    }

    // desfaz escape comum de .env (ex: \#)
    $v = str_replace('\#', '#', $v);

    return $v === '' ? $default : $v;
}

function env_int(string $key, int $default): int {
    $v = env_str($key, null);
    if ($v === null) return $default;
    if (!is_numeric($v)) return $default;
    return (int)$v;
}

// ======================================
// 1) MySQL (principal)
// ======================================
$mysqlHost    = env_str('MYSQL_HOST', '31.97.240.207');
$mysqlPort    = env_int('MYSQL_PORT', 3306);
$mysqlDb      = env_str('MYSQL_DATABASE', 'prd-mysql-database');
$mysqlUser    = env_str('MYSQL_USER', 'operator');
$mysqlPass    = env_str('MYSQL_PASSWORD', '#bLB0R160w&');
$mysqlCharset = env_str('MYSQL_CHARSET', 'utf8mb4');

$pdoOptions = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// MySQL DSN
$dsnMy = "mysql:host={$mysqlHost};port={$mysqlPort};dbname={$mysqlDb};charset={$mysqlCharset}";

try {
    $pdo = new PDO($dsnMy, (string)$mysqlUser, (string)$mysqlPass, $pdoOptions);
} catch (PDOException $e) {
    error_log("Erro de conexão PDO MySQL: " . $e->getMessage());
    exit('Erro na conexão com o banco MySQL.');
}

// ======================================
// 2) Postgres (externo / BI)
// ======================================
$pgHost = env_str('PG_HOST', '31.97.240.207');
$pgPort = env_int('PG_PORT', 5432);
$pgDb   = env_str('PG_DB', 'prd-postgres-database');
$pgUser = env_str('PG_USER', 'root');

// Fallback apenas para não “morrer” em dev.
// Em produção: DEFINA PG_PASS no Dokploy.
$pgPass = env_str('PG_PASS', 'G3Zrem#78D^D');

// SSL opcional (se o servidor exigir)
$pgSslMode = env_str('PG_SSLMODE', null); // ex: require / prefer / disable

$dsnPg = "pgsql:host={$pgHost};port={$pgPort};dbname={$pgDb}";
if ($pgSslMode) {
    // aceita "require|prefer|disable|verify-full|verify-ca"
    $dsnPg .= ";sslmode={$pgSslMode}";
}

try {
    $pdoPg = new PDO($dsnPg, (string)$pgUser, (string)$pgPass, $pdoOptions);

    // timezone coerente
    try {
        $pdoPg->exec("SET TIME ZONE 'America/Sao_Paulo'");
    } catch (Throwable $e) {
        error_log("Aviso: não foi possível setar timezone no Postgres: " . $e->getMessage());
    }
} catch (PDOException $e) {
    error_log("Erro de conexão PDO Postgres: " . $e->getMessage());
    exit('Erro na conexão com o banco Postgres.');
}

// ======================================
// 3) Pagar.me
// ======================================
$pagarmeKey  = env_str('PAGARME_API_KEY', null);
$pagarmeBase = env_str('PAGARME_BASE_URL', 'https://api.pagar.me/core/v5');

if (!$pagarmeKey) {
    // não “mata” o app todo (às vezes config é carregado por telas que não usam pagarme)
    error_log("Aviso: PAGARME_API_KEY não definida em ENV.");
} else {
    putenv("PAGARME_API_KEY={$pagarmeKey}");
}
putenv("PAGARME_BASE_URL={$pagarmeBase}");

// ======================================
// Fim
// ======================================
