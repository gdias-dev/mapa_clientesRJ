<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/planilha.php';
require_once __DIR__ . '/../lib/xlsx.php';

/**
 * Importação em massa a partir de relatório (.xlsx / .csv), em duas etapas:
 *   POST /importacao/analisar  → lê, valida e devolve a prévia SEM gravar.
 *   POST /importacao/confirmar → grava as linhas revisadas numa transação.
 */

const DICIONARIO_IMPORT = [
    'clientes' => [
        'nome' => ['nome', 'cliente', 'razao social', 'razao', 'empresa', 'otica', 'optica', 'nome fantasia'],
        'cnpj' => ['cnpj', 'cnpj cpf', 'documento', 'cpf cnpj'],
        'telefone' => ['telefone', 'fone', 'celular', 'contato', 'whatsapp'],
        'email' => ['email', 'e mail'],
        'local_trabalho' => ['local de trabalho', 'local', 'loja', 'onde trabalha', 'estabelecimento'],
        'endereco' => ['endereco', 'logradouro', 'rua'],
        'bairro' => ['bairro'],
        'municipio' => ['municipio', 'cidade'],
        'uf' => ['uf', 'estado'],
        'latitude' => ['latitude', 'lat'],
        'longitude' => ['longitude', 'long', 'lng'],
        'status' => ['status', 'situacao'],
        'colaborador' => ['colaborador', 'responsavel', 'vendedor', 'representante'],
        'observacoes' => ['observacao', 'observacoes', 'obs'],
    ],
    'compras' => [
        'cliente_cnpj' => ['cnpj', 'cnpj cliente', 'documento'],
        'cliente_nome' => ['cliente', 'nome do cliente', 'razao social', 'nome'],
        'descricao' => ['produto', 'servico', 'produto servico', 'descricao', 'item', 'lente'],
        'quantidade' => ['quantidade', 'qtd', 'qtde', 'qte'],
        'valor_unitario' => ['valor unitario', 'preco unitario', 'valor unit', 'preco', 'unitario'],
        'valor_total' => ['valor total', 'total', 'valor'],
        'data_compra' => ['data', 'data da compra', 'data compra', 'emissao', 'data pedido'],
        'forma_pagamento' => ['forma de pagamento', 'pagamento', 'forma pagto', 'condicao'],
        'colaborador' => ['colaborador', 'responsavel', 'vendedor'],
        'observacao' => ['observacao', 'obs'],
    ],
];

function normalizar_pagamento(mixed $v): string
{
    $s = chave_nome($v);
    if ($s === '') return 'boleto';
    if (str_contains($s, 'pix')) return 'pix';
    if (str_contains($s, 'cart') || str_contains($s, 'credito') || str_contains($s, 'debito')) return 'cartao';
    if (str_contains($s, 'dinheiro') || str_contains($s, 'especie')) return 'dinheiro';
    if (str_contains($s, 'transfer') || str_contains($s, 'ted') || str_contains($s, 'doc')) return 'transferencia';
    if (str_contains($s, 'prazo') || str_contains($s, 'faturado') || str_contains($s, 'dias')) return 'prazo';
    return in_array($s, ['boleto', 'pix', 'cartao', 'dinheiro', 'transferencia', 'prazo'], true) ? $s : 'boleto';
}

function normalizar_status_import(mixed $v): ?string
{
    $s = chave_nome($v);
    if (str_contains($s, 'prospec')) return 'prospeccao';
    if (str_contains($s, 'inativ')) return 'inativo';
    if (str_contains($s, 'ativ') || str_contains($s, 'cliente')) return 'ativo';
    return null;
}

function indices_import(): array
{
    $clientes = consulta('SELECT id, nome, cnpj, municipio FROM clientes');
    $porCnpj = $porNome = [];
    foreach ($clientes as $c) {
        if ($c['cnpj']) $porCnpj[$c['cnpj']] = $c;
        $porNome[chave_nome($c['nome'])] = $c;
    }
    $colaboradores = [];
    foreach (consulta('SELECT id, nome, email FROM colaboradores') as $c) {
        $colaboradores[] = ['id' => (int) $c['id'], 'nome' => $c['nome'],
                            'chave' => chave_nome($c['nome']), 'email' => chave_nome($c['email'] ?? '')];
    }
    $produtos = [];
    foreach (consulta('SELECT id, nome FROM produtos') as $p) $produtos[chave_nome($p['nome'])] = (int) $p['id'];

    return compact('porCnpj', 'porNome', 'colaboradores', 'produtos');
}

