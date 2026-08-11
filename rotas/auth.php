<?php
declare(strict_types=1);

function rota_auth(string $metodo, array $seg): void
{
    $acao = $seg[0] ?? '';

    if ($acao === 'login' && $metodo === 'POST') {
        $dados = corpo();
        exigir($dados, ['email', 'senha']);

        $u = consulta_um('SELECT id, nome, email, senha_hash, papel, ativo FROM usuarios WHERE LOWER(email) = LOWER(?)',
            [$dados['email']]);

        // Mensagem única para usuário inexistente e senha errada: não entregamos
        // ao atacante quais e-mails existem na base.
        if (!$u || !(int) $u['ativo'] || !password_verify((string) $dados['senha'], $u['senha_hash'])) {
            throw new ErroApp(401, 'E-mail ou senha incorretos.');
        }

        executa('UPDATE usuarios SET ultimo_acesso = NOW() WHERE id = ?', [$u['id']]);

        responder([
            'token' => jwt_gerar(['sub' => (int) $u['id'], 'nome' => $u['nome'], 'email' => $u['email'], 'papel' => $u['papel']]),
            'usuario' => ['id' => (int) $u['id'], 'nome' => $u['nome'], 'email' => $u['email'], 'papel' => $u['papel']],
        ]);
    }

    if ($acao === 'eu' && $metodo === 'GET') {
        $u = autenticar();
        responder(['usuario' => ['id' => $u['sub'], 'nome' => $u['nome'], 'email' => $u['email'], 'papel' => $u['papel']]]);
    }

    if ($acao === 'senha' && $metodo === 'POST') {
        $u = autenticar();
        $dados = corpo();
        exigir($dados, ['senha_atual', 'senha_nova']);
        if (mb_strlen((string) $dados['senha_nova']) < 8) {
            throw new ErroApp(400, 'A nova senha precisa ter ao menos 8 caracteres.');
        }
        $atual = consulta_um('SELECT senha_hash FROM usuarios WHERE id = ?', [$u['sub']]);
        if (!$atual || !password_verify((string) $dados['senha_atual'], $atual['senha_hash'])) {
            throw new ErroApp(400, 'A senha atual não confere.');
        }
        executa('UPDATE usuarios SET senha_hash = ? WHERE id = ?',
            [password_hash((string) $dados['senha_nova'], PASSWORD_BCRYPT), $u['sub']]);
        responder(['ok' => true]);
    }

    throw new ErroApp(404, 'Rota não encontrada.');
}
