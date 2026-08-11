<?php
declare(strict_types=1);
/**
 * Instalador: cria as tabelas, o trigger e (opcionalmente) os dados de
 * demonstração. Abra no navegador:
 *   https://seudominio.com.br/painel/api/db/instalar.php?senha=...
 * Parâmetros: &demo=1 popula dados de exemplo; sem ele cria só o usuário admin.
 *
 * APAGUE ESTE ARQUIVO depois de usar.
 */
mb_internal_encoding('UTF-8');
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/db.php';

header('Content-Type: text/plain; charset=utf-8');
$cfg = require __DIR__ . '/../config.php';

if (($_GET['senha'] ?? '') !== ($cfg['senha_instalacao'] ?? '')) {
    http_response_code(403);
    exit("Senha de instalação incorreta. Defina 'senha_instalacao' em config.local.php.\n");
}

$pdo = db();
$log = fn(string $t) => print($t . "\n");


// -------------------------------------------------------------- usuários
$usuarios = [
    ['Administrador Nova Varonil', '.', '.', 'admin'],
    ['Equipe Comercial', 'c.', 'c.', 'operador'],
];
foreach ($usuarios as [$nome, $email, $senha, $papel]) {
    executa('INSERT INTO usuarios (nome, email, senha_hash, papel) VALUES (?,?,?,?)',
        [$nome, $email, password_hash($senha, PASSWORD_BCRYPT), $papel]);
}

// -------------------------------------------------- catálogo de produtos
$produtos = [
    ['Lente monofocal CR-39', 'lente', 45], ['Lente monofocal policarbonato', 'lente', 89],
    ['Lente monofocal 1.67 alto índice', 'lente', 210], ['Lente multifocal digital', 'lente', 480],
    ['Lente multifocal premium', 'lente', 790], ['Lente bifocal Kryptok', 'lente', 120],
    ['Lente ocupacional', 'lente', 360], ['Antirreflexo padrão', 'tratamento', 60],
    ['Antirreflexo premium blue', 'tratamento', 130], ['Tratamento fotossensível', 'tratamento', 180],
    ['Coloração / degradê', 'tratamento', 40], ['Endurecimento (hard coat)', 'tratamento', 35],
    ['Filtro de luz azul', 'tratamento', 95], ['Serviço de surfaçagem', 'servico', 55],
    ['Montagem em armação do cliente', 'servico', 30],
];
foreach ($produtos as $p) {
    executa('INSERT INTO produtos (nome, categoria, preco_base) VALUES (?,?,?)', $p);
}
$log('OK  catálogo de produtos criado (' . count($produtos) . ' itens).');

if (empty($_GET['demo'])) {
    $log('');
    $log('Pronto. Base limpa, só com usuários e catálogo.');
    $log('Para popular dados de demonstração, acrescente &demo=1 na URL.');
    $log('APAGUE este arquivo (db/instalar.php) agora.');
    exit;
}

// ---------------------------------------------------------- demonstração
$colaboradores = [
    ['Ana Beatriz Lopes', 'Comercial', '(21) 98811-0201', 'ana@novavaronil.com.br'],
    ['Ricardo Menezes', 'Comercial', '(21) 98811-0202', 'ricardo@novavaronil.com.br'],
    ['Juliana Braga', 'Atendimento', '(21) 98811-0203', 'juliana@novavaronil.com.br'],
    ['Marcos Tavares', 'Produção', '(21) 98811-0204', 'marcos@novavaronil.com.br'],
    ['Patrícia Nogueira', 'Financeiro', '(21) 98811-0205', 'patricia@novavaronil.com.br'],
];
$colabIds = [];
foreach ($colaboradores as $c) {
    executa('INSERT INTO colaboradores (nome, setor, telefone, email) VALUES (?,?,?,?)', $c);
    $colabIds[] = (int) $pdo->lastInsertId();
}

/** Recalcula os DV para os CNPJs de demonstração passarem na validação. */
function cnpj_demo(string $base14): string
{
    $base = substr($base14, 0, 12);
    $dv = function (string $s): int {
        $peso = strlen($s) - 7;
        $soma = 0;
        for ($i = 0; $i < strlen($s); $i++) { $soma += (int) $s[$i] * $peso--; if ($peso < 2) $peso = 9; }
        $r = $soma % 11;
        return $r < 2 ? 0 : 11 - $r;
    };
    $d1 = $dv($base);
    return $base . $d1 . $dv($base . $d1);
}

