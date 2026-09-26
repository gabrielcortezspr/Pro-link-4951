<?php

declare(strict_types=1);

namespace ProLink\Support;

/**
 * A mensagem que acompanha o convite (D88): um modelo por conta, com marcadores preenchidos a
 * cada convite.
 *
 * O convite nasceu sem mensagem nenhuma: o backend aceitava o texto, mas o botão do feed não
 * tinha onde escrevê-lo, e todo convite chegava vazio. Agora a empresa tem um modelo (o padrão da
 * plataforma, ou o que ela salvar) e, a cada convite, a mensagem vem montada e editável.
 *
 * Três marcadores, os que a tela sabe preencher: `{nome}` (o primeiro nome de quem é convidado),
 * `{demanda}` (o título) e `{local}` (município/UF da obra, ou só a UF). Marcador que o modelo não
 * usa não aparece; marcador sem dado vira texto vazio, e o espaço duplo que sobraria é recolhido.
 */
final class ModeloConvite
{
    public const PADRAO = 'Olá, {nome}. Vimos o seu perfil e gostaríamos de conversar sobre a demanda '
        . '"{demanda}". Se tiver interesse, responda por aqui.';

    public const TAMANHO_MAXIMO = 1000;

    public const MARCADORES = ['{nome}', '{demanda}', '{local}'];

    /** O sufixo de natureza jurídica no fim da razão social, que num vocativo sobra. */
    private const NATUREZA_JURIDICA = '/[\s,.-]+(ltda|s\.?\s?\/?\s?a|eireli|epp|me|mei|s\/s|ss)\.?$/iu';

    /**
     * O modelo com os marcadores preenchidos.
     *
     * @param array{nome?: ?string, empresa?: bool, demanda?: ?string, local?: ?string} $dados
     *        `nome` como está no cadastro; `empresa` diz se é razão social
     */
    public static function montar(?string $modelo, array $dados): string
    {
        $modelo = self::efetivo($modelo);

        $texto = strtr($modelo, [
            '{nome}'    => self::primeiroNome($dados['nome'] ?? null, (bool) ($dados['empresa'] ?? false)),
            '{demanda}' => trim((string) ($dados['demanda'] ?? '')),
            '{local}'   => trim((string) ($dados['local'] ?? '')),
        ]);

        // Marcador sem dado deixa espaço duplo ou vírgula solta ("Olá, ."), que não se escreve.
        $texto = (string) preg_replace('/[ \t]{2,}/', ' ', $texto);
        $texto = (string) preg_replace('/\s+([,.;!?])/', '$1', $texto);
        $texto = (string) preg_replace('/,([.;!?])/', '$1', $texto);

        return trim($texto);
    }

    /** O modelo que vale: o salvo, ou o padrão quando não há nenhum. */
    public static function efetivo(?string $modelo): string
    {
        $modelo = trim((string) $modelo);

        return $modelo === '' ? self::PADRAO : $modelo;
    }

    /**
     * O primeiro nome, com caixa de nome próprio: "ARTHUR GOMES" vira "Arthur". Empresa entra
     * pelo nome inteiro, porque "Rio" de "Rio Negro Engenharia" não é o primeiro nome de ninguém,
     * e sem a natureza jurídica: "Olá, Base Sólida Construções.", não "Olá, Base Sólida Ltda.".
     */
    public static function primeiroNome(?string $nome, bool $ehEmpresa = false): string
    {
        $nome = trim((string) $nome);

        if ($nome === '') {
            return '';
        }

        // Pela caixa alta: `nomeProprio` acerta o nome que chega em maiúsculas (é como a API e o
        // cadastro o gravam), e assim "sophia martins" também sai como "Sophia".
        $proprio = Rotulos::nomeProprio(mb_strtoupper($nome, 'UTF-8'));

        if (!$ehEmpresa) {
            return (string) strtok($proprio, ' ');
        }

        $semSufixo = trim((string) preg_replace(self::NATUREZA_JURIDICA, '', $proprio));

        return $semSufixo === '' ? $proprio : $semSufixo;
    }
}
