<?php

declare(strict_types=1);

namespace ProLink\Repository;

/**
 * A busca ativa de profissionais (RF04; Anexo I item 3, capacidade do perfil Público).
 *
 * ## O que ela é, e o que ela deliberadamente não é
 *
 * Isto **não** é o motor. O motor parte de uma demanda, cruza códigos da Tabela de Obras e
 * Serviços com o acervo e devolve um pool pontuado e sorteado. Aqui quem parte é a pessoa, com
 * uma palavra, e o resultado é um filtro: quem casa aparece, quem não casa não. Não há score,
 * não há limiar e não há semente — e é por isso que a ordem pode ser alfabética sem virar
 * ranking (item 10.1). Não existe "mais relevante" nesta tela.
 *
 * ## A busca não normaliza acento nem caixa, pelo mesmo motivo da TosRepository
 *
 * `utf8mb4_unicode_ci`, que o item 8.1.2 exige, já compara ignorando os dois: `LIKE '%eletrica%'`
 * encontra "Elétrica". Normalizar em PHP criaria um segundo lugar definindo o que conta como
 * igual, e os dois acabariam discordando.
 *
 * ## O portão de privacidade não mora aqui
 *
 * Esta classe devolve candidatos do cadastro; quem decide se cada um pode ser exibido é
 * `VisibilidadeService::perfisAbertos()`, aplicado pelo serviço em lote. Repetir a regra em SQL
 * seria a terceira cópia dela — e a que ninguém lembraria de atualizar.
 */
final class BuscaRepository extends Repositorio
{
    /**
     * Teto de resultados.
     *
     * Existe por dois motivos, e o segundo importa mais. O primeiro é a tela: lista maior que
     * isto não se lê. O segundo é o item 10.4, que proíbe coleta automatizada — uma busca sem
     * teto, aberta a anônimo, é um endpoint de extração da massa inteira com uma requisição. O
     * serviço avisa quando o corte aconteceu, em vez de truncar em silêncio: resultado truncado
     * sem aviso é o jeito de alguém concluir que a plataforma não tem ninguém naquela busca.
     */
    public const LIMITE = 60;

