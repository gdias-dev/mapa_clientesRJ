<?php
declare(strict_types=1);

function rota_colaboradores(string $metodo, array $seg): void
{
    autenticar();
    $primeiro = $seg[0] ?? '';
    $campos = ['nome', 'setor', 'telefone', 'email', 'ativo'];

    if ($primeiro === '' && $metodo === 'GET') {
        $linhas = consulta(
            'SELECT c.*,
                    COUNT(cli.id)                          AS clientes_atendidos,
                    COALESCE(SUM(v.faturamento_total), 0)  AS carteira_faturamento
               FROM colaboradores c
               LEFT JOIN clientes cli         ON cli.colaborador_id = c.id
               LEFT JOIN vw_clientes_resumo v ON v.id = cli.id
              GROUP BY c.id, c.nome, c.setor, c.telefone, c.email, c.ativo, c.criado_em
              ORDER BY c.nome');
        responder(['dados' => numerizar($linhas, ['carteira_faturamento', 'clientes_atendidos'])]);
    }

    if ($primeiro === '' && $metodo === 'POST') {
        $corpo = corpo();
        exigir($corpo, ['nome', 'setor']);
        $dados = array_intersect_key($corpo, array_flip($campos));
        if (isset($dados['ativo'])) $dados['ativo'] = $dados['ativo'] ? 1 : 0;
        $cols = array_keys($dados);
        executa('INSERT INTO colaboradores (' . implode(',', $cols) . ') VALUES (' .
            implode(',', array_fill(0, count($cols), '?')) . ')', array_values($dados));
        responder(consulta_um('SELECT * FROM colaboradores WHERE id = ?', [db()->lastInsertId()]), 201);
    }

    $id = ctype_digit($primeiro) ? (int) $primeiro : null;
    if ($id === null) throw new ErroApp(404, 'Rota não encontrada.');

    if ($metodo === 'PUT') {
        $dados = array_intersect_key(corpo(), array_flip($campos));
        if (isset($dados['ativo'])) $dados['ativo'] = $dados['ativo'] ? 1 : 0;
        if (!$dados) throw new ErroApp(400, 'Nenhum campo informado para atualizar.');
        $sets = implode(', ', array_map(fn($c) => "$c = ?", array_keys($dados)));
        executa("UPDATE colaboradores SET $sets WHERE id = ?", [...array_values($dados), $id]);
        $registro = consulta_um('SELECT * FROM colaboradores WHERE id = ?', [$id]);
        if (!$registro) throw new ErroApp(404, 'Colaborador não encontrado.');
        responder($registro);
    }

    if ($metodo === 'DELETE') {
        if (executa('DELETE FROM colaboradores WHERE id = ?', [$id]) === 0) {
            throw new ErroApp(404, 'Colaborador não encontrado.');
        }
        http_response_code(204);
        exit;
    }

    throw new ErroApp(405, 'Método não permitido.');
}
