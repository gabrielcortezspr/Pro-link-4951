<?php

declare(strict_types=1);

namespace ProLink\Support;

/**
 * Os limites de cada parâmetro editável de `sis_parametros`.
 *
 * ## Por que os limites moram aqui e não no banco
 *
 * A tabela já guarda chave, valor, tipo, grupo, descrição e a marca de sensível. Faltava a faixa
 * aceitável, e acrescentá-la seria `par_min`/`par_max` em `estrutura.sql` na véspera da entrega,
 * com migração de banco já carregado. A faixa é regra de negócio, não configuração: quem muda o
 * intervalo válido de um peso está mudando o motor, e isso passa por revisão de código. Ver D61.
 *
 * ## O que esta lista faz de fato
 *
 * Fecha a superfície do editor do administrador. Chave que não está aqui **não é editável pela
 * tela**, mesmo existindo no banco: o formulário do painel aceita de volta o que ele mesmo
 * desenhou, e nada além disso. É a mesma lição da D27, onde um POST aceitou alvo que não era do
 * titular porque o servidor confiou no que voltou do navegador.
 */
final class Parametros
{
    /**
     * Faixa aceitável por chave.
     *
     * `passo` existe só para o campo do formulário: é a granularidade que faz sentido para
     * aquele número, não uma restrição de validação.
     *
     * @var array<string, array{min: float, max: float, passo: float, inteiro: bool}>
     */
    public const LIMITES = [
        // Score composto mínimo para entrar no pool. Zero deixaria entrar qualquer um; 1 exige
        // aderência perfeita em todas as dimensões medidas, o que esvazia o pool.
        'match.limiar' => ['min' => 0.0, 'max' => 1.0, 'passo' => 0.01, 'inteiro' => false],

        // Pesos das seis dimensões. Não precisam somar 1: o motor divide pela soma dos pesos das
        // dimensões efetivamente presentes (`Compatibilidade::compor`), o que já normaliza e é o
        // que faz dimensão ausente não penalizar. Zero é legítimo e significa desligar a dimensão.
        'match.peso.competencia'    => ['min' => 0.0, 'max' => 1.0, 'passo' => 0.05, 'inteiro' => false],
        'match.peso.area'           => ['min' => 0.0, 'max' => 1.0, 'passo' => 0.05, 'inteiro' => false],
        'match.peso.localizacao'    => ['min' => 0.0, 'max' => 1.0, 'passo' => 0.05, 'inteiro' => false],
        'match.peso.experiencia'    => ['min' => 0.0, 'max' => 1.0, 'passo' => 0.05, 'inteiro' => false],
        'match.peso.contrato'       => ['min' => 0.0, 'max' => 1.0, 'passo' => 0.05, 'inteiro' => false],
        'match.peso.disponibilidade' => ['min' => 0.0, 'max' => 1.0, 'passo' => 0.05, 'inteiro' => false],

        // Abaixo disto o perfil é sinalizado como em construção, sem sair do pool. O teto de 20 é
        // de sanidade: a distribuição real da massa é de 2 a 4 ARTs, média 3,2, e um limiar alto
        // marcaria todo mundo, o que não informa nada (ver backlog, E4).
        'match.early_career.min_arts' => ['min' => 0.0, 'max' => 20.0, 'passo' => 1.0, 'inteiro' => true],

        // Intervalo mínimo entre reconsultas de status do profissional na API oficial. O piso de
        // uma hora protege o item 10.4: intervalo curto demais transforma sincronização em
        // varredura, que é vedada.
        'api.sincronizacao.horas' => ['min' => 1.0, 'max' => 720.0, 'passo' => 1.0, 'inteiro' => true],
    ];

    /**
     * Os cinco níveis de afinidade do código TOS, o único parâmetro que não é um número solto.
     *
     * Cada posição é quantos componentes iniciais do código coincidem: nenhum, um, dois, três, e
     * código idêntico. Ver `Support\Tos::afinidade` e `docs/matching.md`.
     */
    public const AFINIDADE_NIVEIS = 'match.afinidade.niveis';

    public const AFINIDADE_QUANTIDADE = 5;

    /** Um parâmetro é editável pela tela do administrador se, e somente se, está aqui. */
    public static function editavel(string $chave): bool
    {
        return isset(self::LIMITES[$chave]) || $chave === self::AFINIDADE_NIVEIS;
    }

    /** @return array{min: float, max: float, passo: float, inteiro: bool}|null */
    public static function limite(string $chave): ?array
    {
        return self::LIMITES[$chave] ?? null;
    }

    /**
     * O grupo ao qual a chave pertence, para a tela agrupar sem inventar taxonomia própria.
     *
     * Vem de `par_grupo` no banco; este método só existe para o caso de a coluna vir vazia, que a
     * carga inicial não produz mas uma edição manual produziria.
     */
    public static function grupo(string $valorDoBanco): string
    {
        return $valorDoBanco !== '' ? $valorDoBanco : 'GERAL';
    }
}
