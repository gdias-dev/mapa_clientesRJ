# Nova Varonil — Painel Comercial (versão PHP + MySQL, para KingHost)

Mesmo sistema de antes, com o backend reescrito em **PHP 8 + MySQL** para
rodar inteiro na hospedagem compartilhada. O frontend React não mudou —
apenas foi compilado para a subpasta `/painel`.

Nada de Composer, nada de biblioteca externa: as únicas extensões usadas
(`pdo_mysql`, `zip`, `simplexml`, `mbstring`) já vêm ativas na KingHost.

## Passo a passo do deploy

**1. Criar o banco.** No painel da KingHost, crie um banco MySQL e anote nome,
usuário e senha.

**2. Enviar os arquivos.** Suba a pasta `painel/` inteira para dentro de
`public_html` (via FTP ou gerenciador de arquivos). Fica assim:

```
public_html/painel/          index.html, assets/, .htaccess   ← React compilado
public_html/painel/api/      index.php, lib/, rotas/, db/     ← API PHP
```

**3. Configurar.** Renomeie `api/config.local.example.php` para
`api/config.local.php` e preencha os dados do banco, o `jwt_secret` (string
longa e aleatória) e uma `senha_instalacao` temporária.

**4. Instalar o banco.** Abra no navegador:

```
https://seudominio.com.br/painel/api/db/instalar.php?senha=SUA_SENHA_INSTALACAO
```

Acrescente `&demo=1` para popular 30 ópticas do RJ e ~2 anos de compras de
exemplo. Sem isso, cria apenas os usuários e o catálogo de produtos.
**Apague `api/db/instalar.php` depois de rodar.**

**5. Entrar.** `https://seudominio.com.br/painel/` —
`admi` / `nov`. Troque a senha em seguida.

## Teste rápido se algo não abrir

`https://seudominio.com.br/painel/api/saude` deve responder
`{"ok":true,...}`. Se der 404, o `mod_rewrite` não pegou o `.htaccess`; se der
erro de conexão, revise `config.local.php`.

## O que mudou em relação à versão Node

| Antes (Node/Postgres) | Agora (PHP/MySQL) |
|---|---|
| Express com rotas por arquivo | `api/index.php` roteia para `rotas/*.php` — mesmos endpoints e mesmo JSON |
| `jsonwebtoken` | JWT HS256 em ~40 linhas com `hash_hmac`, comparação por `hash_equals` |
| `bcrypt` | `password_hash` / `password_verify` (bcrypt nativo) |
| `pdfkit` | `lib/pdf.php` — gerador próprio (A4, Helvetica, acentuação via WinAnsi, quebra de página) |
| `exceljs` | `lib/xlsx.php` — escreve o .xlsx direto com `ZipArchive`, sem PhpSpreadsheet (que estouraria a memória do plano compartilhado) |
| `multer` | `$_FILES` nativo |
| `date_trunc`, `FILTER`, window functions | `DATE_FORMAT`, `SUM(cond)`, `CASE WHEN` e uma consulta extra de `COUNT` — nada exige MySQL 8, funciona no 5.7 |
| View com `GROUP BY c.id` | mesma view com todas as colunas no `GROUP BY` (exigência do `ONLY_FULL_GROUP_BY`) |

Segue igual: coluna gerada `valor_total`, trigger que promove o ponto do mapa
de cinza para vermelho na primeira compra, validação de CNPJ por dígito
verificador, consultas 100% parametrizadas e a importação de planilha em duas
etapas (prévia → gravação em transação).

## Manutenção do frontend

O React continua na pasta `frontend/` do projeto original. Para publicar
alterações: `npm run build` e suba o conteúdo de `dist/` para
`public_html/painel/`. Os dois ajustes já aplicados foram `base: '/painel/'`
no `vite.config.js` e `basename="/painel"` no `BrowserRouter`.

## Segurança na hospedagem

- `.htaccess` da API bloqueia acesso direto a `config.local.php` e a `.sql`.
- Troque `jwt_secret` por um valor gerado aleatoriamente antes de liberar aos
  usuários — quem tiver essa chave consegue forjar sessões.
- Apague `db/instalar.php` após a instalação.
- Use HTTPS (a KingHost oferece Let's Encrypt no painel).
