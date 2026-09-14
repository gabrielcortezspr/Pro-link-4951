<?php

declare(strict_types=1);

/**
 * Verificação do portfólio contra o banco de verdade (RF02, RF03; operação atômica 1).
 *
 * O par deste script é a suíte: `tests/Support/AcervoTest.php` prova as regras de projeção,
 * mescla e selo sem banco; aqui se prova que elas sobrevivem à ida e volta do MariaDB, à
 * transação e à trilha de auditoria.
 *
 *     docker compose exec php php scripts/verificar-e2.php
 *
 * **Não gasta chamada da API.** Roda contra `TransporteFixture`, então é determinístico e pode
 * ser executado à vontade — quem confere a API de verdade é o `verificar-api.php`. É também a
 * demonstração de que a D13 não deixou ninguém na mão: dá para exercitar a E2 inteira offline,
 * construindo o cliente com o transporte de fixtures na mão, sem interruptor em `.env`.
 *
 * Idempotente: as ARTs entram por `art_numero`, que é único, então rodar de novo reescreve as
 * mesmas quatro linhas em vez de multiplicá-las.
 */

require_once dirname(__DIR__) . '/_config.php';

use ProLink\Repository\AcervoRepository;
use ProLink\Repository\ConsentimentoRepository;
use ProLink\Repository\EmpresaRepository;
use ProLink\Repository\ParametroRepository;
use ProLink\Repository\ProfissionalRepository;
use ProLink\Repository\QuadroTecnicoRepository;
use ProLink\Repository\UsuarioRepository;
use ProLink\Service\ApiIndisponivelException;
use ProLink\Service\CreaApiClient;
use ProLink\Service\EmpresaCreaService;
use ProLink\Service\PerfilCreaService;
use ProLink\Service\PortfolioService;
use ProLink\Service\ValidacaoException;
use ProLink\Service\VisibilidadeService;
use ProLink\Support\Crypto;
use ProLink\Support\Database;
use ProLink\Support\RespostaHttp;
use ProLink\Support\Transporte;
use ProLink\Support\TransporteFixture;

const RNP        = '0412340011';
const ART        = 'AM20269999001';
const ART_ALHEIA = 'AM20269999290';
const CPF              = '12312300109';  // ANA CLARA COSTA, dona do RNP acima
const CPF_SEM_REGISTRO = '98765432100';  // CPF válido, fora da massa: a API responde 200 []

// AMAZÔNIA CONSTRUÇÕES E ENGENHARIA LTDA, a única das três empresas com CAO capturado cujo CNPJ
// passa no dígito verificador (D07). O RNP acima está no quadro técnico dela — é o que faz a
// herança de acervo ser verificável com a mesma massa dos dois lados.
const CNPJ             = '00123001000123';
const REGISTRO_CREA    = '61859';
const CNPJ_SEM_REGISTRO = '00123002000178';  // CNPJ válido que esta captura não conhece: 200 []

$aprovado = 0;
$falhou   = 0;
$pdo      = Database::conexao();

function conferir(string $descricao, bool $condicao, string $detalhe = ''): void
{
    global $aprovado, $falhou;

    if ($condicao) {
        $aprovado++;
        printf("  \e[32mok\e[0m    %s\n", $descricao);

        return;
    }

    $falhou++;
    printf("  \e[31mFALHOU\e[0m %s%s\n", $descricao, $detalhe !== '' ? "  ({$detalhe})" : '');
}

function secao(string $titulo): void
{
    printf("\n\e[1m%s\e[0m\n", $titulo);
}

/**
 * Usuário de verificação. Com `$email` fixo, é reaproveitado entre execuções — necessário para o
 * perfil profissional, porque `uq_prf_rnp` é global: o RNP 0412340011 pertence a uma conta só, e
 * criar um usuário novo a cada rodada esbarraria na guarda de RNP já vinculado (que é a guarda
 * certa). Sem `$email`, é descartável e termina marcado como excluído.
 */
