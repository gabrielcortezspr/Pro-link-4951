<?php

declare(strict_types=1);

/**
 * Cadastra candidatos da massa fictícia pelo fluxo real, para o motor ter o que compatibilizar.
 *
 * **Pelo fluxo real, e isso não é detalhe.** Usa os mesmos serviços que o formulário chama:
 * `AutenticacaoService::cadastrar()` e depois `PerfilCreaService::vincularProfissional()` ou
 * `EmpresaCreaService::vincularEmpresa()`. Inserir direto no banco seria mais rápido e produziria
 * candidato quebrado de três formas: sem consentimento (e perfil sem `EXIBICAO_PERFIL` concedido
 * nunca entra no pool), sem linha em `sis_auditoria`, e sem acervo, porque quem importa as ARTs é
 * o serviço de vínculo. O banco pareceria povoado e o feed continuaria vazio.
 *
 * **Consome chamada da API oficial**: no profissional, uma consulta de perfil, uma de ARTs, uma
 * da lista de CATs e uma por CAT (D76), quatro na massa; na empresa, perfil e CAO. Não é
 * varredura (item 10.4): é cadastro individual, do mesmo jeito que uma pessoa faria pelo site, e
 * por isso o script tem limite obrigatório em vez de percorrer a massa inteira.
 *
 * Uso:
 *   docker compose exec php php scripts/semear-candidatos.php --profissionais=12 --empresas=5
 *   docker compose exec php php scripts/semear-candidatos.php --simular     (não grava nem chama)
 *
 * Idempotente: documento já cadastrado é pulado, porque `documentoEmUso()` não filtra status e
 * conta excluída continua segurando o CPF dela (D15).
 */

require_once dirname(__DIR__) . '/_config.php';

use ProLink\Repository\EmpresaRepository;
use ProLink\Repository\ProfissionalRepository;
use ProLink\Repository\UsuarioRepository;
use ProLink\Service\AutenticacaoService;
use ProLink\Service\EmpresaCreaService;
use ProLink\Service\PerfilCreaService;
use ProLink\Support\Crypto;

$opcoes = getopt('', ['profissionais::', 'empresas::', 'simular', 'senha::']);

$limiteProfissionais = (int) ($opcoes['profissionais'] ?? 10);
$limiteEmpresas      = (int) ($opcoes['empresas'] ?? 4);
$simular             = isset($opcoes['simular']);
$senha               = (string) ($opcoes['senha'] ?? 'ProLinkDemo2026!');

$usuarios      = new UsuarioRepository();
$profissionais = new ProfissionalRepository();
$empresas      = new EmpresaRepository();
$autenticacao  = new AutenticacaoService();
$perfilCrea    = new PerfilCreaService();
$empresaCrea   = new EmpresaCreaService();

