<?php

declare(strict_types=1);

namespace ProLink\Service;

use PDO;
use ProLink\Repository\ConsentimentoRepository;
use ProLink\Repository\EmpresaRepository;
use ProLink\Repository\QuadroTecnicoRepository;
use ProLink\Repository\UsuarioRepository;
use ProLink\Support\Auditoria;
use ProLink\Support\Cao;
use ProLink\Support\Crypto;
use ProLink\Support\Database;
use ProLink\Support\DesfechoCrea;

/**
 * Quem a empresa é no CREA, segundo o CREA (RF02).
 *
 * O par de `PerfilCreaService`, com a mesma forma e os mesmos três desfechos
 * (`Support\DesfechoCrea`), porque o problema é o mesmo: uma conta acabou de nascer, a API pode
 * responder, negar ou não responder, e nenhum dos três pode custar o cadastro. Três consumidores,
 * também iguais: o cadastro, o botão de revalidar registro e o `sincronizar-status.php`.
 *
 * ## O que muda em relação ao profissional
 *
 * **A empresa não tem acervo próprio.** O profissional registra ARTs; a empresa não registra
 * nada — ela responde por quem registrou. Por isso a validação da empresa são três passos e não
 * dois: identidade (`pro_empresas`), quadro técnico (`crea_quadro_tecnico`) e acervo (o CAO,
 * gravado sob o RNP de cada profissional). A ligação entre os três é a view `crea_evidencias`,
 * e só ela: vínculo vigente herda o acervo daquele profissional, vínculo encerrado não herda
 * nada (D19).
 *
 * **A API não devolve situação de empresa.** A busca por CNPJ traz `emp_cnpj`,
 * `emp_razao_social`, `emp_nome_fantasia`, `emp_registro_crea` e `emp_dt_registro` — não existe
 * `emp_status`. Não há, portanto, o equivalente ao `prf_status_api != 'A'` que zera a
 * visibilidade do profissional: para a empresa o portão global é só o consentimento de exibição
 * (ver `VisibilidadeService::perfilAberto`). É limitação da API, e está declarada em vez de
 * fingida.
 *
 * **Não existe "empresa em construção".** O limiar `match.early_career.min_arts` é sinalização
 * de início de carreira de uma pessoa; uma empresa com poucas ARTs no quadro não é iniciante,
 * é uma empresa com quadro pequeno. Rotular isso seria inventar informação.
 *
 * ## Duas chamadas depois do CNPJ, e nenhuma delas é dispensável
 *
 * `quadro-tecnico` e `cao` parecem redundantes — os dois listam profissionais. Não são: só o
 * primeiro traz `qut_dt_fim`, que é o campo de que a herança depende, e só o segundo traz as
 * ARTs. Montar o quadro pelo CAO gravaria todo vínculo como vigente. O porquê está inteiro no
 * cabeçalho de `QuadroTecnicoRepository`.
 *
 * ## Meia validação também é um estado aqui
 *
 * `emp_dt_sincronizacao` segue a regra da D21: só é carimbada quando a identidade, o quadro
 * técnico **e** o acervo entraram. Nula (ou velha) significa "tentar de novo" — sem ela, uma
 * empresa cuja consulta caiu no meio ficaria indistinguível de uma empresa sem quadro técnico
 * nenhum, que são zero linhas em `crea_quadro_tecnico` nos dois casos.
 */
final class EmpresaCreaService
{
    public const VINCULADO        = DesfechoCrea::VINCULADO;
    public const SEM_REGISTRO     = DesfechoCrea::SEM_REGISTRO;
    public const API_INDISPONIVEL = DesfechoCrea::API_INDISPONIVEL;

    public function __construct(
        private readonly CreaApiClient $api = new CreaApiClient(),
        private readonly EmpresaRepository $empresas = new EmpresaRepository(),
        private readonly QuadroTecnicoRepository $quadros = new QuadroTecnicoRepository(),
        private readonly UsuarioRepository $usuarios = new UsuarioRepository(),
        private readonly ConsentimentoRepository $consentimentos = new ConsentimentoRepository(),
        private readonly PortfolioService $portfolio = new PortfolioService(),
    ) {
    }