$clientes = [
    ['Óptica Visão Central', '04572841000163', 'Rua da Alfândega, 120', 'Centro', 'Rio de Janeiro', -22.9035, -43.1791, 'ativo'],
    ['Ótica Olhar Carioca', '19278341000180', 'Rua Dias da Cruz, 480', 'Méier', 'Rio de Janeiro', -22.9024, -43.2789, 'ativo'],
    ['Óptica Copacabana Lentes', '27865439000107', 'Av. N. S. de Copacabana, 900', 'Copacabana', 'Rio de Janeiro', -22.9711, -43.1822, 'ativo'],
    ['Ótica Tijuca Vision', '33920178000104', 'Rua Conde de Bonfim, 350', 'Tijuca', 'Rio de Janeiro', -22.9245, -43.2333, 'ativo'],
    ['Óptica Barra Óptica', '11778392000160', 'Av. das Américas, 4666', 'Barra da Tijuca', 'Rio de Janeiro', -23.0045, -43.3220, 'ativo'],
    ['Ótica Campo Grande Foco', '55219038000172', 'Rua Coronel Agostinho, 200', 'Campo Grande', 'Rio de Janeiro', -22.9053, -43.5605, 'prospeccao'],
    ['Óptica Madureira Luz', '87421903000148', 'Estrada do Portela, 90', 'Madureira', 'Rio de Janeiro', -22.8724, -43.3396, 'ativo'],
    ['Ótica Niterói Vision', '62109834000155', 'Rua Gavião Peixoto, 88', 'Icaraí', 'Niterói', -22.9006, -43.1032, 'ativo'],
    ['Óptica Centro de Niterói', '31840927000116', 'Av. Amaral Peixoto, 210', 'Centro', 'Niterói', -22.8940, -43.1213, 'prospeccao'],
    ['Ótica São Gonçalo Lentes', '70382914000130', 'Rua Dr. Nilo Peçanha, 45', 'Centro', 'São Gonçalo', -22.8268, -43.0634, 'ativo'],
    ['Óptica Nova Iguaçu Clara', '48120973000122', 'Av. Gov. Amaral Peixoto, 700', 'Centro', 'Nova Iguaçu', -22.7592, -43.4510, 'ativo'],
    ['Ótica Duque Visão', '90218374000199', 'Av. Presidente Vargas, 1200', 'Centro', 'Duque de Caxias', -22.7858, -43.3117, 'ativo'],
    ['Óptica Belford Roxo Olhar', '15903827000174', 'Rua Floripes Rocha, 30', 'Centro', 'Belford Roxo', -22.7642, -43.3996, 'prospeccao'],
    ['Ótica Petrópolis Serra', '26718490000121', 'Rua do Imperador, 500', 'Centro', 'Petrópolis', -22.5050, -43.1786, 'ativo'],
    ['Óptica Teresópolis Alto', '81093472000118', 'Av. Feliciano Sodré, 210', 'Várzea', 'Teresópolis', -22.4118, -42.9658, 'prospeccao'],
    ['Ótica Nova Friburgo Lentes', '39281740000165', 'Rua General Osório, 77', 'Centro', 'Nova Friburgo', -22.2820, -42.5311, 'ativo'],
    ['Óptica Volta Redonda Foco', '52830917000180', 'Rua Trinta e Três, 145', 'Vila Santa Cecília', 'Volta Redonda', -22.5202, -44.0996, 'ativo'],
    ['Ótica Barra Mansa Vision', '74019283000109', 'Rua Pinto Ribeiro, 60', 'Centro', 'Barra Mansa', -22.5441, -44.1712, 'prospeccao'],
    ['Óptica Resende Clara', '68204719000132', 'Rua Barão de Itapeva, 90', 'Centro', 'Resende', -22.4685, -44.4468, 'prospeccao'],
    ['Ótica Angra Litoral', '13579024000186', 'Rua do Comércio, 15', 'Centro', 'Angra dos Reis', -23.0067, -44.3181, 'ativo'],
    ['Óptica Cabo Frio Sol', '24680135000147', 'Av. Assunção, 320', 'Braga', 'Cabo Frio', -22.8894, -42.0286, 'ativo'],
    ['Ótica Búzios Vista', '35791246000108', 'Rua das Pedras, 200', 'Centro', 'Armação dos Búzios', -22.7469, -41.8817, 'prospeccao'],
    ['Óptica Macaé Petro', '46802357000169', 'Av. Rui Barbosa, 410', 'Centro', 'Macaé', -22.3708, -41.7869, 'ativo'],
    ['Ótica Campos Visão', '57913468000120', 'Av. Sete de Setembro, 155', 'Centro', 'Campos dos Goytacazes', -21.7622, -41.3181, 'ativo'],
    ['Óptica Itaperuna Norte', '69024579000181', 'Rua Dez de Maio, 60', 'Centro', 'Itaperuna', -21.2050, -41.8878, 'prospeccao'],
    ['Ótica Itaboraí Leste', '70135680000142', 'Rua Nilo Peçanha, 25', 'Centro', 'Itaboraí', -22.7440, -42.8592, 'prospeccao'],
    ['Óptica Maricá Lagoa', '81246791000103', 'Av. Ver. Francisco Sabino, 88', 'Centro', 'Maricá', -22.9194, -42.8186, 'ativo'],
    ['Ótica Rio Bonito Central', '92357802000164', 'Rua Dr. Aureliano, 44', 'Centro', 'Rio Bonito', -22.7080, -42.6180, 'prospeccao'],
    ['Óptica Três Rios Vale', '10468913000125', 'Rua Sete de Setembro, 300', 'Centro', 'Três Rios', -22.1163, -43.2093, 'prospeccao'],
    ['Ótica Saquarema Onda', '21579024000186', 'Av. Saquarema, 1200', 'Bacaxá', 'Saquarema', -22.9292, -42.5099, 'ativo'],
];

