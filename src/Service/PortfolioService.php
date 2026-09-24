<?php

declare(strict_types=1);

namespace ProLink\Service;

use PDO;
use ProLink\Repository\AcervoRepository;
use ProLink\Repository\CatRepository;
use ProLink\Repository\ConsentimentoRepository;
use ProLink\Repository\ParametroRepository;
use ProLink\Repository\ProfissionalRepository;
use ProLink\Support\Acervo;
use ProLink\Support\Auditoria;
use ProLink\Support\Cao;
use ProLink\Support\Cat;
use ProLink\Support\Database;

/**
 * Acervo técnico: importar o do profissional (ARTs e CATs), importar o da empresa pelo CAO e
 * associar ART informada à mão (RF02, RF03).
 *
 * Dono das escritas em `crea_arts`, `crea_art_atividades`, `crea_cats` e `crea_cat_arts`,
 * venha o documento de onde vier. É por isso
 * que o CAO entra aqui e não num serviço da empresa: uma ART é uma linha só, com um selo só, e
 * duas rotas de gravação para a mesma tabela acabariam selando a mesma ART de dois jeitos.
 *
 * Contém a **operação atômica 1** da proposta, `associarArt`. Quatro decisões de desenho que
 * valem mais do que o código que as implementa:
 *
 * ## 1. A rede fica fora da transação
 *
 * Toda chamada à API acontece antes do `beginTransaction`, e a transação só envolve a gravação.
 * O contrário — abrir a transação e chamar a API dentro — seguraria locks do InnoDB pelo tempo
 * de uma requisição HTTP externa, que o `.env` permite chegar a 30 segundos. Numa importação de
 * 290 ARTs isso seria uma transação de minutos, prendendo conexão do pool por causa de latência
 * de terceiro.
 *
 * É por isso que `importarArts` drena o generator do cliente **antes** de gravar, em vez de
 * gravar página a página. O generator continua útil para quem quiser interromper cedo; aqui a
 * escolha é deliberada e custa memória de propósito.
 *
 * ## 2. O consentimento é conferido antes da chamada, não depois
 *
 * Consultar a API é tratamento de dado pessoal do titular. Sem `CONSULTA_API` concedida
 * (item 11.3), nem a requisição sai — conferir depois de já ter perguntado ao CREA seria
 * conferir tarde demais.
 *
 * ## 3. Reassociar não apaga o que já se sabia
 *
 * A mesma ART chega por caminhos com campos diferentes: `?p=arts` valida a titularidade sem
 * devolver local nem atividades; a lista do profissional traz tudo. A mescla está em
 * `Support\Acervo::mesclar` e a regra é "valor novo vence, exceto quando é nulo e já havia
 * valor". Sem ela, a ordem em que o usuário clica decidiria o conteúdo do acervo.
 *
 * ## 4. O selo é integridade em repouso, não prova de origem
 *
 * Ver o cabeçalho de `Support\Acervo`. Repetido aqui porque é o ponto que mais confunde: o Selo
 * ART não certifica que o dado veio do CREA, certifica que ninguém mexeu nele depois que a gente
 * gravou.
 */
final class PortfolioService
{
    public function __construct(
        private readonly CreaApiClient $api = new CreaApiClient(),
        private readonly AcervoRepository $acervo = new AcervoRepository(),
        private readonly ConsentimentoRepository $consentimentos = new ConsentimentoRepository(),
        private readonly ProfissionalRepository $profissionais = new ProfissionalRepository(),
        private readonly ParametroRepository $parametros = new ParametroRepository(),
        private readonly CatRepository $cats = new CatRepository(),
    ) {
    }