    /**
     * Consulta o CNPJ no CREA e monta o perfil da empresa, com quadro técnico e acervo.
     *
     * @param string|null $cnpj em claro. Null decifra de `usu_documento_cif` — é o caminho da
     *                          revalidação e da sincronização, onde o CNPJ só existe cifrado.
     * @return array{situacao: string, registro_crea: ?string, razao_social: ?string,
     *               vinculos: int, vigentes: int, arts: int, aviso: ?string}
     */
    public function vincularEmpresa(int $usuarioId, ?string $cnpj = null): array
    {
        $this->exigirConsentimento($usuarioId);

        $cnpj ??= $this->cnpjDoUsuario($usuarioId);

        // --- fora de qualquer transação: rede (D18)
        try {
            $daApi = $this->api->empresaPorCnpj($cnpj);
        } catch (ApiIndisponivelException $e) {
            Auditoria::registrar(
                Auditoria::CONSULTA_API, 'pro_empresas', null, null, null,
                ['resultado' => self::API_INDISPONIVEL, 'motivo' => $e->getMessage()], $usuarioId,
            );

            return $this->desfecho(self::API_INDISPONIVEL, null, null, 0, 0, 0,
                'Não conseguimos falar com a API do CREA agora. A conta da empresa foi criada e o '
                . 'registro será validado assim que o serviço voltar.');
        }

        if ($daApi === null) {
            return $this->rebaixarParaTerceiro($usuarioId);
        }

        $registroCrea = (string) ($daApi['emp_registro_crea'] ?? '');

        if ($registroCrea === '') {
            // Sem registro no CREA não há o que vincular nem como pedir o CAO. Não é falha da
            // API: é uma resposta que não sustenta um perfil de empresa registrada.
            return $this->rebaixarParaTerceiro($usuarioId);
        }

        $this->exigirRegistroLivre($registroCrea, $usuarioId);

        // --- transação 1: identidade da empresa
        $empresaId = Database::transacao(function (PDO $pdo) use ($usuarioId, $daApi, $registroCrea): int {
            $id = $this->empresas->salvar([
                'usuario_id'       => $usuarioId,
                'registro_crea'    => $registroCrea,
                'razao_social'     => isset($daApi['emp_razao_social']) ? (string) $daApi['emp_razao_social'] : null,
                'nome_fantasia'    => isset($daApi['emp_nome_fantasia']) ? (string) $daApi['emp_nome_fantasia'] : null,
                // A API chama de `emp_dt_registro` a data do registro no conselho; nossa coluna
                // com esse nome é a data da nossa linha. Ver o cabeçalho de EmpresaRepository.
                'dt_registro_crea' => isset($daApi['emp_dt_registro']) ? (string) $daApi['emp_dt_registro'] : null,
            ]);

            Auditoria::registrar(
                Auditoria::CRIAR, 'pro_empresas', $id, null, null,
                ['registro_crea' => $registroCrea, 'razao_social' => $daApi['emp_razao_social'] ?? null],
                $usuarioId, $pdo,
            );

            return $id;
        });

        $razaoSocial = isset($daApi['emp_razao_social']) ? (string) $daApi['emp_razao_social'] : null;

        // --- transações seguintes, separadas de propósito: o acervo pode falhar sem levar a
        //     identidade junto. Sem carimbo de sincronização, o botão do perfil tenta de novo.
        //
        // O quadro técnico entra antes do CAO e o resultado dele é guardado fora do `try`: a
        // falha mais provável é a segunda chamada, e nesse caso o quadro **já entrou**. Relatar
        // zero seria mentir sobre o que está no banco, e a tela decide o que mostrar a partir
        // destes números.
        $quadro = ['ativos' => 0, 'vigentes' => 0];

        try {
            $quadro = $this->importarQuadroTecnico($usuarioId, $registroCrea);
            $acervo = $this->portfolio->importarCao($usuarioId, $registroCrea);
        } catch (ApiIndisponivelException) {
            return $this->desfecho(
                self::VINCULADO, $registroCrea, $razaoSocial, $quadro['ativos'], $quadro['vigentes'], 0,
                'O registro da empresa foi validado, mas a consulta ao CREA não terminou: falta '
                . 'importar o acervo operacional. Tente de novo pelo perfil em alguns minutos.',
            );
        }

        $this->empresas->marcarSincronizado($empresaId);

        return $this->desfecho(
            self::VINCULADO, $registroCrea, $razaoSocial,
            $quadro['ativos'], $quadro['vigentes'], $acervo['arts'], null,
        );
    }

    // ---------------------------------------------------------------- interno

