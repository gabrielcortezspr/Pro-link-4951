<?php

declare(strict_types=1);

namespace ProLink\Repository;

/**
 * Termos de Uso e Política de Privacidade versionados (sis_termos).
 *
 * Versionado porque o aceite precisa dizer *o que* foi aceito: `sis_consentimentos.con_ter_id`
 * aponta para a versão vigente no momento do cadastro. Texto novo entra como versão nova, nunca
 * por edição — senão o aceite de ontem passa a apontar para um texto que o usuário nunca viu.
 */
final class TermoRepository extends Repositorio
{
    /**
     * A versão vigente de cada tipo: a de maior data de vigência já alcançada.
     *
     * Os dois `:ativo_*` são o mesmo valor com nomes diferentes de propósito. Com
     * PDO::ATTR_EMULATE_PREPARES desligado — que é o certo, e é o que Database configura — o
     * driver não aceita reusar um parâmetro nomeado na mesma consulta.
     *
     * @return array<string, array<string, mixed>> indexado por ter_tipo
     */
    public function vigentes(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.ter_id, t.ter_tipo, t.ter_versao, t.ter_conteudo, t.ter_dt_vigencia
               FROM sis_termos t
               JOIN (SELECT ter_tipo, MAX(ter_dt_vigencia) AS vigencia
                       FROM sis_termos
                      WHERE ter_status = :ativo_interno AND ter_dt_vigencia <= CURDATE()
                      GROUP BY ter_tipo) atual
                 ON atual.ter_tipo = t.ter_tipo AND atual.vigencia = t.ter_dt_vigencia
              WHERE t.ter_status = :ativo_externo'
        );
        $stmt->execute([':ativo_interno' => STATUS_ATIVO, ':ativo_externo' => STATUS_ATIVO]);

        $porTipo = [];

        foreach ($stmt->fetchAll() as $termo) {
            $porTipo[$termo['ter_tipo']] = $termo;
        }

        return $porTipo;
    }
}