    /**
     * Associa uma ART informada pelo profissional — operação atômica 1 da proposta (RF03).
     *
     * Duas chamadas à API, nesta ordem e não na inversa: `validarArt` confirma que a ART é dele,
     * e só então `atividadesDaArt` busca o escopo. O endpoint de atividades não pede RNP, então
     * qualquer número válido responde — invertê-la deixaria alguém trazer para o próprio
     * portfólio o escopo de uma ART alheia.
     *
     * @return array{art_id: int, art_numero: string, atividades: int, selo: string}
     * @throws ValidacaoException  quando a ART não é do profissional, ou falta consentimento
     * @throws ApiIndisponivelException quando a API não responde — não é culpa do usuário
     */
    public function associarArt(int $usuarioId, string $rnp, string $numero): array
    {
        $this->exigirConsentimento($usuarioId);

        $numero = strtoupper(trim($numero));

        // --- fora da transação: rede
        $validacao = $this->api->validarArt($rnp, $numero);

        if ($validacao === null) {
            // 200 [] — a ART pode até existir, mas não é dele. Reprovação de validação, não erro.
            throw new ValidacaoException(
                'Esta ART não consta como sua no CREA. Confira o número e o seu RNP.',
                ['art_numero' => 'Não encontramos esta ART vinculada ao seu registro.'],
            );
        }

        $atividades = $this->api->atividadesDaArt($numero);
        $consultada = date('Y-m-d H:i:s');

        // --- dentro da transação: só gravação
        return Database::transacao(function (PDO $pdo) use (
            $usuarioId, $rnp, $numero, $validacao, $atividades, $consultada
        ): array {
            $resultado = $this->persistir($rnp, $validacao, $atividades, $consultada);
            $this->reavaliarEmConstrucao($rnp, $pdo);

            Auditoria::registrar(
                Auditoria::VALIDAR_ART,
                'crea_arts',
                $resultado['art_id'],
                null,
                null,
                ['art_numero' => $numero, 'rnp' => $rnp, 'atividades' => $resultado['atividades']],
                $usuarioId,
                $pdo,
            );

            return $resultado;
        });
    }

    /**
     * Importa o acervo inteiro do profissional no cadastro (RF02).
     *
     * Uma chamada por página, e nenhuma por ART: a lista já traz as atividades TOS embutidas.
     * Isso não é varredura — é o acervo de um titular que consentiu, o mesmo que ele veria na
     * própria certidão.
     *
     * @return array{arts: int, atividades: int, ja_existiam: int}
     */
    public function importarArts(int $usuarioId, string $rnp, int $limitePagina = 20): array
    {
        $this->exigirConsentimento($usuarioId);

        // --- fora da transação: drena a API inteira antes de abrir qualquer transação (nota 1)
        $daApi      = iterator_to_array($this->api->todasArtsDoProfissional($rnp, $limitePagina));
        $consultada = date('Y-m-d H:i:s');

        // --- dentro da transação: a importação é tudo ou nada
        return Database::transacao(function (PDO $pdo) use ($usuarioId, $rnp, $daApi, $consultada): array {
            $arts = 0;
            $atividades = 0;
            $jaExistiam = 0;

            foreach ($daApi as $art) {
                $resultado = $this->persistir($rnp, $art, $art['atividades'] ?? [], $consultada);

                $arts++;
                $atividades += $resultado['atividades'];
                $jaExistiam += $resultado['ja_existia'] ? 1 : 0;
            }

            $this->reavaliarEmConstrucao($rnp, $pdo);

            Auditoria::registrar(
                Auditoria::CONSULTA_API,
                'crea_arts',
                null,
                null,
                null,
                ['rnp' => $rnp, 'arts' => $arts, 'atividades' => $atividades, 'ja_existiam' => $jaExistiam],
                $usuarioId,
                $pdo,
            );

            return ['arts' => $arts, 'atividades' => $atividades, 'ja_existiam' => $jaExistiam];
        });
    }

