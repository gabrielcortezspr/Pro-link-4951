<?php

declare(strict_types=1);

/**
 * Valida os documentos da massa fictícia do desafio contra Support\Documento.
 *
 * Por que existe: a massa tem CNPJ com dígito verificador quebrado. A plataforma valida
 * corretamente — então parte das empresas da massa simplesmente não se cadastra, e é preciso
 * saber quais antes de escrever qualquer cenário de demonstração. Este script é a fonte daquela
 * lista. A contagem em si é travada por tests/Dados/MassaDeDadosTest.php.
 *
 * Usa a mesma classe que o formulário de cadastro usa, de propósito: se a regra mudar, o
 * relatório muda com ela, sem segunda implementação para sair de sincronia.
 *
 *     php scripts/validar-massa.php            relatório na tela
 *     php scripts/validar-massa.php --csv      só os válidos, em CSV, para redirecionar
 */

require_once dirname(__DIR__) . '/_config.php';

use ProLink\Support\Documento;

$somenteCsv = in_array('--csv', $argv, true);

/** @return list<array<string,string>> */
function lerCsv(string $caminho): array
{
    $arquivo = fopen($caminho, 'rb');

    if ($arquivo === false) {
        fwrite(STDERR, "Não consegui ler {$caminho}\n");
        exit(1);
    }

    $cabecalho = fgetcsv($arquivo, null, ',', '"', '');
    $linhas    = [];

    while (($campos = fgetcsv($arquivo, null, ',', '"', '')) !== false) {
        if ($campos === [null] || $campos === []) {
            continue;
        }

        $linhas[] = array_combine($cabecalho, $campos);
    }

    fclose($arquivo);

    return $linhas;
}

$profissionais = lerCsv(PATH_DATA . '/csv/profissionais.csv');
$empresas      = lerCsv(PATH_DATA . '/csv/empresas.csv');

$cpfsValidos    = array_values(array_filter($profissionais, fn (array $p) => Documento::ehCpf($p['cpf'])));
$cpfsInvalidos  = array_values(array_filter($profissionais, fn (array $p) => !Documento::ehCpf($p['cpf'])));
$cnpjsValidos   = array_values(array_filter($empresas, fn (array $e) => Documento::ehCnpj($e['cnpj'])));
$cnpjsInvalidos = array_values(array_filter($empresas, fn (array $e) => !Documento::ehCnpj($e['cnpj'])));

// ---------------------------------------------------------------- saída CSV, para alimentar script
if ($somenteCsv) {
    $saida = fopen('php://output', 'wb');
    fputcsv($saida, ['cnpj', 'razao_social', 'nome_fantasia', 'registro_crea'], ',', '"', '');

    foreach ($cnpjsValidos as $e) {
        fputcsv($saida, [$e['cnpj'], $e['razao_social'], $e['nome_fantasia'], $e['registro_crea']], ',', '"', '');
    }

    fclose($saida);
    exit(0);
}

// ---------------------------------------------------------------- relatório
printf("Profissionais: %d CPF, %d válidos, %d recusados\n",
    count($profissionais), count($cpfsValidos), count($cpfsInvalidos));
printf("Empresas:      %d CNPJ, %d válidos, %d recusados\n\n",
    count($empresas), count($cnpjsValidos), count($cnpjsInvalidos));

if ($cpfsInvalidos !== []) {
    echo "CPFs recusados (não deveria haver nenhum):\n";

    foreach ($cpfsInvalidos as $p) {
        printf("  %s  %s\n", $p['cpf'], $p['nome']);
    }

    echo "\n";
}

echo "Empresas cadastráveis — use só estas nos cenários de demonstração:\n\n";
printf("  %-16s %-10s %s\n", 'CNPJ', 'REG. CREA', 'RAZÃO SOCIAL');

foreach ($cnpjsValidos as $e) {
    printf("  %-16s %-10s %s\n", $e['cnpj'], $e['registro_crea'], $e['razao_social']);
}

printf("\n%d das %d empresas da massa têm CNPJ com dígito verificador quebrado e são\n", count($cnpjsInvalidos), count($empresas));
echo "recusadas no cadastro. É defeito da massa fictícia, não da validação.\n";
