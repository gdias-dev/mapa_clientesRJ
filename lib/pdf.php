<?php
declare(strict_types=1);

/**
 * Gerador mínimo de PDF (A4 retrato, fontes core Helvetica) sem dependência
 * externa. Cobre o que o relatório precisa: texto, retângulo preenchido,
 * quebra de página e acentuação (UTF-8 convertido para WinAnsi).
 */
class MiniPdf
{
    public float $largura = 595.28;   // A4 em pontos
    public float $altura  = 841.89;
    private array $paginas = [];
    private string $atual = '';
    private array $corTexto = [0, 0, 0];

    public function __construct() { $this->novaPagina(); }

    public function novaPagina(): void
    {
        if ($this->atual !== '') $this->paginas[] = $this->atual;
        $this->atual = '';
    }

    private static function hex(string $h): array
    {
        $h = ltrim($h, '#');
        return [hexdec(substr($h, 0, 2)) / 255, hexdec(substr($h, 2, 2)) / 255, hexdec(substr($h, 4, 2)) / 255];
    }

    private static function codificar(string $t): string
    {
        $t = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $t) ?: $t;
        return str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', ''], $t);
    }

    public function retangulo(float $x, float $y, float $l, float $a, string $cor): void
    {
        [$r, $g, $b] = self::hex($cor);
        $this->atual .= sprintf("q %.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re f Q\n",
            $r, $g, $b, $x, $this->altura - $y - $a, $l, $a);
    }

    public function cor(string $hex): void { $this->corTexto = self::hex($hex); }

    /** $estilo: 'normal' | 'negrito'. $alinhamento: 'esq' | 'dir'. */
    public function texto(string $conteudo, float $x, float $y, float $tamanho = 10,
                          string $estilo = 'normal', ?float $limite = null, string $alinhamento = 'esq'): void
    {
        $fonte = $estilo === 'negrito' ? '/F2' : '/F1';
        $t = $conteudo;
        if ($limite !== null) {
            // Corte aproximado: Helvetica tem ~0.5em de largura média por caractere.
            $max = (int) floor($limite / ($tamanho * 0.5));
            if (mb_strlen($t) > $max) $t = mb_substr($t, 0, max(1, $max - 1)) . '…';
        }
        if ($alinhamento === 'dir') {
            $x -= mb_strlen($t) * $tamanho * 0.5;
        }
        [$r, $g, $b] = $this->corTexto;
        $this->atual .= sprintf("BT %.3f %.3f %.3f rg %s %.1f Tf %.2f %.2f Td (%s) Tj ET\n",
            $r, $g, $b, $fonte, $tamanho, $x, $this->altura - $y, self::codificar($t));
    }

    public function enviar(string $nomeArquivo): void
    {
        if ($this->atual !== '') { $this->paginas[] = $this->atual; $this->atual = ''; }

        $objetos = [];
        $totalPaginas = count($this->paginas);
        $idsPaginas = [];
        for ($i = 0; $i < $totalPaginas; $i++) $idsPaginas[] = 4 + $i * 2;

        $objetos[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objetos[2] = "<< /Type /Pages /Kids [" .
            implode(' ', array_map(fn($id) => "$id 0 R", $idsPaginas)) .
            "] /Count $totalPaginas >>";
        $objetos[3] = "<< /Font << /F1 " . (4 + $totalPaginas * 2) . " 0 R /F2 " . (5 + $totalPaginas * 2) . " 0 R >> >>";

        foreach ($this->paginas as $i => $conteudo) {
            $idPagina = 4 + $i * 2;
            $idFluxo  = $idPagina + 1;
            $objetos[$idPagina] = sprintf(
                "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Resources 3 0 R /Contents %d 0 R >>",
                $this->largura, $this->altura, $idFluxo);
            $objetos[$idFluxo] = "<< /Length " . strlen($conteudo) . " >>\nstream\n$conteudo\nendstream";
        }
        $objetos[4 + $totalPaginas * 2] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objetos[5 + $totalPaginas * 2] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        ksort($objetos);
        $pdf = "%PDF-1.4\n";
        $posicoes = [];
        foreach ($objetos as $id => $corpo) {
            $posicoes[$id] = strlen($pdf);
            $pdf .= "$id 0 obj\n$corpo\nendobj\n";
        }
        $inicioXref = strlen($pdf);
        $total = count($objetos) + 1;
        $pdf .= "xref\n0 $total\n0000000000 65535 f \n";
        for ($i = 1; $i < $total; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $posicoes[$i] ?? 0);
        }
        $pdf .= "trailer\n<< /Size $total /Root 1 0 R >>\nstartxref\n$inicioXref\n%%EOF";

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');
        header('Content-Length: ' . (string) strlen($pdf));
        echo $pdf;
        exit;
    }
}
