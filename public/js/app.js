/* global document, Api, Filtros, Graficos, Importar, Formato, avisar, esc */
'use strict';

Api.exigirSessao();

const paginacao = { pagina: 1, ordenarPor: 'faturamento', ordem: 'desc' };
let carregando = 0;

function marcarCarregando(ativo) {
  carregando += ativo ? 1 : -1;
  document.getElementById('carregando').classList.toggle('ativo', carregando > 0);
}

/* ---------------- KPIs ---------------- */
function desenharKpis(kpis, comparativo, periodoAnterior) {
  const cartoes = [
    { rotulo: 'Faturamento', valor: Formato.moeda(kpis.faturamento), campo: 'faturamento' },
    { rotulo: 'Unidades', valor: Formato.numero(kpis.quantidade), campo: 'quantidade' },
    { rotulo: 'Clientes que compraram', valor: Formato.numero(kpis.clientes), campo: 'clientes' },
    { rotulo: 'Produtos vendidos', valor: Formato.numero(kpis.produtos), campo: 'produtos' },
    { rotulo: 'Media por cliente', valor: Formato.moeda(kpis.ticketMedioCliente), campo: 'ticketMedioCliente' },
    { rotulo: 'Desconto concedido', valor: Formato.moeda(kpis.desconto), campo: 'desconto' }
  ];

  document.getElementById('kpis').innerHTML = cartoes.map((c) => {
    const variacao = comparativo ? Formato.variacao(kpis[c.campo], comparativo[c.campo]) : null;
    let nota = '';
    let classe = '';
    if (variacao !== null && Number.isFinite(variacao)) {
      classe = variacao >= 0 ? 'sobe' : 'desce';
      nota = `${variacao >= 0 ? '+' : ''}${variacao.toFixed(1).replace('.', ',')}% vs ${Formato.periodo(periodoAnterior)}`;
    }
    return `
      <div class="kpi">
        <div class="kpi-rotulo">${c.rotulo}</div>
        <div class="kpi-valor">${c.valor}</div>
        <div class="kpi-nota ${classe}">${nota || '&nbsp;'}</div>
        <div class="regua" aria-hidden="true">${'<i></i>'.repeat(21)}</div>
      </div>`;
  }).join('');
}

/* ---------------- Curva ABC ---------------- */
function desenharAbc(abc, concentracao) {
  const total = abc.reduce((acc, c) => acc + c.clientes, 0) || 1;
  const totalValor = abc.reduce((acc, c) => acc + Number(c.faturamento), 0) || 1;

  document.getElementById('abc-barra').innerHTML = abc.map((c) => {
    const largura = (c.clientes / total) * 100;
    return `<div class="abc-${c.classe.toLowerCase()}" style="width:${largura}%" title="${c.clientes} clientes">
              ${largura > 7 ? c.classe : ''}
            </div>`;
  }).join('');

  document.getElementById('abc-legenda').innerHTML = abc.map((c) => `
    <div class="abc-item">
      <span class="bolinha abc-${c.classe.toLowerCase()}"></span>
      <span>Classe ${c.classe}</span>
      <span class="cifra">${Formato.numero(c.clientes)} clientes</span>
      <span class="cifra">${Formato.percentual((Number(c.faturamento) / totalValor) * 100)}</span>
    </div>`).join('');

  document.getElementById('concentracao').innerHTML = `
    <div><b>${Formato.percentual(concentracao.top10)}</b><span>top 10</span></div>
    <div><b>${Formato.percentual(concentracao.top20)}</b><span>top 20</span></div>
    <div><b>${Formato.percentual(concentracao.top50)}</b><span>top 50</span></div>
    <div><b>${Formato.percentual(concentracao.top100)}</b><span>top 100</span></div>`;
}

/* ---------------- Rankings ---------------- */
function desenharTopProdutos(lista) {
  const alvo = document.getElementById('top-produtos');
  if (!lista.length) {
    alvo.innerHTML = '<p class="vazio"><strong>Nada encontrado</strong>Remova algum filtro.</p>';
    return;
  }
  alvo.innerHTML = `
    <table class="tabela">
      <thead><tr><th>Produto</th><th class="num">Qtd</th><th class="num">Faturamento</th><th class="num">Clientes</th></tr></thead>
      <tbody>${lista.map((p) => `
        <tr>
          <td>${esc(p.descricao)}<div class="fraco" style="font-size:11.5px">${esc(p.familia)}</div></td>
          <td class="num">${Formato.numero(p.quantidade)}</td>
          <td class="num">${Formato.moeda(p.faturamento)}</td>
          <td class="num">${Formato.numero(p.clientes)}</td>
        </tr>`).join('')}
      </tbody>
    </table>`;
}

