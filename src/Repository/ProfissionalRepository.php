<?php

declare(strict_types=1);

namespace ProLink\Repository;

/**
 * `pro_profissionais` e `pro_prof_modalidades`: quem a pessoa é no CREA, segundo o CREA.
 *
 * A separação entre esta tabela e `sis_usuarios` não é organizacional, é de origem do dado.
 * `usu_nome` é o que a pessoa digitou; `prf_nome_api` é o que a API devolveu e não se edita.
 * Quando os dois divergirem, quem vale para identidade profissional é o segundo.
 *
 * Ausência de linha aqui, para um usuário de perfil PROFISSIONAL, é um estado com significado:
 * a conta existe e o registro no CREA ainda não foi validado — porque a API estava fora do ar no
 * cadastro. Não é erro, é pendência, e `PerfilCreaService::vincularProfissional` a resolve.
 */
final class ProfissionalRepository extends Repositorio
{
    private const CAMPOS = 'prf_id, prf_usu_id, prf_rnp, prf_registro_crea, prf_nome_api,
                            prf_status_api, prf_resumo, prf_tipo_contrato, prf_disponibilidade,
                            prf_em_construcao, prf_dt_sincronizacao, prf_status';

    /** @return array<string, mixed>|null */
    public function porUsuario(int $usuarioId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::CAMPOS . ' FROM pro_profissionais WHERE prf_usu_id = :usuario'
        );
        $stmt->execute([':usuario' => $usuarioId]);

