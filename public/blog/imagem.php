<?php
// blog/imagem.php
declare(strict_types=1);

// Limpa qualquer buffer antes de enviar imagem
while (ob_get_level() > 0) {
    ob_end_clean();
}

require_once '../cadastro/config.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    header('Content-Type: image/svg+xml; charset=UTF-8');
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="300" viewBox="0 0 400 300">
        <rect width="400" height="300" fill="#f8fafc"/>
        <text x="50%" y="50%" font-family="Arial" font-size="16" fill="#94a3b8" text-anchor="middle">Erro de conexão</text>
    </svg>';
    exit;
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/**
 * Escapa saída HTML
 */
function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Base URL do sistema
 */
function getBaseUrl(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'app.petflow.pro';

    $https = false;

    if (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) ||
        (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    ) {
        $https = true;
    }

    return ($https ? 'https' : 'http') . '://' . $host;
}

/**
 * Envia imagem padrão quando algo falha
 */
function enviarImagemPadrao(int $statusCode = 404): void
{
    http_response_code($statusCode);

    $caminhos = [
        __DIR__ . '/../assets/images/no-image.jpg',
        __DIR__ . '/../App/assets/images/logo.png',
        __DIR__ . '/assets/images/no-image.jpg',
        __DIR__ . '/../uploads/blog/default.jpg',
    ];

    foreach ($caminhos as $caminho) {
        if (is_file($caminho) && is_readable($caminho)) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($caminho) ?: 'image/jpeg';

            header('Content-Type: ' . $mime);
            header('Content-Length: ' . (string) filesize($caminho));
            header('Cache-Control: public, max-age=86400');
            header('Content-Disposition: inline');
            readfile($caminho);
            exit;
        }
    }

    header('Content-Type: image/svg+xml; charset=UTF-8');
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="300" viewBox="0 0 400 300">
        <rect width="400" height="300" fill="#f8fafc"/>
        <text x="50%" y="50%" font-family="Arial" font-size="16" fill="#94a3b8" text-anchor="middle">Imagem não disponível</text>
    </svg>';
    exit;
}

/**
 * Detecta MIME real do conteúdo binário
 */
function detectarMimeBinario(string $binario): string
{
    if ($binario === '') {
        return 'application/octet-stream';
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->buffer($binario);

    if (is_string($mime) && strpos($mime, 'image/') === 0) {
        return $mime;
    }

    $hex4 = strtolower(bin2hex(substr($binario, 0, 4)));
    $hex8 = strtolower(bin2hex(substr($binario, 0, 8))));
    
    if (str_starts_with($hex8, '89504e470d0a1a0a')) {
        return 'image/png';
    }

    if (str_starts_with($hex4, 'ffd8ffe0') || str_starts_with($hex4, 'ffd8ffe1') || str_starts_with($hex4, 'ffd8ffe2') || str_starts_with($hex4, 'ffd8ffe3') || str_starts_with($hex4, 'ffd8ffee')) {
        return 'image/jpeg';
    }

    if (substr($binario, 0, 6) === 'GIF87a' || substr($binario, 0, 6) === 'GIF89a') {
        return 'image/gif';
    }

    if (substr($binario, 0, 4) === 'RIFF' && substr($binario, 8, 4) === 'WEBP') {
        return 'image/webp';
    }

    if (stripos(substr($binario, 0, 256), '<svg') !== false || stripos(substr($binario, 0, 256), '<?xml') !== false) {
        return 'image/svg+xml';
    }

    return 'application/octet-stream';
}

/**
 * Verifica se a string é URL HTTP/HTTPS válida
 */
function isHttpUrl(string $value): bool
{
    $value = trim($value);

    if ($value === '') {
        return false;
    }

    if (!preg_match('~^https?://~i', $value)) {
        return false;
    }

    return filter_var($value, FILTER_VALIDATE_URL) !== false;
}

/**
 * Verifica se é data URI de imagem
 */
function isImageDataUri(string $value): bool
{
    return (bool) preg_match('~^data:image/[a-zA-Z0-9.+-]+;base64,~', trim($value));
}