/* ---------------- Oportunidades ---------------- */
async function carregarOportunidades() {
  const filtros = Filtros.estado();
  const naoCompra = document.getElementById('oportunidade-familia').value;
  try {
    const dados = await Api.get('/api/oportunidades', {
      relatorioId: filtros.relatorioId,
      naoCompra
    });

    document.getElementById('oportunidade-resumo').innerHTML = `
      <b>${Formato.numero(dados.total)}</b> clientes compram lente e nunca compraram
      <b>${esc(naoCompra.toLowerCase())}</b> &mdash; ${Formato.moeda(dados.faturamentoBase)} de base instalada.`;

    const alvo = document.getElementById('lista-oportunidades');
    alvo.innerHTML = dados.clientes.length
      ? `<table class="tabela">
           <thead><tr><th></th><th>Cliente</th><th class="num">Faturamento</th><th class="num">Unidades</th></tr></thead>
           <tbody>${dados.clientes.slice(0, 40).map((c) => `
             <tr class="clicavel" data-cliente="${c.id}">
               <td><span class="selo ${c.classe}">${c.classe}</span></td>
               <td>${esc(c.nome)}<div class="fraco" style="font-size:11.5px">cod. ${esc(c.codigo)}</div></td>
               <td class="num">${Formato.moeda(c.faturamento)}</td>
               <td class="num">${Formato.numero(c.quantidade)}</td>
             </tr>`).join('')}
           </tbody>
         </table>`
      : '<p class="vazio"><strong>Nenhum cliente nessa situacao</strong>Todos ja compram essa familia.</p>';

    alvo.querySelectorAll('[data-cliente]').forEach((linha) =>
      linha.addEventListener('click', () => abrirFicha(linha.dataset.cliente)));
  } catch (erro) {
    avisar(erro.message, 'erro');
  }
}

/* ---------------- Tabela de clientes ---------------- */
async function carregarClientes() {
  marcarCarregando(true);
  try {
    const dados = await Api.get('/api/clientes', {
      ...Filtros.estado(),
      pagina: paginacao.pagina,
      porPagina: 25,
      ordenarPor: paginacao.ordenarPor,
      ordem: paginacao.ordem
    });

    const corpo = document.getElementById('corpo-clientes');
    corpo.innerHTML = dados.clientes.length
      ? dados.clientes.map((c) => `
          <tr class="clicavel" data-cliente="${c.id}">
            <td><span class="posicao">${c.posicao ?? '-'}</span></td>
            <td><span class="selo ${c.classe || 'C'}">${c.classe || '-'}</span></td>
            <td>${esc(c.nome)}<div class="fraco" style="font-size:11.5px">cod. ${esc(c.codigo)}</div></td>
            <td class="fraco">${esc(c.municipio || '-')}</td>
            <td class="num">${Formato.numero(c.produtos)}</td>
            <td class="num">${Formato.numero(c.quantidade)}</td>
            <td class="num">${Formato.moedaExata(c.faturamento)}</td>
          </tr>`).join('')
      : '<tr><td colspan="7"><p class="vazio"><strong>Nenhum cliente encontrado</strong>Tente remover um filtro.</p></td></tr>';

    corpo.querySelectorAll('[data-cliente]').forEach((linha) =>
      linha.addEventListener('click', () => abrirFicha(linha.dataset.cliente)));

    const ultima = Math.max(1, Math.ceil(dados.total / dados.porPagina));
    document.getElementById('paginacao-texto').textContent =
      `${Formato.numero(dados.total)} clientes - pagina ${dados.pagina} de ${ultima}`;
    document.getElementById('pagina-anterior').disabled = dados.pagina <= 1;
    document.getElementById('pagina-proxima').disabled = dados.pagina >= ultima;
  } catch (erro) {
    avisar(erro.message, 'erro');
  } finally {
    marcarCarregando(false);
  }
}

/* ---------------- Ficha do cliente ---------------- */
async function abrirFicha(id) {
  const modal = document.getElementById('modal-cliente');
  modal.classList.add('aberto');
  document.getElementById('ficha-corpo').innerHTML = '<p class="vazio">Carregando...</p>';

  try {
    const ficha = await Api.get(`/api/clientes/${id}`, { relatorioId: Filtros.estado().relatorioId });
    const r = ficha.resumo || {};

    document.getElementById('ficha-titulo').textContent = ficha.cliente.nome;
    document.getElementById('ficha-subtitulo').innerHTML =
      `codigo ${esc(ficha.cliente.codigo)}
       ${r.classe ? `&middot; classe <span class="selo ${r.classe}">${r.classe}</span> &middot; posicao ${r.posicao}` : ''}
       ${ficha.cliente.municipio ? `&middot; ${esc(ficha.cliente.municipio)}` : ''}`;

    document.getElementById('ficha-corpo').innerHTML = `
      <div class="resultado-linhas">
        <div><b>${Formato.moeda(r.faturamento || 0)}</b>faturamento</div>
        <div><b>${Formato.numero(r.quantidade || 0)}</b>unidades</div>
        <div><b>${Formato.numero(r.produtos_distintos || 0)}</b>produtos</div>
        <div><b>${Formato.percentual(r.participacao || 0)}</b>do total</div>
      </div>

      <h3 style="font-size:13px;margin:22px 0 8px">Mix por familia</h3>
      <div class="grafico-caixa" style="height:${Math.max(140, ficha.familias.length * 34)}px">
        <canvas id="grafico-mix"></canvas>
      </div>

      <h3 style="font-size:13px;margin:22px 0 8px">Produtos comprados</h3>
      <div class="tabela-rolagem" style="max-height:280px;overflow:auto">
        <table class="tabela">
          <thead><tr><th>Produto</th><th>Familia</th><th class="num">Qtd</th><th class="num">Valor</th></tr></thead>
          <tbody>${ficha.produtos.map((p) => `
            <tr>
              <td>${esc(p.descricao)}</td>
              <td class="fraco">${esc(p.familia)}</td>
              <td class="num">${Formato.numero(p.quantidade)}</td>
              <td class="num">${Formato.moedaExata(p.valor)}</td>
            </tr>`).join('')}
          </tbody>
        </table>
      </div>`;

    Graficos.mixCliente(ficha.familias);
  } catch (erro) {
    document.getElementById('ficha-corpo').innerHTML = `<p class="vazio">${esc(erro.message)}</p>`;
  }
}

