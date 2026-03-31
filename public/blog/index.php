<?php
// blog/index.php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once '../cadastro/config.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit('Erro interno: conexão com banco de dados indisponível.');
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/**
 * Escape seguro de HTML
 */
function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Base URL
 */
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

/**
 * Monta query string preservando filtros
 */
function buildBlogUrl(array $params = []): string
{
    $filtered = [];

    foreach ($params as $key => $value) {
        if ($value !== null && $value !== '') {
            $filtered[$key] = $value;
        }
    }

    $query = http_build_query($filtered);
    return '/blog/' . ($query ? '?' . $query : '');
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
    if ($trimmed !== '' && (strpos($trimmed, 'http') === 0 || strpos($trimmed, '/') === 0 || strpos($trimmed, 'uploads/') === 0)) {
        return false;
    }
    if (strlen($data) > 10) {
        $firstBytes = substr($data, 0, 20);
        if (strpos($firstBytes, "\x89PNG") === 0) return true;
        if (strpos($firstBytes, "\xff\xd8\xff") === 0) return true;
        if (strpos($firstBytes, "GIF87a") === 0 || strpos($firstBytes, "GIF89a") === 0) return true;
        if (strpos($firstBytes, "RIFF") === 0 && strpos($data, "WEBP", 8) !== false) return true;
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($data);
        if (is_string($mime) && strpos($mime, 'image/') === 0) return true;
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
    $mime = $finfo->buffer($data);
    if (is_string($mime) && strpos($mime, 'image/') === 0) return $mime;
    return 'image/jpeg';
}

function processImageData($imageData): array
{
    $result = ['type' => 'none', 'content' => null, 'mime' => 'image/jpeg'];

    if (empty($imageData)) return $result;

    if (is_resource($imageData)) {
        $bin = stream_get_contents($imageData);
        if ($bin !== false && $bin !== '' && isBinaryImage($bin)) {
            $result['type'] = 'blob';
            $result['content'] = $bin;
            $result['mime'] = detectImageMime($bin);
            return $result;
        }
    }

    if (!is_string($imageData)) return $result;

    $raw = $imageData;
    $trimmed = trim($imageData);
    if ($trimmed === '') return $result;

    if (isValidHttpUrl($trimmed)) {
        $result['type'] = 'url';
        $result['content'] = $trimmed;
        return $result;
    }

    if (strpos($trimmed, '/') === 0 || strpos($trimmed, 'uploads/') === 0 || strpos($trimmed, 'blog/') === 0 || strpos($trimmed, 'assets/') === 0) {
        $cleanPath = ltrim($trimmed, '/');
        $result['type'] = 'path';
        $result['content'] = getBaseUrl() . '/' . $cleanPath;
        return $result;
    }

    if (strlen($trimmed) < 255 && preg_match('/\.(jpg|jpeg|png|gif|webp|svg|bmp|avif)$/i', $trimmed)) {
        $result['type'] = 'path';
        $result['content'] = getBaseUrl() . '/uploads/blog/' . ltrim($trimmed, '/');
        return $result;
    }

    if (preg_match('~^data:image/([a-zA-Z0-9.+-]+);base64,~', $trimmed)) {
        $result['type'] = 'datauri';
        $result['content'] = $trimmed;
        return $result;
    }

    if (strlen($trimmed) > 100) {
        $decoded = base64_decode($trimmed, true);
        if ($decoded !== false && isBinaryImage($decoded)) {
            $result['type'] = 'blob';
            $result['content'] = $decoded;
            $result['mime'] = detectImageMime($decoded);
            return $result;
        }
    }

    if (isBinaryImage($raw)) {
        $result['type'] = 'blob';
        $result['content'] = $raw;
        $result['mime'] = detectImageMime($raw);
        return $result;
    }

    return $result;
}

function generateImageTag($imageData, string $alt = 'Imagem do post', string $class = ''): string
{
    $processed = processImageData($imageData);

    if ($processed['type'] === 'none') return '';

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
        $base64 = base64_encode((string)$processed['content']);
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

/**
 * Renderiza um post como card HTML — mesma estrutura do carregar_posts.php
 */
function renderPostCard(array $post): string
{
    $titulo = (string)($post['titulo'] ?? '');
    $resumo = (string)($post['resumo'] ?? '');
    $slug   = trim((string)($post['slug'] ?? ''));
    $postUrl = $slug !== '' ? '/blog/' . rawurlencode($slug) : '#';

    $dataFmt = '';
    if (!empty($post['data_publicacao'])) {
        $ts = strtotime((string)$post['data_publicacao']);
        if ($ts !== false) {
            $dataFmt = date('d/m/Y', $ts);
        }
    }

    if ($resumo === '' && !empty($post['conteudo'])) {
        $texto = strip_tags((string)$post['conteudo']);
        $texto = preg_replace('/\s+/u', ' ', $texto);
        $texto = trim((string)$texto);
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

    $categoriaLabel = h((string)($post['categoria'] ?? 'Geral'));
    $dataLabel = $dataFmt !== '' ? ' • ' . h($dataFmt) : '';

    $imgBlock = $imgTag !== ''
        ? $imgTag
        : '<div class="w-full h-48 flex items-center justify-center text-gray-400"><i class="fas fa-image text-3xl"></i></div>';

    return '
<article class="bg-white rounded-2xl shadow-lg hover:shadow-2xl transition duration-300 overflow-hidden flex flex-col card-hover">
    <a href="' . h($postUrl) . '" class="block h-full">
        <div class="w-full h-48 bg-gray-100 overflow-hidden flex items-center justify-center">
            ' . $imgBlock . '
        </div>
        <div class="p-6">
            <h2 class="text-2xl font-semibold mb-3 text-indigo-700 leading-snug">
                ' . h($titulo) . '
            </h2>
            <p class="text-gray-600 mb-4 text-sm line-clamp-3">
                ' . h($resumo) . '
            </p>
            <div class="text-xs text-gray-400">
                Categoria: <span class="font-medium text-gray-700">' . $categoriaLabel . '</span>' . $dataLabel . '
            </div>
        </div>
    </a>
</article>';
}

// ─── Parâmetros da requisição ────────────────────────────────────────────────

$categoriaSelecionada = trim((string)($_GET['categoria'] ?? ''));
$termoBusca = trim((string)($_GET['busca'] ?? ''));
$limite = 12;

// ─── Categorias ──────────────────────────────────────────────────────────────

$categorias = [];

try {
    $catStmt = $pdo->query("
        SELECT nome, slug
        FROM blog_categorias
        ORDER BY nome ASC
    ");
    $categorias = $catStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('Erro ao carregar categorias do blog: ' . $e->getMessage());
    $categorias = [];
}

// ─── SSR: primeiros posts (página 1) ─────────────────────────────────────────
//
// Renderizamos os primeiros $limite posts diretamente no HTML.
// Isso garante que o Googlebot veja o conteúdo sem depender de JavaScript.
// O JS depois começa da página 2 para o "Carregar mais".

$primeirosPosts = [];

try {
    $sqlSsr = "
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

    $paramsSsr = [];

    if ($categoriaSelecionada !== '') {
        $sqlSsr .= " AND c.slug = :categoria";
        $paramsSsr[':categoria'] = $categoriaSelecionada;
    }

    if ($termoBusca !== '') {
        $sqlSsr .= " AND (p.titulo LIKE :busca1 OR p.resumo LIKE :busca2 OR p.conteudo LIKE :busca3)";
        $like = '%' . $termoBusca . '%';
        $paramsSsr[':busca1'] = $like;
        $paramsSsr[':busca2'] = $like;
        $paramsSsr[':busca3'] = $like;
    }

    $sqlSsr .= " ORDER BY p.data_publicacao DESC, p.id DESC LIMIT :limite OFFSET 0";

    $stmtSsr = $pdo->prepare($sqlSsr);

    foreach ($paramsSsr as $key => $value) {
        $stmtSsr->bindValue($key, $value, PDO::PARAM_STR);
    }

    $stmtSsr->bindValue(':limite', $limite, PDO::PARAM_INT);
    $stmtSsr->execute();

    $primeirosPosts = $stmtSsr->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('Erro ao carregar posts SSR: ' . $e->getMessage());
    $primeirosPosts = [];
}

// Se a primeira página já retornou menos de $limite posts, não há mais para carregar via JS
$temMaisPostos = count($primeirosPosts) >= $limite;

// ─── Canonical URL ────────────────────────────────────────────────────────────

$canonicalUrl = buildBlogUrl([
    'categoria' => $categoriaSelecionada,
    'busca' => $termoBusca
]);

$fullCanonicalUrl = getBaseUrl() . $canonicalUrl;

// ─── JSON-LD (structured data para o Google) ─────────────────────────────────

$jsonLdItems = [];
foreach ($primeirosPosts as $p) {
    $pSlug = trim((string)($p['slug'] ?? ''));
    if ($pSlug === '') continue;
    $pUrl = getBaseUrl() . '/blog/' . rawurlencode($pSlug);
    $pTitle = (string)($p['titulo'] ?? '');
    $pDesc  = (string)($p['resumo'] ?? '');
    $pDate  = (string)($p['data_publicacao'] ?? '');

    $jsonLdItems[] = [
        '@type'            => 'BlogPosting',
        'headline'         => $pTitle,
        'description'      => $pDesc,
        'url'              => $pUrl,
        'datePublished'    => $pDate,
        'author'           => ['@type' => 'Organization', 'name' => 'PetFlow'],
        'publisher'        => [
            '@type' => 'Organization',
            'name'  => 'PetFlow',
            'logo'  => ['@type' => 'ImageObject', 'url' => 'https://app.petflow.pro/App/assets/images/logo.png'],
        ],
    ];
}

$jsonLd = [
    '@context'        => 'https://schema.org',
    '@type'           => 'Blog',
    'name'            => 'Blog PetFlow',
    'url'             => $fullCanonicalUrl,
    'description'     => 'Dicas de marketing, gestão e tecnologia para petshops.',
    'blogPost'        => $jsonLdItems,
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-8736836789858084" crossorigin="anonymous"></script>
    <meta name="google-adsense-account" content="ca-pub-8736836789858084">

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Blog PetFlow | Estratégias, Gestão e Sucesso para Petshops</title>
    <meta name="description" content="Dicas práticas de marketing, gestão e tecnologia para petshops. Aprenda como crescer, fidelizar clientes e aumentar o faturamento com o PetFlow.">

    <link rel="canonical" href="<?= h($fullCanonicalUrl) ?>">
    <meta name="robots" content="index, follow">
    <meta name="author" content="PetFlow.pro">

    <meta property="og:title" content="Blog PetFlow | Dicas para Petshops">
    <meta property="og:description" content="Aprenda como atrair mais clientes e melhorar a gestão do seu petshop com conteúdos exclusivos do PetFlow.">
    <meta property="og:url" content="<?= h($fullCanonicalUrl) ?>">
    <meta property="og:type" content="website">
    <meta property="og:image" content="https://app.petflow.pro/App/assets/images/logo.png">

    <!-- JSON-LD structured data: ajuda o Google a entender os artigos -->
    <script type="application/ld+json">
        <?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>
    </script>

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="icon" href="https://app.petflow.pro/App/assets/images/logo.png" type="image/png">

    <script async src="https://www.googletagmanager.com/gtag/js?id=AW-17761390239"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){ dataLayer.push(arguments); }
        gtag('js', new Date());
        gtag('config', 'AW-17761390239');
    </script>

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        html { scroll-behavior: smooth; font-size: 16px; }

        body {
            font-family: 'Inter', sans-serif;
            background: #f8fafc;
            color: #0f172a;
            line-height: 1.6;
            overflow-x: hidden;
        }

        .container-blog {
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 1.5rem;
        }

        @media (max-width: 640px) {
            .container-blog { padding: 0 1rem; }
        }

        .navbar-glass {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border-radius: 1rem;
        }

        .hero-gradient {
            background: linear-gradient(135deg, #312e81 0%, #4f46e5 50%, #7c3aed 100%);
            position: relative;
            overflow: hidden;
        }

        .hero-gradient::before {
            content: '';
            position: absolute;
            inset: 0;
            background: url('data:image/svg+xml,<svg width="60" height="60" viewBox="0 0 60 60" xmlns="http://www.w3.org/2000/svg"><path d="M60 60L0 60L60 0L60 60Z" fill="rgba(255,255,255,0.02)"/></svg>');
            opacity: 0.3;
            pointer-events: none;
        }

        .hero-gradient::after {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at 20% 50%, rgba(255,255,255,0.1) 0%, transparent 50%);
            pointer-events: none;
        }

        .category-pill-modern {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(5px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: white;
            padding: 0.5rem 1.25rem;
            border-radius: 100px;
            font-size: 0.875rem;
            font-weight: 600;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            display: inline-block;
        }

        .search-box-modern {
            background: white;
            border-radius: 1rem;
            overflow: hidden;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .search-box-modern input {
            width: 100%;
            padding: 1rem 1rem 1rem 3rem;
            border: none;
            font-size: 1rem;
            background: white;
        }

        .search-box-modern input:focus { outline: none; }

        .filter-tag {
            transition: all 0.3s ease;
            padding: 0.5rem 1.25rem;
            border-radius: 100px;
            font-size: 0.875rem;
            font-weight: 500;
            display: inline-block;
        }

        .filter-tag-active {
            background: #4f46e5;
            color: white;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
        }

        .filter-tag-inactive {
            background: #f1f5f9;
            color: #475569;
        }

        .filter-tag-inactive:hover {
            background: #e2e8f0;
            transform: translateY(-2px);
        }

        .select-custom {
            appearance: none;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 0.5rem;
            padding: 0.75rem 2.5rem 0.75rem 1rem;
            font-size: 0.95rem;
            color: #0f172a;
            cursor: pointer;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%234b5563'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 1.25rem;
        }

        .select-custom:focus { outline: none; border-color: #8b5cf6; }

        .btn-primary {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            color: white;
            padding: 0.875rem 2.5rem;
            border-radius: 9999px;
            font-weight: 600;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(79, 70, 229, 0.4);
        }

        .btn-primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .animate-fade-in-up {
            animation: fadeInUp 0.8s cubic-bezier(0.4, 0, 0.2, 1) forwards;
        }

        .animate-on-scroll {
            opacity: 0;
            transform: translateY(40px);
            transition: all 0.8s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .animate-on-scroll.visible {
            opacity: 1;
            transform: translateY(0);
        }

        .delay-1 { transition-delay: 0.1s; }
        .delay-2 { transition-delay: 0.2s; }
        .delay-3 { transition-delay: 0.3s; }

        .wave-divider { position: absolute; bottom: 0; left: 0; right: 0; line-height: 0; }
        .wave-divider svg { display: block; width: 100%; height: auto; }

        .empty-state {
            grid-column: 1 / -1;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 1rem;
            padding: 2rem;
            text-align: center;
            color: #64748b;
        }

        /* card-hover mantido igual ao carregar_posts.php */
        .card-hover { transition: transform 0.3s ease, box-shadow 0.3s ease; }
        .card-hover:hover { transform: translateY(-4px); }
    </style>
</head>

<body>
    <section class="hero-gradient relative">
        <div class="container-blog pt-4 pb-16 md:pb-24 relative z-10">
            <div class="flex justify-center mb-8">
                <nav class="navbar-glass w-full max-w-5xl rounded-2xl px-4 md:px-6 py-3 flex items-center justify-between gap-4">
                    <a href="/blog/" class="flex items-center gap-2.5 group" aria-label="Ir para a página inicial do blog">
                        <div class="w-9 h-9 bg-gradient-to-br from-violet-600 to-indigo-600 rounded-xl flex items-center justify-center shadow-lg group-hover:scale-105 transition-transform">
                            <img src="https://app.petflow.pro/App/assets/images/logo.png" alt="PetFlow" class="w-6 h-6 object-contain brightness-0 invert">
                        </div>
                        <span class="font-bold text-slate-900 text-lg">PetFlow Blog</span>
                    </a>

                    <div class="hidden md:flex items-center gap-8">
                        <a href="/blog/" class="text-slate-600 hover:text-violet-700 font-medium transition-colors relative group">
                            Início
                            <span class="absolute bottom-0 left-0 w-0 h-0.5 bg-violet-600 transition-all group-hover:w-full"></span>
                        </a>
                        <a href="/blog/?categoria=marketing" class="text-slate-600 hover:text-violet-700 font-medium transition-colors relative group">
                            Categorias
                            <span class="absolute bottom-0 left-0 w-0 h-0.5 bg-violet-600 transition-all group-hover:w-full"></span>
                        </a>
                        <a href="https://petflow.pro" class="text-slate-600 hover:text-violet-700 font-medium transition-colors relative group">
                            Site
                            <span class="absolute bottom-0 left-0 w-0 h-0.5 bg-violet-600 transition-all group-hover:w-full"></span>
                        </a>
                    </div>

                    <div class="flex items-center gap-3">
                        <a href="https://petflow.pro" class="hidden sm:inline-flex items-center justify-center rounded-xl bg-violet-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-violet-700 transition-all hover:shadow-lg hover:shadow-violet-200">
                            Teste grátis
                            <svg class="ml-2 w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                            </svg>
                        </a>
                        <button type="button" class="md:hidden p-2 rounded-lg hover:bg-slate-100" onclick="toggleMobileMenu()" aria-label="Abrir menu mobile">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                            </svg>
                        </button>
                    </div>
                </nav>
            </div>

            <div id="mobileMenu" class="md:hidden hidden bg-white rounded-xl p-4 mb-4 shadow-xl">
                <a href="/blog/" class="block py-2 px-3 text-slate-700 hover:bg-violet-50 rounded-lg">Início</a>
                <a href="/blog/?categoria=marketing" class="block py-2 px-3 text-slate-700 hover:bg-violet-50 rounded-lg">Categorias</a>
                <a href="https://petflow.pro" class="block py-2 px-3 text-slate-700 hover:bg-violet-50 rounded-lg">Site</a>
                <hr class="my-2 border-slate-200">
                <a href="https://petflow.pro" class="block py-2 px-3 bg-violet-600 text-white rounded-lg text-center font-medium">Teste grátis</a>
            </div>

            <div class="text-center max-w-3xl mx-auto">
                <div class="mb-6 animate-on-scroll visible" style="transition-delay: 0s;">
                    <span class="category-pill-modern">
                        <i class="fas fa-star text-yellow-500 mr-2"></i>
                        ESPECIALIZADO PARA PETSHOPS
                    </span>
                </div>

                <h1 class="text-4xl md:text-5xl lg:text-6xl font-extrabold text-white mb-4 animate-on-scroll visible" style="transition-delay: 0.1s">
                    Blog <span class="text-yellow-400">PetFlow</span>
                </h1>

                <p class="text-lg md:text-xl text-violet-100 mb-8 animate-on-scroll visible" style="transition-delay: 0.2s">
                    O lugar certo para aprender como <span class="font-semibold text-white">gerenciar, crescer e fidelizar clientes</span> no seu petshop.
                </p>

                <div class="max-w-2xl mx-auto animate-on-scroll visible" style="transition-delay: 0.3s">
                    <form method="GET" action="/blog/" class="relative">
                        <?php if ($categoriaSelecionada !== ''): ?>
                            <input type="hidden" name="categoria" value="<?= h($categoriaSelecionada) ?>">
                        <?php endif; ?>

                        <div class="search-box-modern flex items-center">
                            <i class="fas fa-search absolute left-4 text-violet-400" aria-hidden="true"></i>
                            <input
                                type="text"
                                name="busca"
                                placeholder="Buscar artigos, dicas, estratégias..."
                                value="<?= h($termoBusca) ?>"
                                class="w-full"
                                aria-label="Buscar conteúdo do blog"
                            >
                            <?php if ($termoBusca !== ''): ?>
                                <a href="<?= h(buildBlogUrl(['categoria' => $categoriaSelecionada])) ?>" class="mr-3 text-gray-400 hover:text-gray-600" aria-label="Limpar busca">
                                    <i class="fas fa-times"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="wave-divider">
            <svg viewBox="0 0 1440 120" fill="none" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none">
                <path d="M0 120L60 105C120 90 240 60 360 45C480 30 600 30 720 37.5C840 45 960 60 1080 67.5C1200 75 1320 75 1380 75L1440 75V120H1380C1320 120 1200 120 1080 120C960 120 840 120 720 120C600 120 480 120 360 120C240 120 120 120 60 120H0Z" fill="#F8FAFC"/>
            </svg>
        </div>
    </section>

    <main class="container-blog py-8">
        <section class="mb-10 animate-on-scroll" id="filters">
            <div class="flex flex-col md:flex-row items-center justify-between gap-4 mb-6">
                <form method="GET" action="/blog/" class="flex flex-col sm:flex-row items-center gap-3 w-full md:w-auto">
                    <div class="relative w-full sm:w-64">
                        <select
                            name="categoria"
                            class="select-custom w-full"
                            aria-label="Filtro por categoria"
                            onchange="this.form.submit()"
                        >
                            <option value="">Todas as categorias</option>
                            <?php foreach ($categorias as $cat): ?>
                                <option value="<?= h((string)$cat['slug']) ?>" <?= $categoriaSelecionada === (string)$cat['slug'] ? 'selected' : '' ?>>
                                    <?= h((string)$cat['nome']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if ($termoBusca !== ''): ?>
                        <input type="hidden" name="busca" value="<?= h($termoBusca) ?>">
                    <?php endif; ?>

                    <?php if ($termoBusca !== '' || $categoriaSelecionada !== ''): ?>
                        <a href="/blog/" class="filter-tag filter-tag-inactive">
                            <i class="fas fa-times mr-2"></i>Limpar filtros
                        </a>
                    <?php endif; ?>
                </form>
            </div>

            <?php if (!empty($categorias)): ?>
                <div class="flex flex-wrap gap-2">
                    <a
                        href="<?= h(buildBlogUrl(['busca' => $termoBusca])) ?>"
                        class="filter-tag <?= $categoriaSelecionada === '' ? 'filter-tag-active' : 'filter-tag-inactive' ?>"
                    >
                        Todos
                    </a>

                    <?php foreach ($categorias as $cat): ?>
                        <?php
                            $catSlug = (string)($cat['slug'] ?? '');
                            $catNome = (string)($cat['nome'] ?? '');
                        ?>
                        <a
                            href="<?= h(buildBlogUrl(['categoria' => $catSlug, 'busca' => $termoBusca])) ?>"
                            class="filter-tag <?= $categoriaSelecionada === $catSlug ? 'filter-tag-active' : 'filter-tag-inactive' ?>"
                        >
                            <?= h($catNome) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($termoBusca !== ''): ?>
                <div class="bg-violet-50 border border-violet-200 rounded-lg p-4 mt-4">
                    <p class="text-violet-800">
                        <i class="fas fa-search mr-2"></i>
                        Mostrando resultados para: <strong>"<?= h($termoBusca) ?>"</strong>
                    </p>
                </div>
            <?php endif; ?>
        </section>

        <section>
            <!--
                CORREÇÃO SEO: os primeiros posts são renderizados diretamente no PHP.
                O Googlebot lê este HTML sem precisar executar JavaScript.
            -->
            <div id="posts-container" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8" aria-live="polite">
                <?php if (!empty($primeirosPosts)): ?>
                    <?php foreach ($primeirosPosts as $post): ?>
                        <?= renderPostCard($post) ?>
                    <?php endforeach; ?>
                <?php elseif ($termoBusca !== '' || $categoriaSelecionada !== ''): ?>
                    <div class="empty-state">
                        <i class="fas fa-newspaper text-3xl mb-3 text-violet-400"></i>
                        <p class="text-lg font-semibold text-slate-700 mb-1">Nenhum artigo encontrado</p>
                        <p>Tente mudar a busca ou remover os filtros aplicados.</p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="mt-12 text-center">
                <?php if ($temMaisPostos): ?>
                    <button id="load-more" class="btn-primary" type="button">
                        <i class="fas fa-plus"></i>
                        Carregar mais artigos
                    </button>
                <?php endif; ?>
                <div id="loading" class="mt-4 text-gray-500 hidden">
                    <i class="fas fa-spinner fa-spin mr-2"></i>
                    Carregando...
                </div>
            </div>

            <div class="mt-16 bg-gradient-to-r from-violet-50 to-indigo-50 rounded-3xl p-8 md:p-12 text-center animate-on-scroll" id="newsletter">
                <h3 class="text-2xl font-bold text-slate-900 mb-3">Receba conteúdos como este</h3>
                <p class="text-slate-600 mb-6 max-w-md mx-auto">Inscreva-se para receber nossos melhores artigos sobre gestão e marketing para petshops.</p>

                <div id="newsletterMessage" class="hidden mb-4 p-3 rounded-lg"></div>

                <form id="newsletterForm" class="flex flex-col sm:flex-row gap-3 max-w-md mx-auto">
                    <input
                        type="email"
                        name="email"
                        id="newsletterEmail"
                        placeholder="Seu melhor e-mail"
                        class="flex-1 px-4 py-3 rounded-xl border border-violet-200 focus:outline-none focus:ring-2 focus:ring-violet-400"
                        required
                    >
                    <button
                        type="submit"
                        id="newsletterSubmit"
                        class="px-6 py-3 bg-violet-600 text-white font-semibold rounded-xl hover:bg-violet-700 transition-all hover:shadow-lg hover:shadow-violet-200 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        Inscrever
                    </button>
                </form>

                <p class="text-xs text-slate-500 mt-4">
                    Ao se inscrever, você concorda em receber conteúdos do PetFlow.
                    Você pode cancelar a qualquer momento.
                </p>
            </div>
        </section>
    </main>

    <footer class="bg-slate-900 text-white py-12">
        <div class="container-blog">
            <div class="grid md:grid-cols-4 gap-8">
                <div class="animate-on-scroll">
                    <img src="https://app.petflow.pro/App/assets/images/logo.png" alt="PetFlow" class="w-12 h-12 mb-4">
                    <p class="text-slate-400 text-sm">Sistema completo para gestão de petshops</p>
                </div>
                <div class="animate-on-scroll delay-1">
                    <h4 class="font-semibold mb-4">Blog</h4>
                    <ul class="space-y-2 text-sm text-slate-400">
                        <li><a href="/blog/" class="hover:text-white transition">Últimos posts</a></li>
                        <li><a href="/blog/?categoria=marketing" class="hover:text-white transition">Marketing</a></li>
                        <li><a href="/blog/?categoria=gestao" class="hover:text-white transition">Gestão</a></li>
                    </ul>
                </div>
                <div class="animate-on-scroll delay-2">
                    <h4 class="font-semibold mb-4">PetFlow</h4>
                    <ul class="space-y-2 text-sm text-slate-400">
                        <li><a href="https://petflow.pro" class="hover:text-white transition">Site oficial</a></li>
                        <li><a href="https://app.petflow.pro" class="hover:text-white transition">Login</a></li>
                        <li><a href="https://petflow.pro/contato" class="hover:text-white transition">Contato</a></li>
                    </ul>
                </div>
                <div class="animate-on-scroll delay-3">
                    <h4 class="font-semibold mb-4">Redes Sociais</h4>
                    <div class="flex gap-4">
                        <a href="https://www.instagram.com/petflow.pro/" target="_blank" rel="noopener noreferrer" class="text-slate-400 hover:text-white transition text-2xl">📸</a>
                        <a href="#" class="text-slate-400 hover:text-white transition text-2xl">📘</a>
                    </div>
                </div>
            </div>
            <div class="border-t border-slate-800 mt-8 pt-8 text-center text-sm text-slate-400 animate-on-scroll">
                <p>&copy; <?= date('Y') ?> PetFlow. Todos os direitos reservados.</p>
            </div>
        </div>
    </footer>

    <script>
        function toggleMobileMenu() {
            const menu = document.getElementById('mobileMenu');
            if (menu) menu.classList.toggle('hidden');
        }

        document.addEventListener('DOMContentLoaded', () => {
            // ── Intersection Observer para animações ─────────────────────────
            const observer = new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) entry.target.classList.add('visible');
                });
            }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });

            document.querySelectorAll('.animate-on-scroll').forEach((el) => observer.observe(el));

            // ── Paginação via JS ──────────────────────────────────────────────
            //
            // IMPORTANTE: começamos da página 2 porque a página 1 já foi
            // renderizada pelo PHP diretamente no HTML (SSR).

            const limite      = <?= (int)$limite ?>;
            const container   = document.getElementById('posts-container');
            const btn         = document.getElementById('load-more');
            const loading     = document.getElementById('loading');

            const urlParams  = new URLSearchParams(window.location.search);
            const categoria  = urlParams.get('categoria') || '';
            const busca      = urlParams.get('busca') || '';

            // Se não há botão de carregar mais (PHP já detectou que não há mais posts), encerra
            if (!btn) return;

            let pagina      = 2; // ← começa na 2 porque a 1 já veio no PHP
            let carregando  = false;
            let fimDosPosts = false;

            function mostrarErroCarregamento() {
                if (!loading) return;
                loading.classList.remove('hidden');
                loading.innerHTML = '<span class="text-red-600">Erro ao carregar. Tente novamente.</span>';
                btn.disabled = false;

                setTimeout(() => {
                    loading.classList.add('hidden');
                    loading.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Carregando...';
                }, 3000);
            }

            async function carregarMais() {
                if (carregando || fimDosPosts) return;

                carregando = true;
                if (loading) loading.classList.remove('hidden');
                btn.disabled = true;

                try {
                    const url = `carregar_posts.php?pagina=${pagina}&categoria=${encodeURIComponent(categoria)}&busca=${encodeURIComponent(busca)}`;
                    const res = await fetch(url, {
                        method: 'GET',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });

                    if (res.status === 204) {
                        fimDosPosts = true;
                        btn.style.display = 'none';
                        if (loading) loading.classList.add('hidden');
                        carregando = false;
                        return;
                    }

                    if (!res.ok) throw new Error(`Erro HTTP ${res.status}`);

                    const html = await res.text();

                    if (!html.trim()) {
                        fimDosPosts = true;
                        btn.style.display = 'none';
                        if (loading) loading.classList.add('hidden');
                        carregando = false;
                        return;
                    }

                    const temp = document.createElement('div');
                    temp.innerHTML = html;

                    Array.from(temp.children).forEach((item) => {
                        item.classList.add('animate-on-scroll');
                        container.appendChild(item);
                        observer.observe(item);
                    });

                    if (temp.children.length < limite) {
                        fimDosPosts = true;
                        btn.style.display = 'none';
                    }

                    pagina++;
                    if (loading) loading.classList.add('hidden');
                    btn.disabled = false;
                    carregando = false;

                } catch (error) {
                    console.error('Erro ao carregar posts:', error);
                    carregando = false;
                    mostrarErroCarregamento();
                }
            }

            btn.addEventListener('click', carregarMais);

            // ── Newsletter ────────────────────────────────────────────────────
            const newsletterForm    = document.getElementById('newsletterForm');
            const newsletterMessage = document.getElementById('newsletterMessage');
            const newsletterSubmit  = document.getElementById('newsletterSubmit');
            const newsletterEmail   = document.getElementById('newsletterEmail');

            function showNewsletterMessage(message, type) {
                if (!newsletterMessage) return;
                newsletterMessage.textContent = message;
                newsletterMessage.classList.remove('hidden', 'bg-green-100', 'text-green-800', 'bg-red-100', 'text-red-800');
                newsletterMessage.classList.add(
                    type === 'success' ? 'bg-green-100' : 'bg-red-100',
                    type === 'success' ? 'text-green-800' : 'text-red-800'
                );
                setTimeout(() => newsletterMessage.classList.add('hidden'), 5000);
            }

            if (newsletterForm && newsletterSubmit && newsletterEmail) {
                newsletterForm.addEventListener('submit', async (e) => {
                    e.preventDefault();

                    const email = newsletterEmail.value.trim();
                    if (!email || !email.includes('@') || !email.includes('.')) {
                        showNewsletterMessage('Por favor, insira um e-mail válido.', 'error');
                        return;
                    }

                    newsletterSubmit.disabled = true;
                    newsletterSubmit.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Enviando...';

                    try {
                        const response = await fetch('newsletter.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: new URLSearchParams({ email }).toString()
                        });

                        const data = await response.json();

                        if (data.success) {
                            showNewsletterMessage(data.message || 'Inscrição realizada com sucesso.', 'success');
                            newsletterEmail.value = '';
                        } else {
                            showNewsletterMessage(data.message || 'Não foi possível concluir a inscrição.', 'error');
                        }
                    } catch (error) {
                        console.error('Erro newsletter:', error);
                        showNewsletterMessage('Erro ao processar sua inscrição. Tente novamente.', 'error');
                    } finally {
                        newsletterSubmit.disabled = false;
                        newsletterSubmit.innerHTML = 'Inscrever';
                    }
                });
            }
        });
    </script>
</body>
</html>