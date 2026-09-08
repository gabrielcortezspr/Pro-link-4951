<?php

declare(strict_types=1);

namespace ProLink\Service;

use PDO;
use ProLink\Repository\ConsentimentoRepository;
use ProLink\Repository\ParametroRepository;
use ProLink\Repository\ProfissionalRepository;
use ProLink\Repository\UsuarioRepository;
use ProLink\Support\Auditoria;
use ProLink\Support\Crypto;
use ProLink\Support\Database;

/**
 * Quem a pessoa é no CREA, segundo o CREA (RF02).
 *
 * Dono de `pro_profissionais` e das modalidades. Três consumidores: o cadastro, o botão de
 * revalidar registro e o `sincronizar-status.php` — por isso é serviço próprio, e não um trecho
 * dentro do `AutenticacaoService`, que é sobre identidade na plataforma, não no conselho.
 *
 * ## Os três desfechos de uma consulta, e por que nenhum deles é "erro"
 *
 * A API distingue "não achei" de "não respondi", e o cadastro precisa distinguir junto:
 *
 *   · **VINCULADO** — a API devolveu o profissional. Perfil montado, modalidades gravadas, acervo
 *     importado.
 *   · **SEM_REGISTRO** — `200 []`: o CPF é válido e não tem registro no CREA. A conta continua
 *     existindo, mas como Terceiro PF, porque manter o perfil Profissional prometeria um registro
 *     que não existe.
 *   · **API_INDISPONIVEL** — a API não respondeu. A conta é criada e fica **pendente de
 *     validação**, sem linha em `pro_profissionais`. Não é erro do usuário e não pode custar a
 *     inscrição dele: perder um cadastro porque um serviço de terceiro piscou seria o pior dos
 *     três desfechos, e o único irreversível.
 *
 * A pendência não precisou de coluna nova. Usuário com perfil PROFISSIONAL e sem linha em
 * `pro_profissionais` **é** o estado pendente — e como nada é público por padrão e o motor só
 * enxerga quem tem evidência em `crea_evidencias`, um perfil pendente não aparece para ninguém.
 * Fica invisível até ser validado, que é exatamente o comportamento seguro.
 *
 * ## Meia validação também é um estado, e ele tem carimbo
 *
 * A API pode responder ao CPF e cair na importação do acervo. Aí o perfil existe e o acervo está
 * vazio — indistinguível de um profissional que genuinamente não tem ART. Por isso
 * `prf_dt_sincronizacao` só é carimbada quando as **duas** metades entram: nula (ou velha)
 * significa "tentar de novo", e é o mesmo campo que `api.sincronizacao.horas` vai comparar.
 * Acervo pela metade não existe — `importarArts` lê todas as páginas antes de abrir a transação
 * (D18), então ou entram todas as ARTs ou nenhuma.
 */
final class PerfilCreaService
{
    public const VINCULADO        = 'VINCULADO';
    public const SEM_REGISTRO     = 'SEM_REGISTRO';
    public const API_INDISPONIVEL = 'API_INDISPONIVEL';

    public function __construct(
        private readonly CreaApiClient $api = new CreaApiClient(),
        private readonly ProfissionalRepository $profissionais = new ProfissionalRepository(),
        private readonly UsuarioRepository $usuarios = new UsuarioRepository(),
        private readonly ConsentimentoRepository $consentimentos = new ConsentimentoRepository(),
        private readonly ParametroRepository $parametros = new ParametroRepository(),
        private readonly PortfolioService $portfolio = new PortfolioService(),
    ) {
    }