    /**
     * Importa as Certidões de Acervo Técnico do profissional e liga cada uma às ARTs que ela
     * certifica (RF02; D76, que revê a D67).
     *
     * Duas rotas da API, porque nenhuma sozinha basta. A lista do profissional dá os números das
     * certidões, e só isso; o detalhe de cada número (`validarCat`) dá as ARTs que ela agrupa,
     * cada uma com as atividades. O custo é uma chamada por página da lista, mais uma por CAT.
     * Continua não sendo varredura: é o acervo de um titular que consentiu, o mesmo que ele veria
     * na própria certidão, e o cliente segue sem método que liste profissionais.
     *
     * As ARTs do detalhe passam pelo mesmo `persistir()` de toda ART. Na massa elas já chegaram
     * pela lista do profissional, e aí a mescla só confirma o que havia; se chegar uma que não
     * estava, ela entra, e entra com titularidade conferida, porque o detalhe foi pedido por RNP.
     *
     * Certidão cujo detalhe não confere (outro RNP, outro número, ou resposta vazia) não é
     * gravada, e a importação segue com as outras: uma certidão estranha não pode apagar a
     * evidência das que conferem. Ela sai contada em `recusadas` e registrada na auditoria.
     *
     * @return array{cats: int, arts_certificadas: int, recusadas: int}
     * @throws ValidacaoException  quando falta consentimento
     * @throws ApiIndisponivelException quando a API não responde
     */
    public function importarCats(int $usuarioId, string $rnp, int $limitePagina = 20): array
    {
        $this->exigirConsentimento($usuarioId);

        // --- fora da transação: rede (nota 1). Lista e detalhe inteiros antes de gravar.
        $detalhes  = [];
        $recusadas = [];

        foreach ($this->api->todasCatsDoProfissional($rnp, $limitePagina) as $daLista) {
            $numero = (string) ($daLista['cat_numero'] ?? '');

            if ($numero === '') {
                continue;
            }

            $detalhe = $this->api->validarCat($rnp, $numero);

            if ($detalhe === null || !Cat::pertence($detalhe, $rnp, $numero)) {
                $recusadas[] = $numero;
                continue;
            }

            // O detalhe traz os mesmos campos da certidão que a lista; a lista entra por baixo
            // só para não perder campo que o detalhe, por acaso, não repita.
            $detalhes[] = $detalhe + $daLista;
        }

        $consultada = date('Y-m-d H:i:s');

        // --- dentro da transação: a importação é tudo ou nada
        return Database::transacao(function (PDO $pdo) use (
            $usuarioId, $rnp, $detalhes, $recusadas, $consultada
        ): array {
            $cats = 0;
            $certificadas = [];

            foreach ($detalhes as $detalhe) {
                $artIds  = [];
                $numeros = [];

                foreach (Cat::arts($detalhe) as $item) {
                    $resultado = $this->persistir($rnp, $item['art'], $item['atividades'], $consultada);

                    $artIds[]  = $resultado['art_id'];
                    $numeros[] = $resultado['art_numero'];
                    $certificadas[$resultado['art_numero']] = true;
                }

                $cat   = Cat::projetar($detalhe, $rnp);
                $catId = $this->cats->gravar($cat, Cat::selo($cat, $numeros), $consultada);
                $this->cats->sincronizarArts($catId, $artIds);

                $cats++;
            }

            // A CAT pode ter trazido ART que a lista não trouxe, e a contagem de ARTs decide a
            // marca de início de carreira.
            $this->reavaliarEmConstrucao($rnp, $pdo);

            Auditoria::registrar(
                Auditoria::VALIDAR_CAT,
                'crea_cats',
                null,
                null,
                null,
                ['rnp' => $rnp, 'cats' => $cats, 'arts_certificadas' => count($certificadas),
                 'recusadas' => $recusadas],
                $usuarioId,
                $pdo,
            );

            return [
                'cats'              => $cats,
                'arts_certificadas' => count($certificadas),
                'recusadas'         => count($recusadas),
            ];
        });
    }

