-- Executado separadamente porque o delimitador muda.
-- Um cliente com compra registrada nunca deveria continuar como prospecção:
-- o ponto cinza do mapa vira vermelho sozinho na primeira compra.
DROP TRIGGER IF EXISTS trg_compras_promove;
CREATE TRIGGER trg_compras_promove
AFTER INSERT ON compras
FOR EACH ROW
  UPDATE clientes SET status = 'ativo'
   WHERE id = NEW.cliente_id AND status = 'prospeccao';
