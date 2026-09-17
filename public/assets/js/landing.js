/**
 * As quatro cenas da landing: o plano que se ergue em volume.
 *
 * Não é enfeite, é o argumento da proposta desenhado. Dois eixos formam o plano (Confiabilidade e
 * Compatibilidade) e o terceiro é a profundidade que transforma o plano em volume (Contato). A
 * mesma figura aparece quatro vezes: uma no hero e uma em cada cartão da faixa escura.
 *
 * A geometria, os tempos e o comportamento são os de `docs/mockups/prolink-landing.html`. Só o
 * lugar do arquivo mudou: lá o código é um <script> embutido, e a CSP (`script-src 'self'`)
 * recusa inline. Arquivo servido pela própria aplicação ela aceita, que é o caso deste, do
 * `feed-compativeis.js`, do `info.js`, do `confirmar-acao.js` e do `perfil-abrangencia.js`.
 *
 * Melhoria progressiva, como o resto do JavaScript da casa. Sem este arquivo cada cena continua
 * desenhada: o SVG irmão do <canvas> é o quadro final da animação, e é ele que a página mostra
 * enquanto ninguém marcar o par com `is-js`. Nenhuma <canvas> vazia fica na tela.
 *
 * Quem pediu menos movimento no sistema (`prefers-reduced-motion: reduce`) recebe o quadro final
 * desenhado uma vez, sem laço e sem requestAnimationFrame.
 */
