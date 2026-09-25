<?php

declare(strict_types=1);

namespace ProLink\Support;

/**
 * Teto de requisições por IP nas rotas públicas que devolvem dado de profissional (D83).
 *
 * A busca é a única tela aberta a quem não tem conta (Anexo I item 3), e o perfil público é o
 * passo seguinte dela. Sem teto, um script consegue percorrer as duas em minutos e levar o
 * cadastro inteiro, que é a coleta automatizada que o item 10.4 veda e o risco de exposição que a
 * proposta prometeu tratar. A visibilidade por campo decide O QUE sai; este limite decide QUANTO.
 *
 * **Janela deslizante com registro de marcas.** Cada chave (rota + IP) guarda os instantes das
 * requisições aceitas nos últimos `$janelaSegundos`. Janela fixa por minuto cheio deixaria passar
 * o dobro do teto na virada (30 no fim de um minuto, 30 no começo do outro); com a marca de cada
 * pedido a conta é exata, e o teto pequeno faz o arquivo ter no máximo algumas dezenas de números.
 *
 * **Sem tabela nova.** A contagem mora em `storage/cache/limite/`, um arquivo por chave, com
 * `flock` exclusivo durante a leitura e a escrita: duas requisições simultâneas do mesmo IP não
 * leem a mesma contagem. O nome do arquivo é o SHA-256 da chave, então o IP não fica em claro no
 * disco. É estado descartável: apagar a pasta só zera as contagens, não perde dado de ninguém.
 *
 * **Recusa não conta.** Quem já estourou e insiste não empurra a própria espera para a frente, e
 * o Retry-After que a tela mostra é a verdade: passado aquele tempo, a próxima busca passa.
 *
 * **Na falha de disco, libera.** Se a pasta não puder ser criada ou o arquivo não abrir (clone
 * limpo com permissão diferente, disco cheio), a requisição segue e a falha vai para o log. A
 * alternativa, recusar tudo, derrubaria a busca pública por um problema de infraestrutura, e o
 * item 8.8 pede que o ambiente suba e funcione a partir de um clone limpo.
 */
final class LimiteDeRequisicoes
{
    /**
     * Busca pública: 30 por minuto por IP. Uma pessoa refinando o termo faz poucas buscas por
     * minuto, e a demonstração faz menos de dez; 30 é folga de três vezes sobre o uso humano
     * intenso, e ainda assim corta um coletor para 1.800 páginas por hora em vez de milhares por
     * minuto.
     */
    public const BUSCA_MAXIMO = 30;

    /**
     * Perfil público por identificador: 60 por minuto por IP. É por ele que se enumera o cadastro
     * (`/perfil/1`, `/perfil/2`...), mas quem lê perfis de verdade abre um a cada vários
     * segundos. O dobro da busca porque cada busca leva a vários perfis.
     */
    public const PERFIL_MAXIMO = 60;

    public const JANELA_SEGUNDOS = 60;

    /** Um pedido em cada tantos varre arquivos velhos da pasta, para ela não crescer sem fim. */
    private const LIMPEZA_A_CADA = 200;

    public function __construct(
        private readonly string $diretorio,
        private readonly int $maximo,
        private readonly int $janelaSegundos = self::JANELA_SEGUNDOS,
    ) {
    }

    /** O limitador da busca pública, com a pasta padrão da aplicação. */
    public static function daBusca(): self
    {
        return new self(PATH_CACHE . '/limite', self::BUSCA_MAXIMO);
    }

    /** O limitador do perfil público por identificador. */
    public static function doPerfil(): self
    {
        return new self(PATH_CACHE . '/limite', self::PERFIL_MAXIMO);
    }

    /**
     * Conta a requisição da chave e diz se ela passa.
     *
     * @return int 0 quando passa; senão, os segundos até a próxima passar (o Retry-After).
     */
    public function consumir(string $chave, ?int $agora = null): int
    {
        $agora ??= time();

        if (!is_dir($this->diretorio) && !@mkdir($this->diretorio, 0775, true) && !is_dir($this->diretorio)) {
            error_log('LimiteDeRequisicoes: pasta indisponível, requisição liberada: ' . $this->diretorio);

            return 0;
        }

        $arquivo = $this->diretorio . '/' . hash('sha256', $chave);
        $handle  = @fopen($arquivo, 'c+');

        if ($handle === false) {
            error_log('LimiteDeRequisicoes: arquivo indisponível, requisição liberada: ' . $arquivo);

            return 0;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                error_log('LimiteDeRequisicoes: trava indisponível, requisição liberada.');

                return 0;
            }

            $marcas = json_decode((string) stream_get_contents($handle), true);
            $marcas = is_array($marcas) ? array_map('intval', $marcas) : [];

            [$marcas, $espera] = self::decidir($marcas, $agora, $this->maximo, $this->janelaSegundos);

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($marcas));
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        if (random_int(1, self::LIMPEZA_A_CADA) === 1) {
            $this->limparVelhos($agora);
        }

        return $espera;
    }

    /**
     * A regra, sem disco: separada para o teste provar a conta sem depender de arquivo.
     *
     * @param  list<int> $marcas instantes das requisições já aceitas
     * @return array{0: list<int>, 1: int} as marcas a gravar e a espera (0 = passou)
     */
    public static function decidir(array $marcas, int $agora, int $maximo, int $janelaSegundos): array
    {
        // Só vale o que caiu dentro da janela. Marca no futuro (relógio que voltou) também sai,
        // senão prenderia a chave até o relógio alcançá-la.
        $marcas = array_values(array_filter(
            $marcas,
            static fn (int $m): bool => $m > $agora - $janelaSegundos && $m <= $agora,
        ));
        sort($marcas);

        if ($maximo <= 0 || count($marcas) < $maximo) {
            $marcas[] = $agora;

            return [$marcas, 0];
        }

        // Cheio: a próxima vaga abre quando a marca mais antiga sai da janela. Nunca menos de 1
        // segundo, porque Retry-After 0 convidaria a tentar de novo na mesma hora.
        $espera = max(1, $marcas[0] + $janelaSegundos - $agora);

        return [$marcas, $espera];
    }

    /** Remove contagens de IPs que não aparecem há mais de duas janelas. É cache, não registro. */
    private function limparVelhos(int $agora): void
    {
        foreach (glob($this->diretorio . '/*') ?: [] as $arquivo) {
            $mtime = @filemtime($arquivo);

            if ($mtime !== false && $mtime < $agora - 2 * $this->janelaSegundos) {
                @unlink($arquivo);
            }
        }
    }
}
