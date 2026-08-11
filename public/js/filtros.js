/* global document, Api, Formato */
'use strict';

const Filtros = (() => {
  const estado = {
    relatorioId: '',
    busca: '',
    classe: '',
    familia: '',
    compraFamilia: '',
    produtoId: '',
    valorMin: '',
    valorMax: '',
    qtdMin: '',
    qtdMax: ''
  };

  const ouvintes = [];
  let opcoes = { familias: [], periodos: [] };
  let temporizador = null;

  const ROTULOS = {
    busca: 'Busca', classe: 'Classe', familia: 'Familia',
    compraFamilia: 'Compra', produtoId: 'Produto',
    valorMin: 'Faturamento min', valorMax: 'Faturamento max',
    qtdMin: 'Qtd min', qtdMax: 'Qtd max'
  };

  const aoMudar = (callback) => ouvintes.push(callback);

  function notificar() {
    desenharMarcadores();
    ouvintes.forEach((cb) => cb({ ...estado }));
  }

  function aplicar(parcial, imediato = false) {
    Object.assign(estado, parcial);
    sincronizar();
    clearTimeout(temporizador);
    if (imediato) notificar();
    else temporizador = setTimeout(notificar, 280);
  }

  function limpar() {
    Object.keys(estado).forEach((chave) => {
      if (chave !== 'relatorioId') estado[chave] = '';
    });
    sincronizar();
    notificar();
  }

  function sincronizar() {
    for (const [chave, valor] of Object.entries(estado)) {
      const campo = document.querySelector(`[data-filtro="${chave}"]`);
      if (campo && campo.value !== valor) campo.value = valor;
    }
    document.querySelectorAll('[data-classe]').forEach((botao) => {
      botao.setAttribute('aria-pressed', String(estado.classe === botao.dataset.classe));
    });
  }

  function desenharMarcadores() {
    const caixa = document.getElementById('marcadores');
    if (!caixa) return;
    caixa.innerHTML = '';
    for (const [chave, valor] of Object.entries(estado)) {
      if (!valor || chave === 'relatorioId') continue;
      const marcador = document.createElement('span');
      marcador.className = 'marcador';
      marcador.textContent = `${ROTULOS[chave] || chave}: ${descrever(chave, valor)}`;
      const remover = document.createElement('button');
      remover.type = 'button';
      remover.textContent = '\u00d7';
      remover.title = 'Remover filtro';
      remover.addEventListener('click', () => aplicar({ [chave]: '' }, true));
      marcador.appendChild(remover);
      caixa.appendChild(marcador);
    }
  }

  function descrever(chave, valor) {
    if (chave === 'valorMin' || chave === 'valorMax') return Formato.moeda(valor);
    return valor;
  }

  function preencher(nomeFiltro, itens, textoPadrao) {
    const select = document.querySelector(`select[data-filtro="${nomeFiltro}"]`);
    if (!select) return;
    const atual = select.value;
    select.innerHTML = `<option value="">${textoPadrao}</option>`;
    itens.forEach((item) => {
      const opcao = document.createElement('option');
      opcao.value = item.valor;
      opcao.textContent = item.texto;
      select.appendChild(opcao);
    });
    select.value = atual;
  }

  async function iniciar() {
    const [familias, relatorios] = await Promise.all([
      Api.get('/api/produtos/familias'),
      Api.get('/api/relatorios')
    ]);
    opcoes = { familias: familias.conhecidas, periodos: relatorios.relatorios };

    preencher('familia', opcoes.familias.map((f) => ({ valor: f, texto: f })), 'Todas as familias');
    preencher('compraFamilia', opcoes.familias.map((f) => ({ valor: f, texto: f })), 'Qualquer familia');

    const seletor = document.getElementById('seletor-periodo');
    if (seletor) {
      seletor.innerHTML = opcoes.periodos
        .map((p) => `<option value="${p.id}">${Formato.periodo(p)}</option>`)
        .join('');
      if (opcoes.periodos.length) {
        estado.relatorioId = String(opcoes.periodos[0].id);
        seletor.value = estado.relatorioId;
      }
      seletor.addEventListener('change', () => aplicar({ relatorioId: seletor.value }, true));
    }

    document.querySelectorAll('[data-filtro]').forEach((campo) => {
      const evento = campo.tagName === 'SELECT' ? 'change' : 'input';
      campo.addEventListener(evento, () =>
        aplicar({ [campo.dataset.filtro]: campo.value }, evento === 'change'));
    });

    document.querySelectorAll('[data-classe]').forEach((botao) => {
      botao.addEventListener('click', () => {
        aplicar({ classe: estado.classe === botao.dataset.classe ? '' : botao.dataset.classe }, true);
      });
    });

    document.getElementById('limpar-filtros')?.addEventListener('click', limpar);
    document.getElementById('mais-filtros')?.addEventListener('click', () => {
      const extras = document.getElementById('filtros-extras');
      const aberto = extras.classList.toggle('aberto');
      document.getElementById('mais-filtros').textContent = aberto ? 'Menos filtros' : 'Mais filtros';
    });

    sincronizar();
    return opcoes;
  }

  return { iniciar, aplicar, limpar, aoMudar, estado: () => ({ ...estado }), opcoes: () => opcoes };
})();