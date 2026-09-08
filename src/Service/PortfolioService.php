<?php

declare(strict_types=1);

namespace ProLink\Service;

use PDO;
use ProLink\Repository\AcervoRepository;
use ProLink\Repository\ConsentimentoRepository;
use ProLink\Support\Acervo;
use ProLink\Support\Auditoria;
use ProLink\Support\Database;

/**
 * Portfólio do profissional: importar o acervo e associar ART informada à mão (RF02, RF03).
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