    /**
     * Recalcula o selo da CAT gravada e compara com `cat_hash`, como `conferirSelo` faz com a
     * ART. Divergência é aviso e linha na auditoria, nunca página derrubada.
     */
    public function conferirSeloCat(string $numero, ?int $usuarioId = null): ?bool
    {
        $gravada = $this->cats->porNumero($numero);

        if ($gravada === null) {
            return null;
        }

        $confere = hash_equals(
            (string) $gravada['cat']['cat_hash'],
            Cat::selo($gravada['cat'], $gravada['arts']),
        );

        if (!$confere) {
            Auditoria::registrar(
                Auditoria::SELO_DIVERGENTE,
                'crea_cats',
                (int) $gravada['cat']['cat_id'],
                'cat_hash',
                (string) $gravada['cat']['cat_hash'],
                'recálculo não confere',
                $usuarioId,
            );
        }

        return $confere;
    }

    /**
     * A certidão de cada ART, pronta para a tela, com o selo da CAT reconferido.
     *
     * A CAT aparece na tela **pendurada na ART**, e não numa lista própria: ela herda a
     * visibilidade da ART que certifica (quem esconde a ART esconde a certidão dela junto), e
     * quem lê o perfil vê a diferença onde ela importa, ART a ART. Selo divergente vai para a
     * auditoria, como na ART, e a tela avisa em vez de esconder.
     *
     * Todas as certidões de cada ART, na ordem de `CatRepository::porArtIds` (validade mais
     * longa primeiro). A aba Acervo agrupa por elas; o retrato de manifestação lê só a primeira.
     *
     * @param list<int> $artIds
     * @return array<int, list<array{numero: string, tipo: ?string, finalidade: ?string,
     *                               dt_emissao: ?string, dt_validade: ?string, vigente: bool,
     *                               selo_confere: bool}>>
     */
    public function certidoesPorArt(array $artIds, ?int $espectadorId = null): array
    {
        $hoje       = date('Y-m-d');
        $conferidas = [];
        $telas      = [];

        foreach ($this->cats->porArtIds($artIds) as $artId => $certidoes) {
            foreach ($certidoes as $cat) {
                $catId = (int) $cat['cat_id'];

                if (!isset($conferidas[$catId])) {
                    $conferidas[$catId] = hash_equals(
                        (string) $cat['cat_hash'],
                        Cat::selo($cat, $cat['arts']),
                    );

                    if (!$conferidas[$catId]) {
                        Auditoria::registrar(
                            Auditoria::SELO_DIVERGENTE, 'crea_cats', $catId, 'cat_hash',
                            (string) $cat['cat_hash'], 'recálculo não confere ao exibir', $espectadorId,
                        );
                    }
                }

                $telas[$artId][] = [
                    'numero'       => (string) $cat['cat_numero'],
                    'tipo'         => $cat['cat_tipo'],
                    'finalidade'   => $cat['cat_finalidade'],
                    'dt_emissao'   => $cat['cat_dt_emissao'],
                    'dt_validade'  => $cat['cat_dt_validade'],
                    'vigente'      => Cat::vigente($cat['cat_dt_validade'], $hoje),
                    'selo_confere' => $conferidas[$catId],
                ];
            }
        }

        return $telas;
    }

