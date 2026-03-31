<?php
declare(strict_types=1);
/**
 * config.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Expoe:
 *   $pdo   → PDO MySQL  (principal)
 *   $pdoPg → PDO Postgres (BI / externo)
 *
 * Variaveis de ambiente lidas via env_str() / env_int().
 * Fallbacks hardcoded abaixo — substituir por ENV no Dokploy em producao.
 * ─────────────────────────────────────────────────────────────────────────────
 */

// ═══════════════════════════════════════════════════════════════════════════
// CONFIGURACOES GLOBAIS
// ═══════════════════════════════════════════════════════════════════════════
date_default_timezone_set(getenv('APP_TZ') ?: 'America/Sao_Paulo');
ini_set('display_errors', getenv('APP_DEBUG') === '1' ? '1' : '0');
ini_set('log_errors',     '1');
ini_set('error_log',      __DIR__ . '/php-error.log');

// ═══════════════════════════════════════════════════════════════════════════
// HELPERS DE LEITURA DE ENV
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Le variavel de ambiente como string.
 * - Remove aspas simples/duplas externas (ex: 'valor' ou "valor")
 * - Desfaz escape de # (ex: \# → #)
 * - Retorna $default se nao encontrada ou vazia
 */
function env_str(string $key, ?string $default = null): ?string
{
    $v = getenv($key);
    if ($v === false || $v === null) return $default;
    $v = trim((string)$v);

    // Remove aspas externas se presentes
    if (strlen($v) >= 2) {
        $first = $v[0];
        $last  = $v[strlen($v) - 1];
        if (($first === "'" && $last === "'") || ($first === '"' && $last === '"')) {
            $v = substr($v, 1, -1);
        }
    }

    // Desfaz escape comum de .env (ex: \# → #)
    $v = str_replace('\#', '#', $v);

    return $v === '' ? $default : $v;
}

/**
 * Le variavel de ambiente como inteiro.
 * Retorna $default se nao encontrada ou nao numerica.
 */
function env_int(string $key, int $default): int
{
    $v = env_str($key);
    if ($v === null || !is_numeric($v)) return $default;
    return (int)$v;
}

// ═══════════════════════════════════════════════════════════════════════════
// 1) MySQL — banco principal
// ═══════════════════════════════════════════════════════════════════════════
$mysqlHost    = env_str('MYSQL_HOST',     '31.97.240.207');
$mysqlPort    = env_int('MYSQL_PORT',     3306);
$mysqlDb      = env_str('MYSQL_DATABASE', 'prd-mysql-database');
$mysqlUser    = env_str('MYSQL_USER',     'operator');
$mysqlPass    = env_str('MYSQL_PASSWORD', '#bLB0R160w&');
$mysqlCharset = env_str('MYSQL_CHARSET',  'utf8mb4');

$pdoOptions = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

$dsnMy = "mysql:host={$mysqlHost};port={$mysqlPort};dbname={$mysqlDb};charset={$mysqlCharset}";

try {
    $pdo = new PDO($dsnMy, (string)$mysqlUser, (string)$mysqlPass, $pdoOptions);
} catch (PDOException $e) {
    error_log('[config] Erro PDO MySQL: ' . $e->getMessage());
    exit('Erro na conexao com o banco MySQL.');
}

// ═══════════════════════════════════════════════════════════════════════════
// 2) Postgres — BI / externo
// ═══════════════════════════════════════════════════════════════════════════
$pgHost    = env_str('PG_HOST',    '31.97.240.207');
$pgPort    = env_int('PG_PORT',    5432);
$pgDb      = env_str('PG_DB',      'prd-postgres-database');
$pgUser    = env_str('PG_USER',    'root');
$pgPass    = env_str('PG_PASS',    'G3Zrem#78D^D');
$pgSslMode = env_str('PG_SSLMODE', null); // ex: require | prefer | disable

$dsnPg = "pgsql:host={$pgHost};port={$pgPort};dbname={$pgDb}";
if ($pgSslMode !== null) {
    $dsnPg .= ";sslmode={$pgSslMode}";
}

try {
    $pdoPg = new PDO($dsnPg, (string)$pgUser, (string)$pgPass, $pdoOptions);
    try {
        $pdoPg->exec("SET TIME ZONE 'America/Sao_Paulo'");
    } catch (Throwable $e) {
        error_log('[config] Aviso timezone Postgres: ' . $e->getMessage());
    }
} catch (PDOException $e) {
    error_log('[config] Erro PDO Postgres: ' . $e->getMessage());
    exit('Erro na conexao com o banco Postgres.');
}

// ═══════════════════════════════════════════════════════════════════════════
// 3) Mercado Pago Argentina
// ═══════════════════════════════════════════════════════════════════════════

// Access Token de PRODUCAO
// Obtido em: mercadopago.com.ar → Configuracoes → Credenciais → Producao
$mpAccessToken = env_str(
    'MP_ACCESS_TOKEN',
    'APP_USR-3849765017803800-020418-e01fdf21231eedb6b254727c5266c135-1610448324'
);

// Public Key de PRODUCAO (usada no SDK JS do frontend para tokenizar o cartao)
// Obtida no mesmo painel, aba "Producao" → Public Key
$mpPublicKey = env_str(
    'MP_PUBLIC_KEY',
    'APP_USR-COLE-SUA-PUBLIC-KEY-AQUI'
);

// Registra no ambiente para que processar_cadastro_ar.php leia via getenv()
putenv('MP_ACCESS_TOKEN=' . $mpAccessToken);
putenv('MP_PUBLIC_KEY='   . $mpPublicKey);

// Sandbox para testes — descomenta e troca o token de producao acima
// putenv('MP_ACCESS_TOKEN=TEST-SEU-TOKEN-SANDBOX-AQUI');
// putenv('MP_PUBLIC_KEY=TEST-SUA-PUBLIC-KEY-SANDBOX-AQUI');

// ═══════════════════════════════════════════════════════════════════════════
// Fim do config.php
// ═══════════════════════════════════════════════════════════════════════════