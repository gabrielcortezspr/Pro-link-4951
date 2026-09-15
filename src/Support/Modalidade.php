<?php

declare(strict_types=1);

namespace ProLink\Support;

/**
 * Correspondência entre a modalidade do profissional e os grupos da Tabela de Obras e Serviços.
 *
 * Alimenta a dimensão "área de atuação" do item 3.2 (peso 0.15). O CREA publica as duas listas
 * separadamente e não publica a ligação entre elas, então este mapa é **nosso**, não oficial.
 *
 * Por isso ele é conservador de propósito: só entram as correspondências que o nome já resolve
 * sozinho, como Computação com o grupo Computação. Onde a atribuição depende de resolução do
 * Confea que a gente não tem em mãos, a modalidade fica **fora do mapa**, e a dimensão devolve
 * null para aquele candidato, saindo da média em vez de contar zero.
 *
 * A escolha importa e não é técnica: afirmar que uma modalidade **não** atende um grupo é
 * afirmar limite de atribuição profissional, que é competência do conselho e não nossa. Errar
 * para o lado de "não sei" tira 0.15 do cálculo; errar para o lado de inventar produziria uma
 * afirmação sobre o que um engenheiro pode assinar. O motor continua decidindo pela competência
 * comprovada em ART, que é o dado que a API confirma.
 *
 * Nada aqui ordena candidato nem qualifica pessoa (item 10.1): o mapa só diz se a área pedida
 * pela demanda está entre as que o candidato declara atuar.
 */
final class Modalidade
{
    /**
     * Modalidade (`crea_modalidades.mod_nome`) => grupos TOS (`tos_nivel1`) inequívocos.
     *
     * Comparação por nome normalizado, porque `mod_codigo` vem nulo na massa e `mod_id` é chave
     * local que pode mudar entre bancos.
     */
    private const MAPA = [
        'engenharia civil'                        => [1, 2, 3, 4, 5, 6, 8, 9, 10],
        'engenharia eletrica'                     => [11, 12, 15],
        'engenharia mecanica'                     => [16],
        'engenharia quimica'                      => [21],
        'engenharia de minas'                     => [29, 31, 32],
        'engenharia de computacao'                => [14],
        'engenharia de telecomunicacoes'          => [15],
        'engenharia eletronica'                   => [12],
        'engenharia de alimentos'                 => [24],
        'engenharia de materiais'                 => [23],
        'engenharia metalurgica'                  => [17],
        'engenharia naval'                        => [18],
        'engenharia aeronautica'                  => [19, 9],
        'engenharia de producao'                  => [20],
        'engenharia de controle e automacao'      => [13],
        'engenharia ambiental'                    => [6, 7, 46],
        'engenharia de seguranca do trabalho'     => [42, 43, 44, 45],
        'engenharia florestal'                    => [39],
        'engenharia agricola'                     => [39],
        'agronomia'                               => [39],
        'engenharia de pesca'                     => [39],
        'geologia'                                => [3, 26, 27, 29],
        'geografia'                               => [38],
        'meteorologia'                            => [41],

        // Engenharia de Petróleo fica de fora: o grupo 30 (Hidrocarbonetos) é o candidato óbvio,
        // mas a atribuição sobre lavra (31) e sobre química de processo (21) varia por resolução,
        // e chutar aqui produziria exclusão que não sabemos sustentar.
    ];

    /**
     * Grupos TOS cobertos pelas modalidades do candidato.
     *
     * Modalidade fora do mapa não contribui e não atrapalha. Se **nenhuma** das modalidades do
     * candidato for conhecida, a lista sai vazia e a dimensão inteira sai da média, que é o
     * comportamento pedido: perfil não é punido por lacuna nossa.
     *
     * @param  list<string> $modalidades  nomes vindos de `crea_modalidades.mod_nome`
     * @return list<int>
     */
    public static function gruposDe(array $modalidades): array
    {
        $grupos = [];

        foreach ($modalidades as $nome) {
            $chave = Tos::normalizar((string) $nome);

            foreach (self::MAPA[$chave] ?? [] as $grupo) {
                $grupos[$grupo] = true;
            }
        }

        $lista = array_keys($grupos);
        sort($lista);

        return $lista;
    }

    /** Se a modalidade tem correspondência declarada. Usado para explicar a ausência na interface. */
    public static function conhecida(string $nome): bool
    {
        return isset(self::MAPA[Tos::normalizar($nome)]);
    }
}
