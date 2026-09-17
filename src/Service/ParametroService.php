<?php

declare(strict_types=1);

namespace ProLink\Service;

use PDO;
use ProLink\Repository\ParametroRepository;
use ProLink\Support\Auditoria;
use ProLink\Support\Database;
use ProLink\Support\Parametros;

/**
 * Edição dos parâmetros do motor pelo administrador (RF06; Anexo I item 3; edital 12.3).
 *
 * ## Por que isto é requisito, e não conveniência
 *
 * O item 12.3 exige supervisão humana sobre os critérios de recomendação. Enquanto os pesos do
 * motor só existiram em `sis_parametros` sem tela, a supervisão era uma frase na documentação e
 * um `UPDATE` no banco. Aqui ela vira ato: quem muda um peso é uma pessoa identificada, o valor
 * anterior e o novo ficam na trilha de auditoria, e a mudança aparece na próxima sessão do motor
 * com a semente gravada ao lado. Auditoria do critério, não só do resultado.
 *
 * ## O lote é atômico, e um valor inválido reprova o lote inteiro
 *
 * Mesma regra da D27 e da D28: meia gravação é pior do que nenhuma. Se o administrador mandar
 * seis pesos e o quarto estiver fora da faixa, nenhum dos seis entra. A alternativa, gravar os
 * três primeiros e recusar o resto, deixaria o motor num estado que ninguém pediu, sem que a tela
 * conseguisse explicar qual.
 *
 * ## O que não é editável aqui
 *
 * Só as chaves de `Support\Parametros::editavel()`. Chave que existe no banco mas não está na
 * lista fechada, ou chave que não existe, recusa o lote — o formulário aceita de volta apenas o
 * que ele mesmo desenhou.
 */
final class ParametroService
{
    public function __construct(
        private readonly ParametroRepository $parametros = new ParametroRepository(),
    ) {
    }

    /**
     * Os parâmetros como a tela precisa deles: valor atual, faixa, e se dá para editar.
     *
     * @return list<array<string, mixed>>
     */
    public function listar(): array
    {
        $saida = [];

        foreach ($this->parametros->todos() as $linha) {
            $chave = (string) $linha['par_chave'];

            $saida[] = $linha + [
                'editavel' => Parametros::editavel($chave),
                'limite'   => Parametros::limite($chave),
                'grupo'    => Parametros::grupo((string) $linha['par_grupo']),
            ];
        }

        return $saida;
    }

    /**
     * Grava o lote de alterações e devolve as chaves que mudaram de fato.
     *
     * Chave cujo valor chega igual ao gravado é ignorada em silêncio: não é erro, e gravar assim
     * mesmo produziria linha de auditoria dizendo que alguém mudou 0.35 para 0.35. Isso também
     * evita a armadilha do `rowCount()`, que no MariaDB conta linha **alterada** e devolveria
     * zero para uma gravação idêntica, indistinguível de falha.
     *
     * @param  array<string, mixed> $entrada chave do parâmetro => valor cru do formulário
     * @return list<string>                  as chaves efetivamente alteradas
     * @throws ValidacaoException
     */
    public function salvar(int $administradorId, array $entrada): array
    {
        $mudancas = $this->validar($entrada);

        if ($mudancas === []) {
            return [];
        }

        Database::transacao(function (PDO $pdo) use ($mudancas, $administradorId): void {
            $repositorio = new ParametroRepository($pdo);

            foreach ($mudancas as $chave => $mudanca) {
                if (!$repositorio->atualizar($chave, $mudanca['depois'])) {
                    // A chave existia na leitura e sumiu antes da escrita: alguém a desativou no
                    // meio do caminho. Derruba a transação inteira em vez de seguir com o resto.
                    throw new ValidacaoException(
                        'O parâmetro "' . $chave . '" não pôde ser gravado. Recarregue a página e tente de novo.'
                    );
                }

                Auditoria::registrar(
                    Auditoria::EDITAR,
                    'sis_parametros',
                    (int) $mudanca['id'],
                    $chave,
                    $mudanca['antes'],
                    $mudanca['depois'],
                    $administradorId,
                    $pdo,
                );
            }
        });

        // O cache dura uma requisição e é estático por processo: sem isto, a própria resposta que
        // confirma a gravação ainda leria os valores antigos.
        ParametroRepository::esquecer();

        return array_keys($mudancas);
    }

