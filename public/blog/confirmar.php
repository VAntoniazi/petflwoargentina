<?php
// blog/confirmar.php
require_once '../cadastro/config.php';

$token = $_GET['token'] ?? '';

if (empty($token)) {
    die('Token não fornecido');
}

try {
    $stmt = $pdo->prepare("UPDATE newsletter SET status = 'confirmado', data_confirmacao = NOW() WHERE token_confirmacao = ? AND status = 'pendente'");
    $stmt->execute([$token]);
    
    if ($stmt->rowCount() > 0) {
        echo "<h1 style='text-align:center;margin-top:50px;color:#4f46e5;font-family:Arial,sans-serif;'>✅ E-mail confirmado com sucesso!</h1>";
        echo "<p style='text-align:center;margin-top:20px;font-family:Arial,sans-serif;'>Agora você receberá nossos conteúdos exclusivos.</p>";
    } else {
        echo "<h1 style='text-align:center;margin-top:50px;color:#dc2626;font-family:Arial,sans-serif;'>❌ Token inválido ou e-mail já confirmado</h1>";
    }
} catch (PDOException $e) {
    echo "<h1 style='text-align:center;margin-top:50px;color:#dc2626;font-family:Arial,sans-serif;'>Erro ao confirmar e-mail</h1>";
}