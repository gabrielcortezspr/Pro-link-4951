/**
 * As contas que a suíte usa, e o que cada uma prova.
 *
 * Nenhuma senha real mora aqui. `SENHA_DEMO` é a que `scripts/semear-candidatos.php` imprime ao
 * criar as contas de demonstração, e já está no repositório desde que aquele script existe; a do
 * administrador não tem valor padrão de propósito, e a suíte falha com instrução clara se ela não
 * vier do ambiente. O Anexo VI do edital lista "não contém credenciais, segredos ou chaves reais"
 * como item de triagem, e conta de administração com senha versionada seria exatamente isso.
 */
export const SENHA_DEMO = process.env.PROLINK_E2E_SENHA_DEMO ?? 'ProLinkDemo2026!';

/**
 * Profissionais escolhidos olhando o índice de evidência, não o nome. Arthur e Sophia têm acervo
 * exatamente em `TOS_10.4.2.3`, o código da demanda do roteiro; Pedro entra no mesmo conjunto pela
 * atividade vizinha (`TOS_10.1.1`), o que também exercita a afinidade parcial. Os vínculos de ART
 * para código TOS da massa são aleatórios, então escolher a pessoa antes de olhar o índice
 * produziria um cenário sem compatível nenhum.
 *
 * O e-mail do Pedro mudou em 25/09: `pedro.henrique.alves.0451@prolink.local` era o do volume em
 * que a suíte foi escrita e não existe no banco atual, onde a mesma pessoa (RNP 0412340046) foi
 * cadastrada como `pedro.alves@prolink.local`.
 */
const COMPATIVEIS = [
  { email: 'pedro.alves@prolink.local',               senha: SENHA_DEMO, nome: 'PEDRO HENRIQUE ALVES' },
  { email: 'sophia.martins.0702@prolink.local',       senha: SENHA_DEMO, nome: 'SOPHIA MARTINS' },
  { email: 'arthur.gomes.0613@prolink.local',         senha: SENHA_DEMO, nome: 'ARTHUR GOMES' },
];

/**
 * Qual dos três a execução usa, e por que a escolha gira.
 *
 * `manifestacao.limite_hora` vale 10 e conta **por pessoa**. É um anti-spam correto, e derrubava a
 * suíte: numa sessão de trabalho ela roda muitas vezes, e a partir da décima a plataforma recusa a
 * manifestação. O sintoma era um estouro de tempo em `waitForURL`, e três investigações começaram
 * procurando defeito no botão.
 *
 * Girar entre os três multiplica a folga por três sem mexer no limite, que é o que não se deve
 * fazer: baixá-lo para o teste passar seria adaptar o produto ao teste. A escolha é pelo relógio,
 * em janelas de dez minutos, então é estável dentro de uma execução e de uma rodada de depuração,
 * e muda sozinha entre elas.
 *
 * Os três têm acervo em `TOS_10.4.2.3`, que é o código da demanda do roteiro: trocar de conta não
 * troca o cenário. Para fixar uma, exporte `PROLINK_E2E_PROFISSIONAL` com o e-mail.
 */
function daVez(deslocamento = 0) {
  const fixo = process.env.PROLINK_E2E_PROFISSIONAL;

  if (fixo) {
    const achado = COMPATIVEIS.find((c) => c.email === fixo);

    if (!achado) {
      throw new Error(
        `PROLINK_E2E_PROFISSIONAL=${fixo} não está entre os compatíveis: `
        + COMPATIVEIS.map((c) => c.email).join(', '),
      );
    }

    return achado;
  }

  const janela = Math.floor(Date.now() / 600_000);

  return COMPATIVEIS[(janela + deslocamento) % COMPATIVEIS.length];
}

export const PROFISSIONAL = daVez(0);

/** A demonstração usa o seguinte da roda, para não disputar cota com os cenários. */
export const PROFISSIONAL_DEMO = daVez(1);

export const EMPRESA = {
  email: 'alfa.engenharia.e.consultoria.ltda.0145@prolink.local',
  senha: SENHA_DEMO,
  nome: 'ALFA ENGENHARIA E CONSULTORIA LTDA',
};

export const ADMIN = {
  email: process.env.PROLINK_E2E_ADMIN_EMAIL ?? 'e2e.admin@verificacao.local',
  senha: process.env.PROLINK_E2E_ADMIN_SENHA ?? '',
};

/**
 * O código TOS da demanda de demonstração, e o motivo dele.
 *
 * `TOS_10.4.2.3` é "Planejamento Urbano, Metropolitano e Regional · Planejamento Urbano", e três
 * profissionais da massa têm acervo exatamente nele. `TOS_10.1.1` é do mesmo grupo (nível 1 igual
 * a 10), o que dá afinidade parcial em vez de zero, e serve para a tela mostrar que a
 * compatibilidade tem graus.
 */
export const TOS_PRINCIPAL = 'TOS_10.4.2.3';
export const TOS_SECUNDARIO = 'TOS_10.1.1';
