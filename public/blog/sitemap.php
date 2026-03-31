<?php
// blog/sitemap.php
declare(strict_types=1);

require_once '../cadastro/config.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit;
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function getBaseUrl(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'petflow.pro';

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

$base = getBaseUrl();

// Busca todos os posts publicados
try {
    $stmt = $pdo->query("
        SELECT slug, data_publicacao
        FROM blog_posts
        WHERE slug IS NOT NULL AND slug != ''
        ORDER BY data_publicacao DESC, id DESC
    ");
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('Erro ao gerar sitemap: ' . $e->getMessage());
    http_response_code(500);
    exit;
}

// Retorna XML puro — sem nenhum espaço ou BOM antes do header
header('Content-Type: application/xml; charset=utf-8');
header('X-Robots-Tag: noindex'); // o sitemap em si não precisa ser indexado

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

// Página inicial do blog
echo '  <url>' . "\n";
echo '    <loc>' . htmlspecialchars($base . '/blog/', ENT_XML1) . '</loc>' . "\n";
echo '    <changefreq>daily</changefreq>' . "\n";
echo '    <priority>1.0</priority>' . "\n";
echo '  </url>' . "\n";

// Posts individuais
foreach ($posts as $post) {
    $slug = trim((string)($post['slug'] ?? ''));
    if ($slug === '') continue;

    $url      = $base . '/blog/' . rawurlencode($slug);
    $lastmod  = '';

    if (!empty($post['data_publicacao'])) {
        $ts = strtotime((string)$post['data_publicacao']);
        if ($ts !== false) {
            $lastmod = date('Y-m-d', $ts);
        }
    }

    echo '  <url>' . "\n";
    echo '    <loc>' . htmlspecialchars($url, ENT_XML1) . '</loc>' . "\n";

    if ($lastmod !== '') {
        echo '    <lastmod>' . htmlspecialchars($lastmod, ENT_XML1) . '</lastmod>' . "\n";
    }

    echo '    <changefreq>monthly</changefreq>' . "\n";
    echo '    <priority>0.8</priority>' . "\n";
    echo '  </url>' . "\n";
}

echo '</urlset>';