<?php
declare(strict_types=1);

/** Conexão única com o MySQL, em PDO com exceções e sem emulação de prepare. */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $cfg = require __DIR__ . '/../config.php';
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $cfg['db_host'], $cfg['db_porta'] ?? 3306, $cfg['db_nome']);

    try {
        $pdo = new PDO($dsn, $cfg['db_usuario'], $cfg['db_senha'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        throw new ErroApp(500, 'Não foi possível conectar ao banco. Confira os dados em config.local.php.');
    }
    return $pdo;
}

function consulta(string $sql, array $params = []): array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function consulta_um(string $sql, array $params = []): ?array
{
    $linhas = consulta($sql, $params);
    return $linhas[0] ?? null;
}

function executa(string $sql, array $params = []): int
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}

/**
 * MySQL devolve DECIMAL como string. Converte os campos numéricos conhecidos
 * para número, para o front e os gráficos receberem o tipo certo.
 */
function numerizar(array $linhas, array $campos): array
{
    foreach ($linhas as &$l) {
        foreach ($campos as $c) {
            if (array_key_exists($c, $l) && $l[$c] !== null) $l[$c] = (float) $l[$c];
        }
    }
    return $linhas;
}
