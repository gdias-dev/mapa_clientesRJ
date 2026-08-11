<?php
declare(strict_types=1);

function rota_produtos(string $metodo, array $seg): void
{
    autenticar();

    if ($metodo === 'GET') {
        responder(['dados' => numerizar(
            consulta('SELECT * FROM produtos WHERE ativo = 1 ORDER BY nome'), ['preco_base'])]);
    }

    if ($metodo === 'POST') {
        $dados = corpo();
        exigir($dados, ['nome']);
        executa('INSERT INTO produtos (nome, categoria, preco_base) VALUES (?,?,?)',
            [$dados['nome'], $dados['categoria'] ?? 'lente', moeda($dados['preco_base'] ?? 0) ?? 0]);
        responder(consulta_um('SELECT * FROM produtos WHERE id = ?', [db()->lastInsertId()]), 201);
    }

    throw new ErroApp(405, 'Método não permitido.');
}
