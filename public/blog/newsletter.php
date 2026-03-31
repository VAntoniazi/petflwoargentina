<?php
// blog/newsletter.php
require_once '../cadastro/config.php';

// Configurar cabeçalho para JSON
header('Content-Type: application/json');

// Verificar se é POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método não permitido']);
    exit;
}

// Validar email
$email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
if (!$email) {
    echo json_encode(['success' => false, 'message' => 'E-mail inválido']);
    exit;
}

/**
 * Função para capturar IP por MÚLTIPLOS métodos
 * Tenta todas as formas possíveis de obter o IP real
 */
function captureUserIP() {
    $ipv4 = null;
    $ipv6 = null;
    $allIps = [];
    
    // Lista de todos os headers possíveis que podem conter IP
    $ipHeaders = [
        // Headers do Cloudflare
        'HTTP_CF_CONNECTING_IP',
        'HTTP_CF_PSEUDO_IPV4', // Cloudflare pseudo IPv4
        'HTTP_CF_VISITOR',      // Contém info sobre o protocolo
        
        // Headers padrão de proxy
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_FORWARDED',
        'HTTP_X_REAL_IP',
        'HTTP_X_CLUSTER_CLIENT_IP',
        'HTTP_X_CLIENT_IP',
        'HTTP_X_CLIENT_IP_ADDR',
        'HTTP_FORWARDED_FOR',
        'HTTP_FORWARDED',
        'HTTP_CLIENT_IP',
        'HTTP_TRUE_CLIENT_IP',  // Alguns CDNs usam este
        
        // Headers de balanceadores
        'HTTP_X_SUCURI_CLIENTIP', // Sucuri
        'HTTP_INCAP_CLIENT_IP',   // Incapsula
        'HTTP_X_AKAMAI_TRANSACTION_ID', // Akamai
        
        // Headers de load balancers comuns
        'HTTP_X_ORIGINAL_FORWARDED_FOR',
        'HTTP_X_ORIGINAL_CLIENT_IP',
        'HTTP_X_ARR_LOG_ID',      // Azure
        'HTTP_X_AWS_EC2',         // AWS
        
        // IP direto do servidor
        'REMOTE_ADDR',
        'REMOTE_HOST'
    ];
    
    // Coletar todos os IPs de todos os headers
    foreach ($ipHeaders as $header) {
        if (isset($_SERVER[$header]) && !empty($_SERVER[$header])) {
            $value = $_SERVER[$header];
            
            // Tratar múltiplos IPs (ex: X-Forwarded-For: ip1, ip2, ip3)
            if (strpos($value, ',') !== false) {
                $ips = explode(',', $value);
                foreach ($ips as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        $allIps[] = [
                            'ip' => $ip,
                            'header' => $header,
                            'type' => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 'IPv4' : 'IPv6'
                        ];
                    }
                }
            } else {
                // IP único
                if (filter_var($value, FILTER_VALIDATE_IP)) {
                    $allIps[] = [
                        'ip' => $value,
                        'header' => $header,
                        'type' => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 'IPv4' : 'IPv6'
                    ];
                }
            }
        }
    }
    
    // Tratar caso especial: IPv6 mapeado para IPv4 (ex: ::ffff:192.168.1.1)
    foreach ($allIps as $item) {
        if ($item['type'] === 'IPv6' && preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/', $item['ip'], $matches)) {
            // Extrair o IPv4 do IPv6 mapeado
            $ipv4FromMapped = $matches[1];
            if (filter_var($ipv4FromMapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $allIps[] = [
                    'ip' => $ipv4FromMapped,
                    'header' => $item['header'] . ' (mapeado)',
                    'type' => 'IPv4'
                ];
            }
        }
    }
    
    // Ordenar por prioridade: Cloudflare primeiro, depois IPv4, depois IPv6
    usort($allIps, function($a, $b) {
        // Cloudflare tem prioridade máxima
        $aIsCf = strpos($a['header'], 'HTTP_CF_') === 0;
        $bIsCf = strpos($b['header'], 'HTTP_CF_') === 0;
        if ($aIsCf && !$bIsCf) return -1;
        if (!$aIsCf && $bIsCf) return 1;
        
        // Depois prioriza IPv4 sobre IPv6
        if ($a['type'] === 'IPv4' && $b['type'] === 'IPv6') return -1;
        if ($a['type'] === 'IPv6' && $b['type'] === 'IPv4') return 1;
        
        return 0;
    });
    
    // Separar IPv4 e IPv6 encontrados
    $foundIPv4 = [];
    $foundIPv6 = [];
    
    foreach ($allIps as $item) {
        if ($item['type'] === 'IPv4') {
            $foundIPv4[] = $item['ip'];
            if (!$ipv4) $ipv4 = $item['ip'];
        } else {
            $foundIPv6[] = $item['ip'];
            if (!$ipv6) $ipv6 = $item['ip'];
        }
    }
    
    // Se não encontrou IPv4 mas tem IPv6, tentar converter
    if (!$ipv4 && $ipv6) {
        // Alguns provedores usam IPv6 com prefixo que contém IPv4
        if (preg_match('/::ffff:(\d+\.\d+\.\d+\.\d+)$/', $ipv6, $matches)) {
            $ipv4 = $matches[1];
        }
        // Tentar extrair IPv4 de IPv6 6to4 (2002:xxxx:xxxx::)
        elseif (preg_match('/^2002:([a-f0-9]{4}):([a-f0-9]{4})/i', $ipv6, $matches)) {
            $hex1 = $matches[1];
            $hex2 = $matches[2];
            $ipv4 = hexdec(substr($hex1, 0, 2)) . '.' . 
                    hexdec(substr($hex1, 2, 2)) . '.' . 
                    hexdec(substr($hex2, 0, 2)) . '.' . 
                    hexdec(substr($hex2, 2, 2));
        }
        // Tentar extrair IPv4 de IPv6 Teredo
        elseif (preg_match('/^2001:0:([a-f0-9]+):/i', $ipv6, $matches)) {
            // Teredo é mais complexo, mas podemos tentar
            $teredo = hexdec($matches[1]);
            if ($teredo > 0) {
                $ipv4 = long2ip(~$teredo); // Teredo usa complemento
            }
        }
    }
    
    // Último recurso: usar REMOTE_ADDR se for IPv4
    if (!$ipv4 && isset($_SERVER['REMOTE_ADDR'])) {
        $remote = $_SERVER['REMOTE_ADDR'];
        if (filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ipv4 = $remote;
        }
    }
    
    // Log detalhado para debug
    error_log("=== DEBUG CAPTURA DE IP ===");
    error_log("Todos IPs encontrados: " . print_r($allIps, true));
    error_log("IPv4 capturado: " . ($ipv4 ?? 'null'));
    error_log("IPv6 capturado: " . ($ipv6 ?? 'null'));
    error_log("===========================");
    
    return [
        'ipv4' => $ipv4,
        'ipv6' => $ipv6,
        'all_ips' => $allIps,
        'has_ipv4' => !is_null($ipv4),
        'has_ipv6' => !is_null($ipv6)
    ];
}

