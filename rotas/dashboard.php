<?php
declare(strict_types=1);

function rota_dashboard(string $metodo, array $seg): void
{
    autenticar();
    if ($metodo !== 'GET') throw new ErroApp(405, 'Método não permitido.');

    $p = periodo();
    $intervalo = [$p['inicio'], $p['fim']];
    $acao = $seg[0] ?? '';

    // ------------------------------------------------- indicadores do topo
    if ($acao === 'resumo') {
        $r = consulta_um(
            'SELECT COALESCE(SUM(valor_total), 0) AS faturamento,
                    COUNT(*)                      AS compras,
                    COALESCE(AVG(valor_total), 0) AS ticket_medio,
                    COUNT(DISTINCT cliente_id)    AS clientes_compradores
               FROM compras WHERE data_compra BETWEEN ? AND ?', $intervalo);

        $c = consulta_um(
            "SELECT SUM(status = 'ativo')      AS clientes_ativos,
                    SUM(status = 'prospeccao') AS clientes_prospeccao,
                    COUNT(*)                   AS clientes_total
               FROM clientes");

        responder(['periodo' => $p] + numerizar([array_merge($r, $c)],
            ['faturamento', 'compras', 'ticket_medio', 'clientes_compradores',
             'clientes_ativos', 'clientes_prospeccao', 'clientes_total'])[0]);
    }

    // ------------------------------------ produtos/serviços mais comprados
    if ($acao === 'produtos') {
        $limite = min((int) (query_param('limite') ?? 10) ?: 10, 50);
        $linhas = consulta(
            "SELECT COALESCE(p.nome, c.descricao)   AS produto,
                    COALESCE(p.categoria, 'outros') AS categoria,
                    SUM(c.quantidade)  AS quantidade,
                    SUM(c.valor_total) AS faturamento,
                    COUNT(*)           AS pedidos
               FROM compras c LEFT JOIN produtos p ON p.id = c.produto_id
              WHERE c.data_compra BETWEEN ? AND ?
              GROUP BY produto, categoria
              ORDER BY faturamento DESC LIMIT $limite", $intervalo);
        responder(['dados' => numerizar($linhas, ['quantidade', 'faturamento', 'pedidos'])]);
    }

    // ------------------------------------------------- série temporal
    if ($acao === 'periodo') {
        // DATE_FORMAT no lugar do date_trunc do Postgres; o valor é escolhido
        // de uma lista fixa, nunca vem cru da query string.
        $formatos = ['dia' => '%Y-%m-%d', 'mes' => '%Y-%m-01', 'ano' => '%Y-01-01'];
        $g = query_param('granularidade') ?? 'mes';
        $fmt = $formatos[$g] ?? $formatos['mes'];

        $linhas = consulta(
            "SELECT DATE_FORMAT(data_compra, '$fmt') AS bucket,
                    COUNT(*)         AS compras,
                    SUM(valor_total) AS faturamento,
                    SUM(quantidade)  AS quantidade
               FROM compras WHERE data_compra BETWEEN ? AND ?
              GROUP BY bucket ORDER BY bucket", $intervalo);
        responder([
            'granularidade' => $g,
            'dados' => numerizar($linhas, ['compras', 'faturamento', 'quantidade']),
        ]);
    }

    // ----------------------------------------------- ranking de clientes
    if ($acao === 'top-clientes') {
        $limite = min((int) (query_param('limite') ?? 10) ?: 10, 100);
        $linhas = consulta(
            "SELECT cli.id, cli.nome, cli.municipio, cli.cnpj,
                    SUM(c.valor_total) AS faturamento,
                    COUNT(*)           AS compras,
                    MAX(c.data_compra) AS ultima_compra
               FROM compras c JOIN clientes cli ON cli.id = c.cliente_id
              WHERE c.data_compra BETWEEN ? AND ?
              GROUP BY cli.id, cli.nome, cli.municipio, cli.cnpj
              ORDER BY faturamento DESC LIMIT $limite", $intervalo);
        responder(['dados' => numerizar($linhas, ['faturamento', 'compras'])]);
    }

    if ($acao === 'municipios') {
        $linhas = consulta(
            "SELECT cli.municipio,
                    COUNT(DISTINCT CASE WHEN cli.status = 'ativo'      THEN cli.id END) AS clientes_ativos,
                    COUNT(DISTINCT CASE WHEN cli.status = 'prospeccao' THEN cli.id END) AS prospeccao,
                    COALESCE(SUM(c.valor_total), 0) AS faturamento
               FROM clientes cli
               LEFT JOIN compras c
                      ON c.cliente_id = cli.id AND c.data_compra BETWEEN ? AND ?
              GROUP BY cli.municipio ORDER BY faturamento DESC", $intervalo);
        responder(['dados' => numerizar($linhas, ['clientes_ativos', 'prospeccao', 'faturamento'])]);
    }

    if ($acao === 'pagamentos') {
        $linhas = consulta(
            'SELECT forma_pagamento, COUNT(*) AS compras, SUM(valor_total) AS faturamento
               FROM compras WHERE data_compra BETWEEN ? AND ?
              GROUP BY forma_pagamento ORDER BY faturamento DESC', $intervalo);
        responder(['dados' => numerizar($linhas, ['compras', 'faturamento'])]);
    }

    throw new ErroApp(404, 'Rota não encontrada.');
}