    /**
     * Profissionais que casam com o termo, em ordem alfabética.
     *
     * O termo é procurado em quatro lugares, que são as três dimensões que o edital nomeia
     * ("especialidade, experiência, nome") mais o resumo declarado:
     *
     *   · nome — o da API, com o da conta como reserva para quem ainda não validou o registro;
     *   · especialidade — a modalidade do registro no CREA **e** o grupo da Tabela de Obras e
     *     Serviços onde o acervo tem evidência. São duas leituras de "especialidade" e as duas
     *     valem: a modalidade é o que o conselho registra, o grupo é o que a pessoa efetivamente
     *     tem ART. Quem procura "elétrica" quer as duas;
     *   · experiência — título e descrição do que a pessoa declarou;
     *   · resumo — a apresentação livre do perfil.
     *
     * `DISTINCT` porque um profissional com quatro ARTs no mesmo grupo casaria quatro vezes.
     *
     * @param  string $termo       vazio devolve todo mundo, que é a vitrine inicial da tela
     * @param  bool   $emConstrucao inclui quem tem acervo abaixo do limiar de início de carreira
     * @return list<array<string, mixed>>
     */
    public function profissionais(string $termo, bool $emConstrucao = false, int $limite = self::LIMITE): array
    {
        $termo   = trim($termo);
        $filtra  = $termo !== '';
        $limite  = max(1, min($limite, self::LIMITE));

        $onde = ['p.prf_status = :ativo', 'u.usu_status = :ativo_u'];

        // O filtro de início de carreira é o único que **esconde** alguém por atributo do
        // perfil, e por isso nasce desligado na tela. Ver a decisão registrada: o rótulo informa
        // no feed e some da busca, e as duas coisas são a mesma escolha vista de dois lados.
        if (!$emConstrucao) {
            $onde[] = 'p.prf_em_construcao = 0';
        }

        $params = [':ativo' => STATUS_ATIVO, ':ativo_u' => STATUS_ATIVO];

        if ($filtra) {
            // Cinco nomes para o mesmo valor: ATTR_EMULATE_PREPARES está desligado e placeholder
            // nomeado não pode repetir na mesma instrução.
            $onde[] = '(p.prf_nome_api LIKE :t1 OR u.usu_nome LIKE :t2 OR p.prf_resumo LIKE :t3
                        OR EXISTS (SELECT 1 FROM pro_prof_modalidades pm
                                     JOIN crea_modalidades m ON m.mod_id = pm.pmo_mod_id
                                    WHERE pm.pmo_prf_id = p.prf_id AND pm.pmo_status = "A"
                                      AND m.mod_nome LIKE :t4)
                        OR EXISTS (SELECT 1 FROM pro_experiencias e
                                    WHERE e.exp_prf_id = p.prf_id AND e.exp_status = "A"
                                      AND (e.exp_titulo LIKE :t5 OR e.exp_descricao LIKE :t6))
                        OR EXISTS (SELECT 1 FROM crea_evidencias ev
                                     JOIN crea_tos t ON t.tos_codigo = ev.evi_tos_codigo
                                    WHERE ev.evi_candidato_tipo = "P" AND ev.evi_candidato_id = p.prf_id
                                      AND (t.tos_grupo LIKE :t7 OR t.tos_subgrupo LIKE :t8)))';

            $curinga = '%' . $termo . '%';

            foreach (range(1, 8) as $i) {
                $params[":t{$i}"] = $curinga;
            }
        }

        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT p.prf_id, p.prf_usu_id, p.prf_rnp, p.prf_registro_crea,
                    p.prf_status_api, p.prf_resumo, p.prf_tipo_contrato, p.prf_disponibilidade,
                    p.prf_em_construcao, u.usu_nome,
                    COALESCE(p.prf_nome_api, u.usu_nome) AS nome
               FROM pro_profissionais p
               JOIN sis_usuarios u ON u.usu_id = p.prf_usu_id
              WHERE ' . implode(' AND ', $onde) . '
              ORDER BY nome, p.prf_id
              LIMIT ' . ($limite + 1)
        );

        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * As modalidades do registro no CREA de cada profissional, em uma consulta para o lote.
     *
     * Existe porque a busca **casa** em `mod_nome` e, sem isto, não **mostrava** o que casou:
     * quem procurava "elétrica" recebia quatro pessoas cujos grupos da Tabela de Obras e Serviços
     * dizem "Eletrotécnica", que não casa por substring, e a modalidade "Engenharia Elétrica",
     * que casou, não aparecia em lugar nenhum do cartão. Resultado correto e inexplicável ao
     * mesmo tempo. Modalidade é, além disso, a leitura mais literal de "especialidade" no Anexo I.
     *
     * @param  list<int> $profissionalIds
     * @return array<int, list<string>>
     */
    public function modalidadesEmLote(array $profissionalIds): array
    {
        $linhas = $this->buscarPorIds(
            static fn (array $m): string =>
                'SELECT pm.pmo_prf_id AS id, mo.mod_nome
                   FROM pro_prof_modalidades pm
                   JOIN crea_modalidades mo ON mo.mod_id = pm.pmo_mod_id AND mo.mod_status = :ativo_m
                  WHERE pm.pmo_prf_id IN (' . implode(', ', $m) . ')
                    AND pm.pmo_status = :ativo_p
                  ORDER BY mo.mod_nome',
            $profissionalIds,
            [':ativo_m' => STATUS_ATIVO, ':ativo_p' => STATUS_ATIVO],
        );

        $saida = [];

        foreach ($linhas as $linha) {
            $saida[(int) $linha['id']][] = (string) $linha['mod_nome'];
        }

        return $saida;
    }

    /**
     * Quantas ARTs e CATs cada profissional tem no acervo, em uma consulta para o lote todo.
     *
     * A tela diz "N ARTs" por resultado, e perguntar por profissional seria o N+1 que o feed
     * acabou de evitar do outro lado.
     *
     * @param  list<int> $profissionalIds
     * @return array<int, array{arts: int, cats: int, grupos: list<string>, subgrupos: list<string>}>
     */
    public function acervoEmLote(array $profissionalIds): array
    {
        $linhas = $this->buscarPorIds(
            static fn (array $m): string =>
                'SELECT evi_candidato_id AS id,
                        COUNT(DISTINCT evi_art_numero) AS arts,
                        COUNT(DISTINCT evi_cat_numero) AS cats,
                        GROUP_CONCAT(DISTINCT t.tos_grupo ORDER BY t.tos_grupo SEPARATOR "|") AS grupos,
                        GROUP_CONCAT(DISTINCT t.tos_subgrupo ORDER BY t.tos_subgrupo SEPARATOR "|") AS subgrupos
                   FROM crea_evidencias ev
                   JOIN crea_tos t ON t.tos_codigo = ev.evi_tos_codigo
                  WHERE ev.evi_candidato_tipo = "P"
                    AND ev.evi_candidato_id IN (' . implode(', ', $m) . ')
                  GROUP BY evi_candidato_id',
            $profissionalIds,
        );

        $saida = [];

        foreach ($linhas as $linha) {
            $grupos = (string) ($linha['grupos'] ?? '');

            $sub = (string) ($linha['subgrupos'] ?? '');

            $saida[(int) $linha['id']] = [
                'arts'      => (int) $linha['arts'],
                'cats'      => (int) $linha['cats'],
                'grupos'    => $grupos === '' ? [] : explode('|', $grupos),
                // O subgrupo é o nível onde o termo costuma casar: a busca procura nele, mas o
                // cartão só mostrava o grupo. Quem digitava "elétrica" recebia gente cujo grupo
                // diz "Eletrotécnica" — resultado certo, e sem nada na tela explicando por quê.
                'subgrupos' => $sub === '' ? [] : explode('|', $sub),
            ];
        }

        return $saida;
    }
}
