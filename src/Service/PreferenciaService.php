<?php

declare(strict_types=1);

namespace ProLink\Service;

use PDO;
use ProLink\Repository\ProfissionalRepository;
use ProLink\Support\Auditoria;
use ProLink\Support\Database;
use ProLink\Support\Preferencias;
use ProLink\Support\Validacao;

/**
 * As preferências declaradas do profissional: resumo, tipo de contrato e abrangência geográfica
 * (RF03; edital Anexo I item 3.2, dimensões 5 e 6).
 *
 * ## Por que este serviço precisou existir
 *
 * As três colunas existiam em `pro_profissionais` desde a fundação, `PerfilService` as exibia e
 * `CandidatoRepository` as lia para alimentar duas das seis dimensões do motor — e **nenhum
 * caminho do código as escrevia**. Não era uma tela faltando: era o motor rodando com quatro
 * dimensões em vez de seis, em silêncio, porque dimensão nula sai da média sem reclamar. A regra
 * que protege o perfil incompleto é a mesma que escondia a ausência do formulário.
 *
 * ## É autodeclaração, e o serviço não finge o contrário
 *
 * Nada aqui é conferido contra o CREA, porque não há o que conferir: regime de contratação e onde
 * a pessoa aceita trabalhar são escolhas dela, não fatos que o conselho registre. O que o serviço
 * garante é outra coisa — que o valor gravado pertence ao vocabulário fechado de
 * `Support\Preferencias`, para que a comparação do motor signifique alguma coisa. Texto livre dos
 * dois lados foi exatamente o que fez a dimensão de abrangência devolver zero para todo mundo.
 *
 * O peso dessas duas dimensões (0.10 cada, contra 0.40 da competência via ART) é a tradução
 * numérica de quanto o motor confia nelas.
 */
final class PreferenciaService
{
    /** Limite de `prf_resumo`, que é TEXT — o teto aqui é de legibilidade, não de coluna. */
    private const RESUMO_MAXIMO = 2000;

    public function __construct(
        private readonly ProfissionalRepository $profissionais = new ProfissionalRepository(),
    ) {
    }

    /**
     * Grava as três declarações de uma vez.
     *
     * Uma escrita só para os três campos porque eles são um bloco na tela e uma decisão só para
     * quem preenche. Salvar campo a campo multiplicaria por três as linhas de auditoria de uma
     * única edição, e a trilha ficaria ilegível justamente onde ela serve para mostrar o que o
     * titular mudou.
     *
     * @param  array<string, mixed> $entrada campos crus do formulário
     * @return bool true se algo mudou de fato — deixa o controller dizer a verdade na mensagem
     * @throws ValidacaoException
     */
    public function salvar(int $usuarioId, array $entrada): bool
    {
        $profissional = $this->exigirProfissional($usuarioId);
        $dados        = $this->validar($entrada);

        $antes = [
            'resumo'          => $profissional['prf_resumo'] ?? null,
            'tipo_contrato'   => $profissional['prf_tipo_contrato'] ?? null,
            'disponibilidade' => $profissional['prf_disponibilidade'] ?? null,
        ];

        if ($antes === $dados) {
            return false;
        }

        Database::transacao(function (PDO $pdo) use ($usuarioId, $profissional, $antes, $dados): void {
            (new ProfissionalRepository($pdo))->salvarDeclarado((int) $profissional['prf_id'], $dados);

            Auditoria::registrar(
                Auditoria::EDITAR, 'pro_profissionais', (int) $profissional['prf_id'], null,
                $antes, $dados, $usuarioId, $pdo,
            );
        });

        return true;
    }

