-- =====================================================================
-- Nova Varonil | esquema MySQL 5.7+ / MariaDB 10.2+
-- Rodado por db/instalar.php ou importado pelo phpMyAdmin da KingHost.
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;
DROP VIEW  IF EXISTS vw_clientes_resumo;
DROP TABLE IF EXISTS compras;
DROP TABLE IF EXISTS clientes;
DROP TABLE IF EXISTS produtos;
DROP TABLE IF EXISTS colaboradores;
DROP TABLE IF EXISTS usuarios;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------- login
CREATE TABLE usuarios (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  nome          VARCHAR(120) NOT NULL,
  email         VARCHAR(160) NOT NULL UNIQUE,
  senha_hash    VARCHAR(255) NOT NULL,
  papel         ENUM('admin','operador') NOT NULL DEFAULT 'operador',
  ativo         TINYINT(1) NOT NULL DEFAULT 1,
  ultimo_acesso DATETIME NULL,
  criado_em     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------- colaboradores
CREATE TABLE colaboradores (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  nome      VARCHAR(120) NOT NULL,
  setor     VARCHAR(60)  NOT NULL,
  telefone  VARCHAR(40)  NULL,
  email     VARCHAR(160) NULL,
  ativo     TINYINT(1) NOT NULL DEFAULT 1,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------- produtos
CREATE TABLE produtos (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  nome       VARCHAR(160) NOT NULL UNIQUE,
  categoria  VARCHAR(40) NOT NULL DEFAULT 'lente',
  preco_base DECIMAL(12,2) NOT NULL DEFAULT 0,
  ativo      TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------- clientes
CREATE TABLE clientes (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  nome           VARCHAR(160) NOT NULL,
  cnpj           CHAR(14) NULL UNIQUE,
  telefone       VARCHAR(40) NULL,
  email          VARCHAR(160) NULL,
  local_trabalho VARCHAR(160) NULL,
  endereco       VARCHAR(200) NULL,
  bairro         VARCHAR(80) NULL,
  municipio      VARCHAR(80) NOT NULL,
  uf             CHAR(2) NOT NULL DEFAULT 'RJ',
  latitude       DECIMAL(9,6) NULL,
  longitude      DECIMAL(9,6) NULL,
  status         ENUM('ativo','prospeccao','inativo') NOT NULL DEFAULT 'prospeccao',
  colaborador_id INT NULL,
  observacoes    TEXT NULL,
  criado_em      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_cliente_colab FOREIGN KEY (colaborador_id)
    REFERENCES colaboradores(id) ON DELETE SET NULL,
  INDEX idx_clientes_status (status),
  INDEX idx_clientes_municipio (municipio),
  INDEX idx_clientes_colab (colaborador_id),
  INDEX idx_clientes_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------- compras
CREATE TABLE compras (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  cliente_id      INT NOT NULL,
  produto_id      INT NULL,
  descricao       VARCHAR(200) NOT NULL,
  quantidade      DECIMAL(10,2) NOT NULL DEFAULT 1,
  valor_unitario  DECIMAL(12,2) NOT NULL,
  -- Coluna gerada: o total nunca diverge do que foi digitado.
  valor_total     DECIMAL(14,2) AS (quantidade * valor_unitario) STORED,
  data_compra     DATE NOT NULL,
  forma_pagamento ENUM('boleto','pix','cartao','dinheiro','transferencia','prazo') NOT NULL DEFAULT 'boleto',
  colaborador_id  INT NULL,
  observacao      TEXT NULL,
  criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_compra_cliente FOREIGN KEY (cliente_id)
    REFERENCES clientes(id) ON DELETE CASCADE,
  CONSTRAINT fk_compra_produto FOREIGN KEY (produto_id)
    REFERENCES produtos(id) ON DELETE SET NULL,
  CONSTRAINT fk_compra_colab FOREIGN KEY (colaborador_id)
    REFERENCES colaboradores(id) ON DELETE SET NULL,
  INDEX idx_compras_cliente (cliente_id),
  INDEX idx_compras_data (data_compra),
  INDEX idx_compras_produto (produto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Visão consolidada usada pelo mapa, listagens e relatórios.
-- Todas as colunas não agregadas entram no GROUP BY por causa do
-- ONLY_FULL_GROUP_BY, que vem ligado por padrão no MySQL 5.7+.
CREATE VIEW vw_clientes_resumo AS
SELECT c.id, c.nome, c.cnpj, c.telefone, c.email, c.local_trabalho, c.endereco,
       c.bairro, c.municipio, c.uf, c.latitude, c.longitude, c.status,
       c.colaborador_id, c.observacoes, c.criado_em, c.atualizado_em,
       col.nome AS colaborador_nome,
       COALESCE(SUM(cp.valor_total), 0) AS faturamento_total,
       COUNT(cp.id)                     AS total_compras,
       MAX(cp.data_compra)              AS ultima_compra
  FROM clientes c
  LEFT JOIN colaboradores col ON col.id = c.colaborador_id
  LEFT JOIN compras cp        ON cp.cliente_id = c.id
 GROUP BY c.id, c.nome, c.cnpj, c.telefone, c.email, c.local_trabalho, c.endereco,
          c.bairro, c.municipio, c.uf, c.latitude, c.longitude, c.status,
          c.colaborador_id, c.observacoes, c.criado_em, c.atualizado_em, col.nome;