function usuarioDeVerificacao(
    PDO $pdo,
    bool $comConsentimento,
    ?string $email = null,
    string $perfil = PERFIL_PROFISSIONAL,
    ?string $documento = null,
): int {
    if ($email !== null) {
        $achar = $pdo->prepare('SELECT usu_id FROM sis_usuarios WHERE usu_email = :email');
        $achar->execute([':email' => $email]);
        $existente = $achar->fetchColumn();

        if ($existente !== false) {
            (new ConsentimentoRepository($pdo))
                ->definir((int) $existente, FINALIDADE_CONSULTA_API, $comConsentimento, 'cli');

            return (int) $existente;
        }
    }

    $email ??= 'e2.' . date('His') . '.' . random_int(100, 999) . '@verificacao.local';

    $stmt = $pdo->prepare(
        'INSERT INTO sis_usuarios (usu_per_id, usu_nome, usu_email, usu_senha_hash, usu_tipo_pessoa,
                                   usu_documento_cif, usu_documento_hash)
         SELECT per_id, :nome, :email, :hash, :tipo, :cif, :dochash
           FROM sis_perfis WHERE per_codigo = :perfil'
    );
    $stmt->execute([
        ':nome'    => 'Verificação E2',
        ':email'   => $email,
        ':hash'    => password_hash(bin2hex(random_bytes(16)), PASSWORD_ALGO),
        ':tipo'    => $perfil === PERFIL_EMPRESA ? 'J' : 'F',
        // O documento cifrado é o que o botão "validar meu registro" e o sincronizar-status.php
        // decifram: sem ele não dá para exercitar a revalidação sem o documento em claro.
        ':cif'     => $documento === null ? null : Crypto::cifrar($documento),
        ':dochash' => $documento === null ? null : Crypto::hashBusca($documento),
        ':perfil'  => $perfil,
    ]);

    $id = (int) $pdo->lastInsertId();

    (new ConsentimentoRepository($pdo))->definir($id, FINALIDADE_CONSULTA_API, $comConsentimento, 'cli');

    return $id;
}

$api        = new CreaApiClient(new TransporteFixture());
$portfolio  = new PortfolioService($api, new AcervoRepository($pdo), new ConsentimentoRepository($pdo));
$acervo     = new AcervoRepository($pdo);
$usuario    = usuarioDeVerificacao($pdo, true, 'e2.perfil@verificacao.local');
$semPermitir = usuarioDeVerificacao($pdo, false);

printf("\e[1mVerificação da E2 — portfólio\e[0m  (fixtures, sem rede)\n");

// ---------------------------------------------------------------- consentimento
secao('Consentimento antes da chamada (item 11.3)');

try {
    $portfolio->importarArts($semPermitir, RNP);
    conferir('sem CONSULTA_API concedida, a importação é recusada', false, 'não recusou');
} catch (ValidacaoException $e) {
    conferir('sem CONSULTA_API concedida, a importação é recusada', true);
    conferir('a mensagem diz ao titular o que fazer',
        str_contains($e->getMessage(), 'painel de privacidade'));
}

// ---------------------------------------------------------------- importação
secao('Importação do acervo (RF02)');

$resumo = $portfolio->importarArts($usuario, RNP, 2);   // limite 2: exercita a paginação

conferir('importa as 4 ARTs da profissional', $resumo['arts'] === 4, "vieram {$resumo['arts']}");
conferir('grava as 6 atividades TOS das 4 ARTs', $resumo['atividades'] === 6,
    "gravou {$resumo['atividades']}");
conferir('o acervo local tem 4 ARTs para o RNP', $acervo->contarPorRnp(RNP) === 4);

$gravada = $acervo->porNumero(ART);
conferir('a ART gravada preserva o RNP com zero à esquerda',
    ($gravada['art']['art_pro_rnp'] ?? '') === RNP);
conferir('a importação traz o local, que só este endpoint dá',
    ($gravada['art']['art_local_municipio'] ?? '') === 'Manaus');
conferir('art_dt_consulta registra quando a API respondeu',
    ($gravada['art']['art_dt_consulta'] ?? '') !== '');

// ---------------------------------------------------------------- idempotência
secao('Reimportar não duplica');

$segunda = $portfolio->importarArts($usuario, RNP, 20);

conferir('a segunda importação reconhece as 4 como já existentes', $segunda['ja_existiam'] === 4);
conferir('o acervo continua com 4 ARTs', $acervo->contarPorRnp(RNP) === 4);
conferir('as atividades não duplicaram', count($acervo->atividades((int) $gravada['art']['art_id'])) === 1);

// ---------------------------------------------------------------- selo
secao('Selo ART: integridade em repouso (OWASP A08)');

conferir('o selo confere logo depois de gravar', $portfolio->conferirSelo(ART) === true);
conferir('ART que não está no acervo devolve null, não falso',
    $portfolio->conferirSelo('AM20260000000') === null);

// Adultera a linha por fora, como faria quem tem acesso ao banco.
$pdo->prepare('UPDATE crea_arts SET art_objeto = :novo WHERE art_numero = :numero')
    ->execute([':novo' => 'Projeto que ela nunca fez', ':numero' => ART]);

conferir('mexer na linha por fora quebra o selo', $portfolio->conferirSelo(ART, $usuario) === false);

$stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM sis_auditoria WHERE aud_acao = :acao AND aud_entidade_id = :id'
);
$stmt->execute([':acao' => 'SELO_DIVERGENTE', ':id' => (int) $gravada['art']['art_id']]);
conferir('a divergência vira linha em sis_auditoria', (int) $stmt->fetchColumn() >= 1);

// Acrescenta um código TOS que ela nunca executou: o caso que motiva as atividades entrarem no selo.
$portfolio->importarArts($usuario, RNP, 20);   // restaura o objeto original e o selo
conferir('reimportar conserta a linha adulterada e o selo volta a conferir',
    $portfolio->conferirSelo(ART) === true);