    /**
     * Importa o acervo da empresa pela Certidão de Acervo Operacional (RF02; Anexo I, item 6).
     *
     * **Uma chamada para a empresa inteira.** O CAO traz a árvore completa — quadro técnico,
     * ARTs de cada profissional e atividades TOS de cada ART — num objeto só, sem paginação.
     * Comparado a pedir as ARTs de cada membro do quadro, é uma requisição no lugar de N, o que
     * importa por causa do item 10.4: toda chamada é registrada pela organização.
     *
     * As ARTs entram em `crea_arts` **sob o RNP de quem as registrou**, e não sob a empresa. A
     * empresa não tem acervo próprio: ela herda o dos profissionais do seu quadro, e quem faz
     * essa ligação é a view `crea_evidencias`, pelo vínculo vigente (D19). Gravar a ART sob a
     * empresa duplicaria a mesma evidência em duas identidades e quebraria `uq_art_numero` no
     * dia em que o profissional se cadastrasse.
     *
     * Por isso a mescla da `Support\Acervo` importa aqui mais do que em qualquer outro caminho:
     * o CAO não traz local, contratante nem forma de registro. Importar o CAO depois de o
     * profissional já ter importado o próprio acervo **não pode** apagar o município que ele
     * trouxe — e não apaga, porque nulo novo nunca vence valor existente.
     *
     * @return array{arts: int, atividades: int, profissionais: int, ja_existiam: int}
     * @throws ValidacaoException  falta consentimento, ou o CAO é de outra empresa
     * @throws ApiIndisponivelException quando a API não responde
     */
    public function importarCao(int $usuarioId, string $registroCrea): array
    {
        $this->exigirConsentimento($usuarioId);

        // --- fora da transação: rede (nota 1)
        $cao        = $this->api->cao($registroCrea);
        $consultada = date('Y-m-d H:i:s');

        // A certidão diz de quem ela é. Discordância entre o que pedimos e o que voltou não é
        // detalhe: gravar assim mesmo atribuiria o acervo de uma empresa a outra.
        $dono = Cao::registroCrea($cao);

        if ($dono !== null && $dono !== $registroCrea) {
            throw new ValidacaoException(sprintf(
                'A API devolveu o acervo do registro %s quando pedimos o do registro %s. '
                . 'Nada foi importado.',
                $dono,
                $registroCrea,
            ));
        }

        $acervo = Cao::acervo($cao);

        // --- dentro da transação: a importação é tudo ou nada
        return Database::transacao(function (PDO $pdo) use ($usuarioId, $registroCrea, $acervo, $consultada): array {
            $arts = 0;
            $atividades = 0;
            $jaExistiam = 0;
            $rnps = [];

            foreach ($acervo as $item) {
                $resultado = $this->persistir($item['rnp'], $item['art'], $item['atividades'], $consultada);

                $arts++;
                $atividades += $resultado['atividades'];
                $jaExistiam += $resultado['ja_existia'] ? 1 : 0;
                $rnps[$item['rnp']] = true;
            }

            Auditoria::registrar(
                Auditoria::CONSULTA_API,
                'crea_arts',
                null,
                null,
                null,
                ['cao' => $registroCrea, 'profissionais' => count($rnps), 'arts' => $arts,
                 'atividades' => $atividades, 'ja_existiam' => $jaExistiam],
                $usuarioId,
                $pdo,
            );

            return [
                'arts'          => $arts,
                'atividades'    => $atividades,
                'profissionais' => count($rnps),
                'ja_existiam'   => $jaExistiam,
            ];
        });
    }

    /**
     * Recalcula o selo da ART gravada e compara com `art_hash` (proposta, cenário 03A).
     *
     * Chamado na exibição do perfil. Divergência não é exceção: é aviso na tela e linha em
     * `sis_auditoria`, porque quem precisa saber que a linha foi mexida é o operador, não o
     * visitante — e derrubar a página esconderia o problema em vez de mostrá-lo.
     */
    public function conferirSelo(string $numero, ?int $usuarioId = null): ?bool
    {
        $gravado = $this->acervo->porNumero($numero);

        if ($gravado === null) {
            return null;
        }

        $confere = hash_equals(
            (string) $gravado['art']['art_hash'],
            Acervo::selo($gravado['art'], $gravado['atividades']),
        );

        if (!$confere) {
            Auditoria::registrar(
                Auditoria::SELO_DIVERGENTE,
                'crea_arts',
                (int) $gravado['art']['art_id'],
                'art_hash',
                (string) $gravado['art']['art_hash'],
                'recálculo não confere',
                $usuarioId,
            );
        }

        return $confere;
    }

