<?php
session_start();
require_once 'config.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function moneyFromUSD($cents): string { return number_format(((int)$cents) / 100, 2, ',', '.'); }
function calcDiscountedValue(int $valorCentavos, $descontoPercent): int {
    $descontoPercent = (float)$descontoPercent;
    if ($descontoPercent <= 0) return $valorCentavos;
    $valor = (int) round($valorCentavos * (1 - ($descontoPercent / 100)));
    return max(0, $valor);
}

$sqlPlanos = "
    SELECT p.id AS plano_id, p.slug, p.nome, p.descricao_curta, p.ordem AS plano_ordem, p.is_ativo AS plano_is_ativo,
        pp.id AS preco_id, pp.periodicidade, pp.valor_centavos, pp.desconto_percent, pp.pagarme_price_id, pp.is_ativo AS preco_is_ativo,
        CASE WHEN pp.periodicidade = 'anual' THEN p.ordem + 1000 ELSE p.ordem END AS ordem_exibicao
    FROM petflow_planos p
    INNER JOIN petflow_plano_precos pp ON pp.id_plano = p.id
    WHERE p.is_ativo = 1 AND pp.is_ativo = 1
    ORDER BY ordem_exibicao ASC, p.id ASC, pp.periodicidade ASC
";
$stmtPlanos = $pdo->query($sqlPlanos);
$rowsPlanos = $stmtPlanos->fetchAll(PDO::FETCH_ASSOC);

$planosCards = [];
$planosIds = [];

foreach ($rowsPlanos as $row) {
    $idPlano = (int)$row['plano_id'];
    $periodicidade = strtolower(trim((string)$row['periodicidade']));
    if ($periodicidade !== 'mensal' && $periodicidade !== 'anual') continue;
    $valorCentavos = (int)$row['valor_centavos'];
    $descontoPercent = (float)$row['desconto_percent'];
    $valorFinalCentavos = calcDiscountedValue($valorCentavos, $descontoPercent);
    $valorMensalEquivalente = $periodicidade === 'anual' ? (int) round($valorFinalCentavos / 12) : $valorFinalCentavos;
    $economiaPercent = null;
    if ($periodicidade === 'anual') {
        $stmtMensal = $pdo->prepare("SELECT valor_centavos, desconto_percent FROM petflow_plano_precos WHERE id_plano = ? AND periodicidade = 'mensal' AND is_ativo = 1 LIMIT 1");
        $stmtMensal->execute([$idPlano]);
        $mensalData = $stmtMensal->fetch(PDO::FETCH_ASSOC);
        if ($mensalData) {
            $valorMensalFinal = calcDiscountedValue((int)$mensalData['valor_centavos'], (float)$mensalData['desconto_percent']);
            $valorMensalTotalAno = $valorMensalFinal * 12;
            if ($valorFinalCentavos < $valorMensalTotalAno && $valorMensalTotalAno > 0) {
                $economiaPercent = (int) round((1 - ($valorFinalCentavos / $valorMensalTotalAno)) * 100);
            }
        }
    }
    $cardKey = $idPlano . '_' . $periodicidade;
    $planosCards[$cardKey] = [
        'id' => $idPlano, 'plano_nome' => (string)$row['nome'], 'descricao_curta' => (string)$row['descricao_curta'],
        'periodicidade' => $periodicidade, 'preco_id' => (int)$row['preco_id'], 'valor_centavos' => $valorCentavos,
        'desconto_percent' => $descontoPercent, 'valor_final_centavos' => $valorFinalCentavos,
        'valor_mensal_equivalente_centavos' => $valorMensalEquivalente, 'pagarme_price_id' => (string)$row['pagarme_price_id'],
        'economia_percent' => $economiaPercent,
        'badge' => $periodicidade === 'anual' ? ($economiaPercent ? "Ahorrás {$economiaPercent}%" : 'Mejor precio') : null,
        'ordem' => (int)$row['ordem_exibicao'], 'features' => [],
    ];
    $planosIds[] = $idPlano;
}

$planosIds = array_unique($planosIds);

if (!empty($planosIds)) {
    $placeholders = implode(',', array_fill(0, count($planosIds), '?'));
    $sqlFeatures = "
        SELECT pf.id_plano, pf.id_feature, pf.is_incluso, pf.limite_int, pf.limite_texto, pf.observacao,
            f.chave, f.nome_exibicao, f.descricao_exibicao, f.grupo, f.ordem
        FROM petflow_plano_features pf INNER JOIN petflow_features f ON f.id = pf.id_feature
        WHERE pf.id_plano IN ($placeholders)
        ORDER BY pf.id_plano ASC, f.grupo ASC, f.ordem ASC, f.id ASC
    ";
    $stmtFeatures = $pdo->prepare($sqlFeatures);
    $stmtFeatures->execute($planosIds);
    while ($f = $stmtFeatures->fetch(PDO::FETCH_ASSOC)) {
        $idPlano = (int)$f['id_plano'];
        $limiteTextoFinal = '';
        if (!is_null($f['limite_texto']) && trim((string)$f['limite_texto']) !== '') $limiteTextoFinal = trim((string)$f['limite_texto']);
        elseif (!is_null($f['limite_int']) && (int)$f['limite_int'] > 0) $limiteTextoFinal = (string)(int)$f['limite_int'];
        foreach ($planosCards as &$card) {
            if ($card['id'] === $idPlano) {
                $card['features'][] = [
                    'id_feature' => (int)$f['id_feature'], 'chave' => (string)$f['chave'],
                    'nome_exibicao' => (string)$f['nome_exibicao'], 'descricao_exibicao' => (string)$f['descricao_exibicao'],
                    'grupo' => (string)$f['grupo'], 'ordem' => (int)$f['ordem'], 'is_incluso' => (int)$f['is_incluso'],
                    'limite_int' => is_null($f['limite_int']) ? null : (int)$f['limite_int'],
                    'limite_texto' => $f['limite_texto'], 'limite_exibicao' => $limiteTextoFinal, 'observacao' => (string)$f['observacao'],
                ];
            }
        }
        unset($card);
    }
}

$planosCards = array_values($planosCards);
usort($planosCards, function($a, $b) { return $a['ordem'] <=> $b['ordem']; });

$cad_ok   = isset($_SESSION['cadastro_ok']) ? (int)$_SESSION['cadastro_ok'] : null;
$cad_msg  = $_SESSION['cadastro_msg']   ?? null;
$cad_mail = $_SESSION['cadastro_email'] ?? null;
$app_auto = isset($_SESSION['app_autoredirect']) ? (int)$_SESSION['app_autoredirect'] : 0;
$app_url  = $_SESSION['app_url'] ?? null;
$erro_legado = $_SESSION['erro_cadastro'] ?? null;
unset($_SESSION['cadastro_ok'], $_SESSION['cadastro_msg'], $_SESSION['cadastro_email'], $_SESSION['app_autoredirect'], $_SESSION['app_url'], $_SESSION['erro_cadastro']);

$planosJs = [];
$ofertasJs = [];
foreach ($planosCards as $card) {
    $planosJs[$card['preco_id']] = [
        'plano_id' => $card['id'], 'plano_nome' => $card['plano_nome'], 'periodicidade' => $card['periodicidade'],
        'preco_id' => $card['preco_id'], 'valor_centavos' => $card['valor_centavos'],
        'valor_final_centavos' => $card['valor_final_centavos'],
        'valor_mensal_equivalente_centavos' => $card['valor_mensal_equivalente_centavos'],
        'desconto_percent' => $card['desconto_percent'], 'pagarme_price_id' => $card['pagarme_price_id'],
        'economia_percent' => $card['economia_percent'], 'badge' => $card['badge'],
    ];
    $ofertasJs[(string)$card['preco_id']] = $planosJs[$card['preco_id']];
}