// ON DUPLICATE porque a execução anterior deixou esta linha como 'X' e nada é apagado: reativá-la
// é o que reproduz a adulteração de novo. Um INSERT cru bate em uq_ata_art_tos na segunda rodada.
$pdo->prepare(
    'INSERT INTO crea_art_atividades (ata_art_id, ata_tos_codigo, ata_descricao, ata_status)
     VALUES (:art, :codigo, :descricao, :ativo)
     ON DUPLICATE KEY UPDATE ata_descricao = VALUES(ata_descricao), ata_status = VALUES(ata_status)'
)->execute([
    ':art'       => (int) $gravada['art']['art_id'],
    ':codigo'    => 'TOS_11.10.1.4',
    ':descricao' => 'Eletrotécnica que ela nunca executou',
    ':ativo'     => STATUS_ATIVO,
]);

conferir('acrescentar um código TOS por fora também quebra o selo',
    $portfolio->conferirSelo(ART, $usuario) === false);

$portfolio->importarArts($usuario, RNP, 20);
conferir('a reimportação encerra a atividade intrusa em vez de apagá-la',
    $portfolio->conferirSelo(ART) === true);

$stmt = $pdo->prepare(
    'SELECT ata_status FROM crea_art_atividades WHERE ata_art_id = :art AND ata_tos_codigo = :codigo'
);
$stmt->execute([':art' => (int) $gravada['art']['art_id'], ':codigo' => 'TOS_11.10.1.4']);
conferir('a linha intrusa continua no banco com status X (item 8.6j)',
    $stmt->fetchColumn() === STATUS_EXCLUIDO);

// ---------------------------------------------------------------- associação manual
secao('Associação de ART informada à mão (RF03)');

try {
    $portfolio->associarArt($usuario, RNP, ART_ALHEIA);
    conferir('ART de outra pessoa é recusada', false, 'não recusou');
} catch (ValidacaoException $e) {
    conferir('ART de outra pessoa é recusada', true);
    conferir('a mensagem não sugere erro de sistema',
        str_contains($e->getMessage(), 'não consta como sua'));
}

conferir('a ART recusada não entrou no acervo', $acervo->porNumero(ART_ALHEIA) === null);

// A regra da mescla, de ponta a ponta: a validação não traz local, e o local não pode sumir.
$associada = $portfolio->associarArt($usuario, RNP, ART);
$depois    = $acervo->porNumero(ART);

conferir('associar a mesma ART reaproveita a linha existente',
    $associada['art_id'] === (int) $gravada['art']['art_id']);
conferir('o município que a importação trouxe continua lá',
    ($depois['art']['art_local_municipio'] ?? '') === 'Manaus');
conferir('as atividades TOS não foram zeradas pela revalidação',
    count($depois['atividades']) === 1);
conferir('o selo continua conferindo depois da mescla', $portfolio->conferirSelo(ART) === true);

// ---------------------------------------------------------------- perfil CREA
secao('Perfil CREA no cadastro (RF02)');

$profissionais = new ProfissionalRepository($pdo);
$perfilCrea    = new PerfilCreaService(
    $api, $profissionais, new UsuarioRepository($pdo),
    new ConsentimentoRepository($pdo), new ParametroRepository($pdo), $portfolio,
);

$vinculo = $perfilCrea->vincularProfissional($usuario, CPF);

conferir('CPF da massa devolve VINCULADO', $vinculo['situacao'] === PerfilCreaService::VINCULADO);
conferir('o RNP volta como string, com o zero à esquerda', $vinculo['rnp'] === RNP);

$prf = $profissionais->porUsuario($usuario);
conferir('pro_profissionais recebeu o registro CREA', ($prf['prf_registro_crea'] ?? '') === '61657');
conferir('prf_nome_api guarda o nome oficial, não o digitado',
    ($prf['prf_nome_api'] ?? '') === 'ANA CLARA COSTA');
conferir('prf_status_api guarda a situação que só a busca por CPF devolve',
    ($prf['prf_status_api'] ?? '') === 'A');
conferir('prf_dt_sincronizacao registra quando foi validado',
    ($prf['prf_dt_sincronizacao'] ?? null) !== null);

$modalidades = $profissionais->modalidades((int) $prf['prf_id']);
conferir('a modalidade da API foi vinculada', count($modalidades) === 1);
conferir('e é a que a API devolveu',
    ($modalidades[0]['mod_nome'] ?? '') === 'Engenharia Florestal');

conferir('o acervo foi importado junto com o perfil', $vinculo['arts'] === 4);
conferir('4 ARTs com o mínimo em 3: não é perfil em construção',
    (int) $profissionais->porUsuario($usuario)['prf_em_construcao'] === 0);

