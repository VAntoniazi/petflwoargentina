<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../../cadastro/config.php';

$slug = trim((string)($_GET['slug'] ?? ''));

if ($slug === '') {
    http_response_code(404);
    echo "<h1 style='text-align:center;margin-top:60px;color:#dc2626;font-family:Arial,sans-serif;'>Post não encontrado.</h1>";
    exit;
}

$sql = "SELECT p.*, c.nome AS categoria_nome
        FROM blog_posts p
        LEFT JOIN blog_categorias c ON p.id_categoria = c.id
        WHERE p.slug = ? LIMIT 1";

$stmt = $pdo->prepare($sql);
$stmt->execute([$slug]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) {
    http_response_code(404);
    echo "<h1 style='text-align:center;margin-top:60px;color:#dc2626;font-family:Arial,sans-serif;'>Post não encontrado.</h1>";
    exit;
}

function h(?string $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

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

function isValidHttpUrl(?string $url): bool {
    $url = trim((string)$url);
    if ($url === '') return false;
    if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
    $parts = parse_url($url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return false;
    $scheme = strtolower((string)$parts['scheme']);
    return in_array($scheme, ['http', 'https'], true);
}

function isBinaryImage($data): bool {
    if (empty($data)) return false;
    if (is_string($data) && (strpos($data, 'http') === 0 || strpos($data, '/') === 0)) return false;
    if (is_string($data) && strlen($data) > 10) {
        $firstBytes = substr($data, 0, 20);
        if (strpos($firstBytes, "\x89PNG") === 0) return true;
        if (strpos($firstBytes, "\xff\xd8\xff") === 0) return true;
        if (strpos($firstBytes, "GIF87a") === 0 || strpos($firstBytes, "GIF89a") === 0) return true;
        if (strpos($firstBytes, "RIFF") === 0 && strpos($firstBytes, "WEBP", 8) !== false) return true;
    }
    return false;
}

function processImageData($imageData, $postId = null): array {
    $result = ['type' => 'none', 'content' => null, 'mime' => 'image/png'];
    if (empty($imageData)) return $result;
    if (is_string($imageData) && isValidHttpUrl($imageData)) {
        $result['type'] = 'url'; $result['content'] = $imageData; return $result;
    }
    if (is_string($imageData) && (strpos($imageData, '/') === 0 || strpos($imageData, 'uploads/') === 0)) {
        $result['type'] = 'path';
        $result['content'] = getBaseUrl() . '/' . ltrim($imageData, '/');
        return $result;
    }
    if (is_string($imageData) && strlen($imageData) < 255 && preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $imageData)) {
        $result['type'] = 'path';
        $result['content'] = getBaseUrl() . '/uploads/blog/' . ltrim($imageData, '/');
        return $result;
    }
    if (isBinaryImage($imageData)) {
        $result['type'] = 'blob'; $result['content'] = $imageData;
        if (strpos($imageData, "\x89PNG") === 0) $result['mime'] = 'image/png';
        elseif (strpos($imageData, "\xff\xd8\xff") === 0) $result['mime'] = 'image/jpeg';
        elseif (strpos($imageData, "GIF87a") === 0 || strpos($imageData, "GIF89a") === 0) $result['mime'] = 'image/gif';
        elseif (strpos($imageData, "RIFF") === 0 && strpos($imageData, "WEBP", 8) !== false) $result['mime'] = 'image/webp';
        return $result;
    }
    if (is_string($imageData) && strlen($imageData) > 100) {
        $decoded = base64_decode($imageData, true);
        if ($decoded !== false && isBinaryImage($decoded)) {
            $result['type'] = 'blob'; $result['content'] = $decoded; return $result;
        }
    }
    return $result;
}

function generateImageTag($imageData, $alt = 'Imagem do post', $class = ''): string {
    if (empty($imageData)) return '';
    $processed = processImageData($imageData);
    if ($processed['type'] === 'none') return '';
    if ($processed['type'] === 'url' || $processed['type'] === 'path') {
        return sprintf('<img src="%s" alt="%s" class="%s" loading="lazy" decoding="async" onerror="this.parentElement.classList.add(\'image-error\'); this.style.display=\'none\';">', h($processed['content']), h($alt), h($class));
    }
    if ($processed['type'] === 'blob') {
        $dataUri = 'data:' . $processed['mime'] . ';base64,' . base64_encode($processed['content']);
        return sprintf('<img src="%s" alt="%s" class="%s" loading="lazy" decoding="async">', $dataUri, h($alt), h($class));
    }
    return '';
}

$imgCapaProcessed = processImageData($post['imagem_capa'] ?? '', $post['id'] ?? null);
$imgCapaTag = generateImageTag($post['imagem_capa'] ?? '', $post['titulo'] ?? 'Imagem do post', 'w-full h-auto');
$imagemSeo  = ($imgCapaProcessed['type'] === 'url' || $imgCapaProcessed['type'] === 'path')
    ? (string)$imgCapaProcessed['content']
    : 'https://app.petflow.pro/App/assets/images/logo.png';

$tituloSeo    = h(($post['titulo'] ?? '') . ' | Blog PetFlow');
$descricaoRaw = trim((string)($post['resumo'] ?? ''));
if ($descricaoRaw === '') {
    $texto = strip_tags((string)($post['conteudo'] ?? ''));
    $texto = preg_replace('/\s+/', ' ', $texto);
    $descricaoRaw = mb_substr(trim((string)$texto), 0, 160);
    if (mb_strlen((string)$texto) > 160) $descricaoRaw .= '...';
}
$descricaoSeo = h($descricaoRaw);
$urlCompleta  = getBaseUrl() . '/blog/' . rawurlencode($slug);

$allowedTags = '<p><br><strong><em><ul><ol><li><h1><h2><h3><h4><h5><h6><blockquote><img><a><table><tr><td><th><tbody><thead><figure><figcaption>';
$conteudoSeguro = strip_tags((string)($post['conteudo'] ?? ''), $allowedTags);
$conteudoSeguro = preg_replace_callback('/(href|src)\s*=\s*([\'"])(.*?)\2/i', function($m) {
    $attr = strtolower($m[1]); $q = $m[2]; $val = trim((string)$m[3]);
    if ($val === '' || str_starts_with($val, '/') || preg_match('#^https?://#i', $val)) return $attr . '=' . $q . h($val) . $q;
    return $attr . '=' . $q . '' . $q;
}, $conteudoSeguro);

$headings = []; $usedIds = [];

function slugifyHeading(string $text): string {
    $text = trim(mb_strtolower($text, 'UTF-8'));
    $text = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $text);
    $text = preg_replace('/[\s-]+/u', '-', $text);
    return trim((string)$text, '-');
}