/* ---------------- Carga geral ---------------- */
async function atualizarTudo() {
  marcarCarregando(true);
  try {
    const filtros = Filtros.estado();
    const resumo = await Api.get('/api/painel/resumo', filtros);

    if (resumo.vazio) {
      document.getElementById('kpis').innerHTML =
        `<div class="kpi"><div class="kpi-rotulo">Comece por aqui</div>
         <div class="kpi-valor" style="font-size:19px">Nenhum periodo importado</div>
         <div class="kpi-nota">Use "Importar relatorio" no topo da tela.</div></div>`;
      return;
    }

    desenharKpis(resumo.kpis, resumo.comparativo, resumo.comparativoPeriodo);
    desenharAbc(resumo.abc, resumo.concentracao);
    Graficos.familias(resumo.familias);
    Graficos.periodos(resumo.periodos);
    desenharTopProdutos(resumo.topProdutos);

    document.getElementById('rodape-periodo').innerHTML =
      `Periodo ${Formato.periodo(resumo.relatorio)} &middot;
       ${Formato.numero(resumo.relatorio.qtd_linhas)} linhas lidas &middot;
       <span class="selo-conferido ${resumo.relatorio.conferido ? 'sim' : 'nao'}">
         ${resumo.relatorio.conferido ? 'confere com o rodape do PDF' : 'soma nao confere - reimporte'}
       </span>`;

    const pareto = await Api.get('/api/painel/pareto', { relatorioId: filtros.relatorioId });
    Graficos.pareto(pareto.pontos);

    paginacao.pagina = 1;
    await Promise.all([carregarClientes(), carregarOportunidades()]);
  } catch (erro) {
    avisar(erro.message, 'erro');
  } finally {
    marcarCarregando(false);
  }
}

/* ---------------- Inicializacao ---------------- */
(async function iniciar() {
  const usuario = Api.usuario();
  if (usuario) document.getElementById('usuario').textContent = usuario.nome;

  document.getElementById('sair').addEventListener('click', Api.sair);

  document.getElementById('exportar-clientes').addEventListener('click', async () => {
    try { await Api.baixar('/api/clientes/exportar/csv', Filtros.estado(), 'clientes.csv'); }
    catch (erro) { avisar(erro.message, 'erro'); }
  });

  document.getElementById('exportar-oportunidades').addEventListener('click', async () => {
    try {
      await Api.baixar('/api/oportunidades/exportar/csv', {
        relatorioId: Filtros.estado().relatorioId,
        naoCompra: document.getElementById('oportunidade-familia').value
      }, 'oportunidades.csv');
    } catch (erro) { avisar(erro.message, 'erro'); }
  });

  document.getElementById('oportunidade-familia').addEventListener('change', carregarOportunidades);

  document.getElementById('pagina-anterior').addEventListener('click', () => {
    paginacao.pagina -= 1;
    carregarClientes();
  });
  document.getElementById('pagina-proxima').addEventListener('click', () => {
    paginacao.pagina += 1;
    carregarClientes();
  });
  document.querySelectorAll('#tabela-clientes th.ordenavel').forEach((th) => {
    th.addEventListener('click', () => {
      const coluna = th.dataset.coluna;
      paginacao.ordem = paginacao.ordenarPor === coluna && paginacao.ordem === 'desc' ? 'asc' : 'desc';
      paginacao.ordenarPor = coluna;
      paginacao.pagina = 1;
      carregarClientes();
    });
  });

  document.getElementById('fechar-ficha').addEventListener('click', () =>
    document.getElementById('modal-cliente').classList.remove('aberto'));
  document.getElementById('modal-cliente').addEventListener('click', (e) => {
    if (e.target.id === 'modal-cliente') e.target.classList.remove('aberto');
  });

  Importar.iniciar(async () => {
    await Filtros.iniciar();
    await atualizarTudo();
  });

  await Filtros.iniciar();
  Filtros.aoMudar(atualizarTudo);
  await atualizarTudo();
})();