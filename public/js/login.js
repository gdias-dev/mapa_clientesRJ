/* global document, window, Api, console */
'use strict';

console.log('[login] script carregado');

const campoErro = document.getElementById('erro');
const botao = document.getElementById('entrar');
const campoEmail = document.getElementById('email');
const campoSenha = document.getElementById('senha');

if (!botao || !campoEmail || !campoSenha || !campoErro) {
  console.error('[login] elemento nao encontrado no HTML:', {
    botao: !!botao, email: !!campoEmail, senha: !!campoSenha, erro: !!campoErro
  });
} else if (typeof Api === 'undefined') {
  console.error('[login] api.js nao carregou - confira a ordem das tags <script>');
  campoErro.textContent = 'Erro ao carregar a pagina. Recarregue com Ctrl+Shift+R.';
} else {
  botao.addEventListener('click', entrar);
  document.addEventListener('keydown', (e) => { if (e.key === 'Enter') entrar(); });
  console.log('[login] pronto');
}

async function entrar() {
  console.log('[login] clique recebido');
  const email = campoEmail.value.trim();
  const senha = campoSenha.value;
  campoErro.textContent = '';

  if (!email || !senha) {
    campoErro.textContent = 'Preencha e-mail e senha.';
    return;
  }

  botao.disabled = true;
  botao.textContent = 'Entrando...';
  try {
    const dados = await Api.post('/api/auth/login', { email, senha });
    Api.salvarSessao(dados.token, dados.usuario);
    window.location.href = '/';
  } catch (erro) {
    console.error('[login] falhou:', erro);
    campoErro.textContent = erro.message;
    botao.disabled = false;
    botao.textContent = 'Entrar';
  }
}