// Revincular é o caminho do botão "validar meu registro" e do sincronizar-status.php.
$revinculo = $perfilCrea->vincularProfissional($usuario, CPF);
conferir('revincular atualiza em vez de criar linha nova (uq_prf_usu)',
    $revinculo['situacao'] === PerfilCreaService::VINCULADO
        && (int) $profissionais->porUsuario($usuario)['prf_id'] === (int) $prf['prf_id']);

conferir('sincronização completa carimba prf_dt_sincronizacao',
    $profissionais->porUsuario($usuario)['prf_dt_sincronizacao'] !== null);

// ---------------------------------------------------------------- meia validação
secao('API responde o CPF e cai na importação do acervo');

// Transporte que responde tudo pela fixture, menos as ARTs. É o recorte exato do problema:
// o perfil entra, o acervo não, e o banco fica com zero linhas em crea_arts — indistinguível de
// um profissional que genuinamente não tem ART, se ninguém carimbar a diferença.
$meiaApi = new CreaApiClient(new class (new TransporteFixture()) implements Transporte {
    public function __construct(private readonly TransporteFixture $completo)
    {
    }

    public function get(array $params): RespostaHttp
    {
        if (str_contains((string) ($params['p'] ?? ''), '/arts')) {
            throw new ApiIndisponivelException('Caiu no meio da importação do acervo.');
        }

        return $this->completo->get($params);
    }
});

$meioPerfil = new PerfilCreaService(
    $meiaApi, $profissionais, new UsuarioRepository($pdo), new ConsentimentoRepository($pdo),
    new ParametroRepository($pdo),
    new PortfolioService($meiaApi, new AcervoRepository($pdo), new ConsentimentoRepository($pdo)),
);

// Nunca sincronizado: o carimbo tem de continuar nulo depois da falha.
$pdo->prepare('UPDATE pro_profissionais SET prf_dt_sincronizacao = NULL WHERE prf_usu_id = :u')
    ->execute([':u' => $usuario]);

$meio = $meioPerfil->vincularProfissional($usuario, CPF);

conferir('o perfil é validado mesmo com a importação falhando',
    $meio['situacao'] === PerfilCreaService::VINCULADO);
conferir('mas prf_dt_sincronizacao continua nula: precisa tentar de novo',
    $profissionais->porUsuario($usuario)['prf_dt_sincronizacao'] === null);
conferir('e a mensagem manda tentar pelo perfil',
    str_contains((string) $meio['aviso'], 'Tente de novo'));

// Já sincronizado antes: a falha não pode apagar a data da última sincronização boa.
$pdo->prepare('UPDATE pro_profissionais SET prf_dt_sincronizacao = :d WHERE prf_usu_id = :u')
    ->execute([':d' => '2026-09-01 10:00:00', ':u' => $usuario]);

$meioPerfil->vincularProfissional($usuario, CPF);

conferir('falha posterior preserva a data da última sincronização bem-sucedida',
    str_starts_with((string) $profissionais->porUsuario($usuario)['prf_dt_sincronizacao'], '2026-09-01'));

// Restaura o estado bom para o resto do script.
$perfilCrea->vincularProfissional($usuario, CPF);

// ---------------------------------------------------------------- CPF sem registro
secao('CPF válido que o CREA não conhece (200 [])');

$semRegistro = usuarioDeVerificacao($pdo, true);
$desfecho    = $perfilCrea->vincularProfissional($semRegistro, CPF_SEM_REGISTRO);

conferir('devolve SEM_REGISTRO, não exceção', $desfecho['situacao'] === PerfilCreaService::SEM_REGISTRO);
conferir('a conta continua existindo', (new UsuarioRepository($pdo))->porId($semRegistro) !== null);
conferir('o perfil desce para Terceiro',
    (new UsuarioRepository($pdo))->porId($semRegistro)['per_codigo'] === PERFIL_TERCEIRO);
conferir('não cria linha em pro_profissionais', $profissionais->porUsuario($semRegistro) === null);
conferir('a mensagem explica o que a pessoa ainda pode fazer',
    str_contains((string) $desfecho['aviso'], 'publicar demandas'));

// ---------------------------------------------------------------- API fora do ar
secao('API fora do ar durante o cadastro');

$apiMuda = new CreaApiClient(new class implements Transporte {
    public function get(array $params): RespostaHttp
    {
        throw new ApiIndisponivelException('Falha de transporte simulada.');
    }
});

$pendente = usuarioDeVerificacao($pdo, true);
$offline  = (new PerfilCreaService(
    $apiMuda, $profissionais, new UsuarioRepository($pdo),
    new ConsentimentoRepository($pdo), new ParametroRepository($pdo),
    new PortfolioService($apiMuda, new AcervoRepository($pdo), new ConsentimentoRepository($pdo)),
))->vincularProfissional($pendente, CPF);

conferir('devolve API_INDISPONIVEL, e não derruba o cadastro',
    $offline['situacao'] === PerfilCreaService::API_INDISPONIVEL);