/**
 * Acha o colaborador pelo que veio na planilha: nome completo, e-mail ou
 * nome abreviado ("Ana" ou "Ana Beatriz" → "Ana Beatriz Lopes"), desde que
 * só um colaborador combine. Devolve null quando não acha ou fica ambíguo.
 */
function achar_colaborador(string $texto, array $colaboradores): ?array
{
    $k = chave_nome($texto);
    if ($k === '') return null;
    foreach ($colaboradores as $c) if ($c['chave'] === $k || ($c['email'] !== '' && $c['email'] === $k)) return $c;

    $candidatos = array_values(array_filter($colaboradores, fn($c) =>
        str_starts_with($c['chave'] . ' ', $k . ' ') || str_starts_with($k . ' ', $c['chave'] . ' ')));
    return count($candidatos) === 1 ? $candidatos[0] : null;
}

/**
 * Resolve o colaborador de uma linha. Se não existir, a linha leva o nome em
 * "colaborador_novo" e ele é cadastrado automaticamente na confirmação.
 */
function resolver_colaborador_linha(string $nome, array $idx, array &$alertas): array
{
    if ($nome === '') return ['colaborador_id' => null, 'colaborador_novo' => null, 'colaborador_nome' => null];
    $c = achar_colaborador($nome, $idx['colaboradores']);
    if ($c) return ['colaborador_id' => $c['id'], 'colaborador_novo' => null, 'colaborador_nome' => $c['nome']];
    $alertas[] = "Colaborador \"$nome\" ainda não existe — será cadastrado automaticamente.";
    return ['colaborador_id' => null, 'colaborador_novo' => $nome, 'colaborador_nome' => "$nome (novo)"];
}

