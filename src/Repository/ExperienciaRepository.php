<?php

declare(strict_types=1);

namespace ProLink\Repository;

/**
 * `pro_experiencias`: o que o profissional afirma sobre a própria trajetória (RF03).
 *
 * A tabela irmã de `crea_arts`, e a oposta dela em tudo o que importa. Lá o dado vem da API, tem
 * `art_dt_consulta` e um selo que se reconfere a cada exibição; aqui o dado vem de um formulário e
 * não tem selo nenhum — porque não há o que selar. Ninguém conferiu, e a plataforma não finge que
 * conferiu.
 *
 * Por isso as duas nunca se misturam na tela: `.selo-art` e `.dado-declarado` têm cores
 * diferentes de propósito, e é a tradução em CSS do compromisso central da proposta.
 *
 * ## A ligação com a ART é opcional, e é o ponto da tabela
 *
 * `exp_art_id` nulo é experiência sem ART — o edital pede isso com essas palavras (Anexo I,
 * item 3: "com ou sem ART/CAT"), e é o caso de quem trabalhou sem registro ou está começando.
 * Preenchido, amarra o relato a uma evidência: a tela mostra os dois lado a lado, cada um com a
 * sua cara, e o leitor vê onde o CREA confirma e onde só a pessoa afirma.
 *
 * Quem garante que a ART apontada é **do próprio profissional** é o serviço, não esta classe: a
 * chave estrangeira só garante que a ART existe, e existir não é pertencer.
 */
final class ExperienciaRepository extends Repositorio
{
    private const CAMPOS = 'exp_id, exp_prf_id, exp_titulo, exp_descricao, exp_art_id,
                            exp_dt_inicio, exp_dt_fim, exp_dt_registro, exp_status';

    /**
     * As experiências ativas do profissional, da mais recente para a mais antiga.
     *
     * `exp_dt_inicio` nula vai para o fim: experiência sem data não é a mais recente, é a que não
     * disse quando foi. Ordenar por ela como se fosse "hoje" colocaria o relato mais vago no topo.
     *
     * @return list<array<string, mixed>>
     */
    public function porProfissional(int $profissionalId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::CAMPOS . '
               FROM pro_experiencias
              WHERE exp_prf_id = :prf AND exp_status = :ativo
              ORDER BY (exp_dt_inicio IS NULL), exp_dt_inicio DESC, exp_id DESC'
        );
        $stmt->execute([':prf' => $profissionalId, ':ativo' => STATUS_ATIVO]);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function porId(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::CAMPOS . ' FROM pro_experiencias WHERE exp_id = :id'
        );
        $stmt->execute([':id' => $id]);

        return $stmt->fetch() ?: null;
    }

    /**
     * @param array{profissional_id: int, titulo: string, descricao: ?string, art_id: ?int,
     *              dt_inicio: ?string, dt_fim: ?string} $dados
     */
    public function criar(array $dados): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO pro_experiencias
                (exp_prf_id, exp_titulo, exp_descricao, exp_art_id, exp_dt_inicio, exp_dt_fim, exp_status)
             VALUES
                (:prf, :titulo, :descricao, :art, :inicio, :fim, :ativo)'
        );

        $stmt->execute([
            ':prf'       => $dados['profissional_id'],
            ':titulo'    => $dados['titulo'],
            ':descricao' => $dados['descricao'],
            ':art'       => $dados['art_id'],
            ':inicio'    => $dados['dt_inicio'],
            ':fim'       => $dados['dt_fim'],
            ':ativo'     => STATUS_ATIVO,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array{titulo: string, descricao: ?string, art_id: ?int,
     *              dt_inicio: ?string, dt_fim: ?string} $dados
     */
    public function atualizar(int $id, array $dados): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE pro_experiencias
                SET exp_titulo    = :titulo,
                    exp_descricao = :descricao,
                    exp_art_id    = :art,
                    exp_dt_inicio = :inicio,
                    exp_dt_fim    = :fim
              WHERE exp_id = :id'
        );

        $stmt->execute([
            ':titulo'    => $dados['titulo'],
            ':descricao' => $dados['descricao'],
            ':art'       => $dados['art_id'],
            ':inicio'    => $dados['dt_inicio'],
            ':fim'       => $dados['dt_fim'],
            ':id'        => $id,
        ]);
    }

    /**
     * Exclusão lógica (item 8.6j). A linha permanece, com `exp_status = 'X'`.
     *
     * A escolha de visibilidade sobre ela também permanece, em `pro_visibilidade`, e é de
     * propósito: restaurar da lixeira (E6) tem de devolver a experiência **como ela estava**,
     * inclusive fechada. Apagar a escolha aqui a devolveria com o padrão, e o padrão é a ausência
     * — que é privado, então não haveria vazamento, mas haveria perda silenciosa de uma decisão
     * do titular.
     */
    public function excluir(int $id): void
    {
        $this->pdo->prepare(
            'UPDATE pro_experiencias SET exp_status = :excluido WHERE exp_id = :id'
        )->execute([':excluido' => STATUS_EXCLUIDO, ':id' => $id]);
    }
}