conferir('a conta criada permanece', (new UsuarioRepository($pdo))->porId($pendente) !== null);
conferir('o perfil continua PROFISSIONAL, não vira Terceiro',
    (new UsuarioRepository($pdo))->porId($pendente)['per_codigo'] === PERFIL_PROFISSIONAL);
conferir('pendente é a ausência de linha em pro_profissionais, sem coluna nova',
    $profissionais->porUsuario($pendente) === null);
conferir('a mensagem promete a validação para depois',
    str_contains((string) $offline['aviso'], 'assim que o serviço voltar'));

// ---------------------------------------------------------------- guardas
secao('Guardas de integridade');

$intruso = usuarioDeVerificacao($pdo, true);

try {
    $perfilCrea->vincularProfissional($intruso, CPF);
    conferir('RNP de outra conta é recusado', false, 'não recusou');
} catch (ValidacaoException $e) {
    conferir('RNP de outra conta é recusado', true);
    conferir('e a tentativa fica em sis_auditoria', (function (PDO $pdo, int $usu): bool {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM sis_auditoria WHERE aud_acao = :acao AND aud_usu_id = :usu'
        );
        $stmt->execute([':acao' => 'ACESSO_NEGADO', ':usu' => $usu]);

        return (int) $stmt->fetchColumn() >= 1;
    })($pdo, $intruso));
}

$semAutorizacao = usuarioDeVerificacao($pdo, false);

try {
    $perfilCrea->vincularProfissional($semAutorizacao, CPF);
    conferir('sem CONSULTA_API concedida, nem a consulta sai', false, 'não recusou');
} catch (ValidacaoException) {
    conferir('sem CONSULTA_API concedida, nem a consulta sai', true);
}

// ================================================================== EMPRESA
// A metade da empresa. Reaproveita a massa da metade do profissional de propósito: o RNP
// 0412340011 está no quadro técnico da AMAZÔNIA, então a herança de acervo pode ser conferida
// contra ARTs que já sabemos de quem são.

$empresas   = new EmpresaRepository($pdo);
$quadros    = new QuadroTecnicoRepository($pdo);
$fixtura    = new TransporteFixture();
$apiEmpresa = new CreaApiClient($fixtura);

function servicoDeEmpresa(PDO $pdo, CreaApiClient $api): EmpresaCreaService
{
    return new EmpresaCreaService(
        $api,
        new EmpresaRepository($pdo),
        new QuadroTecnicoRepository($pdo),
        new UsuarioRepository($pdo),
        new ConsentimentoRepository($pdo),
        new PortfolioService($api, new AcervoRepository($pdo), new ConsentimentoRepository($pdo)),
    );
}

// Email fixo pelo mesmo motivo do profissional: `uq_emp_registro_crea` é global, o registro
// 61859 pertence a uma conta só, e a guarda de registro já vinculado é a guarda certa.
$daEmpresa      = usuarioDeVerificacao($pdo, true, 'e2.empresa@verificacao.local', PERFIL_EMPRESA, CNPJ);
$empresaService = servicoDeEmpresa($pdo, $apiEmpresa);

secao('Perfil CREA da empresa no cadastro (RF02)');

$antesDasChamadas = count($fixtura->chamadas());
$vinculoEmpresa   = $empresaService->vincularEmpresa($daEmpresa);
$chamadas         = count($fixtura->chamadas()) - $antesDasChamadas;

conferir('CNPJ da massa devolve VINCULADO', $vinculoEmpresa['situacao'] === EmpresaCreaService::VINCULADO);
conferir('o registro do CREA volta como string', $vinculoEmpresa['registro_crea'] === REGISTRO_CREA);
conferir('a empresa inteira custa três chamadas: CNPJ, quadro técnico e CAO (item 10.4)',
    $chamadas === 3, "gastou {$chamadas}");

$emp = $empresas->porUsuario($daEmpresa);
conferir('pro_empresas guarda a razão social que a API devolveu',
    ($emp['emp_razao_social'] ?? '') === 'AMAZÔNIA CONSTRUÇÕES E ENGENHARIA LTDA');
conferir('e o nome fantasia', ($emp['emp_nome_fantasia'] ?? '') === 'AMAZÔNIA');
conferir('emp_dt_registro_crea guarda a data do conselho, não a da nossa linha',
    str_starts_with((string) ($emp['emp_dt_registro_crea'] ?? ''), '2026-08-12'));
conferir('emp_dt_sincronizacao é carimbada quando as três metades entram',
    ($emp['emp_dt_sincronizacao'] ?? null) !== null);

secao('Quadro técnico: o campo que só este endpoint dá');

$quadro = $quadros->porEmpresa(REGISTRO_CREA);

conferir('o quadro técnico tem uma linha', count($quadro) === 1);
conferir('com o RNP preservando o zero à esquerda', ($quadro[0]['qut_pro_rnp'] ?? '') === RNP);
conferir('e o nome, que o CAO também dá mas a tabela precisa guardar',
    ($quadro[0]['qut_pro_nome'] ?? '') === 'ANA CLARA COSTA');
