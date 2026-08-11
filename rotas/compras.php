<?php
declare(strict_types=1);

const CAMPOS_COMPRA = ['cliente_id','produto_id','descricao','quantidade','valor_unitario',
                       'data_compra','forma_pagamento','colaborador_id','observacao'];

function normalizar_compra(array $corpo): array
{
    $dados = [];
    foreach (CAMPOS_COMPRA as $c) if (array_key_exists($c, $corpo)) $dados[$c] = $corpo[$c];

    foreach (['cliente_id', 'produto_id', 'colaborador_id'] as $c) {
        if (array_key_exists($c, $dados)) $dados[$c] = numero_ou_nulo($dados[$c]);
    }
    if (array_key_exists('quantidade', $dados)) $dados['quantidade'] = (float) $dados['quantidade'];
    if (array_key_exists('valor_unitario', $dados)) {
        $v = moeda($dados['valor_unitario']);   // aceita o formato brasileiro do formulário
        if ($v === null) throw new ErroApp(400, 'Valor unitário inválido.', ['valor_unitario']);
        $dados['valor_unitario'] = $v;
    }
    return $dados;
}

function rota_compras(string $metodo, array $seg): void
{
    autenticar();
    $primeiro = $seg[0] ?? '';

    if ($primeiro === '' && $metodo === 'GET') {
        $where = [];
        $params = [];
        foreach (['cliente_id' => 'c.cliente_id', 'forma_pagamento' => 'c.forma_pagamento'] as $qs => $col) {
            if ($v = query_param($qs)) { $where[] = "$col = ?"; $params[] = $v; }
        }
        if ($v = query_param('inicio')) { $where[] = 'c.data_compra >= ?'; $params[] = $v; }
        if ($v = query_param('fim'))    { $where[] = 'c.data_compra <= ?'; $params[] = $v; }
        if ($b = query_param('busca')) {
            $where[] = '(LOWER(c.descricao) LIKE ? OR LOWER(cli.nome) LIKE ?)';
            $like = '%' . mb_strtolower($b) . '%';
            array_push($params, $like, $like);
        }
        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $limite = min((int) (query_param('limite') ?? 50) ?: 50, 500);
        $pagina = max((int) (query_param('pagina') ?? 1) ?: 1, 1);
        $offset = ($pagina - 1) * $limite;

        $totais = consulta_um(
            "SELECT COUNT(*) AS n, COALESCE(SUM(c.valor_total), 0) AS soma
               FROM compras c JOIN clientes cli ON cli.id = c.cliente_id $sqlWhere", $params);

        $linhas = consulta(
            "SELECT c.*, cli.nome AS cliente_nome, cli.municipio, col.nome AS colaborador_nome
               FROM compras c
               JOIN clientes cli           ON cli.id = c.cliente_id
               LEFT JOIN colaboradores col ON col.id = c.colaborador_id
               $sqlWhere
              ORDER BY c.data_compra DESC, c.id DESC
              LIMIT $limite OFFSET $offset", $params);

        responder([
            'dados'  => numerizar($linhas, ['quantidade', 'valor_unitario', 'valor_total']),
            'total'  => (int) ($totais['n'] ?? 0),
            'soma'   => (float) ($totais['soma'] ?? 0),
            'pagina' => $pagina,
            'limite' => $limite,
        ]);
    }

    if ($primeiro === '' && $metodo === 'POST') {
        $dados = normalizar_compra(corpo());
        exigir($dados, ['cliente_id', 'descricao', 'valor_unitario', 'data_compra']);
        $cols = array_keys($dados);
        executa('INSERT INTO compras (' . implode(',', $cols) . ') VALUES (' .
            implode(',', array_fill(0, count($cols), '?')) . ')', array_values($dados));
        responder(consulta_um('SELECT * FROM compras WHERE id = ?', [db()->lastInsertId()]), 201);
    }

    $id = ctype_digit($primeiro) ? (int) $primeiro : null;
    if ($id === null) throw new ErroApp(404, 'Rota não encontrada.');

    if ($metodo === 'PUT') {
        $dados = normalizar_compra(corpo());
        if (!$dados) throw new ErroApp(400, 'Nenhum campo informado para atualizar.');
        $sets = implode(', ', array_map(fn($c) => "$c = ?", array_keys($dados)));
        executa("UPDATE compras SET $sets WHERE id = ?", [...array_values($dados), $id]);
        $compra = consulta_um('SELECT * FROM compras WHERE id = ?', [$id]);
        if (!$compra) throw new ErroApp(404, 'Compra não encontrada.');
        responder($compra);
    }

    if ($metodo === 'DELETE') {
        if (executa('DELETE FROM compras WHERE id = ?', [$id]) === 0) {
            throw new ErroApp(404, 'Compra não encontrada.');
        }
        http_response_code(204);
        exit;
    }

    throw new ErroApp(405, 'Método não permitido.');
}
