<?php

declare(strict_types=1);

namespace ProLink\Repository;

/**
 * `crea_tos`: o dicionário de obras e serviços, e o elo entre necessidade e capacidade.
 *
 * 2000 linhas, 46 grupos, estático durante o desafio. Carregado uma vez de `data/csv/tos.csv`;
 * o endpoint `?p=tos` da API serve só para reconferir, nunca para popular em requisição.
 *
 * ## A busca não normaliza nada, e é de propósito
 *
 * `utf8mb4_unicode_ci` — a collation que o item 8.1.2 do edital exige — já compara ignorando
 * acento e caixa: `LIKE '%construcao%'` encontra "Construção Civil", e as 51 linhas do grupo
 * respondem igual para `construcao`, `Construção` e `CONSTRUCAO`. Uma coluna normalizada ou um
 * `Tos::normalizar` dentro do SQL seria reimplementar em PHP o que o banco já faz — e, pior,
 * criaria um segundo lugar onde "o que conta como igual" está definido.
 *
 * `Tos::normalizar` continua existindo para comparação **em memória**, onde não há collation.
 */
final class TosRepository extends Repositorio
{
    private const CAMPOS = 'tos_codigo, tos_grupo, tos_subgrupo, tos_obra_servico,
                            tos_complementar, tos_profundidade';

    /** Teto de resultados da busca. Lista maior que isto não se escolhe, se rola. */
    private const LIMITE = 40;

    /**
     * Busca textual sobre grupo, subgrupo, obra/serviço e complemento.
     *
     * Os textos da tabela começam com preposição ("de edificação", "de ponte"), então termo
     * isolado funciona melhor que frase — a interface diz isso ao usuário.
     *
     * @return list<array<string, mixed>>
     */
    public function buscar(string $termo, int $limite = self::LIMITE): array
    {
        $termo = trim($termo);

        if (mb_strlen($termo) < 3) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT ' . self::CAMPOS . '
               FROM crea_tos
              WHERE tos_status = :ativo
                AND (tos_grupo LIKE :g OR tos_subgrupo LIKE :s
                     OR tos_obra_servico LIKE :o OR tos_complementar LIKE :c)
              ORDER BY tos_nivel1, tos_nivel2, tos_nivel3, tos_nivel4
              LIMIT ' . max(1, min($limite, self::LIMITE))
        );

        // Quatro nomes para o mesmo valor: ATTR_EMULATE_PREPARES está desligado e placeholder
        // nomeado não pode repetir na mesma instrução.
        $curinga = '%' . $termo . '%';
        $stmt->execute([
            ':ativo' => STATUS_ATIVO,
            ':g' => $curinga, ':s' => $curinga, ':o' => $curinga, ':c' => $curinga,
        ]);

        return $stmt->fetchAll();
    }

    /**
     * Os 46 grupos, para a navegação em árvore de quem não sabe o que buscar.
     *
     * @return list<array{nivel1: int, grupo: string, itens: int}>
     */
    public function grupos(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT tos_nivel1 AS nivel1, MIN(tos_grupo) AS grupo, COUNT(*) AS itens
               FROM crea_tos
              WHERE tos_status = :ativo
              GROUP BY tos_nivel1
              ORDER BY tos_nivel1'
        );
        $stmt->execute([':ativo' => STATUS_ATIVO]);

        return $stmt->fetchAll();
    }

    /**
     * Os códigos de um grupo, para o segundo passo da navegação.
     *
     * @return list<array<string, mixed>>
     */
    public function porGrupo(int $nivel1, int $limite = 200): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::CAMPOS . '
               FROM crea_tos
              WHERE tos_status = :ativo AND tos_nivel1 = :grupo
              ORDER BY tos_nivel2, tos_nivel3, tos_nivel4
              LIMIT ' . max(1, min($limite, 500))
        );
        $stmt->execute([':ativo' => STATUS_ATIVO, ':grupo' => $nivel1]);

        return $stmt->fetchAll();
    }

    /**
     * Os códigos informados que existem de fato na tabela.
     *
     * É a validação de que um código vindo de formulário é real: o motor consulta a view por
     * prefixo, e um código inventado simplesmente não casaria com nada — a demanda ficaria com
     * um critério que nunca encontra ninguém, sem nada na tela explicando por quê.
     *
     * @param list<string> $codigos
     * @return array<string, array<string, mixed>> código => linha
     */
    public function porCodigos(array $codigos): array
    {
        $codigos = array_values(array_unique(array_filter($codigos)));

        if ($codigos === []) {
            return [];
        }

        $marcas = implode(',', array_fill(0, count($codigos), '?'));

        $stmt = $this->pdo->prepare(
            "SELECT " . self::CAMPOS . "
               FROM crea_tos
              WHERE tos_codigo IN ({$marcas}) AND tos_status = ?"
        );
        $stmt->execute([...$codigos, STATUS_ATIVO]);

        $porCodigo = [];

        foreach ($stmt->fetchAll() as $linha) {
            $porCodigo[(string) $linha['tos_codigo']] = $linha;
        }

        return $porCodigo;
    }

    /**
     * A descrição montada de um código, como a API a devolve em `tos_descricao`.
     *
     * Montada aqui e não guardada em coluna: é derivada de quatro campos que já existem, e a D01
     * já estabeleceu que derivado se calcula na leitura em vez de virar dado a manter.
     *
     * @param array<string, mixed> $tos
     */
    public static function descrever(array $tos): string
    {
        $partes = array_filter([
            $tos['tos_grupo'] ?? null,
            $tos['tos_subgrupo'] ?? null,
        ]);

        $cauda = array_filter([
            $tos['tos_obra_servico'] ?? null,
            $tos['tos_complementar'] ?? null,
        ]);

        return implode(' - ', $partes) . ($cauda === [] ? '' : ' ' . implode(' ', $cauda));
    }
}