conferir('qut_tipo marca o responsável técnico', ($quadro[0]['qut_tipo'] ?? '') === 'R');
conferir('qut_dt_fim nulo é vínculo vigente, e é o que o CAO não sabe dizer',
    ($quadro[0]['qut_dt_fim'] ?? null) === null);
conferir('rnpsVigentes devolve só quem herda acervo', $quadros->rnpsVigentes(REGISTRO_CREA) === [RNP]);

secao('Acervo operacional: a empresa herda, não registra');

conferir('o CAO trouxe as 4 ARTs', $vinculoEmpresa['arts'] === 4);
conferir('e um vínculo vigente', $vinculoEmpresa['vigentes'] === 1);

$doCao = $acervo->porNumero('AM20269999103');
conferir('a ART do CAO é gravada sob o RNP de quem a registrou, nunca sob a empresa',
    ($doCao['art']['art_pro_rnp'] ?? '') === RNP);

$semDono = $pdo->prepare('SELECT COUNT(*) FROM crea_arts WHERE art_pro_rnp = :registro');
$semDono->execute([':registro' => REGISTRO_CREA]);
conferir('nenhuma ART foi gravada com o registro da empresa no lugar do RNP',
    (int) $semDono->fetchColumn() === 0);

// A regra da mescla pelo terceiro caminho: o CAO não traz local, contratante nem forma de
// registro, e não pode apagar o que a importação do profissional já tinha trazido.
$depoisDoCao = $acervo->porNumero(ART);
conferir('o CAO não apagou o município que a importação do profissional trouxe',
    ($depoisDoCao['art']['art_local_municipio'] ?? '') === 'Manaus');
conferir('nem o contratante', ($depoisDoCao['art']['art_contratante_nome'] ?? '') === 'João Silva LTDA');
conferir('e o selo continua conferindo depois do CAO', $portfolio->conferirSelo(ART) === true);

secao('Herança pela view crea_evidencias (D19)');

$contarEvidencias = $pdo->prepare(
    'SELECT COUNT(*) FROM crea_evidencias WHERE evi_candidato_tipo = :tipo AND evi_candidato_id = :id'
);

$contarEvidencias->execute([':tipo' => 'E', ':id' => (int) $emp['emp_id']]);
$evidenciasEmpresa = (int) $contarEvidencias->fetchColumn();
conferir('a empresa tem evidência TOS herdada do quadro técnico', $evidenciasEmpresa > 0);

$contarEvidencias->execute([':tipo' => 'P', ':id' => (int) $prf['prf_id']]);
$evidenciasProfissional = (int) $contarEvidencias->fetchColumn();
conferir('e herda exatamente a mesma quantidade que a profissional tem',
    $evidenciasEmpresa === $evidenciasProfissional,
    "empresa {$evidenciasEmpresa}, profissional {$evidenciasProfissional}");

// Encerrar o vínculo é a regra binária inteira: não há recorte por data porque a API não data
// ART em endpoint nenhum.
$pdo->prepare('UPDATE crea_quadro_tecnico SET qut_dt_fim = :fim WHERE qut_emp_registro_crea = :emp')
    ->execute([':fim' => '2026-01-01', ':emp' => REGISTRO_CREA]);

$contarEvidencias->execute([':tipo' => 'E', ':id' => (int) $emp['emp_id']]);
conferir('vínculo encerrado tira o acervo da empresa, inteiro',
    (int) $contarEvidencias->fetchColumn() === 0);

$contarEvidencias->execute([':tipo' => 'P', ':id' => (int) $prf['prf_id']]);
conferir('e não mexe na evidência da profissional, que continua dona das ARTs',
    (int) $contarEvidencias->fetchColumn() === $evidenciasProfissional);

// Revalidar traz o vínculo de volta ao que a API diz, sem duplicar linha.
$empresaService->vincularEmpresa($daEmpresa);

conferir('revalidar devolve o vínculo à situação da API', $quadros->rnpsVigentes(REGISTRO_CREA) === [RNP]);
conferir('sem duplicar o quadro técnico', count($quadros->porEmpresa(REGISTRO_CREA)) === 1);

$linhas = $pdo->prepare('SELECT COUNT(*) FROM pro_empresas WHERE emp_usu_id = :u');
$linhas->execute([':u' => $daEmpresa]);
conferir('nem a linha em pro_empresas (uq_emp_usu)', (int) $linhas->fetchColumn() === 1);

secao('Visibilidade da empresa');

$visibilidades = new VisibilidadeService(
    new ProLink\Repository\VisibilidadeRepository($pdo), new ConsentimentoRepository($pdo),
    $profissionais, $empresas, new UsuarioRepository($pdo),
);

(new ConsentimentoRepository($pdo))->definir($daEmpresa, FINALIDADE_EXIBICAO_PERFIL, true, 'cli');
conferir('empresa validada e com consentimento tem o perfil aberto',
    $visibilidades->perfilAberto($daEmpresa) === true);