/** @return list<array<string, string>> */
function lerCsv(string $caminho): array
{
    $linhas = array_map('str_getcsv', file($caminho, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    $cabecalho = array_shift($linhas);

    return array_map(static fn (array $l): array => array_combine($cabecalho, $l), $linhas);
}

/** E-mail estável por documento: rodar de novo não cria conta duplicada com outro endereço. */
function emailDe(string $nome, string $documento): string
{
    $base = preg_replace('/[^a-z0-9]+/', '.', mb_strtolower(
        (string) preg_replace('/\p{Mn}/u', '', \Normalizer::normalize($nome, \Normalizer::FORM_D))
    ));

    return trim((string) $base, '.') . '.' . substr($documento, -4) . '@prolink.local';
}

printf(
    "\e[1mSemeando candidatos\e[0m  %s\n  profissionais: até %d · empresas: até %d\n\n",
    $simular ? "\e[33mSIMULAÇÃO, nada é gravado nem consultado\e[0m" : 'gravando e consultando a API',
    $limiteProfissionais,
    $limiteEmpresas,
);

$criados  = ['P' => 0, 'E' => 0];
$pulados  = 0;
$falharam = 0;

// ---------------------------------------------------------------- profissionais

foreach (lerCsv(dirname(__DIR__) . '/data/csv/profissionais.csv') as $pessoa) {
    if ($criados['P'] >= $limiteProfissionais) {
        break;
    }

    $cpf = Crypto::apenasDigitos($pessoa['cpf']);

    if ($usuarios->documentoEmUso(Crypto::hashBusca($cpf))) {
        $pulados++;
        continue;
    }

    // pro_profissionais tem UNIQUE em prf_rnp, e os scripts de verificação anteriores vincularam
    // RNPs da massa a contas sintéticas. Conferir antes de criar a conta: sem isto o cadastro
    // passa, o vínculo estoura na chave duplicada e sobra uma conta órfã segurando o CPF para
    // sempre (D15), depois de já ter gasto a chamada da API.
    if ($profissionais->porRnp($pessoa['rnp']) !== null) {
        printf("  \e[33mpula\e[0m  %-28s RNP %s já pertence a outra conta\n", substr($pessoa['nome'], 0, 28), $pessoa['rnp']);
        $pulados++;
        continue;
    }

    $email = emailDe($pessoa['nome'], $cpf);

    if ($simular) {
        printf("  \e[2msimular\e[0m  %-28s %s\n", substr($pessoa['nome'], 0, 28), $email);
        $criados['P']++;
        continue;
    }

    try {
        $usuarioId = $autenticacao->cadastrar([
            'tipo_cadastro'      => CADASTRO_PROFISSIONAL,
            'nome'               => $pessoa['nome'],
            'email'              => $email,
            'senha'              => $senha,
            'senha_confirmacao'  => $senha,
            'documento'          => $cpf,
            'telefone'           => null,
            'aceite_uso'         => true,
            'aceite_privacidade' => true,
            // Sem este consentimento o perfil nasce fechado e o candidato nunca entra num pool.
            'consentimento_perfil'       => true,
            'consentimento_api'          => true,
            'consentimento_notificacoes' => false,
        ]);

        $r = $perfilCrea->vincularProfissional($usuarioId, $cpf);

        printf(
            "  \e[32mok\e[0m  %-28s RNP %s · %d ART(s)%s\n",
            substr($pessoa['nome'], 0, 28),
            $r['rnp'] ?? '-',
            $r['arts'] ?? 0,
            $r['aviso'] !== null ? "  \e[33m{$r['aviso']}\e[0m" : '',
        );

        $criados['P']++;
    } catch (Throwable $e) {
        $falharam++;
        printf("  \e[31mfalhou\e[0m  %-26s %s\n", substr($pessoa['nome'], 0, 26), $e->getMessage());
    }
}

// ---------------------------------------------------------------- empresas

// Só as que passam no dígito verificador: 85 dos 100 CNPJs da massa têm DV quebrado, e afrouxar a
// validação para acomodar isso seria um defeito nosso para cobrir um defeito do dado.
foreach (lerCsv(dirname(__DIR__) . '/data/csv/empresas.csv') as $empresa) {
    if ($criados['E'] >= $limiteEmpresas) {
        break;
    }

    $cnpj = Crypto::apenasDigitos($empresa['cnpj']);

    if (!\ProLink\Support\Documento::dvCnpjConfere($cnpj)) {
        continue;
    }

    if ($usuarios->documentoEmUso(Crypto::hashBusca($cnpj))) {
        $pulados++;
        continue;
    }

    // Mesma guarda do lado da empresa: pro_empresas tem UNIQUE em emp_registro_crea.
    if ($empresas->porRegistroCrea($empresa['registro_crea']) !== null) {
        $pulados++;
        continue;
    }

    $nome  = $empresa['razao_social'] ?? $empresa['nome'] ?? 'Empresa';
    $email = emailDe($nome, $cnpj);

    if ($simular) {
        printf("  \e[2msimular\e[0m  %-28s %s\n", substr($nome, 0, 28), $email);
        $criados['E']++;
        continue;
    }

    try {
        $usuarioId = $autenticacao->cadastrar([
            'tipo_cadastro'      => CADASTRO_EMPRESA,
            'nome'               => $nome,
            'email'              => $email,
            'senha'              => $senha,
            'senha_confirmacao'  => $senha,
            'documento'          => $cnpj,
            'telefone'           => null,
            'aceite_uso'         => true,
            'aceite_privacidade' => true,
            'consentimento_perfil'       => true,
            'consentimento_api'          => true,
            'consentimento_notificacoes' => false,
        ]);

        $r = $empresaCrea->vincularEmpresa($usuarioId, $cnpj);

        printf(
            "  \e[32mok\e[0m  %-28s registro %s%s\n",
            substr($nome, 0, 28),
            $r['registro_crea'] ?? '-',
            $r['aviso'] !== null ? "  \e[33m{$r['aviso']}\e[0m" : '',
        );

        $criados['E']++;
    } catch (Throwable $e) {
        $falharam++;
        printf("  \e[31mfalhou\e[0m  %-26s %s\n", substr($nome, 0, 26), $e->getMessage());
    }
}

printf(
    "\n%d profissional(is) e %d empresa(s) · %d pulado(s) por documento já em uso · %d falha(s)\n",
    $criados['P'],
    $criados['E'],
    $pulados,
    $falharam,
);

if (!$simular) {
    printf("Senha de todas as contas criadas: \e[1m%s\e[0m\n", $senha);
}

exit($falharam === 0 ? 0 : 1);
