<?php

declare(strict_types=1);

/**
 * Abre a visibilidade dos candidatos semeados, de forma variada, para a demonstração.
 *
 * **Por que não está no `semear-candidatos.php`.** Aquele consome chamada da API oficial e por
 * isso tem limite obrigatório. Este não fala com a API nem com o CREA: só escreve escolhas de
 * visibilidade pelos mesmos serviços que a tela do titular chama. Separar deixa reembaralhar o
 * cenário quantas vezes for preciso sem gastar nenhuma chamada registrada.
 *
 * **Por que abrir alguma coisa.** O padrão da plataforma é a ausência (D22): candidato recém
 * cadastrado tem perfil vazio para quem visita, mesmo estando no pool — entrar no pool é o
 * consentimento `EXIBICAO_PERFIL`, e não abre campo nenhum. Sem este passo, o feed lista
 * compatíveis cujo "ver perfil completo" leva a uma página em branco, e a D52 faz o card não
 * citar ART nenhuma. Parece defeito e não é.
 *
 * **Por que variado, e não tudo aberto.** A visibilidade granular é diferencial da proposta
 * (RF01, RF03). Abrir tudo em todos faria a demonstração nunca exercitar o recurso que ela quer
 * mostrar: ninguém veria um perfil discreto ao lado de um aberto, nem o card dizendo que existe
 * evidência não aberta. O perfil discreto não é defeito do cenário, é o cenário.
 *
 * Determinístico: a mesma base produz a mesma distribuição, porque o sorteio é semeado pelo id do
 * usuário. Rodar duas vezes não muda nada e não duplica linha — `definir()` compara com o estado
 * anterior e só grava o que mudou.
 *
 * Uso:
 *   docker compose exec php php scripts/abrir-visibilidade-demo.php
 *   docker compose exec php php scripts/abrir-visibilidade-demo.php --simular
 */

require_once dirname(__DIR__) . '/_config.php';

use ProLink\Service\VisibilidadeService;
use ProLink\Support\Database;
use ProLink\Support\Visibilidade;

$opcoes  = getopt('', ['simular']);
$simular = isset($opcoes['simular']);

$pdo          = Database::conexao();
$visibilidade = new VisibilidadeService();

/**
 * Os três perfis de postura, e quanto cada um abre.
 *
 * `campos` e `arts` são a fração do que existe que fica visível; `nivel` é o alcance escolhido.
 * Nem a postura mais aberta chega a 1,00 nas ARTs: mesmo quem quer ser encontrado costuma ter um
 * trabalho que prefere não listar, e é esse caso que a tela precisa saber mostrar. Com acervo
 * pequeno o arredondamento pode acabar abrindo todas, e tudo bem — forçar uma ART fechada em quem
 * tem duas seria encenar o recurso em vez de exercitá-lo.
 */
const POSTURAS = [
    // Quer ser achado: abre quase tudo, para qualquer pessoa.
    ['peso' => 6, 'nome' => 'aberto',    'campos' => 1.00, 'arts' => 0.85, 'nivel' => VISIBILIDADE_PUBLICO],
    // Aceita ser achado por quem tem conta, e mostra parte do acervo.
    ['peso' => 3, 'nome' => 'reservado', 'campos' => 0.60, 'arts' => 0.50, 'nivel' => VISIBILIDADE_AUTENTICADO],
    // Está no pool, mas mostra pouco. É o caso que prova o controle granular.
    ['peso' => 1, 'nome' => 'discreto',  'campos' => 0.25, 'arts' => 0.15, 'nivel' => VISIBILIDADE_AUTENTICADO],
];

/** Sorteia uma postura pelos pesos, com o gerador já semeado. */
function postura(): array
{
    $total = array_sum(array_column(POSTURAS, 'peso'));
    $ponto = mt_rand(1, $total);

    foreach (POSTURAS as $p) {
        $ponto -= $p['peso'];

        if ($ponto <= 0) {
            return $p;
        }
    }

    return POSTURAS[0];
}

/**
 * Escolhe uma fração dos alvos, sempre ao menos um quando a fração não é zero: candidato do pool
 * sem nada aberto existe, mas fazer disso a maioria esconderia a plataforma inteira na demo.
 *
 * @param  list<int> $alvos
 * @return list<int>
 */
function fatia(array $alvos, float $fracao): array
{
    if ($alvos === [] || $fracao <= 0) {
        return [];
    }

    shuffle($alvos);

    return array_slice($alvos, 0, max(1, (int) round(count($alvos) * $fracao)));
}