    /**
     * Consulta o CPF no CREA e monta o perfil profissional, com o acervo.
     *
     * @param string|null $cpf em claro. Null decifra de `usu_documento_cif` — é o caminho da
     *                         revalidação e da sincronização, onde o CPF só existe cifrado.
     * @return array{situacao: string, rnp: ?string, modalidades: int, arts: int, aviso: ?string}
     */
    public function vincularProfissional(int $usuarioId, ?string $cpf = null): array
    {
        $this->exigirConsentimento($usuarioId);

        $cpf ??= $this->cpfDoUsuario($usuarioId);

        // --- fora de qualquer transação: rede (D18)
        try {
            $daApi = $this->api->profissionalPorCpf($cpf);
        } catch (ApiIndisponivelException $e) {
            Auditoria::registrar(
                Auditoria::CONSULTA_API, 'pro_profissionais', null, null, null,
                ['resultado' => self::API_INDISPONIVEL, 'motivo' => $e->getMessage()], $usuarioId,
            );

            return $this->desfecho(self::API_INDISPONIVEL, null, 0, 0,
                'Não conseguimos falar com a API do CREA agora. Sua conta foi criada e o registro '
                . 'será validado assim que o serviço voltar.');
        }

        if ($daApi === null) {
            return $this->rebaixarParaTerceiro($usuarioId);
        }

        $rnp = (string) $daApi['pro_rnp'];

        // RNP já vinculado a outra conta é conflito, não atualização. `uq_prf_rnp` é global, e
        // sem esta guarda o ON DUPLICATE KEY UPDATE do repositório reescreveria a linha alheia —
        // o acervo de uma pessoa passaria a responder por outra. Não deveria acontecer, porque
        // CPF já é único em sis_usuarios, mas é barato garantir e caro descobrir depois.
        $dono = $this->profissionais->porRnp($rnp);

        if ($dono !== null && (int) $dono['prf_usu_id'] !== $usuarioId) {
            Auditoria::registrar(
                Auditoria::ACESSO_NEGADO, 'pro_profissionais', (int) $dono['prf_id'], 'prf_rnp',
                null, ['rnp' => $rnp, 'tentou' => $usuarioId], $usuarioId,
            );

            throw new ValidacaoException(
                'Este registro do CREA já está vinculado a outra conta na plataforma. '
                . 'Se o registro é seu, fale com a administração.',
                ['documento' => 'RNP já vinculado a outra conta.'],
            );
        }

        // --- transação 1: identidade profissional
        $profissionalId = Database::transacao(function (PDO $pdo) use ($usuarioId, $daApi, $rnp): int {
            $id = $this->profissionais->salvar([
                'usuario_id'    => $usuarioId,
                'rnp'           => $rnp,
                'registro_crea' => isset($daApi['pro_registro_crea']) ? (string) $daApi['pro_registro_crea'] : null,
                'nome_api'      => isset($daApi['pro_nome']) ? (string) $daApi['pro_nome'] : null,
                'status_api'    => isset($daApi['pro_status']) ? (string) $daApi['pro_status'] : null,
            ]);

            $modalidades = $this->modalidadesValidas($daApi['modalidades'] ?? []);
            $this->profissionais->sincronizarModalidades($id, $modalidades);

            Auditoria::registrar(
                Auditoria::CRIAR, 'pro_profissionais', $id, null, null,
                ['rnp' => $rnp, 'status_api' => $daApi['pro_status'] ?? null,
                 'modalidades' => count($modalidades)],
                $usuarioId, $pdo,
            );

            return $id;
        });

        $modalidades = count($this->profissionais->modalidades($profissionalId));

        // --- transação 2, separada de propósito: o acervo pode falhar sem levar o perfil junto
        try {
            $acervo = $this->portfolio->importarArts($usuarioId, $rnp);
        } catch (ApiIndisponivelException) {
            // Sem carimbo de sincronização: `prf_dt_sincronizacao` nulo é o que diferencia
            // "importamos e ele não tem ART" de "não conseguimos importar". Sem isso, os dois
            // estados ficam idênticos no banco — zero linhas em crea_arts — e nem o botão de
            // revalidar nem o sincronizar-status.php saberiam que precisam tentar de novo.
            $this->atualizarEmConstrucao($profissionalId, $rnp);

            return $this->desfecho(self::VINCULADO, $rnp, $modalidades, 0,
                'Seu registro foi validado, mas não conseguimos importar suas ARTs agora. '
                . 'Tente de novo pelo seu perfil em alguns minutos.');
        }

        $this->atualizarEmConstrucao($profissionalId, $rnp);
        $this->profissionais->marcarSincronizado($profissionalId);

        return $this->desfecho(self::VINCULADO, $rnp, $modalidades, $acervo['arts'], null);
    }