    /**
     * Confere o lote inteiro antes de gravar qualquer coisa.
     *
     * @param  array<string, mixed> $entrada
     * @return array<string, array{id: int, antes: string, depois: string}>
     * @throws ValidacaoException
     */
    private function validar(array $entrada): array
    {
        $erros    = [];
        $mudancas = [];

        foreach ($entrada as $chave => $valorCru) {
            $chave = (string) $chave;

            if (!Parametros::editavel($chave)) {
                $erros[$chave] = 'Este parâmetro não pode ser alterado pela tela.';
                continue;
            }

            $atual = $this->parametros->porChave($chave);

            if ($atual === null) {
                $erros[$chave] = 'Parâmetro não encontrado.';
                continue;
            }

            try {
                $valor = $chave === Parametros::AFINIDADE_NIVEIS
                    ? $this->normalizarNiveis($valorCru)
                    : $this->normalizarNumero($chave, $valorCru);
            } catch (ValidacaoException $e) {
                $erros[$chave] = $e->getMessage();
                continue;
            }

            if ($valor === (string) $atual['par_valor']) {
                continue;
            }

            $mudancas[$chave] = [
                'id'     => (int) $atual['par_id'],
                'antes'  => (string) $atual['par_valor'],
                'depois' => $valor,
            ];
        }

        if ($erros !== []) {
            throw new ValidacaoException(
                'Nenhum parâmetro foi alterado: ' . count($erros) . ' valor(es) fora do aceitável.',
                $erros,
            );
        }

        return $mudancas;
    }

    /**
     * Número dentro da faixa declarada, devolvido na forma em que fica gravado.
     *
     * Aceita vírgula decimal porque o teclado brasileiro a produz e recusar isso seria erro de
     * interface disfarçado de validação. A casa decimal gravada segue o passo do parâmetro, para
     * o valor de volta na tela ser o mesmo que o administrador digitou.
     *
     * @throws ValidacaoException
     */
    private function normalizarNumero(string $chave, mixed $valorCru): string
    {
        $limite = Parametros::limite($chave);

        if ($limite === null) {
            throw new ValidacaoException('Este parâmetro não tem faixa declarada.');
        }

        if (is_array($valorCru)) {
            throw new ValidacaoException('Valor inválido.');
        }

        $texto = trim(str_replace(',', '.', (string) $valorCru));

        if ($texto === '' || !is_numeric($texto)) {
            throw new ValidacaoException('Informe um número.');
        }

        $numero = (float) $texto;

        if ($numero < $limite['min'] || $numero > $limite['max']) {
            throw new ValidacaoException(sprintf(
                'Fora da faixa aceita, de %s a %s.',
                self::formatar($limite['min'], $limite['inteiro']),
                self::formatar($limite['max'], $limite['inteiro']),
            ));
        }

        if ($limite['inteiro'] && floor($numero) !== $numero) {
            throw new ValidacaoException('Informe um número inteiro.');
        }

        return self::formatar($numero, $limite['inteiro']);
    }

    /**
     * Os cinco níveis de afinidade do código TOS.
     *
     * Aceita tanto a forma gravada (`[0.00,0.15,0.40,0.75,1.00]`) quanto cinco campos separados,
     * porque a tela pode escolher qualquer uma das duas e o serviço não deve amarrar o desenho.
     *
     * Além da faixa, exige que a sequência **não decresça**: cada posição é "quantos componentes
     * iniciais do código TOS coincidem", e mais coincidência valendo menos que menos coincidência
     * inverteria o significado do motor sem que nada quebrasse visivelmente.
     *
     * @throws ValidacaoException
     */
    private function normalizarNiveis(mixed $valorCru): string
    {
        $partes = is_array($valorCru)
            ? array_values($valorCru)
            : explode(',', trim((string) $valorCru, " \t\n\r\0\x0B[]"));

        if (count($partes) !== Parametros::AFINIDADE_QUANTIDADE) {
            throw new ValidacaoException(
                'São exatamente ' . Parametros::AFINIDADE_QUANTIDADE . ' níveis, do nenhum ao idêntico.'
            );
        }

        $niveis   = [];
        $anterior = -1.0;

        foreach ($partes as $parte) {
            if (is_array($parte)) {
                throw new ValidacaoException('Valor inválido.');
            }

            $texto = trim(str_replace(',', '.', (string) $parte));

            if ($texto === '' || !is_numeric($texto)) {
                throw new ValidacaoException('Todos os cinco níveis precisam ser números.');
            }

            $numero = (float) $texto;

            if ($numero < 0.0 || $numero > 1.0) {
                throw new ValidacaoException('Cada nível vai de 0 a 1.');
            }

            if ($numero < $anterior) {
                throw new ValidacaoException(
                    'Os níveis não podem diminuir: mais coincidência de código precisa valer pelo menos o mesmo.'
                );
            }

            $anterior = $numero;
            $niveis[] = number_format($numero, 2, '.', '');
        }

        return '[' . implode(',', $niveis) . ']';
    }

    private static function formatar(float $numero, bool $inteiro): string
    {
        return $inteiro
            ? (string) (int) $numero
            : number_format($numero, 2, '.', '');
    }
}
