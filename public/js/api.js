/* global window, document */
'use strict';

const Api = (() => {
  const CHAVE_TOKEN = 'optica.token';
  const CHAVE_USUARIO = 'optica.usuario';

  const token = () => window.localStorage.getItem(CHAVE_TOKEN);
  const usuario = () => {
    try { return JSON.parse(window.localStorage.getItem(CHAVE_USUARIO) || 'null'); }
    catch (_) { return null; }
  };

  function salvarSessao(novoToken, dados) {
    window.localStorage.setItem(CHAVE_TOKEN, novoToken);
    window.localStorage.setItem(CHAVE_USUARIO, JSON.stringify(dados));
  }

  function sair() {
    window.localStorage.removeItem(CHAVE_TOKEN);
    window.localStorage.removeItem(CHAVE_USUARIO);
    window.location.href = '/login.html';
  }

  function exigirSessao() {
    if (!token()) window.location.href = '/login.html';
  }

  function limpar(objeto) {
    const saida = {};
    for (const [chave, valor] of Object.entries(objeto || {})) {
      if (valor === null || valor === undefined || valor === '') continue;
      if (Array.isArray(valor)) { if (valor.length) saida[chave] = valor.join(','); continue; }
      saida[chave] = valor;
    }
    return saida;
  }

  async function requisitar(caminho, opcoes = {}) {
    const cabecalhos = { ...(opcoes.headers || {}) };
    if (token()) cabecalhos.Authorization = `Bearer ${token()}`;
    if (opcoes.body && !(opcoes.body instanceof FormData)) cabecalhos['Content-Type'] = 'application/json';

    const resposta = await fetch(caminho, { ...opcoes, headers: cabecalhos });
    if (resposta.status === 401 && !caminho.includes('/auth/login')) {
      sair();
      throw new Error('Sessao expirada.');
    }
    const tipo = resposta.headers.get('content-type') || '';
    const corpo = tipo.includes('application/json') ? await resposta.json() : await resposta.text();
    if (!resposta.ok) throw new Error((corpo && corpo.erro) || 'Nao foi possivel concluir a operacao.');
    return corpo;
  }

  const get = (caminho, parametros) =>
    requisitar(caminho + (parametros ? `?${new URLSearchParams(limpar(parametros))}` : ''));
  const post = (caminho, corpo) =>
    requisitar(caminho, { method: 'POST', body: corpo instanceof FormData ? corpo : JSON.stringify(corpo || {}) });
  const put = (caminho, corpo) => requisitar(caminho, { method: 'PUT', body: JSON.stringify(corpo) });

  async function baixar(caminho, parametros, nomeArquivo) {
    const url = caminho + (parametros ? `?${new URLSearchParams(limpar(parametros))}` : '');
    const resposta = await fetch(url, { headers: { Authorization: `Bearer ${token()}` } });
    if (!resposta.ok) throw new Error('Nao foi possivel gerar o arquivo.');
    const blob = await resposta.blob();
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = nomeArquivo;
    link.click();
    URL.revokeObjectURL(link.href);
  }

  return { get, post, put, requisitar, baixar, salvarSessao, sair, exigirSessao, token, usuario, limpar };
})();

/* ---------------- Formatacao ---------------- */
const Formato = {
  moeda: (v) => Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL', maximumFractionDigits: 0 }),
  moedaExata: (v) => Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }),
  numero: (v) => Number(v || 0).toLocaleString('pt-BR'),
  percentual: (v) => `${Number(v || 0).toFixed(1).replace('.', ',')}%`,
  data: (v) => (v ? new Date(`${String(v).slice(0, 10)}T12:00:00`).toLocaleDateString('pt-BR') : '-'),
  periodo: (r) => (r ? `${Formato.data(r.periodo_inicio)} a ${Formato.data(r.periodo_fim)}` : '-'),
  variacao: (atual, anterior) => (anterior ? ((atual - anterior) / Math.abs(anterior)) * 100 : null)
};

/* ---------------- Avisos ---------------- */
function avisar(mensagem, tipo = 'ok') {
  let caixa = document.getElementById('aviso');
  if (!caixa) {
    caixa = document.createElement('div');
    caixa.id = 'aviso';
    document.body.appendChild(caixa);
  }
  caixa.textContent = mensagem;
  caixa.className = `aviso visivel${tipo === 'erro' ? ' erro' : ''}`;
  clearTimeout(caixa._temporizador);
  caixa._temporizador = setTimeout(() => { caixa.className = 'aviso'; }, 4500);
}

/** Escapa texto antes de jogar em innerHTML. */
function esc(texto) {
  return String(texto ?? '').replace(/[&<>"']/g, (c) =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}