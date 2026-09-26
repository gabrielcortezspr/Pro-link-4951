/**
 * O volume que se ergue do plano, na tela de entrar.
 *
 * É o desenho do mockup de login, portado sem mudar a geometria: o plano de dois eixos, as
 * quatro arestas subindo, e o eixo vertical que sai do centro. A leitura é a tese do projeto:
 * confiabilidade e compatibilidade formam o plano, e a terceira dimensão é a que faltava.
 *
 * Arquivo próprio, e não script embutido, porque a política de segurança da aplicação declara
 * `script-src 'self'`: inline é recusado, arquivo servido daqui é aceito. Foi por ler isso ao
 * contrário que a animação da landing chegou a sumir.
 *
 * Quem pediu menos movimento no sistema operacional recebe o quadro final, parado, e não a
 * ausência do desenho: a informação é a mesma, sem o movimento.
 */
(function () {
  'use strict';

  var cv = document.querySelector('canvas[data-volume]');

  if (!cv || !cv.getContext) return;

  var ctx = cv.getContext('2d');
  var W, H, DPR;

  // Com o canvas desenhado, o SVG parado ao lado sai de cena. Sem JavaScript nenhum dos dois
  // some, e o que fica é o SVG: canvas em branco não é desenho, é buraco.
  var par = cv.closest('[data-fig]');
  if (par) par.classList.add('is-js');

  function medir() {
    DPR = Math.min(window.devicePixelRatio || 1, 2);
    var r = cv.getBoundingClientRect();
    W = r.width;
    H = r.height;
    cv.width = Math.round(W * DPR);
    cv.height = Math.round(H * DPR);
    ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
  }

  medir();
  window.addEventListener('resize', function () { medir(); if (parado) desenhar(1, 0.5); });

  var parado = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  var COR = {
    x: '#2E6BE6', y: '#63A0F0', z: '#8FE3FF',
    grade: 'rgba(120,165,240,.14)', no: '#DCEBFF',
  };

  function suavizar(t) { return t < 0 ? 0 : t > 1 ? 1 : t * t * (3 - 2 * t); }

  function projetar(x, y, z, st) {
    var ca = Math.cos(st.yaw), sa = Math.sin(st.yaw);
    var rx = x * ca + z * sa, rz = -x * sa + z * ca, ry = y;
    var cp = Math.cos(st.pitch), sp = Math.sin(st.pitch);
    var py = ry * cp - rz * sp, pz = ry * sp + rz * cp;
    var f = st.scale / (st.cam - pz);

    return { x: st.cx + rx * f, y: st.cyv - py * f };
  }

  function linha(a, b, c, w, brilho) {
    ctx.strokeStyle = c;
    ctx.lineWidth = w || 1.4;
    ctx.shadowColor = brilho || 'transparent';
    ctx.shadowBlur = brilho ? 14 : 0;
    ctx.beginPath();
    ctx.moveTo(a.x, a.y);
    ctx.lineTo(b.x, b.y);
    ctx.stroke();
    ctx.shadowBlur = 0;
  }

  function ponto(p, r, c, brilho) {
    ctx.fillStyle = c;
    ctx.shadowColor = brilho || c;
    ctx.shadowBlur = brilho ? 16 : 0;
    ctx.beginPath();
    ctx.arc(p.x, p.y, r, 0, 7);
    ctx.fill();
    ctx.shadowBlur = 0;
  }

  var A = 1.25;

  function desenhar(p, giroBase) {
    ctx.clearRect(0, 0, W, H);

    var yaw = giroBase + suavizar(Math.min(1, Math.max(0, (p - 0.4) / 0.6))) * 0.5;
    var st = { yaw: yaw, pitch: 0.52, cam: 9, scale: Math.min(W, H) * 1.05, cx: W / 2, cyv: H * 0.6 };
    var i;

    for (i = -3; i <= 3; i++) {
      linha(projetar(i * A / 3, 0, -A, st), projetar(i * A / 3, 0, A, st), COR.grade, 1);
      linha(projetar(-A, 0, i * A / 3, st), projetar(A, 0, i * A / 3, st), COR.grade, 1);
    }

    var base = [
      projetar(-A, 0, -A, st), projetar(A, 0, -A, st),
      projetar(A, 0, A, st), projetar(-A, 0, A, st),
    ];

    ctx.fillStyle = 'rgba(46,107,230,.10)';
    ctx.beginPath();
    base.forEach(function (q, k) { k ? ctx.lineTo(q.x, q.y) : ctx.moveTo(q.x, q.y); });
    ctx.closePath();
    ctx.fill();

    linha(base[0], base[1], COR.x, 2, COR.x);
    linha(base[3], base[2], COR.x, 2);
    linha(base[0], base[3], COR.y, 2, COR.y);
    linha(base[1], base[2], COR.y, 2);

    var h = 1.5 * suavizar(Math.min(1, Math.max(0, (p - 0.1) / 0.5)));

    if (h <= 0.01) return;

    var cantos = [[-A, -A], [A, -A], [A, A], [-A, A]];
    var topo = cantos.map(function (c) { return projetar(c[0], h, c[1], st); });

    cantos.forEach(function (c, k) { linha(base[k], projetar(c[0], h, c[1], st), COR.z, 1.8, COR.z); });
    linha(projetar(0, 0, 0, st), projetar(0, h, 0, st), COR.z, 2.4, COR.z);

    if (h <= 1) return;

    // A tampa entra por opacidade, e não de um quadro para o outro quando a altura cruza 1.
    ctx.globalAlpha = suavizar((h - 1) / 0.35);
    ctx.fillStyle = 'rgba(143,227,255,.10)';
    ctx.beginPath();
    topo.forEach(function (q, k) { k ? ctx.lineTo(q.x, q.y) : ctx.moveTo(q.x, q.y); });
    ctx.closePath();
    ctx.fill();

    for (var j = 0; j < 4; j++) linha(topo[j], topo[(j + 1) % 4], COR.z, 1.8, COR.z);

    topo.forEach(function (q) { ponto(q, 2.6, COR.no, COR.z); });
    ctx.globalAlpha = 1;
  }

  // Depois da espera no quadro final, o volume desce de volta ao plano e só então se ergue de
  // novo. Sem a volta, o módulo levava o progresso de 1 a 0 num quadro só e o desenho cortava.
  var DUR = 3600, ESPERA = 1800, VOLTA = 1600, PAUSA = 500;
  var CICLO = DUR + ESPERA + VOLTA + PAUSA, t0 = null;

  function progresso(e) {
    if (e < DUR) return e / DUR;
    e -= DUR;
    if (e < ESPERA) return 1;
    e -= ESPERA;
    if (e < VOLTA) return 1 - e / VOLTA;

    return 0;
  }

  if (parado) {
    desenhar(1, 0.5);

    return;
  }

  function quadro(ts) {
    if (t0 === null) t0 = ts;
    desenhar(progresso((ts - t0) % CICLO), 0.35 + (ts - t0) / 1000 * 0.05);
    requestAnimationFrame(quadro);
  }

  requestAnimationFrame(quadro);
}());
