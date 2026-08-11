/* global document, FormData, Api, Formato, avisar, esc */
'use strict';

const Importar = (() => {
  let arquivo = null;
  let aoConcluir = () => {};

  function abrir() {
    document.getElementById('modal-importar').classList.add('aberto');
    document.getElementById('resultado-importacao').innerHTML = '';
    arquivo = null;
    atualizarRotulo();
    carregarHistorico();
  }

  const fechar = () => document.getElementById('modal-importar').classList.remove('aberto');

  function atualizarRotulo() {
    document.getElementById('area-arquivo').innerHTML = arquivo
      ? `<strong>${esc(arquivo.name)}</strong><span>${(arquivo.size / 1024 / 1024).toFixed(1)} MB &middot; clique para trocar</span>`
      : `<strong>Arraste o relatorio em PDF aqui</strong>
         <span>"Venda de Cliente por Periodo", exportado do sistema</span>`;
    document.getElementById('conferir').disabled = !arquivo;
    document.getElementById('gravar').disabled = !arquivo;
  }

  async function enviar(simular) {
    if (!arquivo) return;
    const corpo = new FormData();
    corpo.append('arquivo', arquivo);

    const botoes = [document.getElementById('conferir'), document.getElementById('gravar')];
    botoes.forEach((b) => { b.disabled = true; });
    document.getElementById('resultado-importacao').innerHTML =
      '<p class="vazio">Lendo o PDF. Em arquivos grandes isso leva alguns segundos.</p>';

    try {
      const resumo = await Api.post(`/api/relatorios/importar?simular=${simular}`, corpo);
      mostrarResultado(resumo, simular);
      if (!simular) {
        avisar(`Periodo ${Formato.data(resumo.periodo.inicio)} a ${Formato.data(resumo.periodo.fim)} importado.`);
        aoConcluir();
        carregarHistorico();
      }
    } catch (erro) {
      document.getElementById('resultado-importacao').innerHTML = '';
      avisar(erro.message, 'erro');
    } finally {
      botoes.forEach((b) => { b.disabled = false; });
    }
  }

  function mostrarResultado(r, simular) {
    const erros = (r.avisos || []).map((a) => `<div>${esc(a.motivo)}</div>`).join('');
    document.getElementById('resultado-importacao').innerHTML = `
      <p style="margin:14px 0 0;color:var(--tinta-2)">
        ${simular ? 'Conferencia sem gravar nada.' : (r.substituiu ? 'Periodo regravado.' : 'Importado.')}
        Periodo ${Formato.data(r.periodo.inicio)} a ${Formato.data(r.periodo.fim)}.
        <span class="selo-conferido ${r.conferido ? 'sim' : 'nao'}">
          ${r.conferido ? 'soma confere com o rodape' : 'soma NAO confere'}
        </span>
      </p>
      <div class="resultado-linhas">
        <div><b>${Formato.numero(r.clientes)}</b>clientes${r.clientesNovos ? ` (${r.clientesNovos} novos)` : ''}</div>
        <div><b>${Formato.numero(r.produtos)}</b>produtos${r.produtosNovos ? ` (${r.produtosNovos} novos)` : ''}</div>
        <div><b>${Formato.numero(r.linhas)}</b>linhas</div>
        <div><b>${Formato.moeda(r.totalValor)}</b>faturamento</div>
        <div><b>${Formato.numero(r.totalQuantidade)}</b>unidades</div>
      </div>
      ${erros ? `<div class="lista-erros">${erros}</div>` : ''}`;
  }

  async function carregarHistorico() {
    const caixa = document.getElementById('historico-importacoes');
    if (!caixa) return;
    try {
      const { relatorios } = await Api.get('/api/relatorios');
      caixa.innerHTML = relatorios.length
        ? `<table class="tabela">
             <thead><tr><th>Periodo</th><th>Arquivo</th><th class="num">Clientes</th><th class="num">Faturamento</th><th></th></tr></thead>
             <tbody>${relatorios.map((r) => `
               <tr>
                 <td>${Formato.periodo(r)}</td>
                 <td class="fraco">${esc(r.arquivo || '-')}</td>
                 <td class="num">${Formato.numero(r.qtd_clientes)}</td>
                 <td class="num">${Formato.moeda(r.total_valor)}</td>
                 <td><span class="selo-conferido ${r.conferido ? 'sim' : 'nao'}">${r.conferido ? 'ok' : 'conferir'}</span></td>
               </tr>`).join('')}
             </tbody>
           </table>`
        : '<p class="vazio">Nenhum periodo importado ainda.</p>';
    } catch (_) {
      caixa.innerHTML = '';
    }
  }

  function iniciar(callback) {
    aoConcluir = callback || (() => {});
    const area = document.getElementById('area-arquivo');
    const campo = document.getElementById('campo-arquivo');

    area.addEventListener('click', () => campo.click());
    campo.addEventListener('change', () => { arquivo = campo.files[0] || null; atualizarRotulo(); });

    ['dragenter', 'dragover'].forEach((e) =>
      area.addEventListener(e, (ev) => { ev.preventDefault(); area.classList.add('sobre'); }));
    ['dragleave', 'drop'].forEach((e) =>
      area.addEventListener(e, (ev) => { ev.preventDefault(); area.classList.remove('sobre'); }));
    area.addEventListener('drop', (ev) => { arquivo = ev.dataTransfer.files[0] || null; atualizarRotulo(); });

    document.getElementById('abrir-importar').addEventListener('click', abrir);
    document.getElementById('fechar-importar').addEventListener('click', fechar);
    document.getElementById('conferir').addEventListener('click', () => enviar(true));
    document.getElementById('gravar').addEventListener('click', () => enviar(false));
    document.getElementById('modal-importar').addEventListener('click', (e) => {
      if (e.target.id === 'modal-importar') fechar();
    });
  }

  return { iniciar, abrir, fechar };
})();