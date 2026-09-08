<?php

declare(strict_types=1);

namespace ProLink\Support;

/**
 * Validação e exibição de CPF e CNPJ.
 *
 * Por que o CNPJ não tem o dígito verificador exigido:
 *
 * A massa fictícia da organização respeita o DV de CPF (100 de 100 conferem) mas não o de CNPJ
 * (15 de 100 conferem — `00123001000123` é o primeiro a falhar). Exigir o DV de CNPJ no cadastro
 * tornaria 85 das 100 empresas do desafio incadastráveis, e a plataforma indemonstrável.
 *
 * A decisão, então: formato é barreira, DV de CNPJ é só sinal. Quem diz se o documento existe de
 * verdade é a API oficial na E2 — `empresaPorCnpj` devolvendo null é a reprovação que importa.
 * Um DV local nunca substituiria essa consulta, então não faz sentido ele barrar antes dela.
 *
 * O documento sempre circula como string: `00123001000123` tem zero à esquerda, e virar número
 * quebra a requisição à API.
 */
final class Documento
{
    public const TIPO_FISICA   = 'F';
    public const TIPO_JURIDICA = 'J';

    /** CPF: 11 dígitos, não todos iguais, e dígito verificador conferindo. */
    public static function ehCpf(string $valor): bool
    {
        $cpf = Crypto::apenasDigitos($valor);

        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf) === 1) {
            return false;
        }

        return self::dvCpfConfere($cpf);
    }

    /**
     * CNPJ: 14 dígitos e não todos iguais. O DV não entra — ver o cabeçalho desta classe.
     * Use cnpjDvConfere() quando quiser o sinal sem transformá-lo em barreira.
     */
    public static function ehCnpj(string $valor): bool
    {
        $cnpj = Crypto::apenasDigitos($valor);

        return strlen($cnpj) === 14 && preg_match('/^(\d)\1{13}$/', $cnpj) !== 1;
    }

    /** Valida conforme o tipo de pessoa do cadastro: 'F' exige CPF, 'J' exige CNPJ. */
    public static function valido(string $valor, string $tipoPessoa): bool
    {
        return $tipoPessoa === self::TIPO_JURIDICA
            ? self::ehCnpj($valor)
            : self::ehCpf($valor);
    }

    /** Sinal informativo, nunca barreira de cadastro. */
    public static function cnpjDvConfere(string $valor): bool
    {
        $cnpj = Crypto::apenasDigitos($valor);

        if (strlen($cnpj) !== 14) {
            return false;
        }

        $pesos1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $pesos2 = array_merge([6], $pesos1);

        return $cnpj[12] === self::digitoPorPesos(substr($cnpj, 0, 12), $pesos1)
            && $cnpj[13] === self::digitoPorPesos(substr($cnpj, 0, 13), $pesos2);
    }

    /**
     * Mascara para exibição: só os extremos aparecem (edital 11.3 — minimização na interface).
     * CPF `12312300109` sai como `123.***.**1-09`; CNPJ mostra só os dois primeiros e os
     * quatro últimos dígitos.
     */
    public static function mascarar(string $valor): string
    {
        $digitos = Crypto::apenasDigitos($valor);
        $tamanho = strlen($digitos);

        if ($tamanho === 11) {
            return sprintf('%s.***.**%s-%s',
                substr($digitos, 0, 3), substr($digitos, 8, 1), substr($digitos, 9, 2));
        }

        if ($tamanho === 14) {
            return sprintf('%s.***.***/**%s-%s',
                substr($digitos, 0, 2), substr($digitos, 10, 2), substr($digitos, 12, 2));
        }

        return str_repeat('*', max($tamanho, 3));
    }

    /** Formatação completa. Só para quem tem direito ao dado inteiro: o próprio titular. */
    public static function formatar(string $valor): string
    {
        $digitos = Crypto::apenasDigitos($valor);

        if (strlen($digitos) === 11) {
            return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $digitos) ?? $digitos;
        }

        if (strlen($digitos) === 14) {
            return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $digitos) ?? $digitos;
        }

        return $digitos;
    }

    private static function dvCpfConfere(string $cpf): bool
    {
        return $cpf[9] === self::digitoCpf(substr($cpf, 0, 9), 10)
            && $cpf[10] === self::digitoCpf(substr($cpf, 0, 10), 11);
    }

    private static function digitoCpf(string $base, int $pesoInicial): string
    {
        $soma = 0;

        foreach (str_split($base) as $posicao => $digito) {
            $soma += (int) $digito * ($pesoInicial - $posicao);
        }

        $resto = ($soma * 10) % 11;

        return (string) ($resto === 10 ? 0 : $resto);
    }

    private static function digitoPorPesos(string $base, array $pesos): string
    {
        $soma = 0;

        foreach (str_split($base) as $posicao => $digito) {
            $soma += (int) $digito * $pesos[$posicao];
        }

        $resto = $soma % 11;

        return (string) ($resto < 2 ? 0 : 11 - $resto);
    }
}