        return $stmt->fetch() ?: null;
    }

    /** @return array<string, mixed>|null */
    public function porRnp(string $rnp): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::CAMPOS . ' FROM pro_profissionais WHERE prf_rnp = :rnp'
        );
        $stmt->execute([':rnp' => $rnp]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Cria ou atualiza o perfil CREA do usuário.
     *
     * `ON DUPLICATE KEY UPDATE` cobre os dois modos de reentrada: revalidar o registro depois de
     * uma indisponibilidade (mesma `prf_usu_id`) e a sincronização periódica de status (mesma
     * `prf_rnp`). Os campos autodeclarados — resumo, tipo de contrato, disponibilidade — não
     * aparecem aqui de propósito: sincronizar com o CREA não pode apagar o que a pessoa escreveu.
     *
     * `prf_dt_sincronizacao` também não aparece: gravar o perfil é metade do trabalho, e o
     * carimbo pertence a `marcarSincronizado()`, chamado só quando o acervo também entrou.
     *
     * @param array{usuario_id: int, rnp: string, registro_crea: ?string, nome_api: ?string,
     *              status_api: ?string} $dados
     */
    public function salvar(array $dados): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO pro_profissionais
                (prf_usu_id, prf_rnp, prf_registro_crea, prf_nome_api, prf_status_api, prf_status)
             VALUES
                (:usuario, :rnp, :registro, :nome, :situacao, :ativo)
             ON DUPLICATE KEY UPDATE
                prf_registro_crea = VALUES(prf_registro_crea),
                prf_nome_api      = VALUES(prf_nome_api),
                prf_status_api    = VALUES(prf_status_api),
                prf_status        = VALUES(prf_status)'
        );

        $stmt->execute([
            ':usuario'  => $dados['usuario_id'],
            ':rnp'      => $dados['rnp'],
            ':registro' => $dados['registro_crea'],
            ':nome'     => $dados['nome_api'],
            ':situacao' => $dados['status_api'],
            ':ativo'    => STATUS_ATIVO,
        ]);

        $id = (int) $this->pdo->lastInsertId();

        if ($id > 0) {
            return $id;
        }

        $existente = $this->porUsuario($dados['usuario_id']);

        return (int) ($existente['prf_id'] ?? 0);
    }

    /**
     * `prf_status_api` de vários usuários, para o portão de privacidade do motor.
     *
     * Devolve só quem tem linha. Ausência aqui é a pendência da D20 — a conta afirma um registro
     * no CREA que ainda não confirmamos —, e quem chama distingue isso de "tem linha, mas o
     * registro não está regular". São dois estados diferentes com o mesmo efeito, e misturá-los
     * apagaria a diferença na hora de explicar ao titular por que o perfil dele não aparece.
     *
     * @param  list<int> $usuarioIds
     * @return array<int, ?string> prf_usu_id => prf_status_api
     */
    public function statusApiEmLote(array $usuarioIds): array
    {
        $linhas = $this->buscarPorIds(
            static fn (array $m): string =>
                'SELECT prf_usu_id, prf_status_api
                   FROM pro_profissionais
                  WHERE prf_usu_id IN (' . implode(', ', $m) . ')',
            $usuarioIds,
        );

        $situacao = [];

        foreach ($linhas as $linha) {
            $situacao[(int) $linha['prf_usu_id']] = $linha['prf_status_api'];
        }

        return $situacao;
    }

    /**
     * Sincroniza as modalidades do profissional com o que a API devolveu.
     *
     * Nada é apagado (item 8.6j): modalidade que sumiu da resposta vira `pmo_status = 'X'`.
     * Perder uma modalidade no CREA é fato relevante para um índice de evidência, não ruído.
     *
     * @param list<int> $modalidades ids de crea_modalidades
     * @return int quantas ficaram ativas
     */
    public function sincronizarModalidades(int $profissionalId, array $modalidades): int
    {
        $this->pdo->prepare(
            'UPDATE pro_prof_modalidades SET pmo_status = :excluido
              WHERE pmo_prf_id = :prf AND pmo_status = :ativo'
        )->execute([':excluido' => STATUS_EXCLUIDO, ':prf' => $profissionalId, ':ativo' => STATUS_ATIVO]);

        $inserir = $this->pdo->prepare(
            'INSERT INTO pro_prof_modalidades (pmo_prf_id, pmo_mod_id, pmo_status)
             VALUES (:prf, :mod, :ativo)
             ON DUPLICATE KEY UPDATE pmo_status = VALUES(pmo_status)'
        );

        foreach ($modalidades as $modalidade) {
            $inserir->execute([':prf' => $profissionalId, ':mod' => $modalidade, ':ativo' => STATUS_ATIVO]);
        }

        return count($modalidades);
    }

    /** @return list<array{mod_id: int, mod_codigo: ?string, mod_nome: string}> */
    public function modalidades(int $profissionalId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.mod_id, m.mod_codigo, m.mod_nome
               FROM pro_prof_modalidades p
               JOIN crea_modalidades m ON m.mod_id = p.pmo_mod_id
              WHERE p.pmo_prf_id = :prf AND p.pmo_status = :ativo
              ORDER BY m.mod_nome'
        );
        $stmt->execute([':prf' => $profissionalId, ':ativo' => STATUS_ATIVO]);

        return $stmt->fetchAll();
    }

    /**
     * Os ids que existem em `crea_modalidades`, para a API não derrubar o cadastro com uma
     * modalidade nova. A tabela é fechada em 25 (`docs/massa-de-dados.md`) e vem da carga
     * inicial; se o CREA criar a 26ª, o certo é ignorá-la e avisar, não estourar a FK.
     *
     * @return list<int>
     */
    public function modalidadesConhecidas(): array
    {
        $stmt = $this->pdo->prepare('SELECT mod_id FROM crea_modalidades WHERE mod_status = :ativo');
        $stmt->execute([':ativo' => STATUS_ATIVO]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Carimba a sincronização como completa. Chamado só quando perfil **e** acervo entraram.
     *
     * É o que distingue "importamos e ele não tem ART" de "não conseguimos importar" — dois
     * estados que, sem este carimbo, ficam idênticos no banco: zero linhas em `crea_arts`.
     * Falha parcial deixa o valor anterior intacto, e não o zera: o que interessa é quando foi a
     * última sincronização **bem-sucedida**, que é o que `api.sincronizacao.horas` compara.
     */
    /**
     * Quem passou do intervalo de sincronização e precisa ser reconsultado na API.
     *
     * Traz o documento cifrado junto porque a consulta da API é por CPF, e é no próprio laço da
     * sincronização que ele é decifrado, um por vez — nunca uma lista de CPFs em claro em memória.
     *
     * `prf_dt_sincronizacao` nulo entra na lista de propósito: nulo é o estado de "importamos o
     * perfil e o acervo não veio", que é justamente quem precisa de nova tentativa.
     *
     * Traz também o consentimento de consulta à API, para quem chama poder pular sem gastar
     * chamada e ainda assim contar quantos pulou. Filtrar isso no `WHERE` esconderia o número.
     *
     * O `LIMIT` é obrigatório e não tem valor padrão aqui: o item 10.4 veda coleta automatizada,
     * e uma sincronização sem teto percorrendo o cadastro inteiro é indistinguível de varredura
     * do lado de quem registra as chamadas.
     *
     * @return list<array<string, mixed>>
     */
    public function vencidos(int $horas, int $limite): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.prf_id, p.prf_usu_id, p.prf_rnp, p.prf_status_api, p.prf_dt_sincronizacao,
                    u.usu_nome, u.usu_documento_cif,
                    COALESCE(c.con_concedido, 0) AS consentiu
               FROM pro_profissionais p
               JOIN sis_usuarios u ON u.usu_id = p.prf_usu_id
               LEFT JOIN sis_consentimentos c
                      ON c.con_usu_id = p.prf_usu_id
                     AND c.con_finalidade = :finalidade
                     AND c.con_status = :ativo
              WHERE p.prf_status = :ativo2
                AND u.usu_status = :ativo3
                AND (p.prf_dt_sincronizacao IS NULL
                     OR p.prf_dt_sincronizacao < NOW() - INTERVAL :horas HOUR)
              ORDER BY p.prf_dt_sincronizacao IS NULL DESC, p.prf_dt_sincronizacao ASC
              LIMIT :limite'
        );

        $stmt->bindValue(':finalidade', FINALIDADE_CONSULTA_API);
        $stmt->bindValue(':ativo', STATUS_ATIVO);
        $stmt->bindValue(':ativo2', STATUS_ATIVO);
        $stmt->bindValue(':ativo3', STATUS_ATIVO);
        $stmt->bindValue(':horas', $horas, \PDO::PARAM_INT);
        $stmt->bindValue(':limite', $limite, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Grava a situação que a API devolveu e carimba a sincronização.
     *
     * Separado de `salvar()` porque aquele é o caminho do vínculo inicial, com `ON DUPLICATE KEY
     * UPDATE` sobre cinco colunas; aqui só duas mudam, e passar pelo outro caminho reescreveria
     * nome e registro do CREA com o que estivesse em memória.
     */
    public function atualizarStatusApi(int $profissionalId, ?string $statusApi): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE pro_profissionais
                SET prf_status_api = :status, prf_dt_sincronizacao = NOW()
              WHERE prf_id = :id'
        );

        $stmt->bindValue(':status', $statusApi);
        $stmt->bindValue(':id', $profissionalId, \PDO::PARAM_INT);
        $stmt->execute();
    }

    public function marcarSincronizado(int $profissionalId): void
    {
        $this->pdo->prepare(
            'UPDATE pro_profissionais SET prf_dt_sincronizacao = NOW() WHERE prf_id = :prf'
        )->execute([':prf' => $profissionalId]);
    }

    public function marcarEmConstrucao(int $profissionalId, bool $emConstrucao): void
    {
        $this->pdo->prepare(
            'UPDATE pro_profissionais SET prf_em_construcao = :flag WHERE prf_id = :prf'
        )->execute([':flag' => $emConstrucao ? 1 : 0, ':prf' => $profissionalId]);
    }

    /**
     * As três colunas que o titular escreve: resumo, tipo de contrato e abrangência geográfica.
     *
     * Existe em separado de `salvar()` pelo motivo que o cabeçalho daquele método já dá — sincronizar
     * com o CREA não pode apagar o que a pessoa escreveu. A recíproca vale aqui: este método não
     * toca em nenhuma coluna de origem da API. Cada lado escreve o que é seu, e nenhum dos dois
     * precisa saber do outro.
     *
     * @param array{resumo: ?string, tipo_contrato: ?string, disponibilidade: ?string} $dados
     */
    public function salvarDeclarado(int $profissionalId, array $dados): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE pro_profissionais
                SET prf_resumo          = :resumo,
                    prf_tipo_contrato   = :contrato,
                    prf_disponibilidade = :abrangencia
              WHERE prf_id = :prf'
        );

        $stmt->execute([
            ':resumo'      => $dados['resumo'],
            ':contrato'    => $dados['tipo_contrato'],
            ':abrangencia' => $dados['disponibilidade'],
            ':prf'         => $profissionalId,
        ]);
    }
}
