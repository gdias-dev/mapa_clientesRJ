<?php
declare(strict_types=1);

/** JWT HS256 sem dependência externa — o suficiente para sessão de painel. */
function b64url(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64url_dec(string $s): string { return base64_decode(strtr($s, '-_', '+/')) ?: ''; }

function jwt_gerar(array $dados): string
{
    $cfg = require __DIR__ . '/../config.php';
    $cabecalho = b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $dados['iat'] = time();
    $dados['exp'] = time() + (int) ($cfg['jwt_horas'] ?? 8) * 3600;
    $carga = b64url(json_encode($dados, JSON_UNESCAPED_UNICODE));
    $assinatura = b64url(hash_hmac('sha256', "$cabecalho.$carga", $cfg['jwt_secret'], true));
    return "$cabecalho.$carga.$assinatura";
}

function jwt_validar(string $token): ?array
{
    $cfg = require __DIR__ . '/../config.php';
    $partes = explode('.', $token);
    if (count($partes) !== 3) return null;
    [$cabecalho, $carga, $assinatura] = $partes;

    $esperada = b64url(hash_hmac('sha256', "$cabecalho.$carga", $cfg['jwt_secret'], true));
    // hash_equals evita comparação suscetível a ataque de temporização.
    if (!hash_equals($esperada, $assinatura)) return null;

    $dados = json_decode(b64url_dec($carga), true);
    if (!is_array($dados) || ($dados['exp'] ?? 0) < time()) return null;
    return $dados;
}

/** Exige sessão válida e devolve o usuário do token. */
function autenticar(): array
{
    $header = cabecalho_autorizacao();
    if (!preg_match('/Bearer\s+(.+)/i', $header, $m)) {
        throw new ErroApp(401, 'Sessão não encontrada. Entre novamente.');
    }
    $usuario = jwt_validar(trim($m[1]));
    if (!$usuario) throw new ErroApp(401, 'Sessão expirada. Entre novamente.');
    return $usuario;
}
