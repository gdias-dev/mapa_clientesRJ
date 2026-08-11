<?php
declare(strict_types=1);

const CAMPOS_CLIENTE = ['nome','cnpj','telefone','email','local_trabalho','endereco','bairro',
                        'municipio','uf','latitude','longitude','status','colaborador_id','observacoes'];

function normalizar_cliente(array $corpo): array
{
    $dados = [];
    foreach (CAMPOS_CLIENTE as $c) if (array_key_exists($c, $corpo)) $dados[$c] = $corpo[$c];

    if (array_key_exists('cnpj', $dados)) {
        $cnpj = so_digitos($dados['cnpj']);
        if ($cnpj !== '' && !cnpj_valido($cnpj)) throw new ErroApp(400, 'CNPJ inválido.', ['cnpj']);
        $dados['cnpj'] = $cnpj !== '' ? $cnpj : null;
    }
    foreach (['latitude', 'longitude', 'colaborador_id'] as $c) {
        if (array_key_exists($c, $dados)) $dados[$c] = numero_ou_nulo($dados[$c]);
    }
    if (!empty($dados['uf'])) $dados['uf'] = mb_strtoupper(mb_substr((string) $dados['uf'], 0, 2));
    return $dados;
}

function rota_clientes(string $metodo, array $seg): void
{
    autenticar();
    $primeiro = $seg[0] ?? '';

    // -------------------------------------------------- pontos do mapa
    if ($primeiro === 'mapa' && $metodo === 'GET') {
        $where = ['latitude IS NOT NULL', 'longitude IS NOT NULL'];
        $params = [];
        if ($s = query_param('status')) { $where[] = 'status = ?'; $params[] = $s; }
        if ($c = query_param('colaborador_id')) { $where[] = 'colaborador_id = ?'; $params[] = $c; }

        $linhas = consulta(
            'SELECT id, nome, cnpj, municipio, bairro, local_trabalho, telefone, status,
                    latitude, longitude, colaborador_nome, faturamento_total, total_compras, ultima_compra
               FROM vw_clientes_resumo WHERE ' . implode(' AND ', $where) . ' ORDER BY nome', $params);
        responder(['dados' => numerizar($linhas, ['latitude', 'longitude', 'faturamento_total', 'total_compras'])]);
    }

    if ($primeiro === 'municipios' && $metodo === 'GET') {
        responder(['dados' => consulta(
            "SELECT municipio,
                    SUM(status = 'ativo')      AS ativos,
                    SUM(status = 'prospeccao') AS prospeccao,
                    COUNT(*)                   AS total
               FROM clientes GROUP BY municipio ORDER BY municipio")]);
    }

    // ------------------------------------------------------- listagem
    if ($primeiro === '' && $metodo === 'GET') {
        $where = [];
        $params = [];
        if ($b = query_param('busca')) {
            $where[] = '(LOWER(nome) LIKE ? OR cnpj LIKE ? OR LOWER(COALESCE(local_trabalho, "")) LIKE ?)';
            $like = '%' . mb_strtolower($b) . '%';
            array_push($params, $like, $like, $like);
        }
        foreach (['status' => 'status', 'municipio' => 'municipio', 'colaborador_id' => 'colaborador_id'] as $qs => $col) {
            if ($v = query_param($qs)) { $where[] = "$col = ?"; $params[] = $v; }
        }
        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $limite = min((int) (query_param('limite') ?? 50) ?: 50, 500);
        $pagina = max((int) (query_param('pagina') ?? 1) ?: 1, 1);
        $offset = ($pagina - 1) * $limite;

        // Sem window function: o MySQL da hospedagem pode ser 5.7.
        $total = (int) (consulta_um("SELECT COUNT(*) AS n FROM clientes $sqlWhere", $params)['n'] ?? 0);
        $linhas = consulta("SELECT * FROM vw_clientes_resumo $sqlWhere ORDER BY nome LIMIT $limite OFFSET $offset", $params);

        responder([
            'dados'  => numerizar($linhas, ['latitude', 'longitude', 'faturamento_total', 'total_compras']),
            'total'  => $total,
            'pagina' => $pagina,
            'limite' => $limite,
        ]);
    }

    // ------------------------------------------------------------ CRUD
    if ($primeiro === '' && $metodo === 'POST') {
        $dados = normalizar_cliente(corpo());
        exigir($dados, ['nome', 'municipio']);
        $cols = array_keys($dados);
        executa('INSERT INTO clientes (' . implode(',', $cols) . ') VALUES (' .
            implode(',', array_fill(0, count($cols), '?')) . ')', array_values($dados));
        responder(consulta_um('SELECT * FROM clientes WHERE id = ?', [db()->lastInsertId()]), 201);
    }

    $id = ctype_digit($primeiro) ? (int) $primeiro : null;
    if ($id === null) throw new ErroApp(404, 'Rota não encontrada.');

    if ($metodo === 'GET') {
        $cliente = consulta_um('SELECT * FROM vw_clientes_resumo WHERE id = ?', [$id]);
        if (!$cliente) throw new ErroApp(404, 'Cliente não encontrado.');
        $compras = consulta(
            'SELECT c.*, col.nome AS colaborador_nome
               FROM compras c LEFT JOIN colaboradores col ON col.id = c.colaborador_id
              WHERE c.cliente_id = ? ORDER BY c.data_compra DESC, c.id DESC', [$id]);
        responder([
            'cliente' => numerizar([$cliente], ['latitude', 'longitude', 'faturamento_total', 'total_compras'])[0],
            'compras' => numerizar($compras, ['quantidade', 'valor_unitario', 'valor_total']),
        ]);
    }

    if ($metodo === 'PUT') {
        $dados = normalizar_cliente(corpo());
        if (!$dados) throw new ErroApp(400, 'Nenhum campo informado para atualizar.');
        $sets = implode(', ', array_map(fn($c) => "$c = ?", array_keys($dados)));
        $n = executa("UPDATE clientes SET $sets WHERE id = ?", [...array_values($dados), $id]);
        if ($n === 0 && !consulta_um('SELECT id FROM clientes WHERE id = ?', [$id])) {
            throw new ErroApp(404, 'Cliente não encontrado.');
        }
        responder(consulta_um('SELECT * FROM clientes WHERE id = ?', [$id]));
    }

    if ($metodo === 'DELETE') {
        if (executa('DELETE FROM clientes WHERE id = ?', [$id]) === 0) {
            throw new ErroApp(404, 'Cliente não encontrado.');
        }
        http_response_code(204);
        exit;
    }

    throw new ErroApp(405, 'Método não permitido.');
}