$ativos = [];
foreach ($clientes as [$nome, $cnpj, $endereco, $bairro, $municipio, $lat, $lng, $status]) {
    executa(
        'INSERT INTO clientes (nome, cnpj, telefone, email, local_trabalho, endereco, bairro,
                               municipio, latitude, longitude, status, colaborador_id)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
        [$nome, cnpj_demo($cnpj), sprintf('(21) 9%04d-%04d', random_int(1000, 9999), random_int(1000, 9999)),
         'contato@' . preg_replace('/\W+/u', '', mb_strtolower($nome)) . '.com.br',
         "$nome — loja $bairro", $endereco, $bairro, $municipio, $lat, $lng, $status,
         $colabIds[array_rand($colabIds)]]);
    if ($status === 'ativo') $ativos[] = (int) $pdo->lastInsertId();
}
$log('OK  ' . count($clientes) . ' clientes criados.');

// Compras só para quem já é cliente ativo; prospecção fica sem histórico.
$catalogo = consulta('SELECT id, nome, preco_base FROM produtos');
$pagamentos = ['boleto', 'pix', 'cartao', 'transferencia', 'prazo'];
$inseridas = 0;

$pdo->beginTransaction();
$st = $pdo->prepare(
    'INSERT INTO compras (cliente_id, produto_id, descricao, quantidade, valor_unitario,
                          data_compra, forma_pagamento, colaborador_id)
     VALUES (?,?,?,?,?,?,?,?)');
foreach ($ativos as $clienteId) {
    $volume = random_int(15, 60);
    for ($i = 0; $i < $volume; $i++) {
        $p = $catalogo[array_rand($catalogo)];
        $st->execute([
            $clienteId, $p['id'], $p['nome'], random_int(1, 12),
            round((float) $p['preco_base'] * (0.85 + random_int(0, 40) / 100), 2),
            date('Y-m-d', strtotime('-' . random_int(0, 730) . ' days')),
            $pagamentos[array_rand($pagamentos)],
            $colabIds[array_rand($colabIds)],
        ]);
        $inseridas++;
    }
}
$pdo->commit();

$log("OK  $inseridas compras geradas.");
$log('');
$log('Instalação concluída. APAGUE este arquivo (db/instalar.php) agora.');