// Só as contas de demonstração. A conta de uma pessoa real nunca é tocada por script.
$candidatos = $pdo->query(
    "SELECT u.usu_id, u.usu_nome, p.per_codigo
       FROM sis_usuarios u
       JOIN sis_perfis p ON p.per_id = u.usu_per_id
      WHERE u.usu_status = 'A'
        AND u.usu_email LIKE '%@prolink.local'
        AND p.per_codigo IN ('PROFISSIONAL', 'EMPRESA')
      ORDER BY u.usu_id"
)->fetchAll();

if ($candidatos === []) {
    printf("\e[33mNenhum candidato de demonstração.\e[0m  Rode scripts/semear-candidatos.php antes.\n");

    exit(1);
}

printf(
    "\e[1mAbrindo visibilidade\e[0m%s\n  %d candidato(s)\n\n",
    $simular ? '  ' . "\e[33mSIMULAÇÃO, nada é gravado\e[0m" : '',
    count($candidatos),
);

$mudancas = 0;

foreach ($candidatos as $candidato) {
    $usuarioId = (int) $candidato['usu_id'];
    $ehEmpresa = $candidato['per_codigo'] === PERFIL_EMPRESA;

    // Semente pelo id: a mesma base sempre produz o mesmo cenário, e um bug no feed é
    // reproduzível em vez de "às vezes some".
    mt_srand($usuarioId);

    $postura = postura();
    $campos  = $ehEmpresa ? Visibilidade::CAMPOS_DA_EMPRESA : Visibilidade::CAMPOS_DO_PERFIL;

    $arts = array_map('intval', $pdo->query(
        'SELECT a.art_id FROM crea_arts a
           JOIN pro_profissionais p ON p.prf_rnp = a.art_pro_rnp
          WHERE p.prf_usu_id = ' . $usuarioId . ' ORDER BY a.art_id'
    )->fetchAll(PDO::FETCH_COLUMN));

    // Experiência tem alvo próprio de visibilidade, e esquecê-la deixava o bloco autodeclarado
    // invisível em toda a plataforma: o perfil mostrava o acervo verificado e nada do que a
    // pessoa relatou, que é metade do que a proposta promete distinguir.
    $experiencias = array_map('intval', $pdo->query(
        'SELECT e.exp_id FROM pro_experiencias e
           JOIN pro_profissionais p ON p.prf_id = e.exp_prf_id
          WHERE p.prf_usu_id = ' . $usuarioId . ' AND e.exp_status = "A" ORDER BY e.exp_id'
    )->fetchAll(PDO::FETCH_COLUMN));

    $camposAbertos = fatia(array_keys($campos), $postura['campos']);
    $artsAbertas   = fatia($arts, $postura['arts']);
    $expAbertas    = fatia($experiencias, $postura['arts']);

    $mudou = 0;

    if (!$simular) {
        foreach ($camposAbertos as $indice) {
            $mudou += $visibilidade->definir(
                $usuarioId, Visibilidade::PERFIL, null, $campos[$indice], $postura['nivel'],
            ) ? 1 : 0;
        }

        foreach ($artsAbertas as $artId) {
            $mudou += $visibilidade->definir(
                $usuarioId, Visibilidade::ART, $artId, null, $postura['nivel'],
            ) ? 1 : 0;
        }

        foreach ($expAbertas as $expId) {
            $mudou += $visibilidade->definir(
                $usuarioId, Visibilidade::EXPERIENCIA, $expId, null, $postura['nivel'],
            ) ? 1 : 0;
        }
    }

    $mudancas += $mudou;

    printf(
        "  \e[2m%-10s\e[0m %-28s %d/%d campo(s) · %d/%d ART(s) · %d/%d exp.%s\n",
        $postura['nome'],
        mb_substr((string) $candidato['usu_nome'], 0, 28),
        count($camposAbertos),
        count($campos),
        count($artsAbertas),
        count($arts),
        count($expAbertas),
        count($experiencias),
        $simular ? '' : sprintf('  (%d gravada(s))', $mudou),
    );
}

printf(
    "\n%s  %d linha(s) de visibilidade %s\n",
    $simular ? "\e[33mSIMULAÇÃO\e[0m" : "\e[32mPRONTO\e[0m",
    $mudancas,
    $simular ? 'seriam gravadas' : 'gravadas',
);
