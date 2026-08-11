<?php
declare(strict_types=1);

/**
 * Leitura de .xlsx e .csv enviados pelo painel.
 * O .xlsx é lido direto do zip (sharedStrings + primeira planilha), sem
 * biblioteca externa — suficiente para relatório tabular simples.
 */

function detectar_separador(string $linha): string
{
    $melhor = ';';
    $maior = 0;
    foreach ([';', ',', "\t", '|'] as $sep) {
        $n = count(explode($sep, $linha));
        if ($n > $maior) { $maior = $n; $melhor = $sep; }
    }
    return $melhor;
}

function ler_csv(string $texto): array
{
    $texto = preg_replace('/^\xEF\xBB\xBF/', '', $texto) ?? $texto;
    $texto = str_replace(["\r\n", "\r"], "\n", $texto);
    $primeira = strtok($texto, "\n") ?: '';
    $sep = detectar_separador($primeira);

    $linhas = [];
    $campo = '';
    $linha = [];
    $aspas = false;
    $tam = strlen($texto);
    for ($i = 0; $i < $tam; $i++) {
        $ch = $texto[$i];
        if ($aspas) {
            if ($ch === '"') {
                if (($texto[$i + 1] ?? '') === '"') { $campo .= '"'; $i++; }
                else $aspas = false;
            } else $campo .= $ch;
        } elseif ($ch === '"') $aspas = true;
        elseif ($ch === $sep) { $linha[] = $campo; $campo = ''; }
        elseif ($ch === "\n") { $linha[] = $campo; $linhas[] = $linha; $linha = []; $campo = ''; }
        else $campo .= $ch;
    }
    if ($campo !== '' || $linha) { $linha[] = $campo; $linhas[] = $linha; }

    return array_values(array_filter($linhas, fn($l) => implode('', array_map('trim', $l)) !== ''));
}

function ler_xlsx(string $caminho): array
{
    $zip = new ZipArchive();
    if ($zip->open($caminho) !== true) throw new ErroApp(400, 'Não foi possível abrir o arquivo .xlsx.');

    $compartilhadas = [];
    if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $sst = simplexml_load_string($xml);
        foreach ($sst->si as $si) {
            $compartilhadas[] = isset($si->t) ? (string) $si->t
                : implode('', array_map(fn($r) => (string) $r->t, iterator_to_array($si->r ?? [])));
        }
    }

    $nome = 'xl/worksheets/sheet1.xml';
    if ($zip->locateName($nome) === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if (str_starts_with($n, 'xl/worksheets/') && str_ends_with($n, '.xml')) { $nome = $n; break; }
        }
    }
    $xml = $zip->getFromName($nome);
    $zip->close();
    if ($xml === false) throw new ErroApp(400, 'Planilha sem aba de dados.');

    $ws = simplexml_load_string($xml);
    $linhas = [];
    foreach ($ws->sheetData->row as $row) {
        $atual = [];
        foreach ($row->c as $c) {
            $ref = (string) $c['r'];
            preg_match('/^([A-Z]+)/', $ref, $m);
            $indice = 0;
            foreach (str_split($m[1] ?? 'A') as $letra) $indice = $indice * 26 + (ord($letra) - 64);
            $indice--;

            $tipo = (string) $c['t'];
            if ($tipo === 's') $valor = $compartilhadas[(int) $c->v] ?? '';
            elseif ($tipo === 'inlineStr') $valor = (string) ($c->is->t ?? '');
            else $valor = isset($c->v) ? (string) $c->v : '';
            $atual[$indice] = $valor;
        }
        if ($atual) {
            ksort($atual);
            $completa = [];
            for ($i = 0; $i <= max(array_keys($atual)); $i++) $completa[] = $atual[$i] ?? '';
            if (implode('', array_map('trim', $completa)) !== '') $linhas[] = $completa;
        }
    }
    return $linhas;
}

/** Devolve ['colunas' => [...], 'linhas' => [ ['Cabeçalho' => valor], ... ] ]. */
function ler_planilha(string $caminho, string $nomeArquivo): array
{
    if (preg_match('/\.csv$/i', $nomeArquivo)) {
        $texto = file_get_contents($caminho) ?: '';
        // Exportação de ERP antigo costuma vir em Latin-1.
        if (!mb_check_encoding($texto, 'UTF-8')) $texto = mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
        $matriz = ler_csv($texto);
    } else {
        $matriz = ler_xlsx($caminho);
    }
    if (!$matriz) return ['colunas' => [], 'linhas' => []];

    $cabecalho = [];
    foreach ($matriz[0] as $i => $c) {
        $t = trim((string) $c);
        $cabecalho[] = $t !== '' ? $t : 'Coluna ' . ($i + 1);
    }

    $linhas = [];
    foreach (array_slice($matriz, 1) as $l) {
        $registro = [];
        foreach ($cabecalho as $i => $nome) $registro[$nome] = $l[$i] ?? '';
        $linhas[] = $registro;
    }
    return ['colunas' => $cabecalho, 'linhas' => $linhas];
}

/** Adivinha qual coluna corresponde a cada campo, comparando com sinônimos. */
function mapear_colunas(array $colunas, array $dicionario): array
{
    $norm = fn($s) => preg_replace('/[^a-z0-9 ]/', '', chave_nome($s));
    $mapa = [];
    foreach ($dicionario as $campo => $sinonimos) {
        foreach ($colunas as $c) {
            foreach ($sinonimos as $s) if ($norm($c) === $norm($s)) { $mapa[$campo] = $c; break 2; }
        }
        foreach ($colunas as $c) {
            foreach ($sinonimos as $s) if (str_contains($norm($c), $norm($s))) { $mapa[$campo] = $c; break 2; }
        }
    }
    return $mapa;
}
