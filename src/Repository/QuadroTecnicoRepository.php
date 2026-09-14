<?php

declare(strict_types=1);

namespace ProLink\Repository;

/**
 * `crea_quadro_tecnico`: quais profissionais respondem tecnicamente por uma empresa.
 *
 * É a tabela de que a herança de acervo depende. A view `crea_evidencias` liga a empresa às ARTs
 * dos profissionais cujo vínculo está **vigente** (`qut_dt_fim IS NULL`) — regra binária, e o
 * porquê de ela não ter recorte temporal está na D19: a API não datar ART em endpoint nenhum.
 *
 * ## Por que o vínculo vem do `quadro-tecnico` e não do CAO
 *
 * O CAO também traz uma lista de profissionais, e seria uma chamada a menos. Mas o CAO devolve só
 * `pro_nome`, `pro_rnp` e `qut_funcao`: **não traz `qut_dt_fim`**. Montar o quadro técnico a
 * partir dele gravaria todo vínculo como vigente, inclusive os encerrados — exatamente o campo de
 * que a regra de herança depende. As duas chamadas são complementares de propósito: o
 * `quadro-tecnico` diz quem vale, o CAO diz o que cada um traz.
 *
 * ## NULL não colide em índice UNIQUE, e por isso a gravação confere antes
 *
 * `uq_qut_emp_pro` cobre (empresa, RNP, data de início), e `qut_dt_inicio` aceita nulo. Em
 * MariaDB duas linhas com NULL na mesma coluna **não** violam UNIQUE — então um
 * `ON DUPLICATE KEY UPDATE` ingênuo duplicaria o vínculo a cada sincronização de uma empresa
 * cuja data de início a API não informa. A busca prévia usa `<=>` (igualdade null-safe) e decide
 * entre UPDATE e INSERT, o que vale para os dois casos sem depender do índice.
 */
final class QuadroTecnicoRepository extends Repositorio
{
    private const CAMPOS = 'qut_id, qut_emp_registro_crea, qut_pro_rnp, qut_pro_nome, qut_tipo,
                            qut_funcao, qut_dt_inicio, qut_dt_fim, qut_dt_consulta, qut_status';

