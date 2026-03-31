<?php
declare(strict_types=1);

require_once '../../cadastro/config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['error' => 'Conexão com banco indisponível.']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'save';

$TBL_POSTS = 'blog_posts';
$TBL_CATS  = 'blog_categorias';

/**
 * Se quiser restringir domínios permitidos (CDN), preencha.
 * Se deixar vazio, aceita qualquer host http(s).
 * Ex: ['res.cloudinary.com','cdn.petflow.pro','images.unsplash.com']
 */
$ALLOWED_IMAGE_HOSTS = []; // [] = aceita qualquer host

function jsonOut($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function isValidHttpUrl(string $url): bool {
    if ($url === '') return true;
    if (!filter_var($url, FILTER_VALIDATE_URL)) return false;

    $parts = parse_url($url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return false;

    $scheme = strtolower((string)$parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) return false;

    return true;
}

function isAllowedHost(string $url, array $allowedHosts): bool {
    if ($url === '') return true;
    if (!$allowedHosts) return true;

    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) return false;

    $host = strtolower($host);
    foreach ($allowedHosts as $allowed) {
        $allowed = strtolower(trim((string)$allowed));
        if ($allowed === '') continue;

        if ($host === $allowed) return true;
        if (str_ends_with($host, '.' . $allowed)) return true; // subdomínios
    }
    return false;
}

if ($action === 'categorias') {
    $st = $pdo->query("SELECT id, nome FROM {$TBL_CATS} ORDER BY nome ASC");
    jsonOut($st->fetchAll(PDO::FETCH_ASSOC));
}

if ($action === 'list') {
    $sql = "
        SELECT p.id, p.titulo, p.slug, p.resumo, p.conteudo, p.id_categoria,
               p.imagem_capa,
               DATE_FORMAT(p.data_publicacao, '%Y-%m-%d') AS data_publicacao,
               c.nome AS categoria
          FROM {$TBL_POSTS} p
          LEFT JOIN {$TBL_CATS} c ON c.id = p.id_categoria
         ORDER BY p.id DESC
    ";
    $st = $pdo->query($sql);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['imagem_capa_url'] = $r['imagem_capa'] ?: null;
    }

    jsonOut($rows);
}

if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) jsonOut(['error' => 'ID inválido.'], 400);

    $st = $pdo->prepare("DELETE FROM {$TBL_POSTS} WHERE id = ?");
    $st->execute([$id]);

    jsonOut(['success' => true]);
}

if ($action === 'save') {
    $id          = (int)($_POST['id'] ?? 0);
    $titulo      = trim((string)($_POST['titulo'] ?? ''));
    $slug        = trim((string)($_POST['slug'] ?? ''));
    $resumo      = trim((string)($_POST['resumo'] ?? ''));
    $conteudo    = trim((string)($_POST['conteudo'] ?? ''));
    $idCategoria = (int)($_POST['id_categoria'] ?? 0);

    $imagemUrl   = trim((string)($_POST['imagem_capa'] ?? ''));
    $removerImg  = (int)($_POST['remover_imagem'] ?? 0) === 1;

    if ($titulo === '' || $slug === '' || $idCategoria <= 0) {
        jsonOut(['error' => 'Preencha título, slug e categoria.'], 400);
    }

    if ($removerImg) {
        $imagemUrl = '';
    }

    if (!isValidHttpUrl($imagemUrl)) {
        jsonOut(['error' => 'URL da imagem inválida. Use um link http(s) completo.'], 400);
    }

    if (!isAllowedHost($imagemUrl, $ALLOWED_IMAGE_HOSTS)) {
        jsonOut(['error' => 'Host da imagem não permitido pela regra de CDN.'], 400);
    }

    $imagemFinal = ($imagemUrl === '') ? null : $imagemUrl;

    if ($id > 0) {
        $sql = "UPDATE {$TBL_POSTS}
                   SET titulo = ?, slug = ?, resumo = ?, conteudo = ?, id_categoria = ?, imagem_capa = ?
                 WHERE id = ?";
        $st = $pdo->prepare($sql);
        $st->execute([$titulo, $slug, $resumo, $conteudo, $idCategoria, $imagemFinal, $id]);

        jsonOut(['success' => true, 'id' => $id]);
    } else {
        $sql = "INSERT INTO {$TBL_POSTS} (titulo, slug, resumo, conteudo, id_categoria, imagem_capa, data_publicacao)
                VALUES (?, ?, ?, ?, ?, ?, NOW())";
        $st = $pdo->prepare($sql);
        $st->execute([$titulo, $slug, $resumo, $conteudo, $idCategoria, $imagemFinal]);

        jsonOut(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
    }
}

jsonOut(['error' => 'Ação inválida.'], 400);