function rota_importacao(string $metodo, array $seg): void
{
    autenticar();
    $acao = $seg[0] ?? '';

    // --------------------------------------------------------- 1) análise
    if ($acao === 'analisar' && $metodo === 'POST') {
        $tipo = ($_POST['tipo'] ?? 'clientes') === 'compras' ? 'compras' : 'clientes';
        if (empty($_FILES['arquivo']['tmp_name']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
            throw new ErroApp(400, 'Nenhum arquivo recebido (ou arquivo acima do limite do servidor).');
        }
        $nomeArquivo = (string) $_FILES['arquivo']['name'];
        if (!preg_match('/\.(xlsx|xlsm|csv)$/i', $nomeArquivo)) {
            throw new ErroApp(400, 'Envie um arquivo .xlsx ou .csv.');
        }

        ['colunas' => $colunas, 'linhas' => $linhas] =
            ler_planilha($_FILES['arquivo']['tmp_name'], $nomeArquivo);
        if (!$linhas) throw new ErroApp(400, 'A planilha está vazia ou não tem linha de cabeçalho.');

        // O operador pode corrigir o mapeamento na tela e reenviar.
        $mapa = !empty($_POST['mapa']) ? json_decode((string) $_POST['mapa'], true) : null;
        if (!is_array($mapa)) $mapa = mapear_colunas($colunas, DICIONARIO_IMPORT[$tipo]);

        $idx = indices_import();
        $vistos = [];
        $registros = [];

        foreach ($linhas as $i => $linha) {
            $valor = fn(string $campo) => isset($mapa[$campo]) ? ($linha[$mapa[$campo]] ?? '') : '';
            $erros = [];
            $alertas = [];
            $numeroLinha = $i + 2; // +1 do cabeçalho, +1 porque planilha começa em 1

            if ($tipo === 'clientes') {
                $nome = texto($valor('nome'));
                $cnpj = so_digitos($valor('cnpj'));
                $municipio = texto($valor('municipio'));

                if ($nome === '') $erros[] = 'Nome em branco.';
                if ($municipio === '') $erros[] = 'Município em branco.';
                if ($cnpj !== '' && !cnpj_valido($cnpj)) $erros[] = 'CNPJ inválido.';

                $existente = ($cnpj !== '' ? ($idx['porCnpj'][$cnpj] ?? null) : null)
                    ?? ($idx['porNome'][chave_nome($nome)] ?? null);
                if ($cnpj !== '' && isset($vistos[$cnpj])) $erros[] = 'CNPJ repetido dentro da própria planilha.';
                if ($cnpj !== '') $vistos[$cnpj] = true;

                $colab = resolver_colaborador_linha(texto($valor('colaborador')), $idx, $alertas);
                if (!isset($mapa['colaborador'])) $alertas[] = 'Planilha sem coluna "Colaborador" — cliente ficará sem responsável.';

                $lat = moeda($valor('latitude'));
                $lng = moeda($valor('longitude'));
                if (!$lat || !$lng) $alertas[] = 'Sem coordenadas — não aparecerá no mapa.';

                $registros[] = [
                    'linha' => $numeroLinha,
                    'acao' => $existente ? 'atualizar' : 'criar',
                    'existente_id' => $existente ? (int) $existente['id'] : null,
                    'erros' => $erros,
                    'alertas' => $alertas,
                    'dados' => [
                        'nome' => $nome,
                        'cnpj' => $cnpj !== '' ? $cnpj : null,
                        'telefone' => texto($valor('telefone')) ?: null,
                        'email' => texto($valor('email')) ?: null,
                        'local_trabalho' => texto($valor('local_trabalho')) ?: null,
                        'endereco' => texto($valor('endereco')) ?: null,
                        'bairro' => texto($valor('bairro')) ?: null,
                        'municipio' => $municipio,
                        'uf' => mb_strtoupper(mb_substr(texto($valor('uf')) ?: 'RJ', 0, 2)),
                        'latitude' => $lat,
                        'longitude' => $lng,
                        'status' => normalizar_status_import($valor('status')) ?? 'prospeccao',
                        'colaborador_id' => $colab['colaborador_id'],
                        'colaborador_novo' => $colab['colaborador_novo'],
                        'colaborador_nome' => $colab['colaborador_nome'],
                        'observacoes' => texto($valor('observacoes')) ?: null,
                    ],
                ];
            } else {
                $cnpj = so_digitos($valor('cliente_cnpj'));
                $nomeCliente = texto($valor('cliente_nome'));
                $cliente = ($cnpj !== '' ? ($idx['porCnpj'][$cnpj] ?? null) : null)
                    ?? ($idx['porNome'][chave_nome($nomeCliente)] ?? null);

                $descricao = texto($valor('descricao'));
                $quantidade = moeda($valor('quantidade')) ?: 1.0;
                $unitario = moeda($valor('valor_unitario'));
                $total = moeda($valor('valor_total'));
                // Muito relatório traz só o total: nesse caso o unitário sai por divisão.
                if ($unitario === null && $total !== null) $unitario = $quantidade ? $total / $quantidade : $total;
                $dataCompra = data_br($valor('data_compra'));

                if (!$cliente) $erros[] = 'Cliente não encontrado (' . ($nomeCliente ?: $cnpj ?: 'sem identificação') . '). Importe a lista de clientes primeiro.';
                if ($descricao === '') $erros[] = 'Produto/serviço em branco.';
                if ($unitario === null) $erros[] = 'Valor não reconhecido.';
                if (!$dataCompra) $erros[] = 'Data ausente ou em formato não reconhecido.';

                $colab = resolver_colaborador_linha(texto($valor('colaborador')), $idx, $alertas);

                $produtoId = $idx['produtos'][chave_nome($descricao)] ?? null;
                if (!$produtoId && $descricao !== '') $alertas[] = 'Item fora do catálogo — será gravado como avulso.';

                $registros[] = [
                    'linha' => $numeroLinha,
                    'acao' => 'criar',
                    'cliente_nome' => $cliente['nome'] ?? $nomeCliente,
                    'erros' => $erros,
                    'alertas' => $alertas,
                    'dados' => [
                        'cliente_id' => $cliente ? (int) $cliente['id'] : null,
                        'produto_id' => $produtoId,
                        'descricao' => $descricao,
                        'quantidade' => $quantidade,
                        'valor_unitario' => $unitario === null ? null : round($unitario, 2),
                        'data_compra' => $dataCompra,
                        'forma_pagamento' => normalizar_pagamento($valor('forma_pagamento')),
                        'colaborador_id' => $colab['colaborador_id'],
                        'colaborador_novo' => $colab['colaborador_novo'],
                        'colaborador_nome' => $colab['colaborador_nome'],
                        'observacao' => texto($valor('observacao')) ?: null,
                    ],
                ];
            }
        }

        $validos = array_filter($registros, fn($r) => !$r['erros']);
        responder([
            'tipo' => $tipo,
            'colunas' => $colunas,
            'mapa' => $mapa,
            'total' => count($registros),
            'validos' => count($validos),
            'comErro' => count($registros) - count($validos),
            'registros' => $registros,
        ]);
    }

    // -------------------------------------------------------- 2) gravação
    if ($acao === 'confirmar' && $metodo === 'POST') {
        $corpo = corpo();
        $tipo = ($corpo['tipo'] ?? 'clientes') === 'compras' ? 'compras' : 'clientes';
        $registros = $corpo['registros'] ?? [];
        $atualizarExistentes = (bool) ($corpo['atualizarExistentes'] ?? true);
        if (!is_array($registros) || !$registros) throw new ErroApp(400, 'Nenhum registro para importar.');

        $validos = array_values(array_filter($registros, fn($r) => empty($r['erros'])));
        if (!$validos) throw new ErroApp(400, 'Todas as linhas têm erro. Corrija a planilha e envie de novo.');

        $pdo = db();
        $criados = $atualizados = $colaboradoresCriados = 0;
        $pdo->beginTransaction();
        try {
            // Cadastra uma única vez cada colaborador novo citado na planilha
            // (rechecando a base, caso alguém o tenha criado depois da prévia).
            $colabs = indices_import()['colaboradores'];
            $novos = [];
            foreach ($validos as $r) {
                $nome = texto($r['dados']['colaborador_novo'] ?? '');
                if ($nome === '' || isset($novos[chave_nome($nome)])) continue;
                $existente = achar_colaborador($nome, $colabs);
                if ($existente) { $novos[chave_nome($nome)] = $existente['id']; continue; }
                executa('INSERT INTO colaboradores (nome, setor) VALUES (?, ?)', [mb_substr($nome, 0, 120), 'Comercial']);
                $id = (int) $pdo->lastInsertId();
                $novos[chave_nome($nome)] = $id;
                $colabs[] = ['id' => $id, 'nome' => $nome, 'chave' => chave_nome($nome), 'email' => ''];
                $colaboradoresCriados++;
            }

            foreach ($validos as $r) {
                $d = $r['dados'];
                $novo = texto($d['colaborador_novo'] ?? '');
                if ($novo !== '') $d['colaborador_id'] = $novos[chave_nome($novo)] ?? null;
                $d['colaborador_id'] = isset($d['colaborador_id']) && $d['colaborador_id'] !== null ? (int) $d['colaborador_id'] : null;
                if ($tipo === 'clientes') {
                    if (($r['acao'] ?? '') === 'atualizar' && !empty($r['existente_id'])) {
                        if (!$atualizarExistentes) continue;
                        // COALESCE: célula vazia na planilha não apaga dado bom já cadastrado.
                        executa(
                            'UPDATE clientes SET
                               nome = ?,
                               telefone = COALESCE(?, telefone),
                               email = COALESCE(?, email),
                               local_trabalho = COALESCE(?, local_trabalho),
                               endereco = COALESCE(?, endereco),
                               bairro = COALESCE(?, bairro),
                               municipio = ?,
                               uf = ?,
                               latitude = COALESCE(?, latitude),
                               longitude = COALESCE(?, longitude),
                               colaborador_id = COALESCE(?, colaborador_id),
                               observacoes = COALESCE(?, observacoes)
                             WHERE id = ?',
                            [$d['nome'], $d['telefone'], $d['email'], $d['local_trabalho'], $d['endereco'],
                             $d['bairro'], $d['municipio'], $d['uf'], $d['latitude'], $d['longitude'],
                             $d['colaborador_id'], $d['observacoes'], $r['existente_id']]);
                        $atualizados++;
                    } else {
                        executa(
                            'INSERT INTO clientes (nome, cnpj, telefone, email, local_trabalho, endereco,
                                                   bairro, municipio, uf, latitude, longitude, status,
                                                   colaborador_id, observacoes)
                             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                            [$d['nome'], $d['cnpj'], $d['telefone'], $d['email'], $d['local_trabalho'],
                             $d['endereco'], $d['bairro'], $d['municipio'], $d['uf'], $d['latitude'],
                             $d['longitude'], $d['status'], $d['colaborador_id'], $d['observacoes']]);
                        $criados++;
                    }
                } else {
                    executa(
                        'INSERT INTO compras (cliente_id, produto_id, descricao, quantidade, valor_unitario,
                                              data_compra, forma_pagamento, colaborador_id, observacao)
                         VALUES (?,?,?,?,?,?,?,?,?)',
                        [$d['cliente_id'], $d['produto_id'], $d['descricao'], $d['quantidade'],
                         $d['valor_unitario'], $d['data_compra'], $d['forma_pagamento'],
                         $d['colaborador_id'], $d['observacao']]);
                    $criados++;
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        responder([
            'ok' => true,
            'criados' => $criados,
            'atualizados' => $atualizados,
            'colaboradoresCriados' => $colaboradoresCriados,
            'ignorados' => count($registros) - count($validos),
        ]);
    }

    // ---------------------------------------------------- planilha modelo
    if ($acao === 'modelo' && $metodo === 'GET') {
        $tipo = ($seg[1] ?? 'clientes') === 'compras' ? 'compras' : 'clientes';
        $xlsx = new MiniXlsx();

        if ($tipo === 'clientes') {
            $xlsx->aba('Clientes', array_map(fn($c) => ['titulo' => $c[0], 'largura' => $c[1]], [
                ['Nome', 28], ['CNPJ', 20], ['Telefone', 16], ['E-mail', 26], ['Local de trabalho', 26],
                ['Endereço', 30], ['Bairro', 18], ['Município', 20], ['UF', 6], ['Latitude', 12],
                ['Longitude', 12], ['Status', 14], ['Colaborador', 20], ['Observações', 30],
            ]), [[
                'Óptica Exemplo', '04.572.841/0001-24', '(21) 99999-0000', 'contato@exemplo.com.br',
                'Loja Centro', 'Rua da Alfândega, 120', 'Centro', 'Rio de Janeiro', 'RJ',
                '-22.9035', '-43.1791', 'prospeccao', 'Ana Beatriz Lopes', '',
            ]]);
        } else {
            $xlsx->aba('Compras', array_map(fn($c) => ['titulo' => $c[0], 'largura' => $c[1]], [
                ['CNPJ', 20], ['Cliente', 30], ['Produto', 30], ['Quantidade', 12],
                ['Valor unitário', 16], ['Valor total', 14], ['Data', 12],
                ['Forma de pagamento', 20], ['Colaborador', 20], ['Observação', 26],
            ]), [[
                '04.572.841/0001-24', 'Óptica Exemplo', 'Lente multifocal digital', '4',
                '480,00', '1.920,00', '15/03/2026', 'Boleto', 'Ana Beatriz Lopes', '',
            ]]);
        }

        $xlsx->aba('Como preencher', [['titulo' => 'Instruções', 'largura' => 96]], array_map(fn($t) => [$t], [
            'Apague a linha de exemplo antes de importar.',
            'A ordem das colunas não importa — o sistema identifica pelo nome do cabeçalho.',
            'Colunas que você não usa podem ser removidas.',
            $tipo === 'clientes'
                ? 'Obrigatórios: Nome e Município. Sem Latitude e Longitude o cliente não aparece no mapa.'
                : 'Obrigatórios: Data, Produto, valor e a identificação do cliente (CNPJ ou nome como está cadastrado).',
            $tipo === 'clientes'
                ? 'Se o CNPJ já existir na base, o cadastro é atualizado em vez de duplicado.'
                : 'Se a planilha trouxer apenas o valor total, o unitário é calculado dividindo pela quantidade.',
            'Datas aceitas: 15/03/2026 ou 2026-03-15. Valores aceitos: 1.234,56 ou 1234.56.',
            'Status válidos: ativo, prospeccao, inativo. Em branco, entra como prospecção.',
            'Colaborador: escreva o nome do responsável (completo, só o primeiro nome ou o e-mail, se não houver outro igual).',
            'Colaborador que ainda não existe é cadastrado automaticamente (setor Comercial) — depois complete telefone e e-mail na tela Colaboradores.',
        ]));

        $xlsx->enviar("modelo-$tipo.xlsx");
    }

    throw new ErroApp(404, 'Rota não encontrada.');
}