    /**
     * Completa as preferências do perfil com o que o profissional acabou de responder ao
     * manifestar interesse, quando ele marca "usar como minhas preferências" (D78).
     *
     * Acrescenta, nunca substitui. A região da obra entra na lista de UFs que ele atende (quem já
     * marcou "qualquer lugar" continua assim). O regime só é gravado se o perfil ainda não tinha
     * nenhum: aceitar PJ nesta demanda não quer dizer que PJ passou a ser a preferência de quem
     * tinha declarado CLT. Só entra o que ele respondeu que aceita ou atende.
     *
     * @return bool true se algo mudou
     */
    public function acrescentarDaManifestacao(int $usuarioId, ?string $contratoAceito, ?string $ufAtendida): bool
    {
        $profissional = $this->profissionais->porUsuario($usuarioId);

        if ($profissional === null) {
            return false;
        }

        $atual = [
            'resumo'          => $profissional['prf_resumo'] ?? null,
            'tipo_contrato'   => $profissional['prf_tipo_contrato'] ?? null,
            'disponibilidade' => $profissional['prf_disponibilidade'] ?? null,
        ];

        $novo = $atual;

        if ($contratoAceito !== null && ($atual['tipo_contrato'] ?? '') === ''
            && Preferencias::contratoValido($contratoAceito)) {
            $novo['tipo_contrato'] = $contratoAceito;
        }

        if ($ufAtendida !== null && Preferencias::abrangenciaCobre($atual['disponibilidade'], $ufAtendida) !== true) {
            $ufs = Preferencias::ufsDaAbrangencia($atual['disponibilidade']);
            $ufs[] = $ufAtendida;
            $novo['disponibilidade'] = Preferencias::normalizarAbrangencia($ufs);
        }

        if ($novo === $atual) {
            return false;
        }

        Database::transacao(function (PDO $pdo) use ($usuarioId, $profissional, $atual, $novo): void {
            (new ProfissionalRepository($pdo))->salvarDeclarado((int) $profissional['prf_id'], $novo);

            Auditoria::registrar(
                Auditoria::EDITAR, 'pro_profissionais', (int) $profissional['prf_id'], null,
                $atual, $novo + ['origem' => 'manifestação de interesse'], $usuarioId, $pdo,
            );
        });

        return true;
    }

    /**
     * @param  array<string, mixed> $entrada
     * @return array{resumo: ?string, tipo_contrato: ?string, disponibilidade: ?string}
     * @throws ValidacaoException
     */
    private function validar(array $entrada): array
    {
        $resumo   = trim((string) ($entrada['resumo'] ?? ''));
        $contrato = trim((string) ($entrada['tipo_contrato'] ?? ''));

        // O formulário manda `abrangencia[]` (caixas de seleção). Aceita string também porque a
        // normalização sabe ler as duas formas, e assim um POST de script de apoio não precisa
        // montar array.
        $abrangenciaBruta = $entrada['abrangencia'] ?? null;

        if (!is_array($abrangenciaBruta) && !is_string($abrangenciaBruta)) {
            $abrangenciaBruta = null;
        }

        $v = new Validacao();

        $v->tamanhoMaximo('resumo', $resumo, self::RESUMO_MAXIMO,
            sprintf('No máximo %d caracteres.', self::RESUMO_MAXIMO));

        // Os três campos são opcionais: perfil incompleto não é erro, e o motor já sabe tirar da
        // média o que não foi declarado. O que não se aceita é valor fora do vocabulário — esse
        // viraria uma comparação que nunca casa, sem nada na tela explicando o silêncio.
        $v->exigir('tipo_contrato', $contrato === '' || Preferencias::contratoValido($contrato),
            'Escolha um dos regimes da lista.');

        $abrangencia = Preferencias::normalizarAbrangencia($abrangenciaBruta);

        $v->exigir(
            'abrangencia',
            $abrangenciaBruta === null || $abrangenciaBruta === '' || $abrangenciaBruta === []
                || $abrangencia !== null,
            'Escolha "Qualquer lugar" ou pelo menos uma UF.',
        );

        $v->lancarSeInvalido();

        return [
            'resumo'          => $resumo === '' ? null : $resumo,
            'tipo_contrato'   => $contrato === '' ? null : $contrato,
            'disponibilidade' => $abrangencia,
        ];
    }

    /**
     * Sem linha em `pro_profissionais` não há onde gravar — e o motivo importa para a mensagem:
     * é a pendência da D20, a conta que nasceu com a API fora do ar. A saída é validar o registro
     * pelo próprio perfil, que é o botão logo acima na mesma tela.
     *
     * @return array<string, mixed>
     */
    private function exigirProfissional(int $usuarioId): array
    {
        $profissional = $this->profissionais->porUsuario($usuarioId);

        if ($profissional === null) {
            throw new ValidacaoException(
                'Seu registro no CREA ainda não foi validado. Valide-o pelo seu perfil antes de '
                . 'declarar suas preferências.'
            );
        }

        return $profissional;
    }
}