(function () {
  'use strict';
  var reduce=window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var COL={x:'#2E6BE6',y:'#63A0F0',z:'#8FE3FF',grid:'rgba(120,165,240,.14)',node:'#DCEBFF'};
  function ease(t){return t<0?0:t>1?1:t*t*(3-2*t);}
  function P(x,y,z,st){var cA=Math.cos(st.yaw),sA=Math.sin(st.yaw);var rx=x*cA+z*sA,rz=-x*sA+z*cA,ry=y;
    var cp=Math.cos(st.pitch),sp=Math.sin(st.pitch);var py=ry*cp-rz*sp,pz=ry*sp+rz*cp;var d=st.cam-pz,f=st.scale/d;
    return{x:st.cx+rx*f,y:st.cyv-py*f};}
  function ln(c,a,b,col,w,glow){c.strokeStyle=col;c.lineWidth=w||1.4;c.shadowColor=glow||'transparent';c.shadowBlur=glow?13:0;c.beginPath();c.moveTo(a.x,a.y);c.lineTo(b.x,b.y);c.stroke();c.shadowBlur=0;}
  function nd(c,p,r,col,glow){c.fillStyle=col;c.shadowColor=glow||col;c.shadowBlur=glow?15:0;c.beginPath();c.arc(p.x,p.y,r,0,7);c.fill();c.shadowBlur=0;}
  function grid(c,st,a){for(var i=-3;i<=3;i++){ln(c,P(i*a/3,0,-a,st),P(i*a/3,0,a,st),COL.grid,1);ln(c,P(-a,0,i*a/3,st),P(a,0,i*a/3,st),COL.grid,1);}}
  var A=1.2;
  function sceneHero(c,W,H,p,el){var yaw=0.35+ease(Math.min(1,Math.max(0,(p-0.4)/0.6)))*0.5+el*0.05;
    var st={yaw:yaw,pitch:0.54,cam:9,scale:Math.min(W,H)*1.15,cx:W/2,cyv:H*0.58};grid(c,st,A);
    var base=[P(-A,0,-A,st),P(A,0,-A,st),P(A,0,A,st),P(-A,0,A,st)];
    c.fillStyle='rgba(46,107,230,.10)';c.beginPath();base.forEach(function(q,i){i?c.lineTo(q.x,q.y):c.moveTo(q.x,q.y);});c.closePath();c.fill();
    ln(c,base[0],base[1],COL.x,2,COL.x);ln(c,base[3],base[2],COL.x,2);ln(c,base[0],base[3],COL.y,2,COL.y);ln(c,base[1],base[2],COL.y,2);
    var h=1.5*ease(Math.min(1,Math.max(0,(p-0.1)/0.5)));
    if(h>0.01){var cc=[[-A,-A],[A,-A],[A,A],[-A,A]];var top=cc.map(function(k){return P(k[0],h,k[1],st);});
      cc.forEach(function(k,i){ln(c,base[i],P(k[0],h,k[1],st),COL.z,1.8,COL.z);});ln(c,P(0,0,0,st),P(0,h,0,st),COL.z,2.4,COL.z);
      if(h>1){c.fillStyle='rgba(143,227,255,.10)';c.beginPath();top.forEach(function(q,i){i?c.lineTo(q.x,q.y):c.moveTo(q.x,q.y);});c.closePath();c.fill();
        for(var i=0;i<4;i++)ln(c,top[i],top[(i+1)%4],COL.z,1.8,COL.z);top.forEach(function(q){nd(c,q,2.6,COL.node,COL.z);});}}}
  function sceneX(c,W,H,p){var st={yaw:0.5,pitch:0.5,cam:8.5,scale:Math.min(W,H)*1.05,cx:W/2,cyv:H*0.56};grid(c,st,A);
    var g=ease(Math.min(1,p/0.55));ln(c,P(-A,0,0,st),P(-A+2*A*g,0,0,st),COL.x,2.4,COL.x);var end=P(A,0,0,st);
    if(p>0.5){var q=ease(Math.min(1,(p-0.5)/0.5));nd(c,end,3+2*q,COL.node,COL.x);c.strokeStyle='rgba(143,227,255,'+(0.7*(1-q))+')';c.lineWidth=2;c.beginPath();c.arc(end.x,end.y,6+20*q,0,7);c.stroke();
      if(q>0.4){c.strokeStyle=COL.z;c.lineWidth=2.4;c.shadowColor=COL.z;c.shadowBlur=9;c.beginPath();c.moveTo(end.x-5,end.y);c.lineTo(end.x-1,end.y+4);c.lineTo(end.x+6,end.y-6);c.stroke();c.shadowBlur=0;}}}
  function sceneY(c,W,H,p){var st={yaw:0.55,pitch:0.52,cam:8.2,scale:Math.min(W,H)*1.05,cx:W/2,cyv:H*0.56};grid(c,st,A);
    var gx=ease(Math.min(1,p/0.32)),gy=ease(Math.min(1,Math.max(0,(p-0.28)/0.32)));var cn=[P(-A,0,-A,st),P(A,0,-A,st),P(A,0,A,st),P(-A,0,A,st)];
    ln(c,cn[0],{x:cn[0].x+(cn[1].x-cn[0].x)*gx,y:cn[0].y+(cn[1].y-cn[0].y)*gx},COL.x,2.4,COL.x);
    ln(c,cn[0],{x:cn[0].x+(cn[3].x-cn[0].x)*gy,y:cn[0].y+(cn[3].y-cn[0].y)*gy},COL.y,2.4,COL.y);
    if(p>0.55){var gs=ease(Math.min(1,(p-0.55)/0.25));ln(c,cn[1],{x:cn[1].x+(cn[2].x-cn[1].x)*gs,y:cn[1].y+(cn[2].y-cn[1].y)*gs},COL.y,2,COL.y);ln(c,cn[3],{x:cn[3].x+(cn[2].x-cn[3].x)*gs,y:cn[3].y+(cn[2].y-cn[3].y)*gs},COL.x,2,COL.x);}
    if(p>0.7){var gm=ease(Math.min(1,(p-0.7)/0.3));for(var i=1;i<=3;i++){var t=i/4;var a=P(-A+2*A*t,0,-A,st),b=P(-A+2*A*((t*1.7)%1),0,A,st);ln(c,a,{x:a.x+(b.x-a.x)*gm,y:a.y+(b.y-a.y)*gm},'rgba(143,227,255,'+(0.5*gm)+')',1.4);nd(c,a,2.4,COL.node);}}}
  function sceneZ(c,W,H,p){var yaw=0.35+ease(Math.min(1,Math.max(0,(p-0.45)/0.55)))*0.55;var st={yaw:yaw,pitch:0.5,cam:8.6,scale:Math.min(W,H)*0.98,cx:W/2,cyv:H*0.6};grid(c,st,A);
    var base=[P(-A,0,-A,st),P(A,0,-A,st),P(A,0,A,st),P(-A,0,A,st)];c.fillStyle='rgba(46,107,230,.12)';c.beginPath();base.forEach(function(q,i){i?c.lineTo(q.x,q.y):c.moveTo(q.x,q.y);});c.closePath();c.fill();
    ln(c,base[0],base[1],COL.x,2,COL.x);ln(c,base[3],base[2],COL.x,2);ln(c,base[0],base[3],COL.y,2,COL.y);ln(c,base[1],base[2],COL.y,2);
    var h=1.3*ease(Math.min(1,Math.max(0,(p-0.15)/0.5)));if(h>0.01){var cc=[[-A,-A],[A,-A],[A,A],[-A,A]];var top=cc.map(function(k){return P(k[0],h,k[1],st);});
      cc.forEach(function(k,i){ln(c,base[i],P(k[0],h,k[1],st),COL.z,2,COL.z);});ln(c,P(0,0,0,st),P(0,h,0,st),COL.z,2.6,COL.z);
      if(h>0.9){c.fillStyle='rgba(143,227,255,.12)';c.beginPath();top.forEach(function(q,i){i?c.lineTo(q.x,q.y):c.moveTo(q.x,q.y);});c.closePath();c.fill();for(var i=0;i<4;i++)ln(c,top[i],top[(i+1)%4],COL.z,2,COL.z);top.forEach(function(q){nd(c,q,2.6,COL.node,COL.z);});}}}
  var SC={hero:sceneHero,x:sceneX,y:sceneY,z:sceneZ},DUR={hero:3800,x:2600,y:3200,z:3400},HOLD=1600;
  document.querySelectorAll('canvas[data-scene]').forEach(function(cv){
    var ctx=cv.getContext('2d'),name=cv.getAttribute('data-scene'),fn=SC[name],W,H,DPR;
    if(!ctx||!fn){return;}
    // O par canvas + SVG troca de lado aqui, e não no CSS: sem JavaScript a <canvas> nunca é
    // exibida, e quem fica na tela é o SVG do quadro final. A marca entra antes da primeira
    // medida porque elemento com display:none mede 0 por 0.
    var par=cv.parentNode;
    if(par&&par.classList){par.classList.add('is-js');}
    function rz(){DPR=Math.min(window.devicePixelRatio||1,2);var r=cv.getBoundingClientRect();W=r.width;H=r.height;cv.width=Math.round(W*DPR);cv.height=Math.round(H*DPR);ctx.setTransform(DPR,0,0,DPR,0,0);}
    function estatico(){rz();ctx.clearRect(0,0,W,H);fn(ctx,W,H,1,0);}
    if(reduce){
      // Redimensionar zera o bitmap da <canvas>. Sem redesenhar aqui, quem pediu menos movimento
      // ficava com o quadro em branco depois de girar o telefone ou mudar a janela de tamanho.
      estatico();window.addEventListener('resize',estatico);return;
    }
    rz();window.addEventListener('resize',rz);
    var t0=null,total=DUR[name]+HOLD;
    function loop(ts){if(t0===null)t0=ts;var e=(ts-t0)%total,p=Math.min(1,e/DUR[name]);ctx.clearRect(0,0,W,H);fn(ctx,W,H,p,(ts-t0)/1000);requestAnimationFrame(loop);}
    requestAnimationFrame(loop);
  });
})();