    /**
     * Marca o perfil como "em construção" quando o acervo ainda é pequeno.
     *
     * Sinalização, nunca exclusão: o limiar `match.early_career.min_arts` decide se a tela avisa
     * que o portfólio está começando, e não se a pessoa entra no pool. Quem está começando é
     * justamente quem mais precisa aparecer (proposta, diferencial "perfil em construção").
     */
    public function atualizarEmConstrucao(int $profissionalId, string $rnp): bool
    {
        $minimo = $this->parametros->inteiro('match.early_career.min_arts', 3);
        $emConstrucao = $this->portfolio->contarArts($rnp) < $minimo;

        $this->profissionais->marcarEmConstrucao($profissionalId, $emConstrucao);

        return $emConstrucao;
    }

    // ---------------------------------------------------------------- interno

    /** CPF válido que a API não conhece: a conta continua, o perfil desce para Terceiro PF. */
    private function rebaixarParaTerceiro(int $usuarioId): array
    {
        $perfilId = $this->usuarios->perfilIdPorCodigo(PERFIL_TERCEIRO);

        if ($perfilId !== null) {
            Database::transacao(function (PDO $pdo) use ($usuarioId, $perfilId): void {
                $this->usuarios->trocarPerfil($usuarioId, $perfilId);

                Auditoria::registrar(
                    Auditoria::EDITAR, 'sis_usuarios', $usuarioId, 'usu_per_id',
                    PERFIL_PROFISSIONAL, PERFIL_TERCEIRO, $usuarioId, $pdo,
                );
            });
        }

        return $this->desfecho(self::SEM_REGISTRO, null, 0, 0,
            'Não encontramos registro no CREA para este CPF. Sua conta foi criada como Terceiro: '
            . 'você pode publicar demandas e contratar. Se o registro sair, fale com a gente.');
    }

    /**
     * Filtra as modalidades pelas que existem em `crea_modalidades`.
     *
     * A tabela é fechada em 25 e vem da carga inicial. Se a API devolver uma 26ª, o cadastro não
     * pode morrer numa violação de chave estrangeira — ignora e segue, porque a modalidade é
     * complemento do perfil, e a evidência que importa está nos códigos TOS das ARTs.
     *
     * @param array<int, array<string, mixed>> $daApi
     * @return list<int>
     */
    private function modalidadesValidas(array $daApi): array
    {
        $conhecidas = $this->profissionais->modalidadesConhecidas();
        $validas    = [];

        foreach ($daApi as $modalidade) {
            $id = (int) ($modalidade['mod_id'] ?? 0);

            if ($id > 0 && in_array($id, $conhecidas, true)) {
                $validas[$id] = $id;
            }
        }

        return array_values($validas);
    }

    private function cpfDoUsuario(int $usuarioId): string
    {
        $usuario = $this->usuarios->porId($usuarioId);
        $cifrado = $usuario['usu_documento_cif'] ?? null;

        if (!is_string($cifrado) || $cifrado === '') {
            throw new ValidacaoException('Esta conta não tem CPF guardado para consultar no CREA.');
        }

        return Crypto::decifrar($cifrado);
    }

    private function exigirConsentimento(int $usuarioId): void
    {
        if ($this->consentimentos->concedido($usuarioId, FINALIDADE_CONSULTA_API)) {
            return;
        }

        throw new ValidacaoException(
            'Para validar seu registro no CREA precisamos da sua autorização. '
            . 'Ative "Consultar a API oficial do CREA" no painel de privacidade.',
            ['consentimento' => 'Consentimento de consulta à API não concedido.'],
        );
    }

    /** @return array{situacao: string, rnp: ?string, modalidades: int, arts: int, aviso: ?string} */
    private function desfecho(string $situacao, ?string $rnp, int $modalidades, int $arts, ?string $aviso): array
    {
        return [
            'situacao'    => $situacao,
            'rnp'         => $rnp,
            'modalidades' => $modalidades,
            'arts'        => $arts,
            'aviso'       => $aviso,
        ];
    }
}
