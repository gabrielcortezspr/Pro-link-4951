<?php

declare(strict_types=1);

namespace ProLink\Repository;

/**
 * `pro_empresas`: quem a empresa é no CREA, segundo o CREA.
 *
 * O par de `ProfissionalRepository`, com a mesma divisão de origem do dado: `usu_nome` é o que
 * quem abriu a conta digitou; `emp_razao_social` é o que a API devolveu e não se edita.
 *
 * Ausência de linha aqui, para um usuário de perfil EMPRESA, é o mesmo estado com significado
 * que a D20 estabeleceu do lado do profissional: a conta existe e o registro no CREA ainda não
 * foi confirmado, porque a API estava fora do ar no cadastro. Não é erro, é pendência, e
 * `EmpresaCreaService::vincularEmpresa` a resolve.
 *
 * ## Uma armadilha de nome
 *
 * A API chama de `emp_dt_registro` a data em que a empresa foi registrada no conselho. Aqui essa
 * data é `emp_dt_registro_crea`, porque `_dt_registro` é nome reservado pela nomenclatura do
 * edital para o instante em que **a nossa** linha nasceu. São duas datas diferentes e nenhuma
 * substitui a outra.
 */
final class EmpresaRepository extends Repositorio
{
    private const CAMPOS = 'emp_id, emp_usu_id, emp_registro_crea, emp_razao_social,
                            emp_nome_fantasia, emp_dt_registro_crea, emp_resumo,
                            emp_dt_sincronizacao, emp_status';

    /** @return array<string, mixed>|null */
    public function porUsuario(int $usuarioId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::CAMPOS . ' FROM pro_empresas WHERE emp_usu_id = :usuario'
        );
        $stmt->execute([':usuario' => $usuarioId]);

        return $stmt->fetch() ?: null;
    }

    /** @return array<string, mixed>|null */
    public function porRegistroCrea(string $registroCrea): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::CAMPOS . ' FROM pro_empresas WHERE emp_registro_crea = :registro'
        );
        $stmt->execute([':registro' => $registroCrea]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Cria ou atualiza o perfil CREA da empresa.
     *
     * `ON DUPLICATE KEY UPDATE` cobre os dois modos de reentrada, como no lado do profissional:
     * revalidar o registro depois de uma indisponibilidade (mesma `emp_usu_id`, por `uq_emp_usu`)
     * e a sincronização periódica (mesmo `emp_registro_crea`). `emp_resumo` não aparece aqui de
     * propósito — sincronizar com o CREA não pode apagar o que a empresa escreveu sobre si.
     *
     * `emp_dt_sincronizacao` também não aparece: o carimbo pertence a `marcarSincronizado()`,
     * chamado só quando o quadro técnico e o acervo do CAO também entraram (D21).
     *
     * @param array{usuario_id: int, registro_crea: string, razao_social: ?string,
     *              nome_fantasia: ?string, dt_registro_crea: ?string} $dados
     */
    public function salvar(array $dados): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO pro_empresas
                (emp_usu_id, emp_registro_crea, emp_razao_social, emp_nome_fantasia,
                 emp_dt_registro_crea, emp_status)
             VALUES
                (:usuario, :registro, :razao, :fantasia, :dt_crea, :ativo)
             ON DUPLICATE KEY UPDATE
                emp_razao_social     = VALUES(emp_razao_social),
                emp_nome_fantasia    = VALUES(emp_nome_fantasia),
                emp_dt_registro_crea = VALUES(emp_dt_registro_crea),
                emp_status           = VALUES(emp_status)'
        );

        $stmt->execute([
            ':usuario'  => $dados['usuario_id'],
            ':registro' => $dados['registro_crea'],
            ':razao'    => $dados['razao_social'],
            ':fantasia' => $dados['nome_fantasia'],
            ':dt_crea'  => $dados['dt_registro_crea'],
            ':ativo'    => STATUS_ATIVO,
        ]);

        $id = (int) $this->pdo->lastInsertId();

        if ($id > 0) {
            return $id;
        }

        // lastInsertId devolve 0 quando o ON DUPLICATE só atualizou: a linha já existe.
        $existente = $this->porUsuario($dados['usuario_id']);

        return (int) ($existente['emp_id'] ?? 0);
    }

    /**
     * Carimba a sincronização como completa: perfil, quadro técnico **e** acervo do CAO.
     *
     * Mesma regra da D21, e pelo mesmo motivo: quadro técnico vazio por falha da API e quadro
     * técnico genuinamente vazio são indistinguíveis no banco sem este carimbo — zero linhas em
     * `crea_quadro_tecnico` nos dois casos. Falha parcial deixa o valor anterior intacto; o que
     * interessa é quando foi a última sincronização **bem-sucedida**.
     */
    public function marcarSincronizado(int $empresaId): void
    {
        $this->pdo->prepare(
            'UPDATE pro_empresas SET emp_dt_sincronizacao = NOW() WHERE emp_id = :emp'
        )->execute([':emp' => $empresaId]);
    }
}