$conteudoComIds = preg_replace_callback('/<h([2-3])>(.*?)<\/h\1>/is', function($matches) use (&$headings, &$usedIds) {
    $level = (int)$matches[1]; $innerHtml = trim((string)$matches[2]); $plainText = trim(strip_tags($innerHtml));
    if ($plainText === '') return $matches[0];
    $id = slugifyHeading($plainText); if ($id === '') $id = 'secao';
    $baseId = $id; $i = 2;
    while (in_array($id, $usedIds, true)) { $id = $baseId . '-' . $i; $i++; }
    $usedIds[] = $id; $headings[] = ['level' => $level, 'text' => $plainText, 'id' => $id];
    return '<h' . $level . ' id="' . h($id) . '">' . $innerHtml . '</h' . $level . '>';
}, $conteudoSeguro);

function estimateReadingTime(string $content): int {
    return max(1, (int)ceil(str_word_count(strip_tags($content)) / 200));
}

$jsonLd = [
    '@context' => 'https://schema.org', '@type' => 'BlogPosting',
    'headline' => (string)($post['titulo'] ?? ''), 'description' => $descricaoRaw,
    'url' => $urlCompleta, 'datePublished' => (string)($post['data_publicacao'] ?? ''),
    'dateModified' => (string)($post['data_publicacao'] ?? ''), 'image' => $imagemSeo,
    'author' => ['@type' => 'Organization', 'name' => 'PetFlow', 'url' => 'https://petflow.pro'],
    'publisher' => ['@type' => 'Organization', 'name' => 'PetFlow', 'logo' => ['@type' => 'ImageObject', 'url' => 'https://app.petflow.pro/App/assets/images/logo.png']],
    'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $urlCompleta],
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-8736836789858084" crossorigin="anonymous"></script>
    <meta name="google-adsense-account" content="ca-pub-8736836789858084">
    <meta charset="UTF-8">
    <title><?= $tituloSeo ?></title>
    <meta name="description" content="<?= $descricaoSeo ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="<?= h($urlCompleta) ?>">
    <meta property="og:title" content="<?= $tituloSeo ?>">
    <meta property="og:description" content="<?= $descricaoSeo ?>">
    <meta property="og:image" content="<?= h($imagemSeo) ?>">
    <meta property="og:url" content="<?= h($urlCompleta) ?>">
    <meta property="og:type" content="article">
    <meta property="og:site_name" content="PetFlow">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= $tituloSeo ?>">
    <meta name="twitter:description" content="<?= $descricaoSeo ?>">
    <meta name="twitter:image" content="<?= h($imagemSeo) ?>">
    <script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?></script>
    <link rel="icon" href="https://app.petflow.pro/App/assets/images/logo.png" type="image/png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        html{scroll-behavior:smooth;font-size:16px}
        body{background:#f8fafc;color:#0f172a;font-family:'Inter',sans-serif;line-height:1.6;overflow-x:hidden}

        .hero-gradient{background:linear-gradient(135deg,#312e81 0%,#4f46e5 50%,#7c3aed 100%);position:relative;overflow:hidden}
        .hero-gradient::before{content:'';position:absolute;top:0;left:0;right:0;bottom:0;background:url('data:image/svg+xml,<svg width="60" height="60" viewBox="0 0 60 60" xmlns="http://www.w3.org/2000/svg"><path d="M60 60L0 60L60 0L60 60Z" fill="rgba(255,255,255,0.02)"/></svg>');opacity:.3;pointer-events:none}
        .navbar-glass{background:rgba(255,255,255,.95);backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,.2);box-shadow:0 8px 32px rgba(0,0,0,.1)}
        .container-blog{max-width:1280px;margin:0 auto;padding:0 1.5rem}
        @media(max-width:640px){.container-blog{padding:0 1rem}}
        .content-narrow{max-width:768px;margin:0 auto}

        /* Prose */
        .prose-enhanced{color:#1e293b;font-size:1.125rem;line-height:1.8}
        .prose-enhanced p{margin:1.5rem 0;color:#334155}
        .prose-enhanced h2{font-size:2rem;font-weight:700;letter-spacing:-.02em;margin:2.5rem 0 1rem;color:#0f172a;scroll-margin-top:80px}
        .prose-enhanced h3{font-size:1.5rem;font-weight:600;margin:2rem 0 1rem;color:#1e293b;scroll-margin-top:80px}
        .prose-enhanced a{color:#4f46e5;text-decoration:none;font-weight:500;border-bottom:2px solid transparent;transition:border-color .2s}
        .prose-enhanced a:hover{border-bottom-color:#4f46e5}
        .prose-enhanced ul,.prose-enhanced ol{margin:1.5rem 0;padding-left:1.5rem}
        .prose-enhanced li{margin:.5rem 0;color:#334155}
        .prose-enhanced ul li{list-style-type:disc}
        .prose-enhanced ol li{list-style-type:decimal}
        .prose-enhanced blockquote{margin:2rem 0;padding:1.5rem 2rem;background:linear-gradient(to right,#f5f3ff,#fff);border-left:4px solid #8b5cf6;border-radius:0 1rem 1rem 0;font-style:italic;color:#1e293b;box-shadow:0 4px 6px -1px rgba(0,0,0,.1)}
        .prose-enhanced img{max-width:100%;height:auto;border-radius:1rem;margin:2rem 0;box-shadow:0 20px 40px -15px rgba(0,0,0,.2)}

        .category-pill-modern{background:rgba(255,255,255,.15);backdrop-filter:blur(5px);border:1px solid rgba(255,255,255,.3);color:#fff;padding:.5rem 1.25rem;border-radius:100px;font-size:.875rem;font-weight:600;letter-spacing:.5px;text-transform:uppercase;display:inline-block}

        /* TOC */
        .toc-accordion{background:#fff;border-radius:1rem;margin:2rem 0;border:1px solid #e9d5ff;box-shadow:0 8px 20px rgba(79,70,229,.08);overflow:hidden;opacity:0;transform:translateY(40px);transition:all .8s cubic-bezier(.4,0,.2,1)}
        .toc-accordion.visible{opacity:1;transform:translateY(0)}
        .toc-header{background:linear-gradient(to right,#f9f7ff,#fff);padding:1.25rem 1.5rem;cursor:pointer;display:flex;align-items:center;justify-content:space-between;transition:all .3s ease;border-bottom:2px solid transparent}
        .toc-header:hover{background:#f5f3ff}
        .toc-header.active{border-bottom-color:#8b5cf6;background:#f5f3ff}
        .toc-header h3{font-size:1.125rem;font-weight:700;color:#312e81;display:flex;align-items:center;gap:.5rem}
        .toc-header h3 svg{width:1.25rem;height:1.25rem;color:#8b5cf6}
        .toc-header .info-badge{background:#8b5cf6;color:#fff;font-size:.75rem;padding:.25rem .75rem;border-radius:100px;margin-left:.75rem;font-weight:500}
        .toc-header .toggle-icon{width:1.5rem;height:1.5rem;display:flex;align-items:center;justify-content:center;background:#fff;border-radius:9999px;box-shadow:0 2px 8px rgba(139,92,246,.2);transition:all .3s;color:#4f46e5}
        .toc-header.active .toggle-icon{transform:rotate(180deg);background:#8b5cf6;color:#fff}
        .toc-content{max-height:0;overflow:hidden;transition:max-height .5s ease-out;background:#fff}
        .toc-content.show{max-height:400px;overflow-y:auto;transition:max-height .5s ease-in}
        .toc-content.show::-webkit-scrollbar{width:6px}
        .toc-content.show::-webkit-scrollbar-track{background:#f1f1f1;border-radius:10px}
        .toc-content.show::-webkit-scrollbar-thumb{background:#c4b5fd;border-radius:10px}
        .toc-content.show::-webkit-scrollbar-thumb:hover{background:#8b5cf6}
        .toc-content-inner{padding:1.5rem;border-top:1px solid #f0e7ff}
        .toc-content ul{list-style:none;padding:0;margin:0}
        .toc-content li{margin:.75rem 0}
        .toc-content a{color:#4b5563;text-decoration:none;font-size:.95rem;display:block;padding:.5rem .75rem;border-radius:.5rem;transition:all .2s;border-left:3px solid transparent;font-weight:500}
        .toc-content a:hover{background:#f5f3ff;color:#4f46e5;border-left-color:#8b5cf6;transform:translateX(8px)}
        .toc-content .toc-level-3{margin-left:1.5rem}
        .toc-content .toc-level-3 a{font-weight:400;border-left-color:#e9d5ff}
        .toc-help-text{font-size:.85rem;color:#6b7280;margin-top:.75rem;padding-top:.75rem;border-top:1px dashed #e2e8f0;display:flex;align-items:center;gap:.5rem}
        .toc-help-text svg{width:1rem;height:1rem;color:#9ca3af}

        /* Cover */
        .cover-image-modern{border-radius:1.5rem;overflow:hidden;box-shadow:0 25px 50px -12px rgba(0,0,0,.25);margin:-4rem auto 3rem;position:relative;z-index:10;max-width:896px;border:4px solid #fff;background:#f1f5f9;min-height:200px;display:flex;align-items:center;justify-content:center;opacity:0;transform:translateY(40px);transition:all .8s cubic-bezier(.4,0,.2,1)}
        .cover-image-modern.visible{opacity:1;transform:translateY(0)}
        .cover-image-modern img{width:100%;height:auto;display:block;aspect-ratio:16/9;object-fit:cover}

        /* Author */
        .author-card{display:flex;align-items:center;gap:1rem;padding:1rem 1.5rem;background:rgba(255,255,255,.95);backdrop-filter:blur(5px);border-radius:100px;border:1px solid rgba(255,255,255,.4);box-shadow:0 8px 20px rgba(0,0,0,.15);opacity:0;transform:translateY(30px);transition:all .8s cubic-bezier(.4,0,.2,1);transition-delay:.2s}
        .author-card.visible{opacity:1;transform:translateY(0)}
        .author-avatar{width:3rem;height:3rem;border-radius:9999px;border:2px solid #fff;object-fit:cover;box-shadow:0 2px 10px rgba(0,0,0,.1)}
        .author-card .text-slate-800{color:#1e293b!important}
        .author-card .text-slate-600{color:#4b5563!important}

        /* Reading progress */
        .reading-progress{position:fixed;top:0;left:0;width:0%;height:4px;background:linear-gradient(90deg,#8b5cf6,#ec4899);z-index:1000;transition:width .1s;box-shadow:0 0 10px rgba(139,92,246,.5)}

        /* ══ SHARE SIDEBAR ══ */
        .share-sidebar{position:fixed;left:1.25rem;top:50%;transform:translateY(-50%);display:flex;flex-direction:column;gap:.5rem;z-index:100;opacity:0;transition:opacity .6s ease .8s}
        .share-sidebar.visible{opacity:1}
        .share-sidebar-label{writing-mode:vertical-rl;text-orientation:mixed;font-size:.65rem;font-weight:700;letter-spacing:.15em;text-transform:uppercase;color:#94a3b8;text-align:center;margin-bottom:.25rem}
        .share-sidebar-btn{width:2.5rem;height:2.5rem;border-radius:.75rem;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .25s cubic-bezier(.4,0,.2,1);position:relative;text-decoration:none;box-shadow:0 2px 8px rgba(0,0,0,.12)}
        .share-sidebar-btn svg{width:1.1rem;height:1.1rem}
        .share-sidebar-btn::after{content:attr(data-tooltip);position:absolute;left:calc(100% + .65rem);top:50%;transform:translateY(-50%) scale(.85);background:#1e293b;color:#fff;font-size:.72rem;font-weight:600;padding:.3rem .65rem;border-radius:.4rem;white-space:nowrap;opacity:0;pointer-events:none;transition:all .2s ease;letter-spacing:.02em}
        .share-sidebar-btn::before{content:'';position:absolute;left:calc(100% + .35rem);top:50%;transform:translateY(-50%) scale(.85);border:5px solid transparent;border-right-color:#1e293b;opacity:0;pointer-events:none;transition:all .2s ease}
        .share-sidebar-btn:hover::after,.share-sidebar-btn:hover::before{opacity:1;transform:translateY(-50%) scale(1)}
        .share-sidebar-btn:hover{transform:translateX(3px) scale(1.08)}
        .share-sidebar-divider{width:1.5rem;height:1px;background:#e2e8f0;margin:.25rem auto}

        .share-btn-whatsapp{background:#25D366;color:#fff}
        .share-btn-whatsapp:hover{background:#20b858;box-shadow:0 4px 16px rgba(37,211,102,.45)}
        .share-btn-telegram{background:#2AABEE;color:#fff}
        .share-btn-telegram:hover{background:#1a9bd8;box-shadow:0 4px 16px rgba(42,171,238,.45)}
        .share-btn-facebook{background:#1877F2;color:#fff}
        .share-btn-facebook:hover{background:#1565d8;box-shadow:0 4px 16px rgba(24,119,242,.45)}
        .share-btn-twitter{background:#000;color:#fff}
        .share-btn-twitter:hover{background:#222;box-shadow:0 4px 16px rgba(0,0,0,.3)}
        .share-btn-copy{background:#fff;color:#64748b;border:1.5px solid #e2e8f0}
        .share-btn-copy:hover{background:#f8fafc;color:#4f46e5;border-color:#c4b5fd;box-shadow:0 4px 16px rgba(79,70,229,.15)}
        .share-btn-copy.copied{background:#ecfdf5;color:#16a34a;border-color:#bbf7d0}

        /* ══ SHARE INLINE ══ */
        .share-inline-block{margin:3rem 0 0;padding:2rem;background:#fff;border-radius:1.5rem;border:1px solid #e9d5ff;box-shadow:0 4px 20px rgba(79,70,229,.06);text-align:center}
        .share-inline-title{font-size:1rem;font-weight:700;color:#312e81;margin-bottom:1.25rem;display:flex;align-items:center;justify-content:center;gap:.5rem}
        .share-inline-title svg{width:1.1rem;height:1.1rem;color:#8b5cf6}
        .share-inline-buttons{display:flex;flex-wrap:wrap;gap:.65rem;justify-content:center}
        .share-inline-btn{display:inline-flex;align-items:center;gap:.5rem;padding:.6rem 1.1rem;border-radius:9999px;font-size:.85rem;font-weight:600;border:none;cursor:pointer;text-decoration:none;transition:all .25s cubic-bezier(.4,0,.2,1);white-space:nowrap;letter-spacing:.01em}
        .share-inline-btn svg{width:1rem;height:1rem;flex-shrink:0}
        .share-inline-btn:hover{transform:translateY(-2px)}
        .share-inline-whatsapp{background:#25D366;color:#fff}
        .share-inline-whatsapp:hover{background:#20b858;box-shadow:0 6px 20px rgba(37,211,102,.4)}
        .share-inline-telegram{background:#2AABEE;color:#fff}
        .share-inline-telegram:hover{background:#1a9bd8;box-shadow:0 6px 20px rgba(42,171,238,.4)}
        .share-inline-facebook{background:#1877F2;color:#fff}
        .share-inline-facebook:hover{background:#1565d8;box-shadow:0 6px 20px rgba(24,119,242,.4)}
        .share-inline-twitter{background:#000;color:#fff}
        .share-inline-twitter:hover{background:#333;box-shadow:0 6px 20px rgba(0,0,0,.25)}
        .share-inline-copy{background:#f1f5f9;color:#475569;border:1.5px solid #e2e8f0}
        .share-inline-copy:hover{background:#ede9fe;color:#4f46e5;border-color:#c4b5fd;box-shadow:0 6px 20px rgba(79,70,229,.15)}
        .share-inline-copy.copied{background:#ecfdf5;color:#16a34a;border-color:#bbf7d0}

        /* Animações */
        .animate-on-scroll{opacity:0;transform:translateY(40px);transition:all .8s cubic-bezier(.4,0,.2,1)}
        .animate-on-scroll.visible{opacity:1;transform:translateY(0)}
        .delay-1{transition-delay:.1s}
        .delay-2{transition-delay:.2s}
        .delay-3{transition-delay:.3s}

        @media(max-width:1024px){.share-sidebar{display:none}}
        @media(max-width:768px){
            .prose-enhanced{font-size:1rem}
            .prose-enhanced h2{font-size:1.75rem}
            .prose-enhanced h3{font-size:1.35rem}
            .author-card{padding:.75rem 1.25rem}
            .share-inline-block{padding:1.5rem 1rem}
        }
    </style>
</head>
<body>
    <div class="reading-progress" id="readingProgress"></div>

    <!-- SHARE SIDEBAR — fixo lateral, visível só em desktop (>1024px) -->
    <div class="share-sidebar" id="shareSidebar">
        <span class="share-sidebar-label">Compartilhar</span>

        <button class="share-sidebar-btn share-btn-whatsapp" onclick="shareVia('whatsapp')" data-tooltip="WhatsApp" aria-label="WhatsApp">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M19.077 4.928C17.191 3.041 14.683 2 12.006 2c-5.349 0-9.703 4.352-9.706 9.698 0 1.71.446 3.38 1.296 4.857L2 22l5.539-1.577c1.424.772 3.027 1.178 4.66 1.179h.004c5.347 0 9.703-4.353 9.706-9.699 0-2.588-1.008-5.02-2.832-6.875zM12.02 20.187h-.003c-1.458 0-2.888-.392-4.14-1.13l-.297-.176-3.288.876.878-3.206-.193-.308c-.804-1.27-1.228-2.73-1.228-4.225C4.75 7.702 8.416 4.04 12.023 4.04c1.855 0 3.598.724 4.91 2.038 1.31 1.313 2.032 3.056 2.03 4.908-.003 3.605-3.67 5.96-6.943 5.96zm3.577-4.556c-.197-.1-1.164-.574-1.345-.64-.18-.066-.312-.1-.445.1-.132.198-.515.64-.632.77-.116.13-.233.148-.43.05-.198-.1-.838-.31-1.596-.986-.59-.526-.988-1.176-1.104-1.376-.116-.198-.012-.306.088-.405.09-.09.198-.232.297-.348.1-.116.133-.198.198-.33.066-.133.033-.248-.017-.348-.05-.1-.445-1.076-.61-1.474-.16-.386-.323-.334-.445-.34-.115-.006-.248-.006-.38-.006-.133 0-.347.05-.53.248-.183.198-.7.684-.7 1.67 0 .985.717 1.937.817 2.07.1.133 1.398 2.136 3.39 2.997.474.206.845.33 1.134.422.476.153.91.132 1.253.08.382-.058 1.164-.476 1.328-.936.165-.46.165-.854.116-.936-.05-.083-.182-.133-.38-.232z"/></svg>
        </button>

        <button class="share-sidebar-btn share-btn-telegram" onclick="shareVia('telegram')" data-tooltip="Telegram" aria-label="Telegram">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>
        </button>

        <button class="share-sidebar-btn share-btn-facebook" onclick="shareVia('facebook')" data-tooltip="Facebook" aria-label="Facebook">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
        </button>

        <button class="share-sidebar-btn share-btn-twitter" onclick="shareVia('twitter')" data-tooltip="X / Twitter" aria-label="X / Twitter">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
        </button>

        <div class="share-sidebar-divider"></div>

        <button class="share-sidebar-btn share-btn-copy" onclick="copyLink(this)" data-tooltip="Copiar link" aria-label="Copiar link" id="sidebarCopyBtn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
        </button>
    </div>

    <!-- Hero -->
    <section class="hero-gradient relative">
        <div class="container-blog pt-4 pb-16 md:pb-24 relative z-10">
            <div class="flex justify-center mb-8">
                <nav class="navbar-glass w-full max-w-5xl rounded-2xl px-4 md:px-6 py-3 flex items-center justify-between gap-4">
                    <a href="/blog/" class="flex items-center gap-2.5 group">
                        <div class="w-9 h-9 bg-gradient-to-br from-violet-600 to-indigo-600 rounded-xl flex items-center justify-center shadow-lg group-hover:scale-105 transition-transform">
                            <img src="https://app.petflow.pro/App/assets/images/logo.png" alt="PetFlow" class="w-6 h-6 object-contain brightness-0 invert">
                        </div>
                        <span class="font-bold text-slate-900 text-lg">PetFlow Blog</span>
                    </a>
                    <div class="hidden md:flex items-center gap-8">
                        <a href="/blog/" class="text-slate-600 hover:text-violet-700 font-medium transition-colors relative group">Início<span class="absolute bottom-0 left-0 w-0 h-0.5 bg-violet-600 transition-all group-hover:w-full"></span></a>
                        <a href="/blog/?categoria=marketing" class="text-slate-600 hover:text-violet-700 font-medium transition-colors relative group">Categorias<span class="absolute bottom-0 left-0 w-0 h-0.5 bg-violet-600 transition-all group-hover:w-full"></span></a>
                        <a href="https://petflow.pro" class="text-slate-600 hover:text-violet-700 font-medium transition-colors relative group">Site<span class="absolute bottom-0 left-0 w-0 h-0.5 bg-violet-600 transition-all group-hover:w-full"></span></a>
                    </div>
                    <div class="flex items-center gap-3">
                        <a href="https://petflow.pro" class="hidden sm:inline-flex items-center justify-center rounded-xl bg-violet-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-violet-700 transition-all hover:shadow-lg hover:shadow-violet-200">
                            Teste grátis
                            <svg class="ml-2 w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                        </a>
                        <button class="md:hidden p-2 rounded-lg hover:bg-slate-100" onclick="toggleMobileMenu()" aria-label="Abrir menu">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
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

            <div class="content-narrow text-center text-white pt-8 md:pt-12">
                <?php if (!empty($post['categoria_nome'])): ?>
                    <div class="mb-6 animate-on-scroll visible" style="transition-delay:0s">
                        <span class="category-pill-modern"><?= h((string)$post['categoria_nome']) ?></span>
                    </div>
                <?php endif; ?>

                <h1 class="text-4xl md:text-5xl lg:text-6xl font-extrabold tracking-tight leading-tight mb-6 animate-on-scroll visible" style="transition-delay:.1s">
                    <?= h((string)($post['titulo'] ?? '')) ?>
                </h1>

                <?php if (!empty($descricaoRaw)): ?>
                    <p class="text-lg md:text-xl text-violet-100 max-w-2xl mx-auto mb-8 animate-on-scroll visible" style="transition-delay:.2s">
                        <?= h($descricaoRaw) ?>
                    </p>
                <?php endif; ?>

                <div class="flex justify-center">
                    <div class="author-card" id="authorCard">
                        <img src="https://app.petflow.pro/App/assets/images/logo.png" alt="Equipe PetFlow" class="author-avatar">
                        <div class="text-left">
                            <div class="font-semibold text-slate-800">Equipe PetFlow</div>
                            <div class="text-sm text-slate-600 flex items-center gap-2">
                                <span><?= !empty($post['data_publicacao']) ? date('d/m/Y', strtotime((string)$post['data_publicacao'])) : 'Artigo recente' ?></span>
                                <span>•</span>
                                <span><?= estimateReadingTime((string)($post['conteudo'] ?? '')) ?> min de leitura</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="absolute bottom-0 left-0 right-0">
            <svg viewBox="0 0 1440 120" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M0 120L60 105C120 90 240 60 360 45C480 30 600 30 720 37.5C840 45 960 60 1080 67.5C1200 75 1320 75 1380 75L1440 75V120H1380C1320 120 1200 120 1080 120C960 120 840 120 720 120C600 120 480 120 360 120C240 120 120 120 60 120H0Z" fill="#F8FAFC"/>
            </svg>
        </div>
    </section>

    <main class="container-blog pb-20 relative">
        <div class="content-narrow">
            <?php if (!empty($post['imagem_capa']) && $imgCapaTag): ?>
                <div class="cover-image-modern" id="coverImage"><?= $imgCapaTag ?></div>
            <?php endif; ?>

            <?php if (!empty($headings)): ?>
                <div class="toc-accordion" id="tocAccordion">
                    <div class="toc-header" id="tocHeader" onclick="toggleToc()">
                        <h3>
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                            Neste artigo você vai ver
                            <span class="info-badge"><?= count($headings) ?> tópicos</span>
                        </h3>
                        <div class="toggle-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></div>
                    </div>
                    <div class="toc-content" id="tocContent">
                        <div class="toc-content-inner">
                            <ul>
                                <?php foreach ($headings as $heading): ?>
                                    <li class="<?= $heading['level'] === 3 ? 'toc-level-3' : '' ?>">
                                        <a href="#<?= h($heading['id']) ?>" onclick="closeTocAfterClick()" style="font-weight:<?= $heading['level'] === 2 ? '700' : '500' ?>">
                                            <?= h($heading['text']) ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <div class="toc-help-text">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <span>Clique nos tópicos em <strong>negrito</strong> para navegar diretamente</span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <article class="prose-enhanced">
                <?= $conteudoComIds ?>
            </article>

            <!-- SHARE INLINE — aparece em todos os dispositivos após o artigo -->
            <div class="share-inline-block animate-on-scroll" id="shareInline">
                <div class="share-inline-title">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/></svg>
                    Gostou? Compartilhe com quem precisa disso!
                </div>
                <div class="share-inline-buttons">
                    <button class="share-inline-btn share-inline-whatsapp" onclick="shareVia('whatsapp')" aria-label="WhatsApp">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M19.077 4.928C17.191 3.041 14.683 2 12.006 2c-5.349 0-9.703 4.352-9.706 9.698 0 1.71.446 3.38 1.296 4.857L2 22l5.539-1.577c1.424.772 3.027 1.178 4.66 1.179h.004c5.347 0 9.703-4.353 9.706-9.699 0-2.588-1.008-5.02-2.832-6.875zM12.02 20.187h-.003c-1.458 0-2.888-.392-4.14-1.13l-.297-.176-3.288.876.878-3.206-.193-.308c-.804-1.27-1.228-2.73-1.228-4.225C4.75 7.702 8.416 4.04 12.023 4.04c1.855 0 3.598.724 4.91 2.038 1.31 1.313 2.032 3.056 2.03 4.908-.003 3.605-3.67 5.96-6.943 5.96zm3.577-4.556c-.197-.1-1.164-.574-1.345-.64-.18-.066-.312-.1-.445.1-.132.198-.515.64-.632.77-.116.13-.233.148-.43.05-.198-.1-.838-.31-1.596-.986-.59-.526-.988-1.176-1.104-1.376-.116-.198-.012-.306.088-.405.09-.09.198-.232.297-.348.1-.116.133-.198.198-.33.066-.133.033-.248-.017-.348-.05-.1-.445-1.076-.61-1.474-.16-.386-.323-.334-.445-.34-.115-.006-.248-.006-.38-.006-.133 0-.347.05-.53.248-.183.198-.7.684-.7 1.67 0 .985.717 1.937.817 2.07.1.133 1.398 2.136 3.39 2.997.474.206.845.33 1.134.422.476.153.91.132 1.253.08.382-.058 1.164-.476 1.328-.936.165-.46.165-.854.116-.936-.05-.083-.182-.133-.38-.232z"/></svg>
                        WhatsApp
                    </button>
                    <button class="share-inline-btn share-inline-telegram" onclick="shareVia('telegram')" aria-label="Telegram">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>
                        Telegram
                    </button>
                    <button class="share-inline-btn share-inline-facebook" onclick="shareVia('facebook')" aria-label="Facebook">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                        Facebook
                    </button>
                    <button class="share-inline-btn share-inline-twitter" onclick="shareVia('twitter')" aria-label="X / Twitter">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                        X / Twitter
                    </button>
                    <button class="share-inline-btn share-inline-copy" onclick="copyLink(this)" aria-label="Copiar link" id="inlineCopyBtn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                        Copiar link
                    </button>
                </div>
            </div>

            <!-- Newsletter -->
            <div class="mt-8 bg-gradient-to-r from-violet-50 to-indigo-50 rounded-3xl p-8 md:p-12 text-center animate-on-scroll" id="newsletter">
                <h3 class="text-2xl font-bold text-slate-900 mb-3">Receba conteúdos como este</h3>
                <p class="text-slate-600 mb-6 max-w-md mx-auto">Inscreva-se para receber nossos melhores artigos sobre gestão e marketing para petshops.</p>
                <div id="newsletterMessage" class="hidden mb-4 p-3 rounded-lg"></div>
                <form id="newsletterForm" class="flex flex-col sm:flex-row gap-3 max-w-md mx-auto">
                    <input type="email" name="email" id="newsletterEmail" placeholder="Seu melhor e-mail" class="flex-1 px-4 py-3 rounded-xl border border-violet-200 focus:outline-none focus:ring-2 focus:ring-violet-400" required>
                    <button type="submit" id="newsletterSubmit" class="px-6 py-3 bg-violet-600 text-white font-semibold rounded-xl hover:bg-violet-700 transition-all hover:shadow-lg hover:shadow-violet-200 disabled:opacity-50 disabled:cursor-not-allowed">Inscrever</button>
                </form>
                <p class="text-xs text-slate-500 mt-4">Ao se inscrever, você concorda em receber conteúdos do PetFlow. Você pode cancelar a qualquer momento.</p>
            </div>

            <!-- Voltar -->
            <div class="mt-12 text-center animate-on-scroll" id="backToBlog">
                <a href="/blog/" class="inline-flex items-center gap-2 text-violet-600 font-semibold hover:text-violet-800 transition-colors group">
                    <svg class="w-5 h-5 group-hover:-translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                    Voltar para o blog
                </a>
            </div>
        </div>
    </main>

    <footer class="bg-slate-900 text-white py-12">
        <div class="container-blog">
            <div class="grid md:grid-cols-4 gap-8">
                <div>
                    <img src="https://app.petflow.pro/App/assets/images/logo.png" alt="PetFlow" class="w-12 h-12 mb-4">
                    <p class="text-slate-400 text-sm">Sistema completo para gestão de petshops</p>
                </div>
                <div>
                    <h4 class="font-semibold mb-4">Blog</h4>
                    <ul class="space-y-2 text-sm text-slate-400">
                        <li><a href="/blog/" class="hover:text-white transition">Últimos posts</a></li>
                        <li><a href="/blog/?categoria=marketing" class="hover:text-white transition">Marketing</a></li>
                        <li><a href="/blog/?categoria=gestao" class="hover:text-white transition">Gestão</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="font-semibold mb-4">PetFlow</h4>
                    <ul class="space-y-2 text-sm text-slate-400">
                        <li><a href="https://petflow.pro" class="hover:text-white transition">Site oficial</a></li>
                        <li><a href="https://app.petflow.pro" class="hover:text-white transition">Login</a></li>
                        <li><a href="https://petflow.pro/contato" class="hover:text-white transition">Contato</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="font-semibold mb-4">Redes Sociais</h4>
                    <div class="flex gap-4">
                        <a href="https://www.instagram.com/petflow.pro/" target="_blank" rel="noopener noreferrer" class="text-slate-400 hover:text-white transition text-2xl">📸</a>
                        <a href="#" class="text-slate-400 hover:text-white transition text-2xl">📘</a>
                    </div>
                </div>
            </div>
            <div class="border-t border-slate-800 mt-8 pt-8 text-center text-sm text-slate-400">
                <p>&copy; <?= date('Y') ?> PetFlow. Todos os direitos reservados.</p>
            </div>
        </div>
    </footer>

    <script>
        // ── Share ─────────────────────────────────────────────────────────────
        const PAGE_URL   = encodeURIComponent(window.location.href);
        const PAGE_TITLE = encodeURIComponent(document.title);

        function shareVia(platform) {
            const urls = {
                whatsapp: `https://wa.me/?text=${PAGE_TITLE}%20${PAGE_URL}`,
                telegram: `https://t.me/share/url?url=${PAGE_URL}&text=${PAGE_TITLE}`,
                facebook: `https://www.facebook.com/sharer/sharer.php?u=${PAGE_URL}`,
                twitter:  `https://twitter.com/intent/tweet?url=${PAGE_URL}&text=${PAGE_TITLE}`,
            };
            if (urls[platform]) window.open(urls[platform], '_blank', 'noopener,noreferrer,width=600,height=500');
        }

        function copyLink(btn) {
            const url = window.location.href;
            // No mobile abre o menu nativo de compartilhamento do sistema
            if (navigator.share) {
                navigator.share({ title: document.title, url }).catch(() => fallbackCopy(url));
                return;
            }
            fallbackCopy(url);
        }

        function fallbackCopy(url) {
            navigator.clipboard.writeText(url).then(() => {
                document.querySelectorAll('#inlineCopyBtn, #sidebarCopyBtn').forEach(b => {
                    const orig = b.innerHTML;
                    b.classList.add('copied');
                    if (b.id === 'inlineCopyBtn') {
                        b.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:1rem;height:1rem"><polyline points="20 6 9 17 4 12"/></svg> Link copiado!`;
                    } else {
                        b.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:1.1rem;height:1.1rem"><polyline points="20 6 9 17 4 12"/></svg>`;
                        b.setAttribute('data-tooltip', 'Copiado!');
                    }
                    setTimeout(() => {
                        b.classList.remove('copied');
                        b.innerHTML = orig;
                        if (b.id === 'sidebarCopyBtn') b.setAttribute('data-tooltip', 'Copiar link');
                    }, 2500);
                });
            }).catch(() => {
                const ta = document.createElement('textarea');
                ta.value = url; ta.style.cssText = 'position:fixed;opacity:0';
                document.body.appendChild(ta); ta.select();
                document.execCommand('copy'); document.body.removeChild(ta);
            });
        }

        // ── TOC ───────────────────────────────────────────────────────────────
        let tocOpen = false;

        function toggleToc() {
            const content = document.getElementById('tocContent');
            const header  = document.getElementById('tocHeader');
            tocOpen = !tocOpen;
            content.classList.toggle('show', tocOpen);
            header.classList.toggle('active', tocOpen);
            localStorage.setItem('tocOpen', tocOpen);
        }

        function closeTocAfterClick() {
            if (window.innerWidth <= 768) {
                setTimeout(() => {
                    document.getElementById('tocContent').classList.remove('show');
                    document.getElementById('tocHeader').classList.remove('active');
                    tocOpen = false;
                    localStorage.setItem('tocOpen', false);
                }, 300);
            }
        }

        function initToc() {
            const saved   = localStorage.getItem('tocOpen');
            const content = document.getElementById('tocContent');
            const header  = document.getElementById('tocHeader');
            if (!content || !header) return;
            const shouldOpen = saved === null ? window.innerWidth > 768 : saved === 'true';
            if (shouldOpen) { content.classList.add('show'); header.classList.add('active'); tocOpen = true; }
        }

        function toggleMobileMenu() {
            document.getElementById('mobileMenu').classList.toggle('hidden');
        }

        // ── Reading progress ──────────────────────────────────────────────────
        window.addEventListener('scroll', () => {
            const s = document.body.scrollTop || document.documentElement.scrollTop;
            const h = document.documentElement.scrollHeight - document.documentElement.clientHeight;
            document.getElementById('readingProgress').style.width = ((s / h) * 100) + '%';
        });

        // ── Smooth scroll ─────────────────────────────────────────────────────
        document.querySelectorAll('a[href^="#"]').forEach(a => {
            a.addEventListener('click', e => {
                e.preventDefault();
                const t = document.querySelector(a.getAttribute('href'));
                if (t) t.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        });

        // ── Intersection Observer ─────────────────────────────────────────────
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(e => { if (e.isIntersecting) e.target.classList.add('visible'); });
        }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });

        document.querySelectorAll('#authorCard,#coverImage,#tocAccordion,#shareInline,#newsletter,#backToBlog')
            .forEach(el => { if (el) observer.observe(el); });

        document.querySelectorAll('.prose-enhanced h2,.prose-enhanced h3,.prose-enhanced p,.prose-enhanced img,.prose-enhanced blockquote,.prose-enhanced ul,.prose-enhanced ol')
            .forEach((el, i) => {
                el.classList.add('animate-on-scroll');
                el.style.transitionDelay = Math.min(i * 0.05, 0.3) + 's';
                observer.observe(el);
            });

        // Sidebar aparece após 1s
        setTimeout(() => {
            const s = document.getElementById('shareSidebar');
            if (s) s.classList.add('visible');
        }, 1000);

        // ── Newsletter ────────────────────────────────────────────────────────
        const nForm  = document.getElementById('newsletterForm');
        const nMsg   = document.getElementById('newsletterMessage');
        const nBtn   = document.getElementById('newsletterSubmit');
        const nEmail = document.getElementById('newsletterEmail');

        function showMsg(msg, type) {
            if (!nMsg) return;
            nMsg.textContent = msg;
            nMsg.classList.remove('hidden','bg-green-100','text-green-800','bg-red-100','text-red-800');
            nMsg.classList.add(type === 'success' ? 'bg-green-100' : 'bg-red-100', type === 'success' ? 'text-green-800' : 'text-red-800');
            setTimeout(() => nMsg.classList.add('hidden'), 5000);
        }

        if (nForm) {
            nForm.addEventListener('submit', async e => {
                e.preventDefault();
                const email = nEmail.value.trim();
                if (!email || !email.includes('@') || !email.includes('.')) { showMsg('Por favor, insira um e-mail válido.', 'error'); return; }
                nBtn.disabled = true; nBtn.textContent = 'Enviando...';
                try {
                    const res = await fetch('/blog/newsletter.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                        body: new URLSearchParams({ email }).toString()
                    });
                    if (!res.ok) throw new Error();
                    const data = await res.json();
                    if (data.success) { showMsg(data.message || 'Inscrição realizada com sucesso.', 'success'); nEmail.value = ''; }
                    else showMsg(data.message || 'Não foi possível concluir a inscrição.', 'error');
                } catch { showMsg('Erro de conexão. Tente novamente.', 'error'); }
                finally { nBtn.disabled = false; nBtn.textContent = 'Inscrever'; }
            });
        }

        document.addEventListener('DOMContentLoaded', initToc);
    </script>
</body>
</html>