    /** Quantas ARTs o profissional tem no acervo local. Alimenta o "perfil em construção". */
    /**
     * Recalcula a marca de perfil em construção do titular do RNP.
     *
     * ## Por que fica aqui, e não em quem chama
     *
     * `prf_em_construcao` é derivado da contagem de ARTs, e quem muda a contagem é este serviço.
     * Enquanto a atualização morava em `PerfilCreaService`, o cadastro atualizava a marca e a
     * associação manual de ART não — quem passasse do limiar associando ARTs à mão continuava
     * sinalizado como iniciante para sempre. Não era visível porque nenhuma rota chamava
     * `associarArt`, e defeito que espera uma tela para aparecer é defeito que aparece na
     * demonstração.
     *
     * Aqui é o ponto único de escrita: toda entrada de ART passa por `persistir()`, e toda
     * chamada de `persistir()` está dentro de um destes dois caminhos.
     *
     * ## Por que não é derivado na leitura
     *
     * Seria o desenho melhor, como a D01 fez com o índice de evidência, e é a alternativa
     * recusada aqui: `prf_em_construcao` é lido pelo motor em lote, dentro da montagem do pool, e
     * trocá-lo por contagem na leitura mudaria o `CandidatoRepository` na véspera da entrega. Fica
     * anotado como melhoria pós-entrega em `docs/decisoes.md` (D63).
     *
     * Devolve null quando o RNP não é de nenhum perfil cadastrado, que é o caso normal do CAO: a
     * empresa importa o acervo de quem talvez nunca tenha criado conta aqui.
     */
    private function reavaliarEmConstrucao(string $rnp, ?PDO $pdo = null): ?bool
    {
        $perfil = (new ProfissionalRepository($pdo))->porRnp($rnp);

        if ($perfil === null) {
            return null;
        }

        $minimo       = $this->parametros->inteiro('match.early_career.min_arts', 3);
        $emConstrucao = (new AcervoRepository($pdo))->contarPorRnp($rnp) < $minimo;

        (new ProfissionalRepository($pdo))->marcarEmConstrucao((int) $perfil['prf_id'], $emConstrucao);

        return $emConstrucao;
    }

    public function contarArts(string $rnp): int
    {
        return $this->acervo->contarPorRnp($rnp);
    }

    // ---------------------------------------------------------------- interno

    /**
     * Mescla, sela e grava. Sempre chamado de dentro de uma transação.
     *
     * @param array<string, mixed> $daApi
     * @param array<int, array<string, mixed>> $atividadesDaApi
     * A conexão do `Database` é única, então o repositório injetado já escreve dentro da
     * transação aberta por `Database::transacao()` — não é preciso (nem barato) instanciar um
     * repositório por ART.
     *
     * @return array{art_id: int, art_numero: string, atividades: int, selo: string, ja_existia: bool}
     */
    private function persistir(
        string $rnp,
        array $daApi,
        array $atividadesDaApi,
        string $consultada,
    ): array {
        $numero    = (string) $daApi['art_numero'];
        $existente = $this->acervo->porNumero($numero);

        $art = Acervo::mesclar(
            $existente['art'] ?? null,
            Acervo::projetarArt($daApi, $rnp),
        );

        $atividades = Acervo::mesclarAtividades(
            $existente['atividades'] ?? [],
            Acervo::projetarAtividades($atividadesDaApi),
        );

        // O selo assina o que foi mesclado, e não a resposta que acabou de chegar: é a linha
        // gravada que será reconferida na exibição.
        $selo  = Acervo::selo($art, $atividades);
        $artId = $this->acervo->gravar($art, $selo, $consultada);

        $this->acervo->sincronizarAtividades($artId, $atividades);

        return [
            'art_id'     => $artId,
            'art_numero' => $numero,
            'atividades' => count($atividades),
            'selo'       => $selo,
            'ja_existia' => $existente !== null,
        ];
    }

    private function exigirConsentimento(int $usuarioId): void
    {
        if ($this->consentimentos->concedido($usuarioId, FINALIDADE_CONSULTA_API)) {
            return;
        }

        throw new ValidacaoException(
            'Para consultar seus dados no CREA precisamos da sua autorização. '
            . 'Ative "Consultar a API oficial do CREA" no painel de privacidade.',
            ['consentimento' => 'Consentimento de consulta à API não concedido.'],
        );
    }
}
