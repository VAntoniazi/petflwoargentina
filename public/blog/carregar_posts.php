<?php
// blog/carregar_posts.php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once '../cadastro/config.php';

header('Content-Type: text/html; charset=utf-8');

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo "<!-- Conexão PDO não disponível -->";
    exit;
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pagina = isset($_GET['pagina']) ? max((int)$_GET['pagina'], 1) : 1;
$limite = 12;
$offset = ($pagina - 1) * $limite;

$categoria = trim((string)($_GET['categoria'] ?? ''));
$busca     = trim((string)($_GET['busca'] ?? ''));

function h(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

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

function isValidHttpUrl(?string $url): bool
{
    $url = trim((string)$url);
    if ($url === '') return false;

    if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
    $parts = parse_url($url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return false;

    $scheme = strtolower((string)$parts['scheme']);
    return in_array($scheme, ['http', 'https'], true);
}

function isBinaryImage($data): bool
{
    if (empty($data) || !is_string($data)) return false;

    $trimmed = trim($data);
    if ($trimmed !== '' && (
        strpos($trimmed, 'http') === 0 ||
        strpos($trimmed, '/') === 0 ||
        strpos($trimmed, 'uploads/') === 0
    )) {
        return false;
    }

    if (strlen($data) > 10) {
        $firstBytes = substr($data, 0, 20);

        if (strpos($firstBytes, "\x89PNG") === 0) return true;
        if (strpos($firstBytes, "\xff\xd8\xff") === 0) return true;
        if (strpos($firstBytes, "GIF87a") === 0 || strpos($firstBytes, "GIF89a") === 0) return true;
        if (strpos($firstBytes, "RIFF") === 0 && strpos($data, "WEBP", 8) !== false) return true;

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->buffer($data);
        if (is_string($mime) && strpos($mime, 'image/') === 0) {
            return true;
        }
    }

    return false;
}

function detectImageMime(string $data): string
{
    if (strpos($data, "\x89PNG") === 0) return 'image/png';
    if (strpos($data, "\xff\xd8\xff") === 0) return 'image/jpeg';
    if (strpos($data, "GIF87a") === 0 || strpos($data, "GIF89a") === 0) return 'image/gif';
    if (strpos($data, "RIFF") === 0 && strpos($data, "WEBP", 8) !== false) return 'image/webp';

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->buffer($data);

    if (is_string($mime) && strpos($mime, 'image/') === 0) {
        return $mime;
    }

    return 'image/jpeg';
}

function processImageData($imageData): array
{
    $result = [
        'type'    => 'none',
        'content' => null,
        'mime'    => 'image/jpeg',
    ];

    if (empty($imageData)) {
        return $result;
    }

    if (is_resource($imageData)) {
        $bin = stream_get_contents($imageData);
        if ($bin !== false && $bin !== '' && isBinaryImage($bin)) {
            $result['type']    = 'blob';
            $result['content'] = $bin;
            $result['mime']    = detectImageMime($bin);
            return $result;
        }
    }

    if (!is_string($imageData)) {
        return $result;
    }

    $raw     = $imageData;
    $trimmed = trim($imageData);

    if ($trimmed === '') {
        return $result;
    }

    // URL absoluta
    if (isValidHttpUrl($trimmed)) {
        $result['type']    = 'url';
        $result['content'] = $trimmed;
        return $result;
    }

    // Caminho absoluto/relativo
    if (
        strpos($trimmed, '/') === 0 ||
        strpos($trimmed, 'uploads/') === 0 ||
        strpos($trimmed, 'blog/') === 0 ||
        strpos($trimmed, 'assets/') === 0
    ) {
        $cleanPath         = ltrim($trimmed, '/');
        $result['type']    = 'path';
        $result['content'] = getBaseUrl() . '/' . $cleanPath;
        return $result;
    }

    // Nome de arquivo simples com extensão de imagem
    if (strlen($trimmed) < 255 && preg_match('/\.(jpg|jpeg|png|gif|webp|svg|bmp|avif)$/i', $trimmed)) {
        $result['type']    = 'path';
        $result['content'] = getBaseUrl() . '/uploads/blog/' . ltrim($trimmed, '/');
        return $result;
    }

    // Data URI
    if (preg_match('~^data:image/([a-zA-Z0-9.+-]+);base64,~', $trimmed)) {
        $result['type']    = 'datauri';
        $result['content'] = $trimmed;
        return $result;
    }

    // Base64 puro
    if (strlen($trimmed) > 100) {
        $decoded = base64_decode($trimmed, true);
        if ($decoded !== false && isBinaryImage($decoded)) {
            $result['type']    = 'blob';
            $result['content'] = $decoded;
            $result['mime']    = detectImageMime($decoded);
            return $result;
        }
    }

    // Blob como string binária
    if (isBinaryImage($raw)) {
        $result['type']    = 'blob';
        $result['content'] = $raw;
        $result['mime']    = detectImageMime($raw);
        return $result;
    }

    return $result;
}

function generateImageTag($imageData, string $alt = 'Imagem do post', string $class = ''): string
{
    $processed = processImageData($imageData);

    if ($processed['type'] === 'none') {
        return '';
    }

    if ($processed['type'] === 'url' || $processed['type'] === 'path') {
        return sprintf(
            '<img src="%s" alt="%s" class="%s" loading="lazy" decoding="async" referrerpolicy="no-referrer" onerror="this.style.display=\'none\'; this.parentElement.innerHTML=\'<div class=&quot;w-full h-48 flex items-center justify-center text-gray-400&quot;><i class=&quot;fas fa-image text-3xl&quot;></i></div>\';">',
            h((string)$processed['content']),
            h($alt),
            h($class)
        );
    }

    if ($processed['type'] === 'datauri') {
        return sprintf(
            '<img src="%s" alt="%s" class="%s" loading="lazy" decoding="async">',
            h((string)$processed['content']),
            h($alt),
            h($class)
        );
    }

    if ($processed['type'] === 'blob') {
        $base64  = base64_encode((string)$processed['content']);
        $dataUri = 'data:' . $processed['mime'] . ';base64,' . $base64;
        return sprintf(
            '<img src="%s" alt="%s" class="%s" loading="lazy" decoding="async">',
            h($dataUri),
            h($alt),
            h($class)
        );
    }

    return '';
}

// ── Query principal ───────────────────────────────────────────────────────────

try {
    $sql = "
        SELECT
            p.id,
            p.titulo,
            p.slug,
            p.resumo,
            p.conteudo,
            p.imagem_capa,
            p.data_publicacao,
            c.nome AS categoria,
            c.slug AS categoria_slug
        FROM blog_posts p
        LEFT JOIN blog_categorias c ON p.id_categoria = c.id
        WHERE 1=1
    ";

    $params = [];

    if ($categoria !== '') {
        $sql .= " AND c.slug = :categoria";
        $params[':categoria'] = $categoria;
    }

    if ($busca !== '') {
        $sql .= " AND (p.titulo LIKE :busca1 OR p.resumo LIKE :busca2 OR p.conteudo LIKE :busca3)";
        $like              = '%' . $busca . '%';
        $params[':busca1'] = $like;
        $params[':busca2'] = $like;
        $params[':busca3'] = $like;
    }

    // CORREÇÃO: ordenação por dois campos garante que o OFFSET seja sempre
    // determinístico. Sem p.id como desempate, posts com a mesma
    // data_publicacao podiam aparecer em ordens diferentes entre páginas,
    // causando duplicatas ou posts pulados (bug do botão sumir cedo).
    $sql .= " ORDER BY p.data_publicacao DESC, p.id DESC LIMIT :limite OFFSET :offset";

    $stmt = $pdo->prepare($sql);

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }

    $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

    $stmt->execute();
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($posts)) {
        http_response_code(204);
        exit;
    }
} catch (Throwable $e) {
    error_log('Erro em carregar_posts.php: ' . $e->getMessage());
    http_response_code(500);
    echo "<!-- Erro interno ao carregar posts -->";
    exit;
}

