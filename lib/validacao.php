<?php
declare(strict_types=1);

function so_digitos(mixed $v): string { return preg_replace('/\D/', '', (string) $v) ?? ''; }

/** Valida CNPJ pelos dois dígitos verificadores. */
function cnpj_valido(mixed $valor): bool
{
    $cnpj = so_digitos($valor);
    if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) return false;
    $dv = function (string $base): int {
        $peso = strlen($base) - 7;
        $soma = 0;
        for ($i = 0; $i < strlen($base); $i++) {
            $soma += (int) $base[$i] * $peso--;
            if ($peso < 2) $peso = 9;
        }
        $r = $soma % 11;
        return $r < 2 ? 0 : 11 - $r;
    };
    $d1 = $dv(substr($cnpj, 0, 12));
    $d2 = $dv(substr($cnpj, 0, 12) . $d1);
    return $cnpj === substr($cnpj, 0, 12) . $d1 . $d2;
}

function texto(mixed $v): string { return trim((string) ($v ?? '')); }

function numero_ou_nulo(mixed $v): ?float
{
    if ($v === null || $v === '' ) return null;
    return is_numeric($v) ? (float) $v : null;
}

/** Aceita "1.234,56", "R$ 1.234,56", "1234.56" e número puro. */
function moeda(mixed $v): ?float
{
    if (is_int($v) || is_float($v)) return (float) $v;
    $s = preg_replace('/[R$\s]/iu', '', texto($v));
    if ($s === '') return null;
    if (str_contains($s, ',') && str_contains($s, '.')) $s = str_replace(['.', ','], ['', '.'], $s);
    elseif (str_contains($s, ',')) $s = str_replace(',', '.', $s);
    return is_numeric($s) ? (float) $s : null;
}

/** Aceita dd/mm/aaaa, aaaa-mm-dd e serial do Excel. Devolve 'Y-m-d'. */
function data_br(mixed $v): ?string
{
    $s = texto($v);
    if ($s === '') return null;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) return "$m[1]-$m[2]-$m[3]";
    if (preg_match('#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{2,4})#', $s, $m)) {
        $ano = strlen($m[3]) === 2 ? '20' . $m[3] : $m[3];
        return sprintf('%s-%02d-%02d', $ano, (int) $m[2], (int) $m[1]);
    }
    if (preg_match('/^\d+([.,]\d+)?$/', $s)) {
        $serial = (float) str_replace(',', '.', $s);
        if ($serial > 20000 && $serial < 60000) {
            return gmdate('Y-m-d', (int) (($serial - 25569) * 86400));
        }
    }
    return null;
}

/** Compara nomes ignorando acento, caixa e espaços extras. */
function chave_nome(mixed $v): string
{
    $s = mb_strtolower(texto($v), 'UTF-8');
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    return preg_replace('/\s+/', ' ', trim($s));
}

const ROTULO_STATUS = ['ativo' => 'Cliente ativo', 'prospeccao' => 'Prospecção', 'inativo' => 'Inativo'];

function brl(float|int|string|null $v): string
{
    return 'R$ ' . number_format((float) ($v ?? 0), 2, ',', '.');
}

function data_exibicao(?string $v): string
{
    if (!$v) return '—';
    $p = explode('-', substr($v, 0, 10));
    return count($p) === 3 ? "$p[2]/$p[1]/$p[0]" : '—';
}

function cnpj_formatado(?string $v): string
{
    if (!$v) return '—';
    return preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $v) ?: $v;
}