    /**
     * Consulta o quadro técnico e grava, numa transação só.
     *
     * A rede acontece antes do `beginTransaction`, como em todo o resto da E2 (D18).
     *
     * @return array{ativos: int, vigentes: int, encerrados: int}
     */
    private function importarQuadroTecnico(int $usuarioId, string $registroCrea): array
    {
        $daApi      = $this->api->quadroTecnico($registroCrea);
        $vinculos   = Cao::vinculos($daApi);
        $consultada = date('Y-m-d H:i:s');

        return Database::transacao(function (PDO $pdo) use ($usuarioId, $registroCrea, $vinculos, $consultada): array {
            $resultado = $this->quadros->sincronizar($registroCrea, $vinculos, $consultada);

            Auditoria::registrar(
                Auditoria::CONSULTA_API, 'crea_quadro_tecnico', null, null, null,
                ['empresa' => $registroCrea] + $resultado,
                $usuarioId, $pdo,
            );

            return $resultado;
        });
    }

    /**
     * Registro do CREA já vinculado a outra conta é conflito, não atualização.
     *
     * `uq_emp_registro_crea` é global, e sem esta guarda o `ON DUPLICATE KEY UPDATE` do
     * repositório reescreveria a linha alheia — o acervo de uma empresa passaria a responder por
     * outra. Não deveria acontecer, porque o CNPJ já é único em `sis_usuarios`, mas é barato
     * garantir e caro descobrir depois. Mesma guarda do RNP, no lado do profissional.
     */
    private function exigirRegistroLivre(string $registroCrea, int $usuarioId): void
    {
        $dono = $this->empresas->porRegistroCrea($registroCrea);

        if ($dono === null || (int) $dono['emp_usu_id'] === $usuarioId) {
            return;
        }

        Auditoria::registrar(
            Auditoria::ACESSO_NEGADO, 'pro_empresas', (int) $dono['emp_id'], 'emp_registro_crea',
            null, ['registro_crea' => $registroCrea, 'tentou' => $usuarioId], $usuarioId,
        );

        throw new ValidacaoException(
            'Este registro do CREA já está vinculado a outra conta na plataforma. '
            . 'Se o registro é da sua empresa, fale com a administração.',
            ['documento' => 'Registro do CREA já vinculado a outra conta.'],
        );
    }

    /** CNPJ válido que a API não conhece: a conta continua, o perfil desce para Terceiro PJ. */
    private function rebaixarParaTerceiro(int $usuarioId): array
    {
        $perfilId = $this->usuarios->perfilIdPorCodigo(PERFIL_TERCEIRO);

        if ($perfilId !== null) {
            Database::transacao(function (PDO $pdo) use ($usuarioId, $perfilId): void {
                $this->usuarios->trocarPerfil($usuarioId, $perfilId);

                Auditoria::registrar(
                    Auditoria::EDITAR, 'sis_usuarios', $usuarioId, 'usu_per_id',
                    PERFIL_EMPRESA, PERFIL_TERCEIRO, $usuarioId, $pdo,
                );
            });
        }

        return $this->desfecho(self::SEM_REGISTRO, null, null, 0, 0, 0,
            'Não encontramos registro no CREA para este CNPJ. A conta foi criada como Terceiro: '
            . 'sua empresa pode publicar demandas e contratar. Se o registro sair, fale com a gente.');
    }

    private function cnpjDoUsuario(int $usuarioId): string
    {
        $usuario = $this->usuarios->porId($usuarioId);
        $cnpj    = Crypto::decifrarColuna($usuario['usu_documento_cif'] ?? null);

        if ($cnpj === null) {
            throw new ValidacaoException('Esta conta não tem CNPJ guardado para consultar no CREA.');
        }

        return $cnpj;
    }

    private function exigirConsentimento(int $usuarioId): void
    {
        if ($this->consentimentos->concedido($usuarioId, FINALIDADE_CONSULTA_API)) {
            return;
        }

        throw new ValidacaoException(
            'Para validar o registro da empresa no CREA precisamos da sua autorização. '
            . 'Ative "Consultar a API oficial do CREA" no painel de privacidade.',
            ['consentimento' => 'Consentimento de consulta à API não concedido.'],
        );
    }

    /**
     * @return array{situacao: string, registro_crea: ?string, razao_social: ?string,
     *               vinculos: int, vigentes: int, arts: int, aviso: ?string}
     */
    private function desfecho(
        string $situacao,
        ?string $registroCrea,
        ?string $razaoSocial,
        int $vinculos,
        int $vigentes,
        int $arts,
        ?string $aviso,
    ): array {
        return [
            'situacao'      => $situacao,
            'registro_crea' => $registroCrea,
            'razao_social'  => $razaoSocial,
            'vinculos'      => $vinculos,
            'vigentes'      => $vigentes,
            'arts'          => $arts,
            'aviso'         => $aviso,
        ];
    }
}
