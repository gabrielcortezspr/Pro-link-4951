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
 * Profissional escolhido olhando o índice de evidência, não o nome: ele tem acervo em
 * `TOS_10.4.2.3`, que é o código com mais candidatos na massa fictícia. Os vínculos de ART para
 * código TOS da massa são aleatórios, então escolher a pessoa antes de olhar o índice produziria
 * um cenário sem compatível nenhum.
 */
export const PROFISSIONAL = {
  email: 'pedro.henrique.alves.0451@prolink.local',
  senha: SENHA_DEMO,
  nome: 'PEDRO HENRIQUE ALVES',
};

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