// Provincias argentinas
$provincias = [
    'BA' => 'Buenos Aires', 'CA' => 'Catamarca', 'CH' => 'Chaco', 'CT' => 'Chubut',
    'CB' => 'Córdoba', 'CR' => 'Corrientes', 'ER' => 'Entre Ríos', 'FO' => 'Formosa',
    'JY' => 'Jujuy', 'LP' => 'La Pampa', 'LR' => 'La Rioja', 'MZ' => 'Mendoza',
    'MS' => 'Misiones', 'NQ' => 'Neuquén', 'RN' => 'Río Negro', 'SA' => 'Salta',
    'SJ' => 'San Juan', 'SL' => 'San Luis', 'SC' => 'Santa Cruz', 'SF' => 'Santa Fe',
    'SE' => 'Santiago del Estero', 'TF' => 'Tierra del Fuego', 'TU' => 'Tucumán',
    'CABA' => 'Ciudad Autónoma de Buenos Aires',
];
?>
<!DOCTYPE html>
<html lang="es-AR">
<head>
  <!-- GTM -->
  <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','GTM-NC72BC4V');</script>
  <!-- Meta Pixel -->
  <script>
    window.__pfMetaPixelInitialized=window.__pfMetaPixelInitialized||false;
    window.__pfMetaPageViewTracked=window.__pfMetaPageViewTracked||false;
    !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
    if(!window.__pfMetaPixelInitialized){fbq('init','2586149598426015');window.__pfMetaPixelInitialized=true;}
    if(!window.__pfMetaPageViewTracked){fbq('track','PageView');window.__pfMetaPageViewTracked=true;}
  </script>
  <noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=2586149598426015&ev=PageView&noscript=1"/></noscript>

  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Registro — PetFlow.PRO</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
  <script src="https://cdn.tailwindcss.com"></script>
  <script async src="https://www.googletagmanager.com/gtag/js?id=AW-17761390239"></script>
  <script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','AW-17761390239');</script>
  <script async src="https://www.googletagmanager.com/gtag/js?id=G-B7KZ8KTJT9"></script>
  <script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','G-B7KZ8KTJT9');</script>

  <style>
    :root {
      --c-brand: #4f46e5;
      --c-brand-dark: #3730a3;
      --c-brand-light: #eef2ff;
      --c-green: #059669;
      --c-green-dark: #047857;
      --c-green-light: #ecfdf5;
      --c-amber: #d97706;
      --c-surface: #ffffff;
      --c-bg: #f8f7ff;
      --c-border: #e5e7eb;
      --c-text: #111827;
      --c-muted: #6b7280;
      --c-error: #dc2626;
      --radius: 14px;
      --radius-sm: 8px;
      --shadow-card: 0 2px 16px rgba(79,70,229,.07), 0 1px 4px rgba(0,0,0,.04);
      --shadow-card-hover: 0 8px 32px rgba(79,70,229,.14), 0 2px 8px rgba(0,0,0,.06);
      --font-display: 'Plus Jakarta Sans', sans-serif;
      --font-body: 'DM Sans', sans-serif;
    }

    *, *::before, *::after { box-sizing: border-box; }
    html { scroll-behavior: smooth; }
    body { font-family: var(--font-body); background: var(--c-bg); color: var(--c-text); min-height: 100vh; }

    .page-header { background: #fff; border-bottom: 1px solid var(--c-border); position: sticky; top: 0; z-index: 50; }
    .page-header-inner { max-width: 720px; margin: 0 auto; padding: 0 1.25rem; height: 60px; display: flex; align-items: center; gap: .75rem; }
    .logo-mark { width: 36px; height: 36px; border-radius: 10px; box-shadow: 0 2px 8px rgba(79,70,229,.2); }
    .logo-text { font-family: var(--font-display); font-weight: 800; font-size: 1.05rem; letter-spacing: -.02em; color: var(--c-brand); }
    .trust-badges { margin-left: auto; display: flex; align-items: center; gap: .625rem; }
    .trust-badge { display: flex; align-items: center; gap: .3rem; font-size: .72rem; color: var(--c-muted); font-weight: 500; }
    .trust-dot { width: 7px; height: 7px; border-radius: 50%; background: #10b981; }

    .progress-wrap { background: #fff; border-bottom: 1px solid var(--c-border); }
    .progress-inner { max-width: 720px; margin: 0 auto; padding: .75rem 1.25rem; }
    .step-list { display: flex; gap: 0; margin-bottom: .5rem; align-items: flex-start; }
    .step-item { flex: 1; display: flex; flex-direction: column; align-items: center; gap: .2rem; cursor: default; min-width: 0; }
    .step-dot { width: 26px; height: 26px; border-radius: 50%; border: 2px solid var(--c-border); display: flex; align-items: center; justify-content: center; font-size: .68rem; font-weight: 700; color: var(--c-muted); background: #fff; transition: all .25s; font-family: var(--font-display); position: relative; z-index: 1; flex-shrink: 0; }
    .step-label { font-size: .6rem; font-weight: 600; color: var(--c-muted); text-align: center; letter-spacing: .01em; transition: color .25s; word-break: break-word; hyphens: auto; line-height: 1.2; max-width: 52px; }
    .step-connector { flex: 1; height: 2px; background: var(--c-border); margin-top: 13px; transition: background .25s; flex-shrink: 1; min-width: 4px; }
    .step-item.done .step-dot { background: var(--c-brand); border-color: var(--c-brand); color: #fff; }
    .step-item.done .step-label { color: var(--c-brand); }
    .step-item.active .step-dot { background: var(--c-brand); border-color: var(--c-brand); color: #fff; box-shadow: 0 0 0 3px rgba(79,70,229,.15); }
    .step-item.active .step-label { color: var(--c-brand); font-weight: 700; }
    .step-connector.done { background: var(--c-brand); }
    .progress-bar-outer { height: 3px; background: #e5e7eb; border-radius: 99px; overflow: hidden; }
    .progress-bar-inner { height: 100%; background: linear-gradient(90deg, var(--c-brand), #818cf8); border-radius: 99px; transition: width .4s cubic-bezier(.4,0,.2,1); }
    @media (max-width: 360px) { .step-label { display: none; } .step-dot { width: 22px; height: 22px; font-size: .6rem; } .step-connector { margin-top: 11px; } }

    .main-content { max-width: 720px; margin: 0 auto; padding: 1.25rem 1rem 3rem; }
    @media (min-width: 480px) { .main-content { padding: 1.75rem 1.25rem 3rem; } }
    .form-card { background: var(--c-surface); border-radius: 20px; box-shadow: var(--shadow-card); padding: 1.5rem; }
    @media (min-width: 480px) { .form-card { padding: 2rem; } }
    @media (max-width: 480px) { .form-card { border-radius: 14px; } }

    .step-header { margin-bottom: 1.5rem; }
    .step-icon-wrap { width: 44px; height: 44px; border-radius: 12px; background: var(--c-brand-light); display: flex; align-items: center; justify-content: center; margin-bottom: .75rem; }
    .step-title { font-family: var(--font-display); font-size: 1.3rem; font-weight: 800; color: var(--c-text); letter-spacing: -.02em; }
    .step-subtitle { font-size: .875rem; color: var(--c-muted); margin-top: .25rem; }

    .field-group { display: grid; gap: 1rem; }
    .field-group.cols-2 { grid-template-columns: 1fr 1fr; }
    .field-group.cols-3 { grid-template-columns: 1fr 1fr 1fr; }
    @media (max-width: 600px) {
      .field-group.cols-2, .field-group.cols-3 { grid-template-columns: 1fr; }
      .field-group.cols-2 .field[style*="grid-column"],
      .field-group.cols-3 .field[style*="grid-column"] { grid-column: 1 / -1 !important; }
    }

    .field { display: flex; flex-direction: column; gap: .3rem; }
    .field label { font-size: .8rem; font-weight: 600; color: #374151; letter-spacing: .01em; }
    .field label .opt { font-weight: 400; color: var(--c-muted); }
    .field-hint { font-size: .73rem; color: var(--c-muted); }
    .field-error { font-size: .73rem; color: var(--c-error); display: none; align-items: center; gap: .25rem; }
    .field-error.visible { display: flex; }

    .f-input {
      height: 46px; border: 1.5px solid var(--c-border); border-radius: var(--radius-sm);
      padding: 0 .875rem; font-size: .875rem; font-family: var(--font-body);
      color: var(--c-text); background: #fff; width: 100%;
      transition: border-color .15s, box-shadow .15s; outline: none;
    }
    .f-input:focus { border-color: var(--c-brand); box-shadow: 0 0 0 3px rgba(79,70,229,.1); }
    .f-input.has-error { border-color: var(--c-error); box-shadow: 0 0 0 3px rgba(220,38,38,.08); }
    .f-input::placeholder { color: #9ca3af; }
    .f-input:read-only { background: #f9fafb; cursor: default; color: var(--c-muted); }
    select.f-input { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%239ca3af' d='M1 1l5 5 5-5'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; padding-right: 2rem; cursor: pointer; }

    .pwd-meter { height: 4px; background: #e5e7eb; border-radius: 99px; overflow: hidden; margin-top: .375rem; }
    .pwd-bar { height: 100%; border-radius: 99px; transition: width .3s, background .3s; }
    .pwd-label { font-size: .72rem; color: var(--c-muted); margin-top: .25rem; }
    .toggle-pwd { position: absolute; right: .75rem; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--c-muted); padding: .25rem; display: flex; align-items: center; }

    /* USD badge */
    .usd-banner {
      background: linear-gradient(135deg,#fefce8,#fef9c3);
      border: 1.5px solid #fde047; border-radius: var(--radius-sm);
      padding: .75rem 1rem; margin-bottom: 1.25rem;
      display: flex; align-items: flex-start; gap: .625rem; font-size: .8rem; color: #713f12;
    }
    .usd-banner svg { flex-shrink: 0; margin-top: .1rem; }

    .plans-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
    @media (max-width: 520px) { .plans-grid { grid-template-columns: 1fr; } }

    .plan-card {
      border: 2px solid var(--c-border); border-radius: var(--radius);
      background: #fff; display: flex; flex-direction: column;
      padding: 1.375rem; cursor: pointer; transition: all .2s;
      position: relative; overflow: hidden;
    }
    @media (max-width: 520px) { .plan-card { padding: 1.125rem; } }
    .plan-card::before {
      content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
      background: linear-gradient(90deg, #c7d2fe, var(--c-brand)); opacity: 0; transition: opacity .2s;
    }
    .plan-card.anual::before { background: linear-gradient(90deg, #a7f3d0, var(--c-green)); }
    .plan-card:hover { border-color: #c7d2fe; box-shadow: var(--shadow-card-hover); transform: translateY(-2px); }
    .plan-card:hover::before { opacity: 1; }
    .plan-card.selected { border-color: var(--c-brand); background: linear-gradient(160deg, #f5f3ff 0%, #fff 60%); box-shadow: 0 0 0 3px rgba(79,70,229,.12), var(--shadow-card-hover); }
    .plan-card.selected::before { opacity: 1; }
    .plan-card.anual.selected { border-color: var(--c-green); background: linear-gradient(160deg, #f0fdf4 0%, #fff 60%); box-shadow: 0 0 0 3px rgba(5,150,105,.12), var(--shadow-card-hover); }

    .plan-tag { display: inline-flex; align-items: center; gap: .3rem; font-size: .68rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; padding: .2rem .6rem; border-radius: 99px; }
    .plan-tag.mensal { background: #eef2ff; color: var(--c-brand); }
    .plan-tag.anual { background: #ecfdf5; color: var(--c-green); }
    .plan-tag-row { display: flex; align-items: center; justify-content: space-between; margin-bottom: .875rem; }
    .plan-savings-badge { display: inline-flex; align-items: center; gap: .2rem; font-size: .68rem; font-weight: 700; padding: .2rem .6rem; border-radius: 99px; background: #fef3c7; color: #92400e; letter-spacing: .02em; }
    .plan-popular-badge { background: var(--c-brand); color: #fff; font-size: .68rem; font-weight: 700; padding: .2rem .6rem; border-radius: 99px; letter-spacing: .02em; }

    .plan-name { font-family: var(--font-display); font-size: 1.05rem; font-weight: 800; color: var(--c-text); margin-bottom: .2rem; }
    .plan-desc { font-size: .78rem; color: var(--c-muted); margin-bottom: .875rem; line-height: 1.4; }
    .plan-price-old { font-size: .78rem; color: var(--c-muted); text-decoration: line-through; margin-bottom: .15rem; }
    .plan-price-main { display: flex; align-items: baseline; gap: .25rem; }
    .plan-price-value { font-family: var(--font-display); font-size: 2rem; font-weight: 800; line-height: 1; }
    @media (max-width: 400px) { .plan-price-value { font-size: 1.6rem; } }
    .plan-card.mensal .plan-price-value { color: var(--c-brand); }
    .plan-card.anual .plan-price-value { color: var(--c-green); }
    .plan-price-period { font-size: .82rem; color: var(--c-muted); }
    .plan-equiv { font-size: .78rem; color: var(--c-green); font-weight: 600; margin-top: .25rem; }

    .plan-divider { height: 1px; background: var(--c-border); margin: .875rem 0; }
    .plan-features { flex: 1; display: flex; flex-direction: column; gap: .35rem; margin-bottom: 1rem; }
    .plan-feature { display: flex; align-items: flex-start; gap: .5rem; font-size: .8rem; color: #374151; line-height: 1.4; }
    .plan-feature-icon { width: 16px; height: 16px; border-radius: 50%; background: #d1fae5; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: .1rem; }
    .plan-feature-icon svg { width: 9px; height: 9px; color: var(--c-green); }
    .plan-more { font-size: .75rem; color: var(--c-brand); font-weight: 600; margin-top: .25rem; }

    .plan-btn {
      width: 100%; height: 44px; border-radius: 99px; font-family: var(--font-display);
      font-size: .82rem; font-weight: 700; border: 2px solid transparent;
      cursor: pointer; transition: all .2s; display: flex; align-items: center; justify-content: center; gap: .4rem;
    }
    .plan-card.mensal .plan-btn { background: var(--c-brand); color: #fff; }
    .plan-card.mensal .plan-btn:hover { background: var(--c-brand-dark); }
    .plan-card.anual .plan-btn { background: var(--c-green); color: #fff; }
    .plan-card.anual .plan-btn:hover { background: var(--c-green-dark); }
    .plan-card.selected .plan-btn { background: transparent !important; }
    .plan-card.mensal.selected .plan-btn { border-color: var(--c-brand); color: var(--c-brand); }
    .plan-card.anual.selected .plan-btn { border-color: var(--c-green); color: var(--c-green); }
    .check-icon { width: 16px; height: 16px; }

    .preselect-banner {
      background: linear-gradient(135deg, #eef2ff, #f5f3ff);
      border: 2px solid var(--c-brand); border-radius: var(--radius);
      padding: 1rem 1.125rem; margin-bottom: 1.25rem;
      display: flex; align-items: center; gap: .875rem; flex-wrap: wrap;
    }
    .preselect-icon { width: 38px; height: 38px; background: var(--c-brand); border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .preselect-text { flex: 1; min-width: 0; }
    .preselect-title { font-family: var(--font-display); font-weight: 700; font-size: .88rem; color: var(--c-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .preselect-value { font-size: .78rem; color: var(--c-muted); margin-top: .1rem; }
    .preselect-change { font-size: .8rem; font-weight: 600; color: var(--c-brand); text-decoration: underline; text-underline-offset: 2px; cursor: pointer; white-space: nowrap; background: none; border: none; font-family: var(--font-body); padding: 0; }
    @media (max-width: 420px) { .preselect-banner { flex-direction: column; align-items: flex-start; } .preselect-title { white-space: normal; } }

    .order-summary {
      background: linear-gradient(135deg, #f5f3ff, #eef2ff);
      border: 1.5px solid #c7d2fe; border-radius: var(--radius-sm);
      padding: .875rem 1rem; margin-bottom: 1rem; display: none;
    }
    .order-summary.visible { display: block; }
    .order-summary-label { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--c-brand); margin-bottom: .375rem; }
    .order-summary-text { font-family: var(--font-display); font-size: .88rem; font-weight: 700; color: var(--c-text); }

    .section-title { font-family: var(--font-display); font-size: 1rem; font-weight: 700; color: var(--c-text); margin-bottom: .875rem; display: flex; align-items: center; gap: .5rem; }
    .section-title svg { color: var(--c-muted); }
    .section-divider { height: 1px; background: var(--c-border); margin: 1.375rem 0; }

    .trust-bar { margin-top: 1.25rem; background: #f9fafb; border: 1.5px solid var(--c-border); border-radius: var(--radius-sm); padding: .875rem 1rem; }
    .trust-bar-items { display: flex; flex-wrap: wrap; gap: .75rem 1.25rem; align-items: center; }
    .trust-item { display: flex; align-items: center; gap: .35rem; font-size: .75rem; color: var(--c-muted); font-weight: 500; }
    .trust-item svg { color: var(--c-green); width: 14px; height: 14px; }

    .notice-box { background: var(--c-brand-light); border: 1.5px solid #c7d2fe; border-radius: var(--radius-sm); padding: 1rem; }
    .notice-box p { font-size: .8rem; color: #3730a3; line-height: 1.5; }
    .notice-box ul { margin-top: .4rem; list-style: none; padding: 0; display: flex; flex-direction: column; gap: .2rem; }
    .notice-box li { font-size: .8rem; color: #4338ca; display: flex; align-items: center; gap: .4rem; }
    .notice-box li::before { content: '✓'; font-weight: 700; color: var(--c-green); }

    .checkbox-row { display: flex; align-items: flex-start; gap: .75rem; }
    .checkbox-custom { width: 18px; height: 18px; border: 2px solid var(--c-border); border-radius: 5px; flex-shrink: 0; margin-top: .1rem; cursor: pointer; appearance: none; display: grid; place-content: center; transition: all .15s; }
    .checkbox-custom:checked { background: var(--c-brand); border-color: var(--c-brand); }
    .checkbox-custom:checked::after { content: ''; width: 9px; height: 6px; background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 9 6'%3E%3Cpath fill='white' d='M1 3l2.5 2.5L8 1'/%3E%3C/svg%3E") center/contain no-repeat; }
    .checkbox-label { font-size: .82rem; color: #374151; line-height: 1.5; }
    .checkbox-label a { color: var(--c-brand); text-decoration: underline; text-underline-offset: 2px; }

    .form-nav { display: flex; align-items: center; gap: .75rem; margin-top: 1.75rem; flex-wrap: wrap; }
    .btn-back { height: 48px; padding: 0 1.25rem; border-radius: var(--radius-sm); border: 2px solid var(--c-border); background: #fff; font-family: var(--font-display); font-weight: 600; font-size: .875rem; color: #374151; cursor: pointer; transition: all .15s; display: flex; align-items: center; gap: .4rem; white-space: nowrap; flex-shrink: 0; }
    .btn-back:hover { border-color: #9ca3af; background: #f9fafb; }
    .btn-back:disabled { opacity: .4; cursor: default; }
    .btn-next { flex: 1; min-width: 140px; height: 48px; border-radius: var(--radius-sm); border: none; background: var(--c-brand); color: #fff; font-family: var(--font-display); font-weight: 700; font-size: .9rem; cursor: pointer; transition: all .2s; display: flex; align-items: center; justify-content: center; gap: .5rem; box-shadow: 0 2px 8px rgba(79,70,229,.3); }
    .btn-next:hover { background: var(--c-brand-dark); box-shadow: 0 4px 16px rgba(79,70,229,.4); transform: translateY(-1px); }
    .btn-next:disabled { opacity: .5; cursor: default; transform: none; box-shadow: none; }
    .btn-submit { flex: 1; min-width: 140px; height: 48px; border-radius: var(--radius-sm); border: none; background: linear-gradient(135deg, var(--c-green), #10b981); color: #fff; font-family: var(--font-display); font-weight: 700; font-size: .9rem; cursor: pointer; transition: all .2s; display: flex; align-items: center; justify-content: center; gap: .5rem; box-shadow: 0 2px 8px rgba(5,150,105,.3); }
    .btn-submit:hover { background: linear-gradient(135deg, var(--c-green-dark), var(--c-green)); box-shadow: 0 4px 16px rgba(5,150,105,.4); transform: translateY(-1px); }
    .btn-submit:disabled { opacity: .5; cursor: default; transform: none; }
    .secure-note { text-align: center; font-size: .72rem; color: var(--c-muted); margin-top: .75rem; display: flex; align-items: center; justify-content: center; gap: .3rem; }
    @media (max-width: 380px) { .form-nav { flex-direction: column; } .btn-back, .btn-next, .btn-submit { width: 100%; min-width: 0; flex: none; } }

    .toast-wrap { position: fixed; top: 1rem; left: 50%; transform: translateX(-50%); z-index: 9999; pointer-events: none; display: flex; flex-direction: column; align-items: center; gap: .5rem; width: calc(100% - 2rem); max-width: 360px; }
    .toast { padding: .6rem 1.25rem; border-radius: 99px; font-size: .8rem; font-weight: 600; font-family: var(--font-display); box-shadow: 0 4px 20px rgba(0,0,0,.15); opacity: 0; transform: translateY(-8px); transition: all .25s; pointer-events: none; text-align: center; width: 100%; }
    .toast.show { opacity: 1; transform: translateY(0); }
    .toast.info { background: #1e1b4b; color: #fff; }
    .toast.success { background: #065f46; color: #fff; }
    .toast.error { background: #991b1b; color: #fff; }

    #spinner { display: none; position: fixed; inset: 0; background: rgba(255,255,255,.7); z-index: 9998; backdrop-filter: blur(2px); align-items: center; justify-content: center; }
    .spinner-ring { width: 48px; height: 48px; border: 3px solid #e5e7eb; border-top-color: var(--c-brand); border-radius: 50%; animation: spin .8s linear infinite; }
    @keyframes spin { to { transform: rotate(360deg); } }

    .alert { border-radius: var(--radius-sm); padding: .875rem 1rem; font-size: .84rem; margin-bottom: 1.25rem; }
    .alert.success { background: #ecfdf5; border: 1.5px solid #6ee7b7; color: #065f46; }
    .alert.error { background: #fef2f2; border: 1.5px solid #fca5a5; color: #991b1b; }
    .alert-title { font-weight: 700; margin-bottom: .2rem; }

    footer { background: #fff; border-top: 1px solid var(--c-border); margin-top: 3rem; }
    .footer-inner { max-width: 720px; margin: 0 auto; padding: 2.5rem 1.25rem; display: grid; grid-template-columns: repeat(3, 1fr); gap: 2rem; }
    @media (max-width: 600px) { .footer-inner { grid-template-columns: 1fr; gap: 1.5rem; } }
    .footer-col-title { font-family: var(--font-display); font-size: .75rem; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; color: var(--c-brand); margin-bottom: .75rem; }
    .footer-col p, .footer-col li, .footer-col a { font-size: .8rem; color: var(--c-muted); line-height: 1.6; }
    .footer-col a:hover { color: var(--c-brand); }
    .footer-col ul { list-style: none; padding: 0; display: flex; flex-direction: column; gap: .35rem; }

    .shake { animation: shake .12s linear 3; }
    @keyframes shake { 0%,100% { transform: translateX(0); } 33% { transform: translateX(-3px); } 66% { transform: translateX(3px); } }

    .input-wrap { position: relative; }
    .input-wrap .f-input { width: 100%; }

    /* DNI helper badge */
    .field-badge { display: inline-flex; align-items: center; gap: .25rem; font-size: .68rem; background: #e0e7ff; color: #3730a3; border-radius: 99px; padding: .15rem .5rem; font-weight: 700; margin-left: .35rem; vertical-align: middle; }
  </style>
</head>
<body>
  <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-NC72BC4V" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>

  <script>(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y)})(window,document,"clarity","script","uziu822qdz");</script>

  <header class="page-header">
    <div class="page-header-inner">
      <img src="https://app.petflow.pro/App/assets/images/logo.png" alt="PetFlow.PRO" class="logo-mark"/>
      <span class="logo-text">PetFlow.PRO</span>
      <div class="trust-badges">
        <span class="trust-badge hidden sm:flex"><span class="trust-dot"></span> Entorno seguro</span>
        <span class="trust-badge hidden md:flex" style="color:#9ca3af">•</span>
        <span class="trust-badge hidden md:flex">Soporte en minutos</span>
      </div>
    </div>
  </header>

  <div class="progress-wrap">
    <div class="progress-inner">
      <div class="step-list" id="stepList"></div>
      <div class="progress-bar-outer" style="margin-top:.5rem">
        <div class="progress-bar-inner" id="progressBar" style="width:20%"></div>
      </div>
    </div>
  </div>

  <div class="toast-wrap" id="toastWrap">
    <div class="toast" id="toast"></div>
  </div>

  <main class="main-content">

    <?php if (!is_null($cad_ok) && $cad_msg): ?>
      <?php if ($cad_ok): ?>
        <script>fbq('track','CompleteRegistration');</script>
        <div class="alert success">
          <div class="alert-title">¡Todo listo! 🎉</div>
          <div><?= h($cad_msg) ?></div>
          <?php if ($app_url): ?>
            <div style="margin-top:.5rem;font-size:.8rem">Redirigiendo automáticamente… <a href="<?= h($app_url) ?>" style="color:var(--c-green);font-weight:600;text-decoration:underline">Hacé clic aquí</a> si no fuiste redirigido.</div>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="alert error"><div><?= h($cad_msg) ?></div></div>
      <?php endif; ?>
    <?php elseif (!empty($erro_legado)): ?>
      <div class="alert error"><div><?= h($erro_legado) ?></div></div>
    <?php endif; ?>

    <div class="form-card">
      <form id="formCadastro" method="POST" action="processar_cadastro.php" autocomplete="off" novalidate>

        <!-- ════ STEP 1 — RESPONSABLE ════ -->
        <fieldset data-step="1" class="step" style="border:none;padding:0;margin:0">
          <div class="step-header">
            <div class="step-icon-wrap">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#4f46e5" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
            </div>
            <div class="step-title">Datos del responsable</div>
            <div class="step-subtitle">Como figura en tu documento de identidad.</div>
          </div>

          <div class="field-group cols-2">
            <div class="field" style="grid-column:1/-1">
              <label for="nombre_completo">Nombre y apellido</label>
              <input class="f-input" type="text" id="nombre_completo" name="nombre_completo" required placeholder="Tu nombre completo"/>
              <span class="field-error" id="err-nombre_completo">⚠ Ingresá tu nombre completo.</span>
            </div>
            <div class="field">
              <label for="fecha_nacimiento">Fecha de nacimiento</label>
              <input class="f-input" type="date" id="fecha_nacimiento" name="fecha_nacimiento" required/>
              <span class="field-error" id="err-fecha_nacimiento">⚠ Ingresá fecha de nacimiento (18+ años).</span>
            </div>
            <div class="field">
              <label for="sexo_biologico">Sexo biológico</label>
              <select class="f-input" id="sexo_biologico" name="sexo_biologico" required>
                <option value="">Seleccionar</option>
                <option value="masculino">Masculino</option>
                <option value="femenino">Femenino</option>
                <option value="otro">Otro</option>
              </select>
              <span class="field-error" id="err-sexo_biologico">⚠ Seleccioná una opción.</span>
            </div>
            <div class="field">
              <label for="dni">
                DNI
                <span class="field-badge">AR</span>
              </label>
              <input class="f-input" type="text" id="dni" name="dni" required inputmode="numeric" placeholder="12.345.678" maxlength="10"/>
              <span class="field-hint">Documento Nacional de Identidad, sin puntos.</span>
              <span class="field-error" id="err-dni">⚠ DNI inválido (7 u 8 dígitos).</span>
            </div>
            <div class="field" style="grid-column:1/-1">
              <label for="telefono_celular">Celular con código de área</label>
              <input class="f-input" type="text" id="telefono_celular" name="telefono_celular" required inputmode="numeric" placeholder="11 2345-6789"/>
              <span class="field-hint">Ej: 11 2345-6789 (CABA) · 351 234-5678 (Córdoba). Sin el 0 ni el 15.</span>
              <span class="field-error" id="err-telefono_celular">⚠ Teléfono inválido.</span>
            </div>
          </div>
        </fieldset>

        <!-- ════ STEP 2 — DOMICILIO ════ -->
        <fieldset data-step="2" class="step hidden" style="border:none;padding:0;margin:0">
          <div class="step-header">
            <div class="step-icon-wrap">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#4f46e5" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            </div>
            <div class="step-title">Domicilio del responsable</div>
            <div class="step-subtitle">Ingresá tu Código Postal para completar los datos automáticamente.</div>
          </div>

          <div class="field-group cols-3">
            <div class="field">
              <label for="codigo_postal_usuario">Código Postal</label>
              <input class="f-input" type="text" id="codigo_postal_usuario" name="codigo_postal_usuario" required inputmode="numeric" placeholder="1425" maxlength="8"/>
              <span class="field-hint">Ej: 1425 (CABA) · 5000 (Cba)</span>
              <span class="field-error" id="err-codigo_postal_usuario">⚠ Código Postal inválido.</span>
            </div>
            <div class="field" style="grid-column:2/-1">
              <label for="provincia_usuario">Provincia</label>
              <select class="f-input" id="provincia_usuario" name="provincia_usuario" required>
                <option value="">Seleccionar provincia</option>
                <?php foreach ($provincias as $cod => $nombre): ?>
                  <option value="<?= h($cod) ?>"><?= h($nombre) ?></option>
                <?php endforeach; ?>
              </select>
              <span class="field-error" id="err-provincia_usuario">⚠ Seleccioná una provincia.</span>
            </div>
            <div class="field">
              <label for="localidad_usuario">Localidad / Ciudad</label>
              <input class="f-input" type="text" id="localidad_usuario" name="localidad_usuario" required placeholder="Localidad"/>
              <span class="field-error" id="err-localidad_usuario">⚠ Campo obligatorio.</span>
            </div>
            <div class="field">
              <label for="barrio_usuario">Barrio</label>
              <input class="f-input" type="text" id="barrio_usuario" name="barrio_usuario" placeholder="Barrio"/>
            </div>
            <div class="field">
              <label for="calle_usuario">Calle</label>
              <input class="f-input" type="text" id="calle_usuario" name="calle_usuario" required placeholder="Nombre de la calle"/>
              <span class="field-error" id="err-calle_usuario">⚠ Campo obligatorio.</span>
            </div>
            <div class="field">
              <label for="numero_usuario">Número</label>
              <input class="f-input" type="text" id="numero_usuario" name="numero_usuario" required placeholder="Nº"/>
              <span class="field-error" id="err-numero_usuario">⚠ Campo obligatorio.</span>
            </div>
            <div class="field" style="grid-column:2/-1">
              <label for="piso_dpto_usuario">Piso / Depto <span class="opt">(opcional)</span></label>
              <input class="f-input" type="text" id="piso_dpto_usuario" name="piso_dpto_usuario" placeholder="Ej: 3° B"/>
            </div>
          </div>
        </fieldset>

        <!-- ════ STEP 3 — NEGOCIO ════ -->
        <fieldset data-step="3" class="step hidden" style="border:none;padding:0;margin:0">
          <div class="step-header">
            <div class="step-icon-wrap">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#4f46e5" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
            </div>
            <div class="step-title">Datos del negocio</div>
            <div class="step-subtitle">El CUIT es opcional — si no tenés, podés saltearlo.</div>
          </div>

          <div class="field-group cols-2">
            <div class="field" style="grid-column:1/-1">
              <label for="cuit">CUIT <span class="opt">(opcional)</span></label>
              <input class="f-input" type="text" id="cuit" name="cuit" inputmode="numeric" placeholder="20-12345678-9" maxlength="13"/>
              <span class="field-hint">Clave Única de Identificación Tributaria.</span>
              <span class="field-error" id="err-cuit">⚠ CUIT inválido (formato: XX-XXXXXXXX-X).</span>
            </div>
            <div class="field">
              <label for="razon_social">Razón Social</label>
              <input class="f-input" type="text" id="razon_social" name="razon_social" placeholder="Razón Social"/>
            </div>
            <div class="field">
              <label for="nombre_fantasia">Nombre de Fantasía</label>
              <input class="f-input" type="text" id="nombre_fantasia" name="nombre_fantasia" placeholder="Nombre de Fantasía"/>
            </div>
            <div class="field" style="grid-column:1/-1">
              <label for="email_negocio">E-mail del negocio</label>
              <input class="f-input" type="email" id="email_negocio" name="email_negocio" placeholder="contacto@ejemplo.com"/>
            </div>
          </div>
        </fieldset>

        <!-- ════ STEP 4 — ACCESO ════ -->
        <fieldset data-step="4" class="step hidden" style="border:none;padding:0;margin:0">
          <div class="step-header">
            <div class="step-icon-wrap">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#4f46e5" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </div>
            <div class="step-title">Acceso al sistema</div>
            <div class="step-subtitle">Creá tu login seguro para entrar a PetFlow.PRO.</div>
          </div>

          <div class="field-group cols-2">
            <div class="field" style="grid-column:1/-1">
              <label for="email">E-mail de acceso</label>
              <input class="f-input" type="email" id="email" name="email" required
                value="<?= $cad_mail ? h($cad_mail) : '' ?>" placeholder="tuemail@ejemplo.com"/>
              <span class="field-error" id="err-email">⚠ E-mail inválido.</span>
            </div>
            <div class="field" style="grid-column:1/-1">
              <label for="senha">Contraseña</label>
              <div class="input-wrap">
                <input class="f-input" type="password" id="senha" name="senha" required placeholder="Mínimo 8 caracteres" style="padding-right:2.5rem"/>
                <button type="button" class="toggle-pwd" onclick="toggleSenha('senha')" aria-label="Mostrar/ocultar contraseña">
                  <svg id="eye-open" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>
                  <svg id="eye-closed" class="hidden" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88"/></svg>
                </button>
              </div>
              <div class="pwd-meter"><div class="pwd-bar" id="pwdBar" style="width:0%"></div></div>
              <div class="pwd-label" id="pwdLabel">Usá letras, números y un símbolo.</div>
              <span class="field-error" id="err-senha">⚠ Contraseña débil — mínimo 8 caracteres con número y símbolo.</span>
            </div>
          </div>

          <div style="margin-top:1.125rem">
            <div class="checkbox-row">
              <input type="checkbox" id="aceite" name="aceite" required class="checkbox-custom"/>
              <label for="aceite" class="checkbox-label">
                Acepto los <a href="/terminos-de-uso" target="_blank">Términos de Uso</a> y la <a href="/politica-de-privacidad" target="_blank">Política de Privacidad</a>.
              </label>
            </div>
            <span class="field-error" id="err-aceite" style="margin-top:.375rem">⚠ Es necesario aceptar los términos.</span>
          </div>
        </fieldset>

        <!-- ════ STEP 5 — PLAN & PAGO ════ -->
        <fieldset data-step="5" class="step hidden" style="border:none;padding:0;margin:0">
          <div class="step-header">
            <div class="step-icon-wrap" style="background:#ecfdf5">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
            </div>
            <div class="step-title">Plan &amp; Pago</div>
            <div class="step-subtitle">Elegí tu plan y finalizá con seguridad. 7 días gratis.</div>
          </div>

          <!-- Aviso USD -->
          <div class="usd-banner">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#92400e" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span><strong>Los precios están en dólares estadounidenses (USD)</strong> para garantizar la estabilidad del servicio. Podés pagar con Mercado Pago, tarjeta de débito o crédito.</span>
          </div>

          <input type="hidden" name="id_plano" id="id_plano"/>
          <input type="hidden" name="id_plano_preco" id="id_plano_preco"/>
          <input type="hidden" name="pagarme_price_id" id="pagarme_price_id"/>
          <input type="hidden" name="periodicidade_escolhida" id="periodicidade_escolhida"/>

          <div id="planPreselectContainer" class="hidden"></div>

          <div id="planosSelectionContainer">
            <?php if (empty($planosCards)): ?>
              <div style="font-size:.84rem;color:var(--c-muted);padding:1rem;background:#f9fafb;border-radius:var(--radius-sm)">No hay planes activos disponibles.</div>
            <?php else: ?>
              <div class="plans-grid" id="planosGrid">
                <?php foreach ($planosCards as $index => $card):
                  $isMensal = $card['periodicidade'] === 'mensal';
                  $cardClass = $isMensal ? 'mensal' : 'anual';
                  $perLabel  = $isMensal ? 'mes' : 'año';
                ?>
                  <div class="plan-card <?= $cardClass ?>"
                    data-preco-id="<?= (int)$card['preco_id'] ?>"
                    data-periodicidade="<?= h($card['periodicidade']) ?>"
                    data-plano-nome="<?= h($card['plano_nome']) ?>">

                    <div class="plan-tag-row">
                      <span class="plan-tag <?= $cardClass ?>">
                        <?= $isMensal ? '📅 Mensual' : '🌟 Anual' ?>
                      </span>
                      <?php if ($index === 0 && $isMensal): ?>
                        <span class="plan-popular-badge">Más popular</span>
                      <?php elseif (!$isMensal && $card['economia_percent']): ?>
                        <span class="plan-savings-badge">🔥 -<?= (int)$card['economia_percent'] ?>%</span>
                      <?php endif; ?>
                    </div>

                    <div class="plan-name"><?= h($card['plano_nome']) ?></div>
                    <?php if (!empty($card['descricao_curta'])): ?>
                      <div class="plan-desc"><?= h($card['descricao_curta']) ?></div>
                    <?php endif; ?>

                    <?php if ($card['desconto_percent'] > 0): ?>
                      <div class="plan-price-old">USD <?= moneyFromUSD($card['valor_centavos']) ?></div>
                    <?php endif; ?>
                    <div class="plan-price-main">
                      <span class="plan-price-value">USD <?= moneyFromUSD($card['valor_final_centavos']) ?></span>
                      <span class="plan-price-period">/<?= $perLabel ?></span>
                    </div>
                    <?php if (!$isMensal && $card['valor_mensal_equivalente_centavos']): ?>
                      <div class="plan-equiv">≈ USD <?= moneyFromUSD($card['valor_mensal_equivalente_centavos']) ?>/mes</div>
                    <?php endif; ?>

                    <?php if (!empty($card['features'])): ?>
                      <div class="plan-divider"></div>
                      <div class="plan-features">
                        <?php foreach (array_slice($card['features'], 0, 5) as $feature):
                          if ((int)$feature['is_incluso'] !== 1) continue;
                        ?>
                          <div class="plan-feature">
                            <div class="plan-feature-icon">
                              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                            </div>
                            <span><?= h($feature['nome_exibicao']) ?><?php if (!empty($feature['limite_exibicao'])): ?> <b style="color:var(--c-muted);font-weight:500">(<?= h($feature['limite_exibicao']) ?>)</b><?php endif; ?></span>
                          </div>
                        <?php endforeach; ?>
                        <?php if (count($card['features']) > 5): ?>
                          <div class="plan-more">+ <?= count($card['features']) - 5 ?> funcionalidades</div>
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>

                    <button type="button" class="plan-btn select-plan-btn" data-preco-id="<?= (int)$card['preco_id'] ?>">
                      <svg class="check-icon hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                      <span class="btn-text"><?= $isMensal ? 'Elegir Mensual' : 'Elegir Anual' ?></span>
                    </button>
                  </div>
                <?php endforeach; ?>
              </div>
              <span class="field-error" id="err_plano" style="margin-top:.625rem">⚠ Seleccioná un plan para continuar.</span>
            <?php endif; ?>
          </div>

          <div class="order-summary" id="orderSummary">
            <div class="order-summary-label">Resumen del pedido</div>
            <div class="order-summary-text" id="orderSummaryText"></div>
          </div>

          <div class="section-divider"></div>
          <div class="section-title">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
            Datos de la tarjeta
          </div>

          <div class="field-group cols-2">
            <div class="field">
              <label for="card_holder_name">Nombre en la tarjeta</label>
              <input class="f-input" type="text" id="card_holder_name" name="card_holder_name" required placeholder="Como figura en la tarjeta"/>
              <span class="field-error" id="err-card_holder_name">⚠ Ingresá el nombre en la tarjeta.</span>
            </div>
            <div class="field">
              <label for="dni_titular">
                DNI del titular
                <span class="field-badge">AR</span>
              </label>
              <input class="f-input" type="text" id="dni_titular" name="dni_titular" required inputmode="numeric" placeholder="12.345.678" maxlength="10"/>
              <span class="field-error" id="err-dni_titular">⚠ DNI inválido (7 u 8 dígitos).</span>
            </div>
            <div class="field" style="grid-column:1/-1">
              <label for="card_number">Número de tarjeta</label>
              <input class="f-input" type="text" id="card_number" name="card_number" inputmode="numeric" required placeholder="0000 0000 0000 0000"/>
              <span class="field-error" id="err-card_number">⚠ Número de tarjeta inválido.</span>
            </div>
            <div class="field">
              <label for="card_exp">Vencimiento</label>
              <input class="f-input" type="text" id="card_exp" name="card_exp" inputmode="numeric" required placeholder="MM/AA"/>
              <span class="field-error" id="err-card_exp">⚠ Vencimiento inválido.</span>
            </div>
            <div class="field">
              <label for="card_cvv">Código de seguridad</label>
              <input class="f-input" type="password" id="card_cvv" name="card_cvv" inputmode="numeric" required placeholder="•••"/>
              <span class="field-error" id="err-card_cvv">⚠ Código inválido.</span>
            </div>
          </div>

          <div class="section-divider"></div>
          <div class="section-title">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
            Domicilio de facturación
          </div>

          <div class="field-group cols-3">
            <div class="field">
              <label for="billing_codigo_postal">Código Postal</label>
              <input class="f-input" type="text" id="billing_codigo_postal" name="billing_codigo_postal" required inputmode="numeric" placeholder="1425" maxlength="8"/>
              <span class="field-error" id="err-billing_codigo_postal">⚠ Código Postal inválido.</span>
            </div>
            <div class="field" style="grid-column:2/-1">
              <label for="billing_provincia">Provincia</label>
              <select class="f-input" id="billing_provincia" name="billing_provincia" required>
                <option value="">Seleccionar provincia</option>
                <?php foreach ($provincias as $cod => $nombre): ?>
                  <option value="<?= h($cod) ?>"><?= h($nombre) ?></option>
                <?php endforeach; ?>
              </select>
              <span class="field-error" id="err-billing_provincia">⚠ Seleccioná una provincia.</span>
            </div>
            <div class="field">
              <label for="billing_localidad">Localidad</label>
              <input class="f-input" type="text" id="billing_localidad" name="billing_localidad" required placeholder="Localidad"/>
              <span class="field-error" id="err-billing_localidad">⚠ Campo obligatorio.</span>
            </div>
            <div class="field" style="grid-column:2/-1">
              <label for="billing_calle">Calle y número</label>
              <input class="f-input" type="text" id="billing_calle" name="billing_calle" required placeholder="Ej: Corrientes 1234"/>
              <span class="field-error" id="err-billing_calle">⚠ Campo obligatorio.</span>
            </div>
          </div>

          <!-- Copiado do domicilio do responsável -->
          <div style="margin-top:.75rem">
            <div class="checkbox-row">
              <input type="checkbox" id="copiar_domicilio" class="checkbox-custom" onchange="copiarDomicilio(this.checked)"/>
              <label for="copiar_domicilio" class="checkbox-label">El domicilio de facturación es el mismo que el del responsable.</label>
            </div>
          </div>

          <input type="hidden" name="pagarme_card_hash" id="pagarme_card_hash"/>

          <div class="notice-box" style="margin-top:1.25rem">
            <p style="font-weight:600;color:#3730a3">Al finalizar aceptás:</p>
            <ul>
              <li>Creación inmediata de tu acceso en PetFlow.PRO</li>
              <li>Tokenización segura de la tarjeta</li>
              <li>Suscripción con <strong>7 días de prueba gratis</strong></li>
              <li>Cancelación en cualquier momento antes del cobro</li>
              <li>Precios en USD — pagá con Mercado Pago</li>
            </ul>
          </div>
        </fieldset>

        <!-- NAVIGATION -->
        <div class="form-nav">
          <button type="button" id="btnVoltar" class="btn-back" disabled>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
            Volver
          </button>
          <button type="button" id="btnContinuar" class="btn-next">
            Continuar
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
          </button>
          <button type="submit" id="btnSubmit" class="btn-submit hidden">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            Finalizar Registro
          </button>
        </div>

        <div class="secure-note">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          Datos encriptados en tránsito — podés retomar desde donde dejaste en este dispositivo.
        </div>
      </form>
    </div>
  </main>

  <footer>
    <div class="footer-inner">
      <div class="footer-col">
        <div class="footer-col-title">PetFlow.PRO</div>
        <p>Agilidad, organización y atención automatizada para peluquerías caninas y pet shops.</p>
        <p style="margin-top:.75rem;font-size:.72rem">© 2026 PetFlow.PRO — Todos los derechos reservados.</p>
      </div>
      <div class="footer-col">
        <div class="footer-col-title">Contacto</div>
        <ul>
          <li>📧 soporte@petflow.pro</li>
          <li>📱 WhatsApp Argentina</li>
        </ul>
      </div>
      <div class="footer-col">
        <div class="footer-col-title">Legal</div>
        <ul>
          <li><a href="/terminos-de-uso">Términos de Uso</a></li>
          <li><a href="/politica-de-privacidad">Política de Privacidad</a></li>
        </ul>
      </div>
    </div>
  </footer>

  <div id="spinner"><div class="spinner-ring"></div></div>

  <script>
  const OFERTAS_DATA = <?= json_encode($ofertasJs, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;

  (function(){
    const cadMail = <?= json_encode($cad_mail ?? '') ?>;
    const appAuto = <?= (int)$app_auto ?>;
    const appUrl  = <?= json_encode($app_url ?? '') ?>;
    const emailInput = document.getElementById('email');
    if (emailInput && cadMail && !emailInput.value) emailInput.value = cadMail;
    if (appAuto === 1 && appUrl) setTimeout(() => window.location.href = appUrl, 3500);
  })();

  const $ = (s, ctx=document) => ctx.querySelector(s);
  const $$ = (s, ctx=document) => [...ctx.querySelectorAll(s)];
  const digits = s => (s||'').replace(/\D/g,'');
  const fmtUSD = cents => 'USD ' + (Number(cents||0)/100).toFixed(2).replace('.',',').replace(/\B(?=(\d{3})+(?!\d))/g,'.');

  /* ── TOAST ── */
  let toastTimer;
  function toast(msg, type='info') {
    const el = $('#toast');
    el.textContent = msg;
    el.className = `toast ${type}`;
    el.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => el.classList.remove('show'), 2800);
  }

  const spinnerShow = () => { $('#spinner').style.display='flex'; };
  const spinnerHide = () => { $('#spinner').style.display='none'; };

  /* ── STEPS ── */
  const STEP_LABELS = ['Responsable','Domicilio','Negocio','Acceso','Plan'];
  function renderSteps(current) {
    const list = $('#stepList');
    list.innerHTML = '';
    STEP_LABELS.forEach((label, i) => {
      const n = i + 1;
      const isDone = n < current;
      const isActive = n === current;
      const item = document.createElement('div');
      item.className = `step-item ${isDone?'done':''} ${isActive?'active':''}`;
      item.innerHTML = `<div class="step-dot">${isDone ? '✓' : n}</div><span class="step-label">${label}</span>`;
      list.appendChild(item);
      if (n < STEP_LABELS.length) {
        const conn = document.createElement('div');
        conn.className = `step-connector ${isDone?'done':''}`;
        list.appendChild(conn);
      }
    });
    $('#progressBar').style.width = (current * (100/STEP_LABELS.length)) + '%';
  }

  let step = 1;
  const totalSteps = 5;
  let isSubmitting = false;

  function updateStepUI() {
    $$('.step').forEach(fs => fs.classList.toggle('hidden', Number(fs.dataset.step) !== step));
    $('#btnVoltar').disabled = (step === 1) || isSubmitting;
    $('#btnContinuar').classList.toggle('hidden', step === totalSteps);
    $('#btnSubmit').classList.toggle('hidden', step !== totalSteps);
    $('#btnContinuar').disabled = isSubmitting;
    $('#btnSubmit').disabled = isSubmitting;
    renderSteps(step);
    const fs = document.querySelector(`fieldset[data-step="${step}"]`);
    const first = fs?.querySelector('input:not([type=hidden]), select');
    if (first && !isSubmitting) try { first.focus({ preventScroll: true }); } catch(e) {}
  }
  updateStepUI();

  $('#btnVoltar').addEventListener('click', () => {
    if (isSubmitting) return;
    step = Math.max(1, step - 1);
    updateStepUI();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });
  $('#btnContinuar').addEventListener('click', () => {
    if (isSubmitting) return;
    if (!validateStep(step, true)) return;
    step = Math.min(totalSteps, step + 1);
    updateStepUI();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });

  /* ── MASKS ── */
  function maskDNI(el) {
    if (!el) return;
    el.addEventListener('input', () => {
      let v = digits(el.value).slice(0,8);
      // formato XX.XXX.XXX
      if (v.length > 3) v = v.slice(0,2) + '.' + v.slice(2);
      if (v.replace(/\./g,'').length > 5) {
        const raw = digits(v);
        v = raw.slice(0,2) + '.' + raw.slice(2,5) + '.' + raw.slice(5);
      }
      el.value = v;
    });
  }
  function maskCUIT(el) {
    if (!el) return;
    el.addEventListener('input', () => {
      let v = digits(el.value).slice(0,11);
      if (v.length > 2) v = v.slice(0,2) + '-' + v.slice(2);
      if (v.replace(/-/g,'').length > 10) {
        const raw = digits(v);
        v = raw.slice(0,2) + '-' + raw.slice(2,10) + '-' + raw.slice(10);
      }
      el.value = v;
    });
  }
  function maskPhone(el) {
    if (!el) return;
    el.addEventListener('input', () => {
      let v = digits(el.value).slice(0,12);
      // formato: XXX XXXX-XXXX
      if (v.length > 6) v = v.slice(0,3) + ' ' + v.slice(3,7) + '-' + v.slice(7);
      else if (v.length > 3) v = v.slice(0,3) + ' ' + v.slice(3);
      el.value = v;
    });
  }
  function maskCP(el) {
    if (!el) return;
    el.addEventListener('input', () => {
      // AR: 4 digits numeric, or up to 8 chars (some extended formats)
      el.value = el.value.replace(/[^\dA-Za-z]/g,'').slice(0,8);
    });
  }
  function maskCard(el) {
    if (!el) return;
    el.addEventListener('input', () => {
      let v = digits(el.value).slice(0,19).replace(/(\d{4})(?=\d)/g,'$1 ');
      el.value = v;
    });
  }
  function maskMMYY(el) {
    if (!el) return;
    el.addEventListener('input', () => {
      let v = digits(el.value).slice(0,4);
      if (v.length >= 3) v = v.slice(0,2) + '/' + v.slice(2);
      el.value = v;
    });
  }

  maskDNI($('#dni')); maskDNI($('#dni_titular'));
  maskCUIT($('#cuit'));
  maskPhone($('#telefono_celular'));
  maskCP($('#codigo_postal_usuario')); maskCP($('#billing_codigo_postal'));
  maskCard($('#card_number')); maskMMYY($('#card_exp'));

  /* ── VALIDATION ── */
  function validarDNI(v) {
    const d = digits(v);
    return d.length >= 7 && d.length <= 8;
  }
  function validarCUIT(v) {
    if (!v || !v.trim()) return true; // optional
    const d = digits(v);
    if (d.length !== 11) return false;
    const mult = [5,4,3,2,7,6,5,4,3,2];
    let sum = 0;
    for (let i=0; i<10; i++) sum += parseInt(d[i]) * mult[i];
    const resto = sum % 11;
    const verificador = resto === 0 ? 0 : resto === 1 ? 9 : 11 - resto;
    return verificador === parseInt(d[10]);
  }
  function isEmail(v) { return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v||''); }
  function isAdult(d) { if(!d) return false; const dt=new Date(d); if(isNaN(dt.getTime())) return false; const n=new Date(); return dt<=new Date(n.getFullYear()-18,n.getMonth(),n.getDate()); }
  function phoneValid(v) { const d=digits(v); return d.length>=8 && d.length<=12; }
  function cpValid(v) { return (v||'').trim().length >= 3; }
  function pwdScore(s) {
    if(!s) return 0; let sc=0;
    if(s.length>=8) sc+=25; if(/[A-Z]/.test(s)) sc+=15; if(/[a-z]/.test(s)) sc+=15;
    if(/\d/.test(s)) sc+=15; if(/[^A-Za-z0-9]/.test(s)) sc+=30;
    return Math.min(100,sc);
  }

  function setError(fieldId, show) {
    const el = document.getElementById(fieldId);
    const err = document.getElementById('err-' + fieldId);
    if (el) el.classList.toggle('has-error', show);
    if (err) err.classList.toggle('visible', show);
    if (show && el) { el.classList.add('shake'); setTimeout(() => el.classList.remove('shake'), 350); }
    return show;
  }
  function clearError(fieldId) { setError(fieldId, false); }

  function validateStep(n, scrollToFirst=false) {
    let ok = true, firstEl = null;
    function fail(id) { setError(id, true); if (!firstEl) firstEl = document.getElementById(id); ok = false; }
    function pass(id) { clearError(id); }

    if (n===1) {
      $('#nombre_completo').value.trim() ? pass('nombre_completo') : fail('nombre_completo');
      isAdult($('#fecha_nacimiento').value) ? pass('fecha_nacimiento') : fail('fecha_nacimiento');
      $('#sexo_biologico').value ? pass('sexo_biologico') : fail('sexo_biologico');
      validarDNI($('#dni').value) ? pass('dni') : fail('dni');
      phoneValid($('#telefono_celular').value) ? pass('telefono_celular') : fail('telefono_celular');
    }
    if (n===2) {
      cpValid($('#codigo_postal_usuario').value) ? pass('codigo_postal_usuario') : fail('codigo_postal_usuario');
      $('#provincia_usuario').value ? pass('provincia_usuario') : fail('provincia_usuario');
      $('#localidad_usuario').value.trim() ? pass('localidad_usuario') : fail('localidad_usuario');
      $('#calle_usuario').value.trim() ? pass('calle_usuario') : fail('calle_usuario');
      $('#numero_usuario').value.trim() ? pass('numero_usuario') : fail('numero_usuario');
    }
    if (n===3) {
      const cuit = $('#cuit').value;
      validarCUIT(cuit) ? pass('cuit') : fail('cuit');
    }
    if (n===4) {
      isEmail($('#email').value) ? pass('email') : fail('email');
      pwdScore($('#senha').value)>=50 ? pass('senha') : fail('senha');
      if (!$('#aceite').checked) { setError('aceite', true); if (!firstEl) firstEl = $('#aceite'); ok = false; }
      else clearError('aceite');
    }
    if (n===5) {
      const offerId = $('#id_plano_preco').value;
      const offer = OFERTAS_DATA[String(offerId)] || null;
      if (!offer) {
        ok = false;
        const ep = $('#err_plano'); ep.classList.add('visible');
        if (!firstEl) firstEl = $('#planosGrid') || document.querySelector('fieldset[data-step="5"]');
      } else {
        $('#err_plano').classList.remove('visible');
      }
      $('#card_holder_name').value.trim() ? pass('card_holder_name') : fail('card_holder_name');
      validarDNI($('#dni_titular').value) ? pass('dni_titular') : fail('dni_titular');
      const cd = digits($('#card_number').value); (cd.length>=13&&cd.length<=19) ? pass('card_number') : fail('card_number');
      const exp=digits($('#card_exp').value); const mm=parseInt(exp.slice(0,2)||'0');
      (mm>=1&&mm<=12&&exp.length===4) ? pass('card_exp') : fail('card_exp');
      const cvv=digits($('#card_cvv').value); (cvv.length>=3&&cvv.length<=4) ? pass('card_cvv') : fail('card_cvv');
      cpValid($('#billing_codigo_postal').value) ? pass('billing_codigo_postal') : fail('billing_codigo_postal');
      $('#billing_provincia').value ? pass('billing_provincia') : fail('billing_provincia');
      $('#billing_localidad').value.trim() ? pass('billing_localidad') : fail('billing_localidad');
      $('#billing_calle').value.trim() ? pass('billing_calle') : fail('billing_calle');
    }

    if (!ok && scrollToFirst && firstEl) {
      firstEl.scrollIntoView({ behavior:'smooth', block:'center' });
      toast('Revisá los campos destacados.', 'error');
    }
    return ok;
  }

  /* ── PASSWORD ── */
  $('#senha').addEventListener('input', () => {
    const s = pwdScore($('#senha').value);
    const bar = $('#pwdBar'); const lbl = $('#pwdLabel');
    bar.style.width = s + '%';
    bar.style.background = s<50 ? '#ef4444' : s<80 ? '#f59e0b' : '#10b981';
    lbl.textContent = s<50 ? 'Contraseña débil' : s<80 ? 'Buena — podés mejorarla' : '¡Excelente! 🎉';
    lbl.style.color = s<50 ? '#ef4444' : s<80 ? '#d97706' : '#059669';
  });

  window.toggleSenha = function(id) {
    const inp = document.getElementById(id);
    const isPwd = inp.type==='password';
    inp.type = isPwd ? 'text' : 'password';
    $('#eye-open').classList.toggle('hidden', isPwd);
    $('#eye-closed').classList.toggle('hidden', !isPwd);
  };

  /* ── COPY BILLING FROM RESPONSABLE ── */
  window.copiarDomicilio = function(checked) {
    if (!checked) return;
    const cp = $('#codigo_postal_usuario').value;
    const prov = $('#provincia_usuario').value;
    const loc = $('#localidad_usuario').value;
    const calle = $('#calle_usuario').value;
    const num = $('#numero_usuario').value;
    const pisoDepto = $('#piso_dpto_usuario').value;
    if (cp) $('#billing_codigo_postal').value = cp;
    if (prov) $('#billing_provincia').value = prov;
    if (loc) $('#billing_localidad').value = loc;
    if (calle || num) $('#billing_calle').value = (calle + (num ? ', ' + num : '') + (pisoDepto ? ' ' + pisoDepto : '')).trim();
    toast('Domicilio copiado.', 'success');
  };

  /* ── FETCH HELPERS ── */
  function fetchTimeout(url, opts={}, ms=8000) {
    const ctrl = new AbortController();
    const id = setTimeout(() => ctrl.abort(), ms);
    return fetch(url, {...opts, signal:ctrl.signal}).finally(() => clearTimeout(id));
  }
  function debounce(fn, ms=500) {
    let t; return (...args) => { clearTimeout(t); t=setTimeout(() => fn(...args), ms); };
  }

  /* ── PLAN SELECTION ── */
  function selectOffer(precoId, persist=true) {
    const offer = OFERTAS_DATA[String(precoId)];
    if (!offer) { deselectAll(); return false; }
    $('#id_plano').value = String(offer.plano_id);
    $('#id_plano_preco').value = String(offer.preco_id);
    $('#pagarme_price_id').value = String(offer.pagarme_price_id||'');
    $('#periodicidade_escolhida').value = String(offer.periodicidade||'');
    updatePlanVisual(); updateOrderSummary();
    if (persist) saveForm();
    return true;
  }
  function deselectAll() {
    $('#id_plano').value=''; $('#id_plano_preco').value='';
    $('#pagarme_price_id').value=''; $('#periodicidade_escolhida').value='';
    updatePlanVisual(); updateOrderSummary();
  }
  function getSelectedId() { return String($('#id_plano_preco').value||''); }

  function updatePlanVisual() {
    const sel = getSelectedId();
    $$('.plan-card').forEach(card => {
      const isSelected = String(card.dataset.precoId) === sel;
      card.classList.toggle('selected', isSelected);
      const btn = card.querySelector('.plan-btn');
      const icon = btn?.querySelector('.check-icon');
      const txt = btn?.querySelector('.btn-text');
      if (btn) {
        const isMensal = card.classList.contains('mensal');
        if (isSelected) { icon?.classList.remove('hidden'); if (txt) txt.textContent = 'Seleccionado'; }
        else { icon?.classList.add('hidden'); if (txt) txt.textContent = isMensal ? 'Elegir Mensual' : 'Elegir Anual'; }
      }
    });
  }

  function updateOrderSummary() {
    const sel = getSelectedId();
    const offer = OFERTAS_DATA[sel]||null;
    const summary = $('#orderSummary'); const txt = $('#orderSummaryText');
    if (!offer) { summary.classList.remove('visible'); return; }
    summary.classList.add('visible');
    const per = offer.periodicidade==='anual' ? 'año' : 'mes';
    const perLabel = offer.periodicidade==='anual' ? 'Anual' : 'Mensual';
    let line = `${offer.plano_nome} · ${perLabel} · USD ${(offer.valor_final_centavos/100).toFixed(2).replace('.',',')}/${per}`;
    if (offer.periodicidade==='anual') line += ` (≈ USD ${(offer.valor_mensal_equivalente_centavos/100).toFixed(2).replace('.',',')}/mes)`;
    txt.textContent = line;
  }

  $$('.select-plan-btn').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      if (isSubmitting) return;
      const precoId = btn.closest('.plan-card')?.dataset?.precoId;
      if (!precoId) return;
      selectOffer(precoId, true);
      toast('¡Plan seleccionado!', 'success');
    });
  });

  function getParam(name) { return new URLSearchParams(window.location.search).get(name); }

  function createPreselectBanner(offer) {
    const container = $('#planPreselectContainer');
    if (!container||!offer) return;
    const per = offer.periodicidade==='anual' ? 'año' : 'mes';
    const perLabel = offer.periodicidade==='anual' ? 'Anual' : 'Mensual';
    container.innerHTML = `
      <div class="preselect-banner">
        <div class="preselect-icon">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <div class="preselect-text">
          <div class="preselect-title">${offer.plano_nome} · ${perLabel} — Plan preseleccionado</div>
          <div class="preselect-value">USD ${(offer.valor_final_centavos/100).toFixed(2).replace('.',',')}/${per}${offer.periodicidade==='anual'?' · ≈ USD '+(offer.valor_mensal_equivalente_centavos/100).toFixed(2).replace('.',',')+'/mes':''}</div>
        </div>
        <button type="button" id="showPlansBtn" class="preselect-change">Cambiar plan</button>
      </div>
    `;
    $('#planosSelectionContainer').classList.add('hidden');
    container.classList.remove('hidden');
    $('#showPlansBtn').addEventListener('click', () => {
      $('#planosSelectionContainer').classList.remove('hidden');
      container.classList.add('hidden');
      const url = new URL(window.location);
      url.searchParams.delete('plan');
      window.history.replaceState({}, '', url);
    });
  }

  function init() {
    const param = getParam('plan');
    let foundId = null;
    if (param==='mensual'||param==='anual') {
      const mapPer = { 'mensual': 'mensal', 'anual': 'anual' };
      for (const [id, data] of Object.entries(OFERTAS_DATA)) {
        if (data.periodicidade===mapPer[param]) { foundId=id; break; }
      }
    }
    if (foundId) {
      selectOffer(foundId, true);
      createPreselectBanner(OFERTAS_DATA[foundId]);
    } else {
      const firstCard = document.querySelector('.plan-card');
      if (firstCard?.dataset?.precoId) selectOffer(firstCard.dataset.precoId, false);
    }
  }
  init();

  /* ── LOCAL STORAGE ── */
  const FORM_KEY = 'petflow.registro.ar.v1';
  const form = $('#formCadastro');

  function saveForm() {
    if (isSubmitting) return;
    const data = Object.fromEntries(new FormData(form).entries());
    try { localStorage.setItem(FORM_KEY, JSON.stringify(data)); } catch(e) {}
  }
  function loadForm() {
    try {
      const raw = localStorage.getItem(FORM_KEY);
      if (!raw) return;
      const data = JSON.parse(raw);
      Object.entries(data).forEach(([k,v]) => {
        if (['pagarme_card_hash'].includes(k)) return;
        const el = form.elements[k];
        if (!el) return;
        if (el instanceof RadioNodeList) { [...el].forEach(e => { if (String(e.value)===String(v)) e.checked=true; }); return; }
        if (el.type==='checkbox') el.checked = v==='on'||v===true||v==='1';
        else if (el.type!=='password') el.value = v;
      });
    } catch(e) {}
  }
  loadForm();
  form.addEventListener('input', debounce(saveForm, 400));
  form.addEventListener('change', debounce(saveForm, 0));

  /* ── SUBMIT ── */
  form.addEventListener('submit', e => {
    if (isSubmitting) { e.preventDefault(); return; }
    const offerId = getSelectedId();
    if (!offerId||!OFERTAS_DATA[offerId]) {
      e.preventDefault(); step=5; updateStepUI();
      $('#err_plano').classList.add('visible');
      toast('Seleccioná un plan.', 'error'); return;
    }
    for (let s=1; s<=totalSteps; s++) {
      if (!validateStep(s, true)) { e.preventDefault(); step=s; updateStepUI(); return; }
    }
    isSubmitting=true;
    $('#btnSubmit').disabled=true; $('#btnContinuar').disabled=true; $('#btnVoltar').disabled=true;
    $('#btnSubmit').innerHTML='<svg width="16" height="16" viewBox="0 0 24 24" style="animation:spin .8s linear infinite"><circle cx="12" cy="12" r="10" fill="none" stroke="white" stroke-width="2" stroke-dasharray="30 100"/></svg> Procesando…';
    spinnerShow();
  });

  window.addEventListener('pageshow', () => {
    spinnerHide(); isSubmitting=false;
    $('#btnSubmit').disabled=false; $('#btnContinuar').disabled=false; $('#btnVoltar').disabled=(step===1);
    $('#btnSubmit').innerHTML='<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Finalizar Registro';
  });

  /* ── BLUR VALIDATION ── */
  function blurValidate(id, check) {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('blur', () => {
      const cur = Number(el.closest('fieldset')?.dataset?.step||0);
      if (cur!==step) return;
      setError(id, !check(el.value));
    });
  }
  blurValidate('dni', v => validarDNI(v));
  blurValidate('dni_titular', v => validarDNI(v));
  blurValidate('email', v => isEmail(v));
  blurValidate('codigo_postal_usuario', v => cpValid(v));
  blurValidate('billing_codigo_postal', v => cpValid(v));
  blurValidate('telefono_celular', v => phoneValid(v));
  blurValidate('senha', v => pwdScore(v)>=50);
  blurValidate('cuit', v => validarCUIT(v));
  blurValidate('billing_provincia', v => !!v);

  /* ── KEYBOARD ── */
  document.addEventListener('keydown', ev => {
    if (isSubmitting) { if (ev.key==='Enter') ev.preventDefault(); return; }
    if (ev.key==='Enter') {
      const a = document.activeElement;
      if (['INPUT','SELECT'].includes(a?.tagName) && a?.type!=='textarea' && step<totalSteps) {
        ev.preventDefault(); $('#btnContinuar').click();
      }
    }
  });

  updatePlanVisual();
  updateOrderSummary();
  </script>
</body>
</html>