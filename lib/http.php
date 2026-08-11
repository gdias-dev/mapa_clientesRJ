<?php
declare(strict_types=1);

/** Erro de aplicação: vira resposta JSON com o status certo. */
class ErroApp extends Exception
{
    public ?array $campos;
    public function __construct(int $status, string $mensagem, ?array $campos = null)
    {
        parent::__construct($mensagem, $status);
        $this->campos = $campos;
    }
}

function responder(mixed $dados, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Corpo JSON da requisição. */
function corpo(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $bruto = file_get_contents('php://input') ?: '';
    $dados = json_decode($bruto, true);
    return $cache = is_array($dados) ? $dados : [];
}

function query_param(string $chave, ?string $padrao = null): ?string
{
    $v = $_GET[$chave] ?? null;
    return ($v === null || $v === '') ? $padrao : (string) $v;
}

function exigir(array $dados, array $campos): void
{
    $faltando = [];
    foreach ($campos as $c) {
        if (!isset($dados[$c]) || $dados[$c] === '' || $dados[$c] === null) $faltando[] = $c;
    }
    if ($faltando) throw new ErroApp(400, 'Preencha: ' . implode(', ', $faltando) . '.', $faltando);
}

/**
 * Cabeçalho Authorization. Em CGI/FastCGI (padrão da KingHost) ele não chega
 * em $_SERVER sem a regra do .htaccess, então tentamos várias origens.
 */
function cabecalho_autorizacao(): string
{
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $k) {
        if (!empty($_SERVER[$k])) return (string) $_SERVER[$k];
    }
    if (function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $nome => $valor) {
            if (strcasecmp($nome, 'Authorization') === 0) return (string) $valor;
        }
    }
    return '';
}

/** Período padrão dos filtros: últimos 12 meses. */
function periodo(): array
{
    $fim = query_param('fim') ?: date('Y-m-d');
    $inicio = query_param('inicio') ?: date('Y-m-d', strtotime('-12 months'));
    return ['inicio' => $inicio, 'fim' => $fim];
}
