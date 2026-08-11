/* global Chart, document, Formato */
'use strict';

const Graficos = (() => {
  const CORES = {
    azul: '#1b57c4', azulClaro: '#9dbdec', verde: '#1f8a70',
    violeta: '#6c4cb6', grafite: '#9aa5ac', linha: '#dfe4e3', tinta: '#0f171e'
  };
  const PALETA = [CORES.azul, CORES.verde, CORES.violeta, CORES.azulClaro, CORES.grafite, '#b8860b', '#b4451f', '#4f6d7a'];

  const instancias = {};

  const base = {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
      legend: { labels: { font: { family: 'Inter', size: 12 }, usePointStyle: true, boxWidth: 8, padding: 16 } },
      tooltip: {
        backgroundColor: CORES.tinta, padding: 10, cornerRadius: 8,
        titleFont: { family: 'Familjen Grotesk', size: 13 },
        bodyFont: { family: 'IBM Plex Mono', size: 12 }
      }
    },
    scales: {
      x: { grid: { display: false }, ticks: { font: { family: 'IBM Plex Mono', size: 10 }, color: '#7c8891' } },
      y: {
        grid: { color: CORES.linha }, border: { display: false },
        ticks: { font: { family: 'IBM Plex Mono', size: 10 }, color: '#7c8891', callback: (v) => Formato.moeda(v) }
      }
    }
  };

  function desenhar(id, config) {
    const elemento = document.getElementById(id);
    if (!elemento) return;
    if (instancias[id]) instancias[id].destroy();
    instancias[id] = new Chart(elemento, config);
  }

  /** Pareto: barras de faturamento por cliente + linha do acumulado. */
  function pareto(pontos) {
    desenhar('grafico-pareto', {
      data: {
        labels: pontos.map((p) => `#${p.posicao}`),
        datasets: [
          {
            type: 'bar', label: 'Faturamento', yAxisID: 'y',
            data: pontos.map((p) => Number(p.faturamento)),
            backgroundColor: pontos.map((p) =>
              p.classe === 'A' ? CORES.azul : p.classe === 'B' ? CORES.azulClaro : CORES.grafite),
            borderRadius: 2
          },
          {
            type: 'line', label: '% acumulado', yAxisID: 'y2',
            data: pontos.map((p) => Number(p.participacao_acumulada)),
            borderColor: CORES.verde, borderWidth: 2, pointRadius: 0, tension: .3
          }
        ]
      },
      options: {
        ...base,
        plugins: {
          ...base.plugins,
          tooltip: {
            ...base.plugins.tooltip,
            callbacks: {
              title: (ctx) => pontos[ctx[0].dataIndex]?.nome || '',
              label: (ctx) => ctx.dataset.yAxisID === 'y2'
                ? ` acumulado: ${Formato.percentual(ctx.parsed.y)}`
                : ` ${Formato.moedaExata(ctx.parsed.y)}`
            }
          }
        },
        scales: {
          x: { ...base.scales.x, ticks: { ...base.scales.x.ticks, maxTicksLimit: 15 } },
          y: base.scales.y,
          y2: {
            position: 'right', min: 0, max: 100, grid: { display: false },
            ticks: { font: { family: 'IBM Plex Mono', size: 10 }, color: '#7c8891', callback: (v) => `${v}%` }
          }
        }
      }
    });
  }

  /** Faturamento por familia de produto. */
  function familias(lista) {
    desenhar('grafico-familias', {
      type: 'doughnut',
      data: {
        labels: lista.map((f) => f.familia),
        datasets: [{
          data: lista.map((f) => Number(f.faturamento)),
          backgroundColor: PALETA, borderWidth: 2, borderColor: '#ffffff'
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false, cutout: '60%',
        plugins: {
          legend: { position: 'bottom', labels: base.plugins.legend.labels },
          tooltip: {
            ...base.plugins.tooltip,
            callbacks: { label: (ctx) => ` ${ctx.label}: ${Formato.moedaExata(ctx.parsed)}` }
          }
        }
      }
    });
  }

  /** Comparacao entre os periodos importados. */
  function periodos(lista) {
    desenhar('grafico-periodos', {
      type: 'bar',
      data: {
        labels: lista.map((p) => Formato.periodo(p)),
        datasets: [{
          label: 'Faturamento do periodo',
          data: lista.map((p) => Number(p.total_valor)),
          backgroundColor: CORES.azul, borderRadius: 4, maxBarThickness: 60
        }]
      },
      options: { ...base, plugins: { ...base.plugins, legend: { display: false } } }
    });
  }

  /** Mix de familias de um cliente (usado na ficha). */
  function mixCliente(lista) {
    desenhar('grafico-mix', {
      type: 'bar',
      data: {
        labels: lista.map((f) => f.familia),
        datasets: [{
          label: 'Faturamento', data: lista.map((f) => Number(f.faturamento)),
          backgroundColor: CORES.azul, borderRadius: 4, maxBarThickness: 22
        }]
      },
      options: {
        ...base, indexAxis: 'y',
        plugins: { ...base.plugins, legend: { display: false } },
        scales: {
          x: { ...base.scales.y, beginAtZero: true },
          y: { ...base.scales.x, ticks: { ...base.scales.x.ticks, font: { family: 'Inter', size: 11.5 } } }
        }
      }
    });
  }

  return { pareto, familias, periodos, mixCliente };
})();