<?php

declare(strict_types=1);

namespace ProLink\Service;

use DateTimeImmutable;
use ProLink\Repository\AuditoriaRepository;
use ProLink\Repository\ParametroRepository;
use ProLink\Support\Auditoria;
use ProLink\Support\DesfechoCrea;
use ProLink\Support\JanelaDeAtualizacao;
use Throwable;

/**
 * O botão "Atualizar meu acervo no CREA", com a espera entre um pedido e outro (D77).
 *
 * Cada pedido refaz o vínculo com o CREA (perfil, ARTs e CATs), e isso são várias chamadas
 * registradas à API oficial. Três proteções, e as três moram aqui e não na tela, porque um POST
 * repetido por fora da interface passaria por qualquer botão desabilitado:
 *
 * 1. **Janela de espera**, medida pela última tentativa na trilha de auditoria (que é
 *    insert-only, então ninguém zera a própria espera): longa depois de uma tentativa concluída,
 *    curta depois de uma em que a API não respondeu. A regra está em `JanelaDeAtualizacao`.
 * 2. **Trava contra clique duplo**: uma trava nomeada do banco por titular. O segundo pedido que
 *    chega enquanto o primeiro roda é recusado na hora, sem consultar nada.
 * 3. **Toda tentativa entra na trilha** com o resultado, inclusive a que falhou por exceção. É
 *    a trilha que alimenta a janela.
 */
final class AtualizacaoAcervoService
{
    private const MINUTOS_PADRAO       = 60;
    private const MINUTOS_FALHA_PADRAO = 5;

    public function __construct(
        private readonly AuditoriaRepository $auditoria = new AuditoriaRepository(),
        private readonly ParametroRepository $parametros = new ParametroRepository(),
    ) {
    }

    /** Quando o titular pode pedir de novo, ou null se já pode. */
    public function liberadaEm(int $usuarioId, ?DateTimeImmutable $agora = null): ?DateTimeImmutable
    {
        return $this->janela($usuarioId, $agora)['libera'];
    }

    /**
     * A janela inteira: quando libera e como terminou a última tentativa. A tela usa as duas
     * coisas, porque "atualizado há pouco" e "o CREA não respondeu" pedem frases diferentes.
     *
     * @return array{libera: ?DateTimeImmutable, resultado: ?string, ultima: ?DateTimeImmutable}
     */
    public function janela(int $usuarioId, ?DateTimeImmutable $agora = null): array
    {
        $ultima = $this->auditoria->ultimaDoUsuario($usuarioId, Auditoria::ATUALIZAR_ACERVO);

        if ($ultima === null) {
            return ['libera' => null, 'resultado' => null, 'ultima' => null];
        }

        $detalhe   = json_decode((string) ($ultima['aud_valor_novo'] ?? ''), true);
        $resultado = is_array($detalhe) ? ($detalhe['resultado'] ?? null) : null;
        $quando    = new DateTimeImmutable((string) $ultima['aud_dt_registro']);

        return [
            'libera'    => JanelaDeAtualizacao::liberadaEm(
                $quando,
                $resultado,
                $this->parametros->inteiro('api.atualizacao.minutos', self::MINUTOS_PADRAO),
                $this->parametros->inteiro('api.atualizacao.minutos_falha', self::MINUTOS_FALHA_PADRAO),
                $agora ?? new DateTimeImmutable(),
            ),
            'resultado' => $resultado,
            'ultima'    => $quando,
        ];
    }

    /**
     * Roda a atualização se a janela e a trava deixarem, e registra a tentativa.
     *
     * `$operacao` é o vínculo com o CREA do profissional ou da empresa, e devolve o desfecho de
     * `DesfechoCrea`. A tentativa conta como falha quando a API não respondeu (desfecho
     * `API_INDISPONIVEL`, ou vínculo feito com o acervo pela metade, que volta com aviso) ou
     * quando a operação lança; qualquer outro desfecho, inclusive registro que o CREA não
     * reconhece, é uma consulta concluída.
     *
     * @param callable(): array<string, mixed> $operacao
     * @return array<string, mixed> o desfecho da operação
     * @throws ValidacaoException quando ainda está na janela, ou outro pedido está em andamento
     */
    public function executar(int $usuarioId, callable $operacao): array
    {
        $trava = 'prolink_atualizar_acervo_' . $usuarioId;

        if (!$this->auditoria->travar($trava)) {
            throw new ValidacaoException(
                'Sua atualização já está em andamento. Aguarde terminar antes de pedir de novo.'
            );
        }

        try {
            $janela = $this->janela($usuarioId);

            if ($janela['libera'] !== null) {
                throw new ValidacaoException(sprintf(
                    $janela['resultado'] === JanelaDeAtualizacao::FALHOU
                        ? 'Na última tentativa o CREA não respondeu. Você pode tentar de novo a partir das %s.'
                        : 'Seu acervo foi atualizado às %s. Você pode pedir de novo a partir das %s.',
                    ...($janela['resultado'] === JanelaDeAtualizacao::FALHOU
                        ? [$janela['libera']->format('H:i')]
                        : [$janela['ultima']->format('H:i'), $janela['libera']->format('H:i')]),
                ));
            }

            try {
                $desfecho = $operacao();
            } catch (Throwable $e) {
                $this->registrar($usuarioId, JanelaDeAtualizacao::FALHOU);

                throw $e;
            }

            $falhou = ($desfecho['situacao'] ?? null) === DesfechoCrea::API_INDISPONIVEL
                || (($desfecho['situacao'] ?? null) === DesfechoCrea::VINCULADO && ($desfecho['aviso'] ?? null) !== null);

            $this->registrar($usuarioId, $falhou ? JanelaDeAtualizacao::FALHOU : JanelaDeAtualizacao::CONCLUIDA);

            return $desfecho;
        } finally {
            $this->auditoria->destravar($trava);
        }
    }

    private function registrar(int $usuarioId, string $resultado): void
    {
        Auditoria::registrar(
            Auditoria::ATUALIZAR_ACERVO, 'sis_usuarios', $usuarioId, null, null,
            ['resultado' => $resultado], $usuarioId,
        );
    }
}