// Capturar IPs
$ipData = captureUserIP();
$ipv4 = $ipData['ipv4'];
$ipv6 = $ipData['ipv6'];

// Gerar token de confirmação
$token = bin2hex(random_bytes(32));

try {
    // Verificar se email já existe
    $checkStmt = $pdo->prepare("SELECT id, status FROM newsletter WHERE email = ?");
    $checkStmt->execute([$email]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        if ($existing['status'] === 'confirmado') {
            echo json_encode([
                'success' => false, 
                'message' => 'Este e-mail já está cadastrado em nossa newsletter!',
                'debug' => [
                    'ipv4' => $ipv4,
                    'ipv6' => $ipv6,
                    'all_ips' => $ipData['all_ips']
                ]
            ]);
        } else {
            // Atualizar registro existente
            $updateStmt = $pdo->prepare("UPDATE newsletter SET 
                ipv4 = ?, 
                ipv6 = ?, 
                token_confirmacao = ?, 
                data_cadastro = NOW(), 
                status = 'pendente', 
                origem = 'blog' 
                WHERE id = ?");
            $updateStmt->execute([$ipv4, $ipv6, $token, $existing['id']]);
            
            echo json_encode([
                'success' => true, 
                'message' => 'Inscrição atualizada! Por favor, confirme seu e-mail.',
                'debug' => [
                    'ipv4' => $ipv4,
                    'ipv6' => $ipv6
                ]
            ]);
        }
        exit;
    }
    
    // Inserir novo email
    $stmt = $pdo->prepare("INSERT INTO newsletter 
        (email, ipv4, ipv6, token_confirmacao, origem) 
        VALUES (?, ?, ?, ?, 'blog')");
    $stmt->execute([$email, $ipv4, $ipv6, $token]);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Inscrição realizada com sucesso! Por favor, confirme seu e-mail.',
        'debug' => [
            'ipv4' => $ipv4,
            'ipv6' => $ipv6
        ]
    ]);
    
} catch (PDOException $e) {
    // Log do erro
    error_log("Erro ao cadastrar newsletter: " . $e->getMessage());
    
    echo json_encode([
        'success' => false, 
        'message' => 'Erro ao processar inscrição. Tente novamente mais tarde.',
        'debug' => [
            'error' => $e->getMessage(),
            'ipv4' => $ipv4,
            'ipv6' => $ipv6
        ]
    ]);
}