// ── Renderização dos cards ────────────────────────────────────────────────────

foreach ($posts as $post):
    $titulo  = (string)($post['titulo'] ?? '');
    $resumo  = (string)($post['resumo'] ?? '');
    $slug    = trim((string)($post['slug'] ?? ''));
    $postUrl = $slug !== '' ? '/blog/' . rawurlencode($slug) : '#';

    $dataFmt = '';
    if (!empty($post['data_publicacao'])) {
        $ts = strtotime((string)$post['data_publicacao']);
        if ($ts !== false) {
            $dataFmt = date('d/m/Y', $ts);
        }
    }

    if ($resumo === '' && !empty($post['conteudo'])) {
        $texto  = strip_tags((string)$post['conteudo']);
        $texto  = preg_replace('/\s+/u', ' ', $texto);
        $texto  = trim((string)$texto);
        $resumo = mb_substr($texto, 0, 160, 'UTF-8');
        if (mb_strlen($texto, 'UTF-8') > 160) {
            $resumo .= '...';
        }
    }

    $imgTag = generateImageTag(
        $post['imagem_capa'] ?? '',
        $titulo,
        'w-full h-48 object-cover'
    );
?>
<article class="bg-white rounded-2xl shadow-lg hover:shadow-2xl transition duration-300 overflow-hidden flex flex-col card-hover">
    <a href="<?= h($postUrl) ?>" class="block h-full">
        <div class="w-full h-48 bg-gray-100 overflow-hidden flex items-center justify-center">
            <?php if ($imgTag !== ''): ?>
                <?= $imgTag ?>
            <?php else: ?>
                <div class="w-full h-48 flex items-center justify-center text-gray-400">
                    <i class="fas fa-image text-3xl"></i>
                </div>
            <?php endif; ?>
        </div>

        <div class="p-6">
            <h2 class="text-2xl font-semibold mb-3 text-indigo-700 leading-snug">
                <?= h($titulo) ?>
            </h2>

            <p class="text-gray-600 mb-4 text-sm line-clamp-3">
                <?= h($resumo) ?>
            </p>

            <div class="text-xs text-gray-400">
                Categoria:
                <span class="font-medium text-gray-700"><?= h((string)($post['categoria'] ?? 'Geral')) ?></span>
                <?php if ($dataFmt !== ''): ?>
                    • <?= h($dataFmt) ?>
                <?php endif; ?>
            </div>
        </div>
    </a>
</article>
<?php endforeach; ?>