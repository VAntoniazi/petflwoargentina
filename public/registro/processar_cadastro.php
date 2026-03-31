<?php
/**
 * processar_cadastro_ar.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Cadastro completo + assinatura PetFlow.PRO via Mercado Pago Argentina
 *
 * API  : Mercado Pago Preapproval (Subscriptions) v1
 * Docs : https://www.mercadopago.com.ar/developers/es/docs/subscriptions
 *
 * Fluxo:
 *   1. Sanitiza e valida POST (formulário argentino: DNI, provincia, CUIT …)
 *   2. Busca plano/preco no banco  (valor NUNCA vem do POST — seguranca)
 *   3. Verifica duplicidades antes de qualquer operacao
 *   4. Cria customer       → POST /v1/customers
 *   5. Tokeniza cartao     → POST /v1/card_tokens  (se nao veio token do SDK JS)
 *   6. Associa cartao      → POST /v1/customers/{id}/cards
 *   7. Cria assinatura     → POST /preapproval  (trial 7 dias gratis)
 *   8. Persiste banco em transacao atomica (rollback automatico em falha)
 *   9. Redireciona com mensagem de sucesso
 *
 * Tabelas utilizadas:
 *   usuarios                        (cpf armazena DNI — retrocompat.)
 *   usuarios_enderecos              (cep_usuario/uf_usuario ampliados)
 *   usuarios_estabelecimentos       (cnpj armazena CUIT; cep/uf ampliados)
 *   petflow_assinaturas
 *   petflow_assinaturas_status
 *   petflow_planos_estabelecimentos
 *   estabelecimentos_pagamentos     (opcional — verificado com SHOW TABLES)
 *   pagamentos_transacoes           (opcional — verificado com SHOW TABLES)
 *
 * Variavel de ambiente obrigatoria (setar no servidor/.env):
 *   MP_ACCESS_TOKEN  →  token de producao da conta Mercado Pago AR
 *
 * Alteracoes de banco necessarias (rodar uma unica vez):
 *   ALTER TABLE usuarios
 *       MODIFY COLUMN cpf varchar(15) NOT NULL,
 *       MODIFY COLUMN sexo_biologico varchar(15) NOT NULL;
 *   ALTER TABLE usuarios_estabelecimentos
 *       MODIFY COLUMN cep varchar(10) NULL,
 *       MODIFY COLUMN uf  varchar(5)  NULL;
 *   ALTER TABLE usuarios_enderecos
 *       MODIFY COLUMN cep_usuario varchar(10) NOT NULL,
 *       MODIFY COLUMN uf_usuario  varchar(5)  NOT NULL;
 *
 * Seguranca:
 *   - Valor do plano SEMPRE vem do banco, jamais do POST
 *   - Cartao nunca trafega em plain-text em producao (SDK JS tokeniza)
 *   - Lock anti-double-submit via sessao (janela de 20 s)
 *   - Transacao PDO com rollback automatico em qualquer falha
 *   - X-Idempotency-Key em todas as chamadas ao MP
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

session_start();
require 'config.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit('Configuracao de banco invalida.');
}

$pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

/* ═══════════════════════════════════════════════════════════════════════════
 * HELPERS
 * ═══════════════════════════════════════════════════════════════════════════ */

/** Sanitiza string generica com limite de caracteres. */
function ar_str($v, int $max = 120): string
{
    return mb_substr(trim((string)$v), 0, $max);
}

/** Valida e retorna e-mail ou string vazia. */
function ar_email($e): string
{
    $e = filter_var(trim((string)$e), FILTER_SANITIZE_EMAIL);
    return filter_var($e, FILTER_VALIDATE_EMAIL) ? (string)$e : '';
}

/** Remove tudo que nao seja digito. */
function ar_digits($n): string
{
    return preg_replace('/\D+/', '', (string)$n);
}

/** Valida formato YYYY-MM-DD e data real. Retorna string ou null. */
function ar_date($d): ?string
{
    $d = trim((string)$d);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return null;
    [$y, $m, $day] = explode('-', $d);
    return checkdate((int)$m, (int)$day, (int)$y) ? $d : null;
}

/** Verifica se a data corresponde a pessoa com 18+ anos. */
function ar_is_adult(?string $date): bool
{
    if (!$date) return false;
    try {
        $dt   = new DateTimeImmutable($date);
        $now  = new DateTimeImmutable('today');
        $diff = $now->diff($dt);
        return $diff->invert === 1 && $diff->y >= 18;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Separa "Av. Corrientes 1234" em ['Av. Corrientes', '1234'].
 * Suporta separacao por virgula: "Corrientes, 1234".
 */
function ar_split_calle(string $t): array
{
    $t = trim($t);
    if ($t === '') return ['', 'S/N'];
    if (strpos($t, ',') !== false) {
        [$c, $n] = array_map('trim', explode(',', $t, 2));
        return [$c !== '' ? $c : $t, $n !== '' ? $n : 'S/N'];
    }
    if (preg_match('/^(.*?)\s+(\d[\w\-\/]*)$/u', $t, $m)) {
        $c = trim($m[1]);
        $n = trim($m[2]);
        if ($c !== '') return [$c, $n !== '' ? $n : 'S/N'];
    }
    return [$t, 'S/N'];
}

/** Converte MM/YY ou MMYY em ['month'=>int, 'year'=>int(4)] ou null. */
function ar_parse_exp($v): ?array
{
    $d = preg_replace('/\D/', '', (string)$v);
    if (strlen($d) !== 4) return null;
    $mm = (int)substr($d, 0, 2);
    $yy = (int)substr($d, 2, 2);
    if ($mm < 1 || $mm > 12) return null;
    return ['month' => $mm, 'year' => 2000 + $yy];
}

/** Normaliza periodicidade para 'mensal' ou 'anual'. */
function ar_per(?string $p): string
{
    static $map = [
        'mensal'  => 'mensal', 'mensual' => 'mensal', 'monthly' => 'mensal',
        'month'   => 'mensal', 'mes'     => 'mensal',
        'anual'   => 'anual',  'annual'  => 'anual',  'yearly'  => 'anual',
        'year'    => 'anual',  'ano'     => 'anual',
    ];
    return $map[strtolower(trim((string)$p))] ?? 'mensal';
}

/**
 * Frequencia para o MP Preapproval.
 * Anual = 12 meses (MP nao suporta interval_type=year diretamente).
 */
function ar_mp_frequency(string $per): array
{
    return $per === 'anual'
        ? ['frequency' => 12, 'frequency_type' => 'months']
        : ['frequency' => 1,  'frequency_type' => 'months'];
}

/** Aplica desconto percentual sobre centavos. */
function ar_desconto(int $cents, float $pct): int
{
    if ($pct <= 0) return $cents;
    return max(0, (int)round($cents * (1 - $pct / 100)));
}

/** Formata centavos para exibicao: "15,00". */
function ar_fmt(int $cents): string
{
    return number_format($cents / 100, 2, ',', '.');
}

/**
 * Valida CUIT argentino via digito verificador.
 * Retorna true se valido OU se CUIT for vazio (campo opcional).
 */
function ar_valida_cuit(?string $cuit): bool
{
    if ($cuit === null || $cuit === '') return true;
    if (strlen($cuit) !== 11 || !ctype_digit($cuit)) return false;
    $mult = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
    $sum  = 0;
    for ($i = 0; $i < 10; $i++) $sum += (int)$cuit[$i] * $mult[$i];
    $resto = $sum % 11;
    $dv    = ($resto === 0) ? 0 : (($resto === 1) ? 9 : 11 - $resto);
    return $dv === (int)$cuit[10];
}

/** Extrai mensagem de erro amigavel da resposta do Mercado Pago. */
function ar_mp_msg($data, string $fallback = 'Error en la API de Mercado Pago'): string
{
    if (!is_array($data)) return $fallback;
    $msg = '';
    if (!empty($data['message']) && is_string($data['message'])) {
        $msg = trim($data['message']);
    } elseif (!empty($data['error']) && is_string($data['error'])) {
        $msg = trim($data['error']);
    }
    if (!empty($data['cause']) && is_array($data['cause'])) {
        $causes = [];
        foreach ($data['cause'] as $c) {
            if (!empty($c['description'])) $causes[] = trim((string)$c['description']);
            elseif (!empty($c['code']))    $causes[] = 'code ' . $c['code'];
        }
        if ($causes) $msg .= ' (' . implode('; ', array_unique($causes)) . ')';
    }
    return $msg !== '' ? $msg : $fallback;
}

/** Redireciona com sessao de erro. */
function ar_falha(string $email, string $msg): never
{
    $_SESSION['cadastro_ok']    = 0;
    $_SESSION['cadastro_msg']   = $msg;
    $_SESSION['cadastro_email'] = $email;
    header('Location: index.php#feedback');
    exit;
}

/** Redireciona com sessao de sucesso + autoredirect para o app. */
function ar_sucesso(string $email, string $msg, string $appUrl): never
{
    $_SESSION['cadastro_ok']      = 1;
    $_SESSION['cadastro_msg']     = $msg;
    $_SESSION['cadastro_email']   = $email;
    $_SESSION['app_autoredirect'] = 1;
    $_SESSION['app_url']          = $appUrl;
    header('Location: index.php#feedback');
    exit;
}

/** Escreve log estruturado no error_log do servidor. */
function ar_log(string $msg, array $ctx = []): void
{
    $line = '[PetFlow-AR] ' . $msg;
    if ($ctx) $line .= ' | ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    error_log($line);
}

/** Verifica existencia de registro via SELECT 1. */
function ar_existe(PDO $pdo, string $sql, array $params): bool
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (bool)$st->fetchColumn();
}

/**
 * Lock anti-double-submit por fingerprint dos dados-chave.
 * Janela: 20 segundos.
 */
function ar_lock(array $chave, string $email): void
{
    $fp  = hash('sha256', json_encode($chave, JSON_UNESCAPED_UNICODE));
    $now = time();
    if (!isset($_SESSION['ar_lock'])) $_SESSION['ar_lock'] = [];
    $ultimo = $_SESSION['ar_lock'][$fp] ?? null;
    if ($ultimo && ($now - (int)$ultimo) < 20) {
        ar_falha($email,
            'Tu registro ya esta siendo procesado. '
            . 'Aguarda unos segundos antes de intentar de nuevo.');
    }
    $_SESSION['ar_lock'][$fp] = $now;
}

function ar_unlock(array $chave): void
{
    $fp = hash('sha256', json_encode($chave, JSON_UNESCAPED_UNICODE));
    unset($_SESSION['ar_lock'][$fp]);
}

/**
 * Verifica duplicidades nas tabelas antes de qualquer INSERT.
 * Retorna mensagem de erro ou null se tudo OK.
 */
function ar_check_dup(PDO $pdo, string $dni, string $email, ?string $cuit, string $email_negocio): ?string
{
    if ($dni !== '' && ar_existe($pdo, 'SELECT 1 FROM usuarios WHERE cpf = ? LIMIT 1', [$dni]))
        return 'Ya existe una cuenta con este DNI.';
    if ($email !== '' && ar_existe($pdo, 'SELECT 1 FROM usuarios WHERE email = ? LIMIT 1', [$email]))
        return 'Ya existe una cuenta con este e-mail.';
    if ($cuit !== null && $cuit !== ''
        && ar_existe($pdo, 'SELECT 1 FROM usuarios_estabelecimentos WHERE cnpj = ? LIMIT 1', [$cuit]))
        return 'Ya existe un negocio registrado con este CUIT.';
    if ($email_negocio !== ''
        && ar_existe($pdo, 'SELECT 1 FROM usuarios_estabelecimentos WHERE email = ? LIMIT 1', [$email_negocio]))
        return 'Ya existe un negocio registrado con este e-mail.';
    return null;
}

/* ═══════════════════════════════════════════════════════════════════════════
 * LEITURA E SANITIZACAO DO POST
 * ═══════════════════════════════════════════════════════════════════════════ */
$P = $_POST;

/* -- Responsavel -- */
$nombre_completo   = ar_str($P['nombre_completo']    ?? '', 120);
$fecha_nacimiento  = ar_date($P['fecha_nacimiento']  ?? '');
$sexo_biologico    = ar_str($P['sexo_biologico']     ?? '', 15);
$dni               = ar_digits($P['dni']             ?? '');   // 7-8 digitos
$telefono          = ar_digits($P['telefono_celular']?? '');   // 8-12 digitos
$email_login       = ar_email($P['email']            ?? '');
$senha_raw         = (string)($P['senha']            ?? '');
$senha_hash        = $senha_raw !== '' ? password_hash($senha_raw, PASSWORD_DEFAULT) : '';

/* -- Domicilio do responsavel -- */
$cp_usuario        = ar_str($P['codigo_postal_usuario'] ?? '', 10);
$provincia_usuario = ar_str($P['provincia_usuario']     ?? '', 10);  // ex: BA, CABA, CB
$localidad_usuario = ar_str($P['localidad_usuario']     ?? '', 100);
$barrio_usuario    = ar_str($P['barrio_usuario']        ?? '', 100);
$calle_usuario     = ar_str($P['calle_usuario']         ?? '', 255);
$numero_usuario    = ar_str($P['numero_usuario']        ?? '', 10);
$piso_dpto         = ar_str($P['piso_dpto_usuario']     ?? '', 100);

/* -- Negocio -- */
$cuit              = !empty($P['cuit']) ? ar_digits($P['cuit']) : null; // 11 digitos ou null
$razon_social      = ar_str($P['razon_social']       ?? '', 255);
$nombre_fantasia   = ar_str($P['nombre_fantasia']    ?? '', 255);
$email_negocio     = ar_email($P['email_negocio']    ?? '');

/* -- Plano -- */
$id_plano          = (int)($P['id_plano']              ?? 0);
$id_plano_preco    = (int)($P['id_plano_preco']        ?? 0);
$periodicidade     = ar_per($P['periodicidade_escolhida'] ?? 'mensal');
$mp_ref_post       = trim((string)($P['pagarme_price_id'] ?? '')); // ID externo MP opcional

/* -- Cartao -- */
$card_holder       = ar_str($P['card_holder_name']   ?? '', 120);
$dni_titular       = ar_digits($P['dni_titular']     ?? '');   // DNI do titular
$card_number       = ar_digits($P['card_number']     ?? '');
$card_exp_raw      = trim((string)($P['card_exp']    ?? ''));
$card_cvv          = ar_digits($P['card_cvv']        ?? '');
$mp_card_token     = trim((string)($P['pagarme_card_hash'] ?? '')); // token SDK JS do MP

/* -- Domicilio de cobranca -- */
$billing_cp        = ar_str($P['billing_codigo_postal'] ?? '', 10);
$billing_provincia = ar_str($P['billing_provincia']     ?? '', 10);
$billing_localidad = ar_str($P['billing_localidad']     ?? '', 100);
$billing_calle_raw = ar_str($P['billing_calle']         ?? '', 160);

/* -- Enderecos separados em rua/numero -- */
[$billing_street, $billing_number] = ar_split_calle($billing_calle_raw);
[$user_street,    $user_number]    = ar_split_calle(
    $calle_usuario . ($numero_usuario !== '' ? ', ' . $numero_usuario : '')
);

/* ═══════════════════════════════════════════════════════════════════════════
 * LOCK ANTI-DOUBLE-SUBMIT
 * ═══════════════════════════════════════════════════════════════════════════ */
$chave_lock = [
    'dni'            => $dni,
    'email'          => $email_login,
    'cuit'           => $cuit,
    'id_plano'       => $id_plano,
    'id_plano_preco' => $id_plano_preco,
];
ar_lock($chave_lock, $email_login);

/* ═══════════════════════════════════════════════════════════════════════════
 * VALIDACOES NO SERVIDOR
 * ═══════════════════════════════════════════════════════════════════════════ */

if (!$nombre_completo) {
    ar_unlock($chave_lock);
    ar_falha($email_login, 'El nombre completo es obligatorio.');
}
if (!$email_login) {
    ar_unlock($chave_lock);
    ar_falha('', 'El e-mail es obligatorio y debe ser valido.');
}
if ($senha_raw === '') {
    ar_unlock($chave_lock);
    ar_falha($email_login, 'La contrasena es obligatoria.');
}
if (strlen($senha_raw) < 8) {
    ar_unlock($chave_lock);
    ar_falha($email_login, 'La contrasena debe tener al menos 8 caracteres.');
}
if (strlen($dni) < 7 || strlen($dni) > 8) {
    ar_unlock($chave_lock);
    ar_falha($email_login, 'DNI invalido. Debe tener 7 u 8 digitos.');
}
if (!$fecha_nacimiento) {
    ar_unlock($chave_lock);
    ar_falha($email_login, 'Fecha de nacimiento invalida.');
}
if (!ar_is_adult($fecha_nacimiento)) {
    ar_unlock($chave_lock);
    ar_falha($email_login, 'Debes ser mayor de 18 anos para registrarte.');
}
if (!in_array(strtolower($sexo_biologico), ['masculino', 'femenino', 'otro', 'outro'], true)) {
    ar_unlock($chave_lock);
    ar_falha($email_login, 'Sexo biologico invalido.');
}
if (strlen($telefono) < 8 || strlen($telefono) > 12) {
    ar_unlock($chave_lock);
    ar_falha($email_login, 'Telefono invalido. Debe tener entre 8 y 12 digitos.');
}
if (!ar_valida_cuit($cuit)) {
    ar_unlock($chave_lock);
    ar_falha($email_login, 'CUIT invalido. Verifica los 11 digitos y el digito verificador.');
}
if ($id_plano <= 0 && $id_plano_preco <= 0 && $mp_ref_post === '') {
    ar_unlock($chave_lock);
    ar_falha($email_login, 'Selecciona un plan valido antes de continuar.');
}
if (!$billing_calle_raw || !$billing_localidad || !$billing_provincia || !$billing_cp) {
    ar_unlock($chave_lock);
    ar_falha($email_login, 'El domicilio de facturacion esta incompleto.');
}

/* -- Validacoes especificas do cartao -- */
if ($mp_card_token !== '') {
    // Caminho A: token gerado pelo SDK JS do MP no frontend (producao)
    if (!$card_holder) {
        ar_unlock($chave_lock);
        ar_falha($email_login, 'Ingresa el nombre del titular de la tarjeta.');
    }
} else {
    // Caminho B: dados raw (apenas sandbox — NUNCA em producao sem HTTPS)
    $exp_parsed = ar_parse_exp($card_exp_raw);
    if (strlen($card_number) < 13 || strlen($card_number) > 19) {
        ar_unlock($chave_lock);
        ar_falha($email_login, 'Numero de tarjeta invalido.');
    }
    if (!$exp_parsed) {
        ar_unlock($chave_lock);
        ar_falha($email_login, 'Vencimiento de tarjeta invalido. Use el formato MM/AA.');
    }
    if (strlen($card_cvv) < 3 || strlen($card_cvv) > 4) {
        ar_unlock($chave_lock);
        ar_falha($email_login, 'Codigo de seguridad (CVV) invalido.');
    }
    if (!$card_holder) {
        ar_unlock($chave_lock);
        ar_falha($email_login, 'Ingresa el nombre del titular de la tarjeta.');
    }
    if (strlen($dni_titular) < 7 || strlen($dni_titular) > 8) {
        ar_unlock($chave_lock);
        ar_falha($email_login, 'DNI del titular de la tarjeta invalido.');
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
 * BUSCA DO PLANO/PRECO NO BANCO
 * Valor NUNCA vem do POST — seguranca obrigatoria.
 * ═══════════════════════════════════════════════════════════════════════════ */
try {
    $planoRec = null;

    ar_log('Buscando plano', [
        'id_plano'       => $id_plano,
        'id_plano_preco' => $id_plano_preco,
        'periodicidade'  => $periodicidade,
        'mp_ref'         => $mp_ref_post,
    ]);

    // Tentativa 1: por id_plano_preco (mais especifico)
    if ($id_plano_preco > 0) {
        $st = $pdo->prepare("
            SELECT p.id AS id_plano, p.slug, p.nome, p.descricao_curta,
                   p.is_ativo  AS plano_ativo,
                   pp.id       AS id_plano_preco, pp.periodicidade,
                   pp.valor_centavos, pp.desconto_percent,
                   pp.pagarme_price_id, pp.is_ativo AS preco_ativo
            FROM petflow_plano_precos pp
            INNER JOIN petflow_planos p ON p.id = pp.id_plano
            WHERE pp.id = :id
            LIMIT 1
        ");
        $st->execute([':id' => $id_plano_preco]);
        $planoRec = $st->fetch() ?: null;
        if ($planoRec) $id_plano = (int)$planoRec['id_plano'];
    }

    // Tentativa 2: por id_plano + periodicidade
    if (!$planoRec && $id_plano > 0) {
        $pers = $periodicidade === 'anual'
            ? ['anual', 'annual', 'year']
            : ['mensal', 'monthly', 'month'];
        $st = $pdo->prepare("
            SELECT p.id AS id_plano, p.slug, p.nome, p.descricao_curta,
                   p.is_ativo  AS plano_ativo,
                   pp.id       AS id_plano_preco, pp.periodicidade,
                   pp.valor_centavos, pp.desconto_percent,
                   pp.pagarme_price_id, pp.is_ativo AS preco_ativo
            FROM petflow_plano_precos pp
            INNER JOIN petflow_planos p ON p.id = pp.id_plano
            WHERE p.id = :id
              AND LOWER(TRIM(pp.periodicidade)) IN (:p1, :p2, :p3)
            ORDER BY pp.is_ativo DESC, pp.id ASC
            LIMIT 1
        ");
        $st->execute([':id' => $id_plano, ':p1' => $pers[0], ':p2' => $pers[1], ':p3' => $pers[2]]);
        $planoRec = $st->fetch() ?: null;
    }

    // Tentativa 3: por pagarme_price_id (campo reutilizado como ID externo MP)
    if (!$planoRec && $mp_ref_post !== '') {
        $st = $pdo->prepare("
            SELECT p.id AS id_plano, p.slug, p.nome, p.descricao_curta,
                   p.is_ativo  AS plano_ativo,
                   pp.id       AS id_plano_preco, pp.periodicidade,
                   pp.valor_centavos, pp.desconto_percent,
                   pp.pagarme_price_id, pp.is_ativo AS preco_ativo
            FROM petflow_plano_precos pp
            INNER JOIN petflow_planos p ON p.id = pp.id_plano
            WHERE pp.pagarme_price_id = :pid
            ORDER BY pp.is_ativo DESC, pp.id ASC
            LIMIT 1
        ");
        $st->execute([':pid' => $mp_ref_post]);
        $planoRec = $st->fetch() ?: null;
        if ($planoRec) $id_plano = (int)$planoRec['id_plano'];
    }

    if (!$planoRec) {
        ar_unlock($chave_lock);
        ar_falha($email_login, 'No se pudo identificar el plan seleccionado. Intenta de nuevo.');
    }
    if ((int)$planoRec['plano_ativo'] !== 1) {
        ar_unlock($chave_lock);
        ar_falha($email_login, 'El plan seleccionado esta inactivo.');
    }
    if ((int)$planoRec['preco_ativo'] !== 1) {
        ar_unlock($chave_lock);
        ar_falha($email_login, 'El precio seleccionado esta inactivo.');
    }

    $id_plano           = (int)$planoRec['id_plano'];
    $id_plano_preco     = (int)$planoRec['id_plano_preco'];
    $periodicidade      = ar_per($planoRec['periodicidade'] ?? 'mensal');
    $valorCentavos      = (int)$planoRec['valor_centavos'];
    $descontoPercent    = (float)$planoRec['desconto_percent'];
    $valorFinalCentavos = ar_desconto($valorCentavos, $descontoPercent);
    $valorFinalFloat    = round($valorFinalCentavos / 100, 2);
    $mp_plan_ref        = trim((string)($planoRec['pagarme_price_id'] ?? ''));

    if ($valorCentavos <= 0) {
        ar_unlock($chave_lock);
        ar_falha($email_login, 'El plan seleccionado no tiene un precio valido configurado.');
    }

} catch (Throwable $e) {
    ar_unlock($chave_lock);
    ar_log('Erro ao buscar plano', ['err' => $e->getMessage()]);
    ar_falha($email_login, 'Error al consultar el plan. Intenta de nuevo mas tarde.');
}

/* ═══════════════════════════════════════════════════════════════════════════
 * PRE-VERIFICACAO DE DUPLICIDADE
 * ═══════════════════════════════════════════════════════════════════════════ */
try {
    $dupMsg = ar_check_dup($pdo, $dni, $email_login, $cuit, $email_negocio);
    if ($dupMsg !== null) {
        ar_unlock($chave_lock);
        ar_falha($email_login, $dupMsg);
    }
} catch (Throwable $e) {
    ar_log('Falha na verificacao de duplicidade', ['err' => $e->getMessage()]);
    // Nao bloqueia — o banco rejeitara na transacao se houver duplicata
}

/* ═══════════════════════════════════════════════════════════════════════════
 * CONFIGURACAO MERCADO PAGO
 * ═══════════════════════════════════════════════════════════════════════════ */
$MP_ACCESS_TOKEN = (string)getenv('MP_ACCESS_TOKEN');
$MP_BASE         = 'https://api.mercadopago.com';

if ($MP_ACCESS_TOKEN === '') {
    ar_unlock($chave_lock);
    ar_falha($email_login, 'Configuracion de pago ausente. Contacta al soporte.');
}

/**
 * Executa requisicao HTTP para o Mercado Pago.
 * Lanca RuntimeException em falha cURL ou resposta 4xx/5xx.
 */
$mp = function (string $method, string $path, ?array $payload = null)
    use ($MP_ACCESS_TOKEN, $MP_BASE): array
{
    $url     = rtrim($MP_BASE, '/') . $path;
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $MP_ACCESS_TOKEN,
        'X-Idempotency-Key: ' . bin2hex(random_bytes(16)),
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Error de red (cURL): ' . $err);
    }

    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode((string)$body, true);

    if ($code < 200 || $code >= 300) {
        $msg = ar_mp_msg(is_array($data) ? $data : [], "Error Mercado Pago HTTP {$code}");
        ar_log("MP erro {$code} em {$path}", ['resp' => substr((string)$body, 0, 500)]);
        throw new RuntimeException("MP {$code} -- {$msg}");
    }

    return is_array($data) ? $data : [];
};

/* ═══════════════════════════════════════════════════════════════════════════
 * TRANSACAO PRINCIPAL
 * ═══════════════════════════════════════════════════════════════════════════ */
try {
    $pdo->beginTransaction();

    /* ─────────────────────────────────────────────────────────────────────
     * 1. INSERT usuarios
     *    cpf → DNI  (campo ampliado para varchar(15))
     *    sexo_biologico → varchar(15) aceita pt/es
     * ───────────────────────────────────────────────────────────────────── */
    $pdo->prepare("
        INSERT INTO usuarios
            (nome_completo, data_nascimento, sexo_biologico,
             cpf, numero_telefone_ddd, email, senha)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $nombre_completo,
        $fecha_nacimiento,
        $sexo_biologico,
        $dni,
        $telefono,
        $email_login,
        $senha_hash,
    ]);
    $id_usuario = (int)$pdo->lastInsertId();

    /* ─────────────────────────────────────────────────────────────────────
     * 2. INSERT usuarios_enderecos
     *    cep_usuario → varchar(10)   uf_usuario → varchar(5)
     * ───────────────────────────────────────────────────────────────────── */
    $pdo->prepare("
        INSERT INTO usuarios_enderecos
            (id_usuario, cep_usuario, uf_usuario, municipio_usuario,
             bairro_usuario, logradouro_usuario, numero_usuario, complemento_usuario)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $id_usuario,
        $cp_usuario,
        $provincia_usuario,
        $localidad_usuario,
        $barrio_usuario,
        $calle_usuario,
        $numero_usuario,
        $piso_dpto,
    ]);

    /* ─────────────────────────────────────────────────────────────────────
     * 3. INSERT usuarios_estabelecimentos
     *    cnpj → CUIT (varchar(20), NULL se nao informado)
     *    cep  → varchar(10)   uf → varchar(5)
     * ───────────────────────────────────────────────────────────────────── */
    $pdo->prepare("
        INSERT INTO usuarios_estabelecimentos
            (id_usuario, cnpj, razao_social, nome_fantasia,
             endereco_email, cep, uf, municipio, bairro,
             logradouro, numero, complemento,
             porte, cnae_principal, situacao_cadastral,
             data_situacao_cadastral, telefone, email)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $id_usuario,
        $cuit,
        $razon_social,
        $nombre_fantasia,
        $email_negocio,
        $cp_usuario,
        $provincia_usuario,
        $localidad_usuario,
        $barrio_usuario,
        $calle_usuario,
        $numero_usuario,
        $piso_dpto,
        '',
        '',
        'ACTIVO',
        null,
        $telefono,
        $email_negocio !== '' ? $email_negocio : $email_login,
    ]);
    $id_estabelecimento = (int)$pdo->lastInsertId();

    /* ─────────────────────────────────────────────────────────────────────
     * 4. CUSTOMER NO MERCADO PAGO
     *    POST /v1/customers
     *    Docs: https://www.mercadopago.com.ar/developers/es/reference/customers/_customers/post
     * ───────────────────────────────────────────────────────────────────── */
    $nomes       = explode(' ', $nombre_completo, 2);
    $first_name  = $nomes[0] ?? $nombre_completo;
    $last_name   = $nomes[1] ?? '';
    $phone_area  = strlen($telefono) >= 3 ? substr($telefono, 0, 3) : $telefono;
    $phone_num   = strlen($telefono) >= 3 ? substr($telefono, 3)    : '';

    // Mapa de codigos de provincia para nome completo (exigido pelo MP em state_name)
    static $provinciaNomes = [
        'BA'   => 'Buenos Aires',
        'CABA' => 'Ciudad Autónoma de Buenos Aires',
        'CA'   => 'Catamarca',
        'CH'   => 'Chaco',
        'CT'   => 'Chubut',
        'CB'   => 'Córdoba',
        'CR'   => 'Corrientes',
        'ER'   => 'Entre Ríos',
        'FO'   => 'Formosa',
        'JY'   => 'Jujuy',
        'LP'   => 'La Pampa',
        'LR'   => 'La Rioja',
        'MZ'   => 'Mendoza',
        'MS'   => 'Misiones',
        'NQ'   => 'Neuquén',
        'RN'   => 'Río Negro',
        'SA'   => 'Salta',
        'SJ'   => 'San Juan',
        'SL'   => 'San Luis',
        'SC'   => 'Santa Cruz',
        'SF'   => 'Santa Fe',
        'SE'   => 'Santiago del Estero',
        'TF'   => 'Tierra del Fuego',
        'TU'   => 'Tucumán',
    ];
    $provincia_nome = $provinciaNomes[strtoupper($provincia_usuario)]
                   ?? $provincia_usuario; // fallback: usa o proprio codigo

    $customerPayload = [
        'email'          => $email_login,
        'first_name'     => $first_name,
        'last_name'      => $last_name,
        'identification' => ['type' => 'DNI', 'number' => $dni],
        'phone'          => ['area_code' => $phone_area, 'number' => $phone_num],
        'address'        => [
            'zip_code'      => $cp_usuario,
            'street_name'   => $user_street,
            'street_number' => is_numeric($user_number) ? (int)$user_number : 0,
            'city'          => $localidad_usuario,       // OBRIGATORIO no MP
            'state_name'    => $provincia_nome,          // OBRIGATORIO no MP
            'country_name'  => 'Argentina',
        ],
        'description' => 'Cliente PetFlow.PRO Argentina',
        'metadata'    => [
            'id_usuario'         => (string)$id_usuario,
            'id_estabelecimento' => (string)$id_estabelecimento,
            'origen'             => 'registro_petflow_ar',
        ],
    ];

    $customer    = $mp('POST', '/v1/customers', $customerPayload);
    $customer_id = $customer['id'] ?? null;

    if (!$customer_id) {
        throw new RuntimeException(
            'No se pudo crear el cliente en Mercado Pago. Respuesta inesperada.');
    }
    ar_log('Customer MP criado', ['customer_id' => $customer_id]);

    /* ─────────────────────────────────────────────────────────────────────
     * 5. TOKENIZACAO DO CARTAO
     *
     *  Caminho A (producao recomendada):
     *    Frontend usa SDK JS do MP → envia mp_card_token preenchido.
     *    Nao chamamos /v1/card_tokens aqui.
     *
     *  Caminho B (sandbox/dev):
     *    Dados raw chegam no POST → geramos token via /v1/card_tokens.
     *    ATENCAO: Nunca usar este caminho em producao sem HTTPS.
     *
     *  Docs: https://www.mercadopago.com.ar/developers/es/reference/card_tokens/_card_tokens/post
     * ───────────────────────────────────────────────────────────────────── */
    $card_token_final = $mp_card_token; // Caminho A

    if ($card_token_final === '') {
        // Caminho B
        $exp_parsed = ar_parse_exp($card_exp_raw); // ja validado acima
        $tokenPayload = [
            'card_number'      => $card_number,
            'security_code'    => $card_cvv,
            'expiration_month' => $exp_parsed['month'],
            'expiration_year'  => $exp_parsed['year'],
            'cardholder'       => [
                'name'           => $card_holder,
                'identification' => ['type' => 'DNI', 'number' => $dni_titular],
            ],
        ];

        $tokenResp        = $mp('POST', '/v1/card_tokens', $tokenPayload);
        $card_token_final = (string)($tokenResp['id'] ?? '');

        if ($card_token_final === '') {
            throw new RuntimeException(
                'No se pudo tokenizar la tarjeta. Verifica los datos e intenta de nuevo.');
        }
        ar_log('Card token gerado (Caminho B)');
    }

    /* ─────────────────────────────────────────────────────────────────────
     * 6. ASSOCIAR CARTAO AO CUSTOMER
     *    POST /v1/customers/{customer_id}/cards
     *    Docs: https://www.mercadopago.com.ar/developers/es/reference/cards/_customers_customer_id_cards/post
     * ───────────────────────────────────────────────────────────────────── */
    $cardResp = $mp('POST', '/v1/customers/' . urlencode($customer_id) . '/cards', [
        'token' => $card_token_final,
    ]);

    $card_id    = $cardResp['id']                     ?? null;
    $card_brand = $cardResp['payment_method']['name'] ?? ($cardResp['brand'] ?? null);
    $card_last4 = $cardResp['last_four_digits']       ?? null;

    if (!$card_id) {
        throw new RuntimeException(
            'No se pudo asociar la tarjeta al cliente en Mercado Pago.');
    }
    ar_log('Cartao associado ao customer', [
        'card_id' => $card_id, 'brand' => $card_brand, 'last4' => $card_last4]);

    /* ─────────────────────────────────────────────────────────────────────
     * 7. ASSINATURA (PREAPPROVAL) COM TRIAL DE 7 DIAS
     *
     *    POST /preapproval
     *
     *    Trial : free_trial = { frequency: 7, frequency_type: 'days' }
     *            usuario nao e cobrado nos primeiros 7 dias.
     *            Primeira cobranca ocorre em start_date.
     *
     *    Moeda : USD → MP converte para ARS na taxa do dia.
     *            Protege o valor real da inflacao argentina.
     *
     *    Docs  : https://www.mercadopago.com.ar/developers/es/reference/subscriptions/_preapproval/post
     * ───────────────────────────────────────────────────────────────────── */
    $trialDays      = 7;
    $now_utc        = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $trial_end_dt   = $now_utc->modify('+' . $trialDays . ' days');
    $start_date_iso = $trial_end_dt->format('Y-m-d\TH:i:s.000\Z');
    $trial_start_db = $now_utc->format('Y-m-d H:i:s');
    $trial_end_db   = $trial_end_dt->format('Y-m-d H:i:s');
    $next_billing   = $trial_end_db;

    $freqData = ar_mp_frequency($periodicidade);

    $subscriptionPayload = [
        'reason'             => $planoRec['nome'] . ' -- PetFlow.PRO',
        'external_reference' => implode('|', [
            'usr='  . $id_usuario,
            'est='  . $id_estabelecimento,
            'plan=' . $id_plano,
            'per='  . $periodicidade,
        ]),
        'payer_email'    => $email_login,
        'card_token_id'  => $card_token_final,
        'auto_recurring' => [
            'frequency'          => $freqData['frequency'],
            'frequency_type'     => $freqData['frequency_type'],
            'transaction_amount' => $valorFinalFloat,
            'currency_id'        => 'USD',
            'start_date'         => $start_date_iso,
            'end_date'           => null,
            'free_trial'         => [
                'frequency'      => $trialDays,
                'frequency_type' => 'days',
            ],
        ],
        'back_url' => 'https://app.petflow.pro/?registered=1',
        'status'   => 'authorized',
        'metadata' => [
            'id_usuario'         => (string)$id_usuario,
            'id_estabelecimento' => (string)$id_estabelecimento,
            'id_plano'           => (string)$id_plano,
            'id_plano_preco'     => (string)$id_plano_preco,
            'periodicidade'      => $periodicidade,
            'valor_centavos'     => (string)$valorCentavos,
            'desconto_percent'   => (string)$descontoPercent,
            'valor_final_cents'  => (string)$valorFinalCentavos,
            'trial_days'         => (string)$trialDays,
            'origen'             => 'registro_petflow_ar',
        ],
    ];

    // preapproval_plan_id so incluido se existir no banco
    if ($mp_plan_ref !== '') {
        $subscriptionPayload['preapproval_plan_id'] = $mp_plan_ref;
    }

    $subscription    = $mp('POST', '/preapproval', $subscriptionPayload);
    $subscription_id = $subscription['id'] ?? null;

    if (!$subscription_id) {
        throw new RuntimeException(
            'No se pudo crear la suscripcion en Mercado Pago. Respuesta inesperada.');
    }

    $subscription_status = (string)($subscription['status'] ?? 'authorized');

    ar_log('Assinatura MP criada', [
        'subscription_id' => $subscription_id,
        'status'          => $subscription_status,
        'id_usuario'      => $id_usuario,
    ]);

    $status_acesso = in_array(
        strtolower($subscription_status),
        ['authorized', 'active', 'pending'],
        true
    ) ? 'ativo' : 'pendente';

    $raw_payload = json_encode(
        $subscription,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    /* ─────────────────────────────────────────────────────────────────────
     * 8. petflow_assinaturas — UPSERT
     *    Campo pagarme_subscription_id reutilizado para ID MP.
     * ───────────────────────────────────────────────────────────────────── */
    $pdo->prepare("
        INSERT INTO petflow_assinaturas
            (id_usuario, pagarme_subscription_id,
             status_acesso, status_gateway, created_at, updated_at)
        VALUES (?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            id_usuario     = VALUES(id_usuario),
            status_acesso  = VALUES(status_acesso),
            status_gateway = VALUES(status_gateway),
            updated_at     = NOW()
    ")->execute([
        $id_usuario,
        $subscription_id,
        $status_acesso,
        $subscription_status,
    ]);

    /* ─────────────────────────────────────────────────────────────────────
     * 9. petflow_assinaturas_status — UPSERT
     *    Campos invoice/charge ficam NULL agora; webhook MP preenche depois.
     * ───────────────────────────────────────────────────────────────────── */
    $pdo->prepare("
        INSERT INTO petflow_assinaturas_status
        (
            pagarme_subscription_id, subscription_status, payment_method,
            pagarme_customer_id,
            latest_invoice_id,       latest_invoice_status,
            latest_invoice_due_at,   latest_invoice_paid_at,
            latest_charge_id,        latest_charge_status,
            latest_charge_paid_at,   latest_charge_updated_at,
            cycle_start_at,          cycle_end_at,
            start_at,                next_billing_at,
            last_paid_at,            last_due_at,
            raw_payload,             created_at, updated_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            subscription_status      = VALUES(subscription_status),
            payment_method           = VALUES(payment_method),
            pagarme_customer_id      = VALUES(pagarme_customer_id),
            latest_invoice_id        = VALUES(latest_invoice_id),
            latest_invoice_status    = VALUES(latest_invoice_status),
            latest_invoice_due_at    = VALUES(latest_invoice_due_at),
            latest_invoice_paid_at   = VALUES(latest_invoice_paid_at),
            latest_charge_id         = VALUES(latest_charge_id),
            latest_charge_status     = VALUES(latest_charge_status),
            latest_charge_paid_at    = VALUES(latest_charge_paid_at),
            latest_charge_updated_at = VALUES(latest_charge_updated_at),
            cycle_start_at           = VALUES(cycle_start_at),
            cycle_end_at             = VALUES(cycle_end_at),
            start_at                 = VALUES(start_at),
            next_billing_at          = VALUES(next_billing_at),
            last_paid_at             = VALUES(last_paid_at),
            last_due_at              = VALUES(last_due_at),
            raw_payload              = VALUES(raw_payload),
            updated_at               = NOW()
    ")->execute([
        $subscription_id,
        $subscription_status,
        'credit_card',
        $customer_id,
        null, null, null, null,   // invoice — vira via webhook
        null, null, null, null,   // charge  — vira via webhook
        $trial_start_db,          // cycle_start_at
        $trial_end_db,            // cycle_end_at
        $trial_end_db,            // start_at (quando começa cobrar)
        $next_billing,            // next_billing_at
        null,                     // last_paid_at
        $trial_end_db,            // last_due_at (1a cobranca)
        $raw_payload,
    ]);

    /* ─────────────────────────────────────────────────────────────────────
     * 10. petflow_planos_estabelecimentos — UPSERT
     *     IDs do MP armazenados nos campos originalmente criados para Pagar.me.
     * ───────────────────────────────────────────────────────────────────── */
    $pdo->prepare("
        INSERT INTO petflow_planos_estabelecimentos
        (
            id_usuario,           id_estabelecimento, id_plano,          id_plano_preco,
            pagarme_customer_id,  pagarme_card_id,    pagarme_subscription_id,
            periodicidade,        valor_centavos,     desconto_percent,  valor_final_centavos,
            status_acesso,        status_gateway,
            em_trial,             trial_dias,         trial_inicio_em,   trial_fim_em,
            inicio_vigencia_em,   proxima_cobranca_em, cancelado_em,
            created_at,           updated_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            id_usuario           = VALUES(id_usuario),
            id_estabelecimento   = VALUES(id_estabelecimento),
            id_plano             = VALUES(id_plano),
            id_plano_preco       = VALUES(id_plano_preco),
            pagarme_customer_id  = VALUES(pagarme_customer_id),
            pagarme_card_id      = VALUES(pagarme_card_id),
            periodicidade        = VALUES(periodicidade),
            valor_centavos       = VALUES(valor_centavos),
            desconto_percent     = VALUES(desconto_percent),
            valor_final_centavos = VALUES(valor_final_centavos),
            status_acesso        = VALUES(status_acesso),
            status_gateway       = VALUES(status_gateway),
            em_trial             = VALUES(em_trial),
            trial_dias           = VALUES(trial_dias),
            trial_inicio_em      = VALUES(trial_inicio_em),
            trial_fim_em         = VALUES(trial_fim_em),
            inicio_vigencia_em   = VALUES(inicio_vigencia_em),
            proxima_cobranca_em  = VALUES(proxima_cobranca_em),
            cancelado_em         = VALUES(cancelado_em),
            updated_at           = NOW()
    ")->execute([
        $id_usuario,          $id_estabelecimento, $id_plano,          $id_plano_preco,
        $customer_id,         $card_id,            $subscription_id,
        $periodicidade,       $valorCentavos,      $descontoPercent,   $valorFinalCentavos,
        'ativo',              $subscription_status,
        1,                    $trialDays,          $trial_start_db,    $trial_end_db,
        $trial_end_db,        $next_billing,       null,
    ]);

    /* ─────────────────────────────────────────────────────────────────────
     * 11. TABELAS LEGADAS OPCIONAIS
     *     Verificadas com SHOW TABLES para nao falhar em instalacoes
     *     que nao possuem essas tabelas.
     * ───────────────────────────────────────────────────────────────────── */
    if ($pdo->query("SHOW TABLES LIKE 'estabelecimentos_pagamentos'")->fetchColumn()) {
        $pdo->prepare("
            INSERT INTO estabelecimentos_pagamentos
                (id_estabelecimento, pagarme_customer_id,
                 pagarme_card_id, pagarme_subscription_id, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ")->execute([$id_estabelecimento, $customer_id, $card_id, $subscription_id]);
    }

    if ($pdo->query("SHOW TABLES LIKE 'pagamentos_transacoes'")->fetchColumn()) {
        $pdo->prepare("
            INSERT INTO pagamentos_transacoes
                (id_estabelecimento,      pagarme_subscription_id,
                 pagarme_invoice_id,      pagarme_charge_id,
                 amount_cents,            currency,       status,
                 next_billing_at,         paid_at,
                 card_brand,              card_last4,     authorization_code,
                 raw,                     created_at,     updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ")->execute([
            $id_estabelecimento, $subscription_id,
            null,                null,
            $valorFinalCentavos, 'USD',
            $subscription_status !== '' ? $subscription_status : 'authorized',
            $next_billing,       null,
            $card_brand,         $card_last4,  null,
            $raw_payload,
        ]);
    }

    /* ─────────────────────────────────────────────────────────────────────
     * 12. COMMIT + SESSAO + SUCESSO
     * ───────────────────────────────────────────────────────────────────── */
    $pdo->commit();

    $_SESSION['id_usuario']         = $id_usuario;
    $_SESSION['id_estabelecimento'] = $id_estabelecimento;

    ar_unlock($chave_lock);

    ar_log('Registro concluido com sucesso', [
        'id_usuario'      => $id_usuario,
        'subscription_id' => $subscription_id,
        'plan'            => $planoRec['nome'],
        'per'             => $periodicidade,
    ]);

    $appUrl       = 'https://app.petflow.pro/?email=' . urlencode($email_login);
    $label_period = $periodicidade === 'anual' ? 'Anual' : 'Mensual';

    ar_sucesso(
        $email_login,
        '!Cuenta creada con exito! Tu suscripcion ' . $label_period
        . ' -- Plan ' . $planoRec['nome']
        . ' (USD ' . ar_fmt($valorFinalCentavos) . ')'
        . ' incluye ' . $trialDays . ' dias de prueba gratis.'
        . ' !Bienvenido/a a PetFlow.PRO!',
        $appUrl
    );

/* ═══════════════════════════════════════════════════════════════════════════
 * TRATAMENTO DE ERROS
 * ═══════════════════════════════════════════════════════════════════════════ */
} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    ar_unlock($chave_lock);

    $rawMsg = $e->getMessage();

    ar_log('Erro fatal no registro', [
        'class' => get_class($e),
        'code'  => $e->getCode(),
        'msg'   => $rawMsg,
        'dni'   => $dni,
        'email' => $email_login,
        'cuit'  => $cuit,
    ]);

    /* PDO: duplicidade (codigo 23000) */
    if ($e instanceof PDOException && (string)$e->getCode() === '23000') {

        // Chave estrangeira — estrutura incorreta
        if (stripos($rawMsg, '1452') !== false
            || stripos($rawMsg, 'foreign key constraint fails') !== false) {
            ar_falha($email_login,
                'Error de integridad entre tablas. '
                . 'Verifica la estructura de petflow_planos_estabelecimentos.');
        }

        // Diagnosticar qual campo duplicou
        $mensagem = null;
        try {
            $mensagem = ar_check_dup($pdo, $dni, $email_login, $cuit, $email_negocio);
        } catch (Throwable $inner) {
            ar_log('Falha no diagnostico pos-erro', ['err' => $inner->getMessage()]);
        }

        if (!$mensagem
            && preg_match('/Duplicate entry .* for key \'([^\']+)\'/i', $rawMsg, $m)) {
            $idx = strtolower($m[1]);
            if (str_contains($idx, 'cpf'))          $mensagem = 'Ya existe una cuenta con este DNI.';
            elseif (str_contains($idx, 'email'))    $mensagem = 'Ya existe una cuenta con este e-mail.';
            elseif (str_contains($idx, 'cnpj'))     $mensagem = 'Ya existe un negocio con este CUIT.';
            elseif (str_contains($idx, 'subscription')) $mensagem = 'La suscripcion ya fue registrada.';
            else $mensagem = 'Error de duplicidad en: ' . $m[1];
        }

        ar_falha($email_login, $mensagem ?? 'Error de integridad en la base de datos.');
    }

    /* Erros do Mercado Pago: extrai mensagem limpa */
    if (preg_match('/^MP\s+\d+\s+--\s+(.+)$/i', $rawMsg, $m)) {
        ar_falha($email_login, $m[1]);
    }

    /* Erros de rede */
    if (stripos($rawMsg, 'Error de red') !== false
        || stripos($rawMsg, 'cURL') !== false) {
        ar_falha($email_login,
            'No se pudo conectar con el procesador de pagos. '
            . 'Verifica tu conexion e intenta de nuevo.');
    }

    // Fallback
    ar_falha($email_login, $rawMsg !== ''
        ? $rawMsg
        : 'Ocurrio un error inesperado. Intenta de nuevo mas tarde.');
}