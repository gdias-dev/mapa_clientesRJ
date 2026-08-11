<?php
declare(strict_types=1);

/**
 * Gerador mínimo de .xlsx usando apenas ZipArchive — sem PhpSpreadsheet, que
 * não caberia bem no limite de memória de hospedagem compartilhada.
 * Suporta cabeçalho estilizado, largura de coluna, texto e número com
 * formato de moeda.
 */
class MiniXlsx
{
    private array $abas = [];

    public function aba(string $nome, array $colunas, array $linhas): void
    {
        // $colunas: [['titulo' => 'Cliente', 'largura' => 30, 'formato' => 'moeda'|'texto'], ...]
        $this->abas[] = ['nome' => mb_substr($nome, 0, 28), 'colunas' => $colunas, 'linhas' => $linhas];
    }

    private static function esc(string $s): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? $s;
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function letra(int $i): string
    {
        $s = '';
        for ($n = $i; $n >= 0; $n = intdiv($n, 26) - 1) $s = chr(65 + $n % 26) . $s;
        return $s;
    }

    private function planilhaXml(array $aba): string
    {
        $cols = '';
        foreach ($aba['colunas'] as $i => $c) {
            $cols .= sprintf('<col min="%d" max="%d" width="%s" customWidth="1"/>',
                $i + 1, $i + 1, (float) ($c['largura'] ?? 16));
        }

        $linhas = '<row r="1">';
        foreach ($aba['colunas'] as $i => $c) {
            $linhas .= sprintf('<c r="%s1" s="1" t="inlineStr"><is><t>%s</t></is></c>',
                self::letra($i), self::esc((string) $c['titulo']));
        }
        $linhas .= '</row>';

        $n = 1;
        foreach ($aba['linhas'] as $linha) {
            $n++;
            $linhas .= '<row r="' . $n . '">';
            foreach (array_values($linha) as $i => $valor) {
                $ref = self::letra($i) . $n;
                $formato = $aba['colunas'][$i]['formato'] ?? 'texto';
                if ($valor === null || $valor === '') {
                    continue;
                }
                if ($formato === 'moeda' && is_numeric($valor)) {
                    $linhas .= sprintf('<c r="%s" s="2"><v>%s</v></c>', $ref, (float) $valor);
                } elseif ($formato === 'numero' && is_numeric($valor)) {
                    $linhas .= sprintf('<c r="%s"><v>%s</v></c>', $ref, (float) $valor);
                } else {
                    $linhas .= sprintf('<c r="%s" t="inlineStr"><is><t xml:space="preserve">%s</t></is></c>',
                        $ref, self::esc((string) $valor));
                }
            }
            $linhas .= '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . ($cols ? "<cols>$cols</cols>" : '')
            . "<sheetData>$linhas</sheetData></worksheet>";
    }

    /** Envia o arquivo pronto para o navegador. */
    public function enviar(string $nomeArquivo): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . implode('', array_map(fn($i) => sprintf(
                '<Override PartName="/xl/worksheets/sheet%d.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>',
                $i + 1), array_keys($this->abas)))
            . '</Types>');

        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');

        $abasXml = '';
        $relsXml = '';
        foreach ($this->abas as $i => $aba) {
            $abasXml .= sprintf('<sheet name="%s" sheetId="%d" r:id="rId%d"/>', self::esc($aba['nome']), $i + 1, $i + 1);
            $relsXml .= sprintf('<Relationship Id="rId%d" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet%d.xml"/>', $i + 1, $i + 1);
            $zip->addFromString("xl/worksheets/sheet" . ($i + 1) . ".xml", $this->planilhaXml($aba));
        }
        $idEstilos = count($this->abas) + 1;

        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . "<sheets>$abasXml</sheets></workbook>");

        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $relsXml
            . sprintf('<Relationship Id="rId%d" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>', $idEstilos)
            . '</Relationships>');

        // Estilos: 0 padrão, 1 cabeçalho (branco sobre azul-escuro), 2 moeda.
        $zip->addFromString('xl/styles.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="&quot;R$&quot;\ #,##0.00"/></numFmts>'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF071B33"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border/></borders>'
            . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
            . '<cellXfs count="3">'
            . '<xf xfId="0"/>'
            . '<xf xfId="0" fontId="1" fillId="2" applyFont="1" applyFill="1"/>'
            . '<xf xfId="0" numFmtId="164" applyNumberFormat="1"/>'
            . '</cellXfs></styleSheet>');

        $zip->close();

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');
        header('Content-Length: ' . (string) filesize($tmp));
        readfile($tmp);
        unlink($tmp);
        exit;
    }
}
