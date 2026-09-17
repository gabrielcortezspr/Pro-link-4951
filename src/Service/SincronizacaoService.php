<?php

declare(strict_types=1);

namespace ProLink\Service;

use ProLink\Repository\ParametroRepository;
use ProLink\Repository\ProfissionalRepository;
use ProLink\Support\Auditoria;
use ProLink\Support\Crypto;

/**
 * Reconsulta na API oficial a situação de quem já está cadastrado (RF02; edital 8.4).
 *
 * ## Por que isto existe
 *
 * `pro_status` é a única informação do CREA que muda sozinha depois do cadastro: um registro
 * ativo hoje pode estar suspenso amanhã, e a plataforma inteira se apoia nele para decidir se o
 * perfil aparece. Sem reconsulta, o selo continuaria verde por tempo indeterminado sobre um
 * registro que o conselho já suspendeu, que é exatamente o que a tese de evidência documental não
 * pode deixar acontecer.
 *
 * ## O que a separa de uma varredura
 *
 * O item 10.4 veda coleta automatizada, e a diferença entre sincronizar e varrer não é a
 * intenção, é o desenho:
 *
 *   · **só quem já é cadastro nosso** é consultado, nunca um identificador que alguém supôs;
 *   · **só quem consentiu** com a consulta à API (LGPD, e o consentimento é revogável);
 *   · **só quem venceu** o intervalo de `api.sincronizacao.horas`;
 *   · **com teto obrigatório** de registros por execução, sem valor padrão infinito;
 *   · e a execução **para na primeira indisponibilidade**, em vez de insistir em rajada.
 *
 * ## O que ela faz quando o registro não está mais ativo
 *
 * Fecha a visibilidade inteira do perfil e registra. Não rebaixa a conta para Terceiro nem apaga
 * acervo: suspensão de registro pode ser temporária, e destruir o vínculo por causa de uma
 * leitura seria irreversível a partir de um dado que muda. Fechar visibilidade é reversível e
 * suficiente, porque é o que impede o perfil de circular enquanto a situação não se resolve.
 */
final class SincronizacaoService
{
    /** Teto absoluto por execução, mesmo que alguém peça mais na linha de comando. */
    public const LIMITE_MAXIMO = 50;

    public function __construct(
        private readonly ProfissionalRepository $profissionais = new ProfissionalRepository(),
        private ?CreaApiClient $api = null,
        private readonly VisibilidadeService $visibilidade = new VisibilidadeService(),
        private readonly ParametroRepository $parametros = new ParametroRepository(),
    ) {
    }

    /**
     * Roda uma passada de sincronização.
     *
     * Em modo simulação nenhuma chamada à API acontece e nada é gravado: serve para ver quem
     * entraria na fila sem gastar chamada registrada pela organização, que é o que se quer numa
     * conferência de véspera de entrega.
     *
     * @return array{
     *     intervalo_horas: int,
     *     candidatos: int,
     *     consultados: int,
     *     atualizados: int,
     *     suspensos: int,
     *     sem_consentimento: int,
     *     sem_documento: int,
     *     sem_registro: int,
     *     interrompido: string|null,
     *     linhas: list<array<string, mixed>>
     * }
     */
    public function executar(int $limite, bool $simular = false): array
    {
        $horas  = max(1, (int) $this->parametros->inteiro('api.sincronizacao.horas', 24));
        $limite = max(1, min($limite, self::LIMITE_MAXIMO));

        $candidatos = $this->profissionais->vencidos($horas, $limite);

        $relatorio = [
            'intervalo_horas'   => $horas,
            'candidatos'        => count($candidatos),
            'consultados'       => 0,
            'atualizados'       => 0,
            'suspensos'         => 0,
            'sem_consentimento' => 0,
            'sem_documento'     => 0,
            'sem_registro'      => 0,
            'interrompido'      => null,
            'linhas'            => [],
        ];

        foreach ($candidatos as $candidato) {
            $usuarioId = (int) $candidato['prf_usu_id'];
            $perfilId  = (int) $candidato['prf_id'];
            $nome      = (string) $candidato['usu_nome'];

            if ((int) $candidato['consentiu'] !== 1) {
                $relatorio['sem_consentimento']++;
                $relatorio['linhas'][] = $this->linha($nome, 'sem consentimento de consulta à API');
                continue;
            }

            $cpf = Crypto::decifrarColuna($candidato['usu_documento_cif'] ?? null);

            if ($cpf === null || $cpf === '') {
                $relatorio['sem_documento']++;
                $relatorio['linhas'][] = $this->linha($nome, 'sem CPF guardado para consultar');
                continue;
            }

            if ($simular) {
                $relatorio['linhas'][] = $this->linha($nome, 'seria consultado');
                continue;
            }

            try {
                $daApi = $this->cliente()->profissionalPorCpf($cpf);
            } catch (ApiIndisponivelException $e) {
                // Para aqui. Se a API não responde, insistir com o resto da fila só produz uma
                // rajada de falhas registrada do lado da organização.
                $relatorio['interrompido'] = $e->getMessage();
                break;
            }

            $relatorio['consultados']++;

            $statusApi = $daApi === null ? null : (string) ($daApi['pro_status'] ?? '');
            $this->profissionais->atualizarStatusApi($perfilId, $statusApi === '' ? null : $statusApi);
            $relatorio['atualizados']++;

            if ($daApi === null) {
                $relatorio['sem_registro']++;
                $relatorio['linhas'][] = $this->linha($nome, 'não encontrado mais na API');
                $this->suspender($usuarioId, $perfilId, 'registro não encontrado na API do CREA', $statusApi);
                $relatorio['suspensos']++;
                continue;
            }

            if ($statusApi !== STATUS_ATIVO) {
                $this->suspender(
                    $usuarioId,
                    $perfilId,
                    'situação do registro no CREA deixou de ser ativa',
                    $statusApi,
                );
                $relatorio['suspensos']++;
                $relatorio['linhas'][] = $this->linha(
                    $nome,
                    'situação ' . ($statusApi ?? 'desconhecida') . ': visibilidade fechada',
                );
                continue;
            }

            $relatorio['linhas'][] = $this->linha($nome, 'ativo, situação conferida');
        }

        return $relatorio;
    }

    /**
     * Fecha a visibilidade do perfil e registra o motivo na trilha.
     *
     * O autor do registro é o próprio titular, e não um administrador: ninguém decidiu isto na
     * plataforma, foi a situação no conselho que mudou. A trilha do titular é onde ele encontra a
     * explicação de por que o perfil parou de aparecer.
     */
    private function suspender(int $usuarioId, int $perfilId, string $motivo, ?string $statusApi): void
    {
        $this->visibilidade->fecharTudo($usuarioId, $motivo);

        Auditoria::registrar(
            Auditoria::CONSULTA_API,
            'pro_profissionais',
            $perfilId,
            'prf_status_api',
            null,
            ['situacao' => $statusApi, 'efeito' => 'visibilidade fechada', 'motivo' => $motivo],
            $usuarioId,
        );
    }

    /**
     * O cliente da API, construído só quando a primeira consulta vai acontecer.
     *
     * `TransporteCurl` lança se `PROLINK_API_TOKEN` faltar, e construí-lo no construtor faria a
     * simulação, que não chama a API, exigir token para rodar.
     */
    private function cliente(): CreaApiClient
    {
        return $this->api ??= new CreaApiClient();
    }

    /** @return array{nome: string, resultado: string} */
    private function linha(string $nome, string $resultado): array
    {
        return ['nome' => $nome, 'resultado' => $resultado];
    }
}
