<?php
declare(strict_types=1);

/**
 * Ponto de entrada único da API. O .htaccess manda tudo que cai em
 * /painel/api/... para cá; aqui o caminho é quebrado em recurso + segmentos.
 */
mb_internal_encoding('UTF-8');
date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/validacao.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

// O front é servido pelo mesmo domínio, então não há CORS a liberar.
// A resposta ao preflight fica só para o caso de uso em outro host durante
// o desenvolvimento local.
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Caminho depois de /api: usa PATH_INFO quando disponível, senão recorta a URI.
$caminho = $_SERVER['PATH_INFO'] ?? '';
if ($caminho === '') {
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $pos = strpos($uri, '/api');
    $caminho = $pos === false ? $uri : substr($uri, $pos + 4);
}
$segmentos = array_values(array_filter(explode('/', trim($caminho, '/')), fn($s) => $s !== ''));
$recurso = array_shift($segmentos) ?? '';
$metodo = $_SERVER['REQUEST_METHOD'];

$rotas = [
    'auth'          => 'rota_auth',
    'clientes'      => 'rota_clientes',
    'compras'       => 'rota_compras',
    'colaboradores' => 'rota_colaboradores',
    'produtos'      => 'rota_produtos',
    'dashboard'     => 'rota_dashboard',
    'relatorios'    => 'rota_relatorios',
    'importacao'    => 'rota_importacao',
];

try {
    if ($recurso === 'saude') {
        responder(['ok' => true, 'servico' => 'nova-varonil-api', 'php' => PHP_VERSION]);
    }
    if (!isset($rotas[$recurso])) throw new ErroApp(404, 'Rota não encontrada.');

    require_once __DIR__ . "/rotas/$recurso.php";
    $rotas[$recurso]($metodo, $segmentos);

    throw new ErroApp(404, 'Rota não encontrada.');
} catch (ErroApp $e) {
    responder(['erro' => $e->getMessage(), 'campos' => $e->campos], $e->getCode());
} catch (PDOException $e) {
    // Violações comuns viram mensagem útil em vez de 500 genérico.
    $codigo = $e->errorInfo[1] ?? 0;
    if ($codigo === 1062) {
        responder(['erro' => 'Já existe um registro com esse valor único (CNPJ ou e-mail).'], 409);
    }
    if (in_array($codigo, [1451, 1452], true)) {
        responder(['erro' => 'Registro vinculado a outros dados e não pode ser removido.'], 409);
    }
    error_log('[nova-varonil] ' . $e->getMessage());
    responder(['erro' => 'Erro no banco de dados. Verifique o log de erros da hospedagem.'], 500);
} catch (Throwable $e) {
    error_log('[nova-varonil] ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
    responder(['erro' => 'Erro interno. Verifique o log de erros da hospedagem.'], 500);
}