/**
 * Verifica se parece caminho de arquivo de imagem
 */
function isImagePath(string $value): bool
{
    $value = trim($value);

    if ($value === '') {
        return false;
    }

    return (bool) preg_match('~\.(jpg|jpeg|png|gif|webp|svg|bmp|avif)(\?.*)?$~i', $value);
}

/**
 * Verifica se string parece binária
 */
function isBinaryString(string $value): bool
{
    if ($value === '') {
        return false;
    }

    if (strpos($value, "\0") !== false) {
        return true;
    }

    if (!mb_check_encoding($value, 'UTF-8')) {
        return true;
    }

    $sample = substr($value, 0, 512);
    $len = strlen($sample);

    if ($len === 0) {
        return false;
    }

    $nonPrintable = 0;

    for ($i = 0; $i < $len; $i++) {
        $ord = ord($sample[$i]);

        if ($ord === 9 || $ord === 10 || $ord === 13) {
            continue;
        }

        if ($ord < 32 || $ord > 126) {
            $nonPrintable++;
        }
    }

    return ($nonPrintable / $len) > 0.20;
}

/**
 * Verifica se o conteúdo binário é de imagem
 */
function isBinaryImageContent(string $binario): bool
{
    $mime = detectarMimeBinario($binario);
    return strpos($mime, 'image/') === 0;
}

/**
 * Verifica base64 puro com mais segurança
 */
function isBase64ImageString(string $value): bool
{
    $value = trim($value);

    if ($value === '' || strlen($value) < 32) {
        return false;
    }

    if (preg_match('/\s/', $value)) {
        return false;
    }

    if (!preg_match('~^[A-Za-z0-9/+]+={0,2}$~', $value)) {
        return false;
    }

    $decoded = base64_decode($value, true);

    if ($decoded === false || $decoded === '') {
        return false;
    }

    return isBinaryImageContent($decoded);
}

/**
 * Normaliza caminhos em URL absoluta
 */
function normalizePathToUrl(string $path): string
{
    $path = trim($path);

    if ($path === '') {
        return '';
    }

    if (isHttpUrl($path) || isImageDataUri($path)) {
        return $path;
    }

    $base = getBaseUrl();

    if (strpos($path, '/') === 0) {
        return $base . $path;
    }

    if (
        strpos($path, 'uploads/') === 0 ||
        strpos($path, 'blog/') === 0 ||
        strpos($path, 'assets/') === 0 ||
        strpos($path, 'storage/') === 0
    ) {
        return $base . '/' . ltrim($path, '/');
    }

    if (isImagePath($path)) {
        return $base . '/uploads/blog/' . ltrim($path, '/');
    }

    return '';
}

/**
 * Faz download da imagem remota
 */
function baixarImagemDaUrl(string $url): array|false
{
    if (!isHttpUrl($url)) {
        error_log("URL inválida em imagem.php: " . $url);
        return false;
    }

    $conteudo = false;
    $contentTypeHeader = '';

    if (function_exists('curl_init')) {
        $headers = [];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome Safari',
            CURLOPT_HTTPHEADER => [
                'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
                'Accept-Language: pt-BR,pt;q=0.9,en;q=0.8'
            ],
            CURLOPT_HEADERFUNCTION => function ($curl, $headerLine) use (&$headers) {
                $len = strlen($headerLine);
                $parts = explode(':', $headerLine, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $len;
            },
        ]);

        $conteudo = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($conteudo === false || $httpCode >= 400) {
            error_log("Falha cURL ao baixar imagem: {$url} | HTTP {$httpCode} | {$curlErr}");
            $conteudo = false;
        }

        if (isset($headers['content-type'])) {
            $contentTypeHeader = $headers['content-type'];
        }
    }

    if ($conteudo === false) {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 15,
                'follow_location' => 1,
                'max_redirects' => 5,
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome Safari',
                'header' => "Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8\r\nAccept-Language: pt-BR,pt;q=0.9,en;q=0.8\r\n"
            ]
        ]);

        $conteudo = @file_get_contents($url, false, $context);

        if ($conteudo === false) {
            error_log("Falha file_get_contents ao baixar imagem: " . $url);
            return false;
        }
    }

    if ($conteudo === '' || $conteudo === false) {
        error_log("Conteúdo vazio ao baixar imagem: " . $url);
        return false;
    }

    $mime = detectarMimeBinario($conteudo);

    if (strpos($mime, 'image/') !== 0 && $contentTypeHeader !== '') {
        $candidate = strtolower(trim(explode(';', $contentTypeHeader)[0]));
        if (strpos($candidate, 'image/') === 0) {
            $mime = $candidate;
        }
    }

    if (strpos($mime, 'image/') !== 0) {
        error_log("Conteúdo remoto não parece imagem: {$url} | MIME: {$mime}");
        return false;
    }

    return [
        'conteudo' => $conteudo,
        'mime'     => $mime,
    ];
}

/**
 * Envia binário de imagem ao navegador
 */
function outputImageBinary(string $binario, string $mime = ''): void
{
    if ($binario === '') {
        enviarImagemPadrao();
    }

    if ($mime === '' || strpos($mime, 'image/') !== 0) {
        $mime = detectarMimeBinario($binario);
    }

    if (strpos($mime, 'image/') !== 0) {
        enviarImagemPadrao();
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) strlen($binario));
    header('Cache-Control: public, max-age=86400');
    header('Content-Disposition: inline');
    echo $binario;
    exit;
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id || $id <= 0) {
    error_log('ID inválido em blog/imagem.php: ' . ($_GET['id'] ?? 'vazio'));
    enviarImagemPadrao(404);
}

try {
    $stmt = $pdo->prepare("
        SELECT id, imagem_capa
        FROM blog_posts
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        error_log("Post não encontrado em imagem.php: ID {$id}");
        enviarImagemPadrao(404);
    }

    $dados = $row['imagem_capa'] ?? null;

    if ($dados === null || $dados === '') {
        error_log("Imagem vazia para post ID {$id}");
        enviarImagemPadrao(404);
    }

    error_log('imagem.php | ID=' . $id . ' | tipo=' . gettype($dados));

    // Caso 1: resource
    if (is_resource($dados)) {
        $binario = stream_get_contents($dados);

        if ($binario === false || $binario === '') {
            error_log("Falha ao ler BLOB resource para ID {$id}");
            enviarImagemPadrao();
        }

        outputImageBinary($binario);
    }

    // Caso 2: string
    if (is_string($dados)) {
        // NÃO usar trim em binário cru
        $raw = $dados;
        $texto = trim($dados);

        // 2.1 Data URI
        if (isImageDataUri($texto)) {
            $parts = explode(',', $texto, 2);
            if (count($parts) === 2) {
                $binario = base64_decode($parts[1], true);
                if ($binario !== false && $binario !== '') {
                    outputImageBinary($binario);
                }
            }
        }

        // 2.2 URL externa
        if (isHttpUrl($texto)) {
            $imagem = baixarImagemDaUrl($texto);

            if ($imagem !== false) {
                outputImageBinary($imagem['conteudo'], $imagem['mime']);
            }

            // último fallback
            header('Location: ' . $texto, true, 302);
            exit;
        }

        // 2.3 Caminho local/relativo
        $pathUrl = normalizePathToUrl($texto);
        if ($pathUrl !== '') {
            header('Location: ' . $pathUrl, true, 302);
            exit;
        }

        // 2.4 Base64 puro
        if (isBase64ImageString($texto)) {
            $binario = base64_decode($texto, true);
            if ($binario !== false && $binario !== '') {
                outputImageBinary($binario);
            }
        }

        // 2.5 String binária vinda do LONG BLOB
        if (isBinaryString($raw) || isBinaryImageContent($raw)) {
            outputImageBinary($raw);
        }

        error_log("Formato de imagem não reconhecido para ID {$id}");
        enviarImagemPadrao();
    }

    error_log("Tipo de dado não suportado para ID {$id}");
    enviarImagemPadrao();

} catch (Throwable $e) {
    error_log('Exceção em blog/imagem.php: ' . $e->getMessage());
    error_log($e->getTraceAsString());
    enviarImagemPadrao(500);
}