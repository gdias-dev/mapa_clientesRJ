<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/xlsx.php';
require_once __DIR__ . '/../lib/pdf.php';

function carregar_relatorio_cliente(int $id, array $p): array
{
    $cliente = consulta_um('SELECT * FROM vw_clientes_resumo WHERE id = ?', [$id]);
    if (!$cliente) throw new ErroApp(404, 'Cliente não encontrado.');
    $compras = consulta(
        'SELECT c.*, col.nome AS colaborador_nome
           FROM compras c LEFT JOIN colaboradores col ON col.id = c.colaborador_id
          WHERE c.cliente_id = ? AND c.data_compra BETWEEN ? AND ?
          ORDER BY c.data_compra DESC, c.id DESC', [$id, $p['inicio'], $p['fim']]);
    return [
        'cliente' => numerizar([$cliente], ['latitude', 'longitude', 'faturamento_total', 'total_compras'])[0],
        'compras' => numerizar($compras, ['quantidade', 'valor_unitario', 'valor_total']),
    ];
}

function arquivo_slug(string $nome): string
{
    $s = preg_replace('/\W+/u', '-', chave_nome($nome)) ?? 'cliente';
    return trim(mb_strtolower($s), '-');
}

function rota_relatorios(string $metodo, array $seg): void
{
    autenticar();
    if ($metodo !== 'GET') throw new ErroApp(405, 'Método não permitido.');
    $p = periodo();

    // ------------------------------------------------ carteira completa
    if (($seg[0] ?? '') === 'carteira' && ($seg[1] ?? '') === 'xlsx') {
        $linhas = consulta(
            'SELECT v.nome, v.cnpj, v.telefone, v.local_trabalho, v.municipio, v.status,
                    v.colaborador_nome, v.faturamento_total, v.ultima_compra,
                    COALESCE(SUM(c.valor_total), 0) AS faturamento_periodo,
                    COUNT(c.id)                     AS compras_periodo
               FROM vw_clientes_resumo v
               LEFT JOIN compras c ON c.cliente_id = v.id AND c.data_compra BETWEEN ? AND ?
              GROUP BY v.id, v.nome, v.cnpj, v.telefone, v.local_trabalho, v.municipio,
                       v.status, v.colaborador_nome, v.faturamento_total, v.ultima_compra
              ORDER BY faturamento_periodo DESC', [$p['inicio'], $p['fim']]);

        $xlsx = new MiniXlsx();
        $xlsx->aba('Carteira', [
            ['titulo' => 'Cliente', 'largura' => 36],
            ['titulo' => 'CNPJ', 'largura' => 20],
            ['titulo' => 'Telefone', 'largura' => 16],
            ['titulo' => 'Local de trabalho', 'largura' => 28],
            ['titulo' => 'Município', 'largura' => 22],
            ['titulo' => 'Situação', 'largura' => 14],
            ['titulo' => 'Responsável', 'largura' => 22],
            ['titulo' => 'Faturamento no período', 'largura' => 22, 'formato' => 'moeda'],
            ['titulo' => 'Compras no período', 'largura' => 18, 'formato' => 'numero'],
            ['titulo' => 'Faturamento total', 'largura' => 20, 'formato' => 'moeda'],
            ['titulo' => 'Última compra', 'largura' => 14],
        ], array_map(fn($r) => [
            $r['nome'], cnpj_formatado($r['cnpj']), $r['telefone'], $r['local_trabalho'],
            $r['municipio'], ROTULO_STATUS[$r['status']] ?? $r['status'], $r['colaborador_nome'],
            (float) $r['faturamento_periodo'], (float) $r['compras_periodo'],
            (float) $r['faturamento_total'], data_exibicao($r['ultima_compra']),
        ], $linhas));
        $xlsx->enviar('carteira-nova-varonil.xlsx');
    }

    // ------------------------------------------------ relatório por cliente
    if (($seg[0] ?? '') !== 'cliente' || !ctype_digit($seg[1] ?? '')) {
        throw new ErroApp(404, 'Rota não encontrada.');
    }
    $id = (int) $seg[1];
    $formato = $seg[2] ?? 'json';
    ['cliente' => $cliente, 'compras' => $compras] = carregar_relatorio_cliente($id, $p);
    $slug = arquivo_slug($cliente['nome']);
    $totalPeriodo = array_sum(array_map(fn($c) => (float) $c['valor_total'], $compras));

    if ($formato === 'json') {
        responder(['cliente' => $cliente, 'compras' => $compras]);
    }

    // ------------------------------------------------------------- Excel
    if ($formato === 'xlsx') {
        $xlsx = new MiniXlsx();
        $xlsx->aba('Cadastro',
            [['titulo' => 'Campo', 'largura' => 26], ['titulo' => 'Valor', 'largura' => 60]],
            [
                ['Nome', $cliente['nome']],
                ['CNPJ', cnpj_formatado($cliente['cnpj'])],
                ['Telefone', $cliente['telefone']],
                ['E-mail', $cliente['email']],
                ['Local de trabalho', $cliente['local_trabalho']],
                ['Endereço', trim(($cliente['endereco'] ?? '') . ' ' . ($cliente['bairro'] ?? ''))],
                ['Município/UF', $cliente['municipio'] . '/' . $cliente['uf']],
                ['Situação', ROTULO_STATUS[$cliente['status']] ?? $cliente['status']],
                ['Colaborador responsável', $cliente['colaborador_nome']],
                ['Faturamento total', brl($cliente['faturamento_total'])],
                ['Total de compras', (string) $cliente['total_compras']],
                ['Última compra', data_exibicao($cliente['ultima_compra'])],
                ['Período do relatório', data_exibicao($p['inicio']) . ' a ' . data_exibicao($p['fim'])],
            ]);

        $linhas = array_map(fn($c) => [
            data_exibicao($c['data_compra']), $c['descricao'], (float) $c['quantidade'],
            (float) $c['valor_unitario'], (float) $c['valor_total'],
            $c['forma_pagamento'], $c['colaborador_nome'],
        ], $compras);
        $linhas[] = ['', 'TOTAL DO PERÍODO', '', '', $totalPeriodo, '', ''];

        $xlsx->aba('Compras', [
            ['titulo' => 'Data', 'largura' => 12],
            ['titulo' => 'Produto / serviço', 'largura' => 42],
            ['titulo' => 'Qtd', 'largura' => 8, 'formato' => 'numero'],
            ['titulo' => 'Valor unitário', 'largura' => 16, 'formato' => 'moeda'],
            ['titulo' => 'Valor total', 'largura' => 16, 'formato' => 'moeda'],
            ['titulo' => 'Pagamento', 'largura' => 16],
            ['titulo' => 'Colaborador', 'largura' => 24],
        ], $linhas);

        $xlsx->enviar("relatorio-$slug.xlsx");
    }

    // --------------------------------------------------------------- PDF
    if ($formato === 'pdf') {
        $pdf = new MiniPdf();
        $AZUL = '#1257D6';
        $ESCURO = '#071B33';
        $CINZA = '#5A6B82';

        $cabecalhoPagina = function (MiniPdf $pdf) use ($ESCURO, $CINZA, $p) {
            $pdf->retangulo(0, 0, $pdf->largura, 96, $ESCURO);
            $pdf->cor('#FFFFFF');
            $pdf->texto('NOVA VARONIL', 48, 44, 20, 'negrito');
            $pdf->cor('#9FC2F5');
            $pdf->texto('Relatório detalhado de cliente', 48, 66, 9);
            $pdf->texto('Período ' . data_exibicao($p['inicio']) . ' a ' . data_exibicao($p['fim']),
                $pdf->largura - 48, 66, 9, 'normal', null, 'dir');
        };
        $cabecalhoPagina($pdf);

        $pdf->cor($ESCURO);
        $pdf->texto($cliente['nome'], 48, 134, 16, 'negrito', 480);
        $pdf->cor($CINZA);
        $pdf->texto((ROTULO_STATUS[$cliente['status']] ?? '') . '  ·  ' .
            $cliente['municipio'] . '/' . $cliente['uf'], 48, 152, 9);

        // Ficha cadastral em duas colunas
        $ficha = [
            ['CNPJ', cnpj_formatado($cliente['cnpj']), 48],
            ['TELEFONE', $cliente['telefone'] ?: '—', 48],
            ['E-MAIL', $cliente['email'] ?: '—', 48],
            ['LOCAL DE TRABALHO', $cliente['local_trabalho'] ?: '—', 310],
            ['ENDEREÇO', trim(($cliente['endereco'] ?? '') . ' ' . ($cliente['bairro'] ?? '')) ?: '—', 310],
            ['RESPONSÁVEL', $cliente['colaborador_nome'] ?: '—', 310],
        ];
        $y = ['48' => 185, '310' => 185];
        foreach ($ficha as [$rotulo, $valor, $x]) {
            $pdf->cor($CINZA);
            $pdf->texto($rotulo, (float) $x, $y[(string) $x], 8);
            $pdf->cor('#111827');
            $pdf->texto((string) $valor, (float) $x, $y[(string) $x] + 12, 10, 'normal', 230);
            $y[(string) $x] += 34;
        }

        // Faixa de indicadores
        $pdf->retangulo(48, 292, $pdf->largura - 96, 56, '#EAF1FB');
        $kpis = [
            ['FATURAMENTO NO PERÍODO', brl($totalPeriodo), 64],
            ['COMPRAS', (string) count($compras), 250],
            ['TICKET MÉDIO', brl($compras ? $totalPeriodo / count($compras) : 0), 350],
            ['ÚLTIMA COMPRA', data_exibicao($cliente['ultima_compra']), 470],
        ];
        foreach ($kpis as [$rotulo, $valor, $x]) {
            $pdf->cor($CINZA);
            $pdf->texto($rotulo, (float) $x, 310, 8);
            $pdf->cor($AZUL);
            $pdf->texto($valor, (float) $x, 328, 12, 'negrito');
        }

        $pdf->cor($ESCURO);
        $pdf->texto('Histórico de compras', 48, 384, 11, 'negrito');

        $colunas = [
            ['Data', 48, 62], ['Produto / serviço', 112, 200], ['Qtd', 318, 34],
            ['Unitário', 356, 68], ['Total', 428, 68], ['Pagamento', 500, 62],
        ];
        $y = 404;
        $desenharCabecalho = function () use ($pdf, $colunas, $ESCURO, &$y) {
            $pdf->retangulo(48, $y - 12, $pdf->largura - 96, 20, $ESCURO);
            $pdf->cor('#FFFFFF');
            foreach ($colunas as [$titulo, $x, $l]) $pdf->texto(mb_strtoupper($titulo), $x + 4, $y + 2, 8, 'negrito');
            $y += 24;
        };
        $desenharCabecalho();

        if (!$compras) {
            $pdf->cor($CINZA);
            $pdf->texto('Nenhuma compra registrada neste período.', 52, $y + 4, 10);
        }

        foreach ($compras as $i => $c) {
            if ($y > $pdf->altura - 80) {
                $pdf->novaPagina();
                $cabecalhoPagina($pdf);
                $y = 140;
                $desenharCabecalho();
            }
            if ($i % 2) $pdf->retangulo(48, $y - 10, $pdf->largura - 96, 18, '#F5F8FD');
            $celulas = [
                data_exibicao($c['data_compra']), $c['descricao'], (string) (float) $c['quantidade'],
                brl($c['valor_unitario']), brl($c['valor_total']), $c['forma_pagamento'],
            ];
            $pdf->cor('#111827');
            foreach ($colunas as $j => [$titulo, $x, $l]) {
                $pdf->texto((string) $celulas[$j], $x + 4, $y + 2, 8.5, 'normal', (float) $l);
            }
            $y += 18;
        }

        $pdf->cor($CINZA);
        $pdf->texto('Emitido em ' . date('d/m/Y H:i') . ' · Nova Varonil', 48, $pdf->altura - 48, 8);
        $pdf->enviar("relatorio-$slug.pdf");
    }

    throw new ErroApp(404, 'Formato de relatório não reconhecido.');
}