$pendenteEmpresa = usuarioDeVerificacao($pdo, true, null, PERFIL_EMPRESA, null);
(new ConsentimentoRepository($pdo))->definir($pendenteEmpresa, FINALIDADE_EXIBICAO_PERFIL, true, 'cli');
conferir('empresa sem linha em pro_empresas fica fechada, mesmo consentindo (D20)',
    $visibilidades->perfilAberto($pendenteEmpresa) === false);

// O formulário só aceita de volta os alvos que a tela desenhou (D27). A lista permitida aqui
// imita a de uma empresa com um campo e nenhuma ART.
$permitidas = ['PERFIL:-:EMAIL'];

$visibilidades->definirLote($pendenteEmpresa, ['ART:999999:-' => VISIBILIDADE_PUBLICO], $permitidas);

$forjado = $pdo->prepare(
    'SELECT COUNT(*) FROM pro_visibilidade WHERE vis_usu_id = :u AND vis_entidade_id = 999999'
);
$forjado->execute([':u' => $pendenteEmpresa]);
conferir('alvo fora da lista da tela não vira linha em pro_visibilidade',
    (int) $forjado->fetchColumn() === 0);

try {
    $visibilidades->definirLote($pendenteEmpresa, [
        'PERFIL:-:EMAIL' => 'SEMI_PUBLICO',
    ], $permitidas);
    conferir('nível inventado é recusado', false, 'não recusou');
} catch (ValidacaoException) {
    conferir('nível inventado é recusado', true);
}

$escritas = $pdo->prepare('SELECT COUNT(*) FROM pro_visibilidade WHERE vis_usu_id = :u');
$escritas->execute([':u' => $pendenteEmpresa]);
conferir('e o lote recusado não grava nada: privacidade não se aplica pela metade',
    (int) $escritas->fetchColumn() === 0);

secao('CNPJ válido que esta captura não conhece (200 [])');

$empresaSemRegistro = usuarioDeVerificacao($pdo, true, null, PERFIL_EMPRESA, CNPJ_SEM_REGISTRO);
$desfechoEmpresa    = $empresaService->vincularEmpresa($empresaSemRegistro);

conferir('devolve SEM_REGISTRO, não exceção',
    $desfechoEmpresa['situacao'] === EmpresaCreaService::SEM_REGISTRO);
conferir('a conta continua existindo', (new UsuarioRepository($pdo))->porId($empresaSemRegistro) !== null);
conferir('o perfil desce para Terceiro',
    (new UsuarioRepository($pdo))->porId($empresaSemRegistro)['per_codigo'] === PERFIL_TERCEIRO);
conferir('não cria linha em pro_empresas', $empresas->porUsuario($empresaSemRegistro) === null);
conferir('a mensagem explica o que a empresa ainda pode fazer',
    str_contains((string) $desfechoEmpresa['aviso'], 'publicar demandas'));

secao('API fora do ar durante o cadastro da empresa');

$empresaPendente = usuarioDeVerificacao($pdo, true, null, PERFIL_EMPRESA, CNPJ);
$offlineEmpresa  = servicoDeEmpresa($pdo, $apiMuda)->vincularEmpresa($empresaPendente);

conferir('devolve API_INDISPONIVEL, e não derruba o cadastro',
    $offlineEmpresa['situacao'] === EmpresaCreaService::API_INDISPONIVEL);
conferir('a conta criada permanece', (new UsuarioRepository($pdo))->porId($empresaPendente) !== null);
conferir('o perfil continua EMPRESA, não vira Terceiro',
    (new UsuarioRepository($pdo))->porId($empresaPendente)['per_codigo'] === PERFIL_EMPRESA);
conferir('pendente é a ausência de linha em pro_empresas, sem coluna nova',
    $empresas->porUsuario($empresaPendente) === null);

secao('API responde o CNPJ e cai no CAO');

// Transporte que responde tudo, menos o CAO: a identidade entra, o acervo não, e sem carimbo
// os dois estados — "importamos e o quadro é vazio" e "não conseguimos importar" — seriam
// idênticos no banco.
$meiaEmpresaApi = new CreaApiClient(new class (new TransporteFixture()) implements Transporte {
    public function __construct(private readonly TransporteFixture $completo)
    {
    }

    public function get(array $params): RespostaHttp
    {
        if (str_ends_with((string) ($params['p'] ?? ''), '/cao')) {
            throw new ApiIndisponivelException('Caiu no meio da leitura do CAO.');
        }

        return $this->completo->get($params);
    }
});

$pdo->prepare('UPDATE pro_empresas SET emp_dt_sincronizacao = NULL WHERE emp_usu_id = :u')
    ->execute([':u' => $daEmpresa]);

$meiaEmpresa = servicoDeEmpresa($pdo, $meiaEmpresaApi)->vincularEmpresa($daEmpresa);

conferir('o registro é validado mesmo com o CAO falhando',
    $meiaEmpresa['situacao'] === EmpresaCreaService::VINCULADO);
conferir('mas emp_dt_sincronizacao continua nula: precisa tentar de novo',
    $empresas->porUsuario($daEmpresa)['emp_dt_sincronizacao'] === null);
conferir('e a mensagem manda tentar pelo perfil',
    str_contains((string) $meiaEmpresa['aviso'], 'Tente de novo'));
conferir('o quadro técnico que entrou antes da falha é relatado, e não zerado',
    $meiaEmpresa['vigentes'] === 1, "relatou {$meiaEmpresa['vigentes']}");

$empresaService->vincularEmpresa($daEmpresa);   // restaura o estado bom

secao('Guardas de integridade da empresa');

$intrusaEmpresa = usuarioDeVerificacao($pdo, true, null, PERFIL_EMPRESA, null);

try {
    $empresaService->vincularEmpresa($intrusaEmpresa, CNPJ);
    conferir('registro do CREA de outra conta é recusado', false, 'não recusou');
} catch (ValidacaoException $e) {
    conferir('registro do CREA de outra conta é recusado', true);
    conferir('e nada foi gravado para a intrusa', $empresas->porUsuario($intrusaEmpresa) === null);
}

$empresaSemAutorizacao = usuarioDeVerificacao($pdo, false, null, PERFIL_EMPRESA, CNPJ);

try {
    $empresaService->vincularEmpresa($empresaSemAutorizacao);
    conferir('sem CONSULTA_API concedida, nem a consulta sai', false, 'não recusou');
} catch (ValidacaoException) {
    conferir('sem CONSULTA_API concedida, nem a consulta sai', true);
}

// ---------------------------------------------------------------- auditoria
secao('Trilha de auditoria (edital 8.5g)');

foreach (['CONSULTA_API' => 'importação', 'VALIDAR_ART' => 'associação manual'] as $acao => $oQue) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM sis_auditoria WHERE aud_acao = :acao AND aud_usu_id = :usu');
    $stmt->execute([':acao' => $acao, ':usu' => $usuario]);
    conferir("sis_auditoria registra {$acao} ({$oQue})", (int) $stmt->fetchColumn() >= 1);
}

$porEntidade = $pdo->prepare(
    'SELECT COUNT(*) FROM sis_auditoria
      WHERE aud_entidade = :entidade AND aud_usu_id = :usu AND aud_acao = :acao'
);

foreach ([
    'pro_empresas'        => ['CRIAR', 'criação do perfil da empresa'],
    'crea_quadro_tecnico' => ['CONSULTA_API', 'sincronização do quadro técnico'],
] as $entidade => [$acao, $oQue]) {
    $porEntidade->execute([':entidade' => $entidade, ':usu' => $daEmpresa, ':acao' => $acao]);
    conferir("sis_auditoria registra {$acao} em {$entidade} ({$oQue})",
        (int) $porEntidade->fetchColumn() >= 1);
}

// A tentativa recusada tem de deixar rastro: é o registro de que alguém tentou assumir o
// acervo de outra empresa.
$negado = $pdo->prepare(
    'SELECT COUNT(*) FROM sis_auditoria WHERE aud_acao = :acao AND aud_usu_id = :usu'
);
$negado->execute([':acao' => 'ACESSO_NEGADO', ':usu' => $intrusaEmpresa]);
conferir('a tentativa de assumir registro alheio fica em sis_auditoria',
    (int) $negado->fetchColumn() >= 1);

// ---------------------------------------------------------------- limpeza
secao('Limpeza');

$descartaveis = [
    $semPermitir, $semRegistro, $pendente, $intruso, $semAutorizacao,
    $pendenteEmpresa, $empresaSemRegistro, $empresaPendente, $intrusaEmpresa, $empresaSemAutorizacao,
];
$marcar = $pdo->prepare('UPDATE sis_usuarios SET usu_status = :x WHERE usu_id = :id');

foreach ($descartaveis as $id) {
    $marcar->execute([':x' => STATUS_EXCLUIDO, ':id' => $id]);
}

printf("  usuários descartáveis marcados como excluídos: %d\n", count($descartaveis));
printf("  e2.perfil@verificacao.local permanece ativo: é o dono do RNP %s, que é único\n", RNP);
printf("  e2.empresa@verificacao.local permanece ativo: é a dona do registro %s, que é único\n", REGISTRO_CREA);
printf("  as 4 ARTs do RNP %s ficam no acervo: são cache de resposta real, não sujeira\n", RNP);

printf(
    "\n%s  %d aprovadas, %d falharam\n",
    $falhou === 0 ? "\e[32mE2 (PORTFÓLIO) VERIFICADA\e[0m" : "\e[31mE2 COM FALHA\e[0m",
    $aprovado,
    $falhou,
);

exit($falhou === 0 ? 0 : 1);