    /**
     * Sincroniza o quadro técnico da empresa com o que a API devolveu.
     *
     * Nada é apagado (item 8.6j): vínculo que sumiu da resposta vira `qut_status = 'X'`. Perder o
     * responsável técnico é fato relevante num índice de evidência, não ruído — e a linha antiga
     * continua contando o que a API já disse um dia.
     *
     * @param list<array{pro_rnp: string, pro_nome: ?string, tipo: ?string, funcao: ?string,
     *                   dt_inicio: ?string, dt_fim: ?string}> $vinculos
     * @return array{ativos: int, vigentes: int, encerrados: int}
     */
    public function sincronizar(string $registroCrea, array $vinculos, string $consultada): array
    {
        $desativar = $this->pdo->prepare(
            'UPDATE crea_quadro_tecnico SET qut_status = :excluido
              WHERE qut_emp_registro_crea = :empresa AND qut_status = :ativo'
        );
        $desativar->execute([
            ':excluido' => STATUS_EXCLUIDO,
            ':empresa'  => $registroCrea,
            ':ativo'    => STATUS_ATIVO,
        ]);
        $desativados = $desativar->rowCount();

        $procurar = $this->pdo->prepare(
            'SELECT qut_id FROM crea_quadro_tecnico
              WHERE qut_emp_registro_crea = :empresa
                AND qut_pro_rnp = :rnp
                AND qut_dt_inicio <=> :inicio
              LIMIT 1'
        );

        $atualizar = $this->pdo->prepare(
            'UPDATE crea_quadro_tecnico
                SET qut_pro_nome    = :nome,
                    qut_tipo        = :tipo,
                    qut_funcao      = :funcao,
                    qut_dt_fim      = :fim,
                    qut_dt_consulta = :consulta,
                    qut_status      = :ativo
              WHERE qut_id = :id'
        );

        $inserir = $this->pdo->prepare(
            'INSERT INTO crea_quadro_tecnico
                (qut_emp_registro_crea, qut_pro_rnp, qut_pro_nome, qut_tipo, qut_funcao,
                 qut_dt_inicio, qut_dt_fim, qut_dt_consulta, qut_status)
             VALUES
                (:empresa, :rnp, :nome, :tipo, :funcao, :inicio, :fim, :consulta, :ativo)'
        );

        $vigentes = 0;

        foreach ($vinculos as $vinculo) {
            $procurar->execute([
                ':empresa' => $registroCrea,
                ':rnp'     => $vinculo['pro_rnp'],
                ':inicio'  => $vinculo['dt_inicio'],
            ]);
            $id = $procurar->fetchColumn();

            if ($id !== false) {
                $atualizar->execute([
                    ':nome'     => $vinculo['pro_nome'],
                    ':tipo'     => $vinculo['tipo'],
                    ':funcao'   => $vinculo['funcao'],
                    ':fim'      => $vinculo['dt_fim'],
                    ':consulta' => $consultada,
                    ':ativo'    => STATUS_ATIVO,
                    ':id'       => (int) $id,
                ]);
            } else {
                $inserir->execute([
                    ':empresa'  => $registroCrea,
                    ':rnp'      => $vinculo['pro_rnp'],
                    ':nome'     => $vinculo['pro_nome'],
                    ':tipo'     => $vinculo['tipo'],
                    ':funcao'   => $vinculo['funcao'],
                    ':inicio'   => $vinculo['dt_inicio'],
                    ':fim'      => $vinculo['dt_fim'],
                    ':consulta' => $consultada,
                    ':ativo'    => STATUS_ATIVO,
                ]);
            }

            $vigentes += $vinculo['dt_fim'] === null ? 1 : 0;
        }

        return [
            'ativos'     => count($vinculos),
            'vigentes'   => $vigentes,
            'encerrados' => max(0, $desativados - count($vinculos)),
        ];
    }

    /**
     * O quadro técnico ativo da empresa: vigentes antes de encerrados, responsáveis técnicos
     * antes do resto.
     *
     * A ordem não é estética. O vínculo vigente é o que transfere acervo (D19), e o responsável
     * técnico é quem responde pela empresa perante o conselho — quem lê a tela procura esses
     * dois primeiro.
     *
     * @return list<array<string, mixed>>
     */
    public function porEmpresa(string $registroCrea): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::CAMPOS . '
               FROM crea_quadro_tecnico
              WHERE qut_emp_registro_crea = :empresa AND qut_status = :ativo
              ORDER BY (qut_dt_fim IS NOT NULL), (qut_tipo <=> \'R\') DESC, qut_pro_nome, qut_pro_rnp'
        );
        $stmt->execute([':empresa' => $registroCrea, ':ativo' => STATUS_ATIVO]);

        return $stmt->fetchAll();
    }

    /**
     * Só os RNPs cujo vínculo está vigente, segundo esta tabela.
     *
     * Leitura do quadro técnico, não a regra de herança: quem decide o acervo da empresa é a view
     * `crea_evidencias` (D19), e é dela que `AcervoRepository::porEmpresa` parte. Este método
     * serve a quem precisa da lista de vínculos em si — a verificação da etapa, por exemplo.
     *
     * @return list<string>
     */
    public function rnpsVigentes(string $registroCrea): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT qut_pro_rnp FROM crea_quadro_tecnico
              WHERE qut_emp_registro_crea = :empresa
                AND qut_status = :ativo
                AND qut_dt_fim IS NULL
              ORDER BY qut_pro_rnp'
        );
        $stmt->execute([':empresa' => $registroCrea, ':ativo' => STATUS_ATIVO]);

        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }
}
