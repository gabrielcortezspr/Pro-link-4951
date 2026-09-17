<?php

declare(strict_types=1);

namespace ProLink\Controller;

use ProLink\Repository\IntegracaoRepository;
use ProLink\Repository\AuditoriaRepository;
use ProLink\Repository\CompatibilizacaoRepository;
use ProLink\Repository\IndicadorRepository;
use ProLink\Repository\LixeiraRepository;
use ProLink\Service\CompatibilizacaoService;
use ProLink\Service\ContaService;
use ProLink\Service\DenunciaService;
use ProLink\Service\LixeiraService;
use ProLink\Service\ParametroService;
use ProLink\Service\ValidacaoException;
use ProLink\Support\Auditoria;
use ProLink\Support\Flash;
use ProLink\Support\Sessao;
use ProLink\Support\View;

/**
 * Painel administrativo (RF06): moderação e auditoria.
 *
 * Todas as rotas daqui já entram sob PERFIL_ADMIN no front controller, então nenhum método
 * reconfere perfil: a autorização é da rota, não do controller, e duplicá-la criaria dois
 * lugares para manter em sincronia.
 */
final class AdminController
{
    public function __construct(
        private readonly DenunciaService $denuncias = new DenunciaService(),
        private readonly AuditoriaRepository $auditoria = new AuditoriaRepository(),
        private readonly CompatibilizacaoRepository $sessoes = new CompatibilizacaoRepository(),
        private readonly CompatibilizacaoService $motor = new CompatibilizacaoService(),
        private readonly IndicadorRepository $indicadores = new IndicadorRepository(),
        private readonly ParametroService $parametros = new ParametroService(),
        private readonly LixeiraService $lixeira = new LixeiraService(),
        private readonly ContaService $contas = new ContaService(),
        //  Acrescentado no fim de propósito: inserir parâmetro no meio de um construtor
        //  quebra em silêncio de tipo toda chamada posicional que já existia.
        private readonly IntegracaoRepository $integracao = new IntegracaoRepository(),
    ) {
    }

    /**
     * As sessões do motor, mais recentes primeiro (RF04 e item 12.3).
     *
     * Cada linha é uma execução da operação atômica 2, com a semente que a ordenou. É por aqui
     * que a supervisão humana que o 12.3 exige deixa de ser uma frase na documentação.
     */
    public function sessoes(): string
    {
        return View::render('admin/sessoes.html.twig', [
            // 'ativo' é estado de navegação da sidebar, não dado: sem ele o item do menu não
            // acende e as duas telas de sessão ficam sem lugar no painel.
            'ativo'   => 'sessoes',
            'titulo'  => 'Sessões do motor',
            'sessoes' => $this->sessoes->recentes(),
        ]);
    }

    /**
     * Uma sessão refeita a partir da semente gravada.
     *
     * O serviço recalcula o sorteio e compara com a ordem que ficou no banco; a tela mostra o
     * resultado dessa comparação. Não é a aplicação afirmando que é reproduzível, é a
     * reprodução acontecendo na frente de quem audita.
     */
    public function sessao(string $id): string
    {
        $reproducao = $this->motor->reproduzir((int) $id);

        if ($reproducao === null) {
            return View::erro(404, 'Sessão de compatibilização não encontrada.');
        }

        return View::render('admin/sessao.html.twig', [
            'ativo'      => 'sessoes',
            'titulo'     => 'Sessão ' . (int) $id,
            'reproducao' => $reproducao,
        ]);
    }

    /**
     * Visão geral: os números da operação (RF06; Anexo I, item 3, "emitir relatórios").
     *
     * Contagem agregada, nunca lista de pessoas ordenada por nada. O item 10.1 veda ranking de
     * profissionais, e é num painel de indicadores que ele apareceria disfarçado de métrica.
     */
    public function index(): string
    {
        return View::render('admin/index.html.twig', [
            'ativo'       => 'visao',
            'indicadores' => $this->indicadores->resumo(),
        ]);
    }

    public function denuncias(): string
    {
        $situacao = ($_GET['situacao'] ?? '') !== ''
            ? strtoupper((string) $_GET['situacao'])
            : null;

        // Filtro adulterado na query string vira "todas", em silêncio: só valor da lista
        // fechada chega ao SQL.
        if ($situacao !== null && !in_array($situacao, DenunciaService::SITUACOES, true)) {
            $situacao = null;
        }

        return View::render('admin/denuncias.html.twig', [
            'ativo'     => 'denuncias',
            'fila'      => $this->denuncias->fila($situacao),
            'situacao'  => $situacao,
            'situacoes' => DenunciaService::SITUACOES,
            'tipos'     => DenunciaService::TIPOS,
        ]);
    }

    public function denuncia(string $id): string
    {
        $denuncia = $this->denuncias->porId((int) $id);

        if ($denuncia === null) {
            return View::erro(404, 'Denúncia não encontrada.');
        }

        return View::render('admin/denuncia.html.twig', [
            'ativo'        => 'denuncias',
            'denuncia'     => $denuncia,
            'tipos'        => DenunciaService::TIPOS,
            'situacoes'    => DenunciaService::SITUACOES,
            'providencias' => DenunciaService::PROVIDENCIAS,
        ]);
    }

    /** Quantos eventos a trilha mostra por página. */
    private const AUDITORIA_POR_PAGINA = 50;

    /**
     * Eventos negativos de segurança: tentativa recusada e divergência de selo. A tela os destaca
     * e conta à parte, porque são o que alguém procura numa trilha e o que se perde no volume.
     */
    private const AUDITORIA_SEGURANCA = [
        Auditoria::ACESSO_NEGADO,
        Auditoria::LOGIN_FALHOU,
        Auditoria::BLOQUEIO_LOGIN,
        Auditoria::SELO_DIVERGENTE,
    ];

    public function auditoria(): string
    {
        $usuarioId = ($_GET['usuario'] ?? '') !== '' ? (int) $_GET['usuario'] : null;

        // Id não positivo é filtro impossível: vira "todas", como qualquer valor fora da faixa.
        if ($usuarioId !== null && $usuarioId < 1) {
            $usuarioId = null;
        }

        $acao   = ($_GET['acao'] ?? '') !== '' ? (string) $_GET['acao'] : null;
        $acoes  = $this->auditoria->acoesDistintas();

        // Mesma regra do filtro da fila: valor fora da lista vira "todas", nunca chega ao SQL.
        if ($acao !== null && !in_array($acao, $acoes, true)) {
            $acao = null;
        }

        $dias = (int) ($_GET['dias'] ?? 7);

        if (!in_array($dias, [7, 30, 90], true)) {
            $dias = 7;
        }

        // Mesmo relógio dos dois lados desde que Database::conexao() alinha o fuso da sessão do
        // MariaDB ao do PHP: este corte compara com aud_dt_registro sem deslocamento.
        $de = date('Y-m-d H:i:s', strtotime("-{$dias} days"));

        $total   = $this->auditoria->contar($usuarioId, $acao, $de, null);
        $paginas = max(1, (int) ceil($total / self::AUDITORIA_POR_PAGINA));

        // Página fora da faixa vira a primeira, em silêncio: o deslocamento é parâmetro de SQL,
        // e a regra da casa é que valor inválido não chega lá.
        $pagina = (int) ($_GET['pagina'] ?? 1);

        if ($pagina < 1 || $pagina > $paginas) {
            $pagina = 1;
        }

        // A contagem respeita o filtro corrente, inclusive o de ação: assim o número do topo é
        // sempre "destes que estão sendo mostrados, tantos são de segurança", sem escopo oculto.
        $criticas = $acao === null
            ? self::AUDITORIA_SEGURANCA
            : array_values(array_intersect(self::AUDITORIA_SEGURANCA, [$acao]));

        $seguranca = 0;

        foreach ($criticas as $critica) {
            $seguranca += $this->auditoria->contar($usuarioId, $critica, $de, null);
        }

        return View::render('admin/auditoria.html.twig', [
            'ativo'  => 'auditoria',
            'linhas' => $this->auditoria->listar(
                $usuarioId,
                $acao,
                $de,
                null,
                self::AUDITORIA_POR_PAGINA,
                ($pagina - 1) * self::AUDITORIA_POR_PAGINA,
            ),
            'total'       => $total,
            'acoes'       => $acoes,
            'filtro'      => ['usuario' => $usuarioId, 'acao' => $acao, 'dias' => $dias],
            'pagina'      => $pagina,
            'paginas'     => $paginas,
            'por_pagina'  => self::AUDITORIA_POR_PAGINA,
            'seguranca'   => $seguranca,
        ]);
    }

    /**
     * Os parâmetros do motor, abertos para edição (edital 12.3: supervisão humana).
     *
     * A tela mostra o que cada número faz e a faixa que ele aceita, porque parâmetro sem
     * explicação é campo que ninguém mexe ou que alguém mexe sem saber no quê.
     */
    public function parametros(): string
    {
        return $this->telaDeParametros();
    }

    /**
     * Grava o lote de parâmetros.
     *
     * Lote atômico: um valor fora da faixa reprova todos, e a tela volta com o erro por campo.
     * Meia gravação deixaria o motor num estado que ninguém pediu.
     */
    public function salvarParametros(): string
    {
        $enviados = is_array($_POST['parametro'] ?? null) ? $_POST['parametro'] : [];

        try {
            $alteradas = $this->parametros->salvar((int) Sessao::usuarioId(), $enviados);
        } catch (ValidacaoException $e) {
            // Volta com o que a pessoa digitou, e não com o que está no banco: o padrão da casa
            // é re-renderizar o formulário, porque redirecionar perderia a edição inteira por
            // causa de um campo.
            return $this->telaDeParametros($e->erros(), $enviados, $e->getMessage());
        }

        Flash::sucesso($alteradas === []
            ? 'Nenhum parâmetro mudou: os valores enviados são os que já estavam gravados.'
            : count($alteradas) . ' parâmetro(s) alterado(s). A mudança vale a partir da próxima sessão do motor.');

        View::redirecionar('/admin/parametros');
    }

    /**
     * @param array<string, string> $erros   campo => mensagem
     * @param array<string, mixed>  $valores o que veio do formulário, para não perder a edição
     */
    private function telaDeParametros(array $erros = [], array $valores = [], string $aviso = ''): string
    {
        return View::render('admin/parametros.html.twig', [
            'ativo'      => 'parametros',
            'parametros' => $this->parametros->listar(),
            'erros'      => $erros,
            'valores'    => $valores,
            'aviso'      => $aviso,
        ]);
    }

    /**
     * A lixeira: o que está em exclusão lógica (edital 8.6j).
     *
     * O 8.6j manda o excluído continuar acessível pelo mecanismo administrativo, e é esta tela.
     * Inclui o que o administrador não pode restaurar: conta que o titular mandou excluir aparece
     * aqui com a restauração fechada, porque esconder resolveria o risco e quebraria o requisito.
     */
    /**
     * O estado da integração com a API oficial do CREA-AM (Anexo I, item 3, "configurar
     * integrações").
     *
     * ## O que esta tela recusa fazer
     *
     * **Não chama a API.** O item 10.4 veda coleta automatizada e a organização registra cada
     * chamada ao ambiente fictício; uma tela que consultasse o serviço a cada carregamento
     * gastaria cota alheia para mostrar um número, e bastaria deixar a página aberta para virar
     * o que o edital proíbe. O estado vem do cache e da trilha.
     *
     * **Não mostra o token.** Ela diz se ele existe e quantos caracteres tem, porque é isso que
     * responde "a integração está configurada?" sem colocar uma credencial na tela de alguém.
     */
    public function integracoes(): string
    {
        $token = (string) API_TOKEN;

        return View::render('admin/integracoes.html.twig', [
            'ativo'    => 'integracoes',
            'conexao'  => [
                'base'             => API_BASE,
                'tempo_limite'     => API_TIMEOUT,
                'token_presente'   => $token !== '',
                'token_tamanho'    => mb_strlen($token),
                'ambiente'         => APP_ENV,
            ],
            'colecoes'    => $this->integracao->colecoes(),
            'situacao'    => $this->integracao->situacaoDosPerfis(),
            'importacoes' => $this->integracao->ultimasImportacoes(),
        ]);
    }

    public function lixeira(): string
    {
        return View::render('admin/lixeira.html.twig', [
            'ativo'   => 'lixeira',
            'lixeira' => $this->lixeira->visao(
                (string) ($_GET['entidade'] ?? ''),
                max(1, (int) ($_GET['pagina'] ?? 1)),
            ),
            'entidades' => LixeiraRepository::ENTIDADES,
            'erros'     => [],
        ]);
    }

    /**
     * Devolve um registro à operação, com motivo obrigatório na trilha de auditoria.
     */
    public function restaurar(): string
    {
        $entidade = (string) ($_POST['entidade'] ?? '');
        $id       = (int) ($_POST['id'] ?? 0);

        try {
            $registro = $this->lixeira->restaurar(
                $entidade,
                $id,
                (int) Sessao::usuarioId(),
                (string) ($_POST['motivo'] ?? ''),
            );
        } catch (ValidacaoException $e) {
            Flash::erro($e->getMessage());
            View::redirecionar('/admin/lixeira?entidade=' . urlencode($entidade));
        }

        Flash::sucesso('"' . $registro['titulo'] . '" voltou para a operação. A restauração e o '
            . 'motivo ficaram na trilha de auditoria.');
        View::redirecionar('/admin/lixeira?entidade=' . urlencode($entidade));
    }

    /**
     * Gestão de contas (Anexo I, item 3: "gerir perfis").
     *
     * Bloquear já existia, mas só como providência de denúncia: conta que precisa ser suspensa sem
     * que ninguém a tenha denunciado não tinha caminho, e não havia lista para responder quem
     * existe na plataforma.
     */
    public function contas(): string
    {
        return $this->telaDeContas();
    }

    public function bloquearConta(string $id): string
    {
        try {
            $r = $this->contas->bloquear(
                (int) $id,
                (int) Sessao::usuarioId(),
                (string) ($_POST['motivo'] ?? ''),
            );
        } catch (ValidacaoException $e) {
            Flash::erro($e->getMessage());
            View::redirecionar('/admin/contas');
        }

        Flash::sucesso(sprintf(
            '"%s" foi bloqueada. Sessões encerradas: %d. O motivo ficou na trilha.',
            $r['nome'],
            $r['sessoes_derrubadas'],
        ));
        View::redirecionar('/admin/contas');
    }

    public function desbloquearConta(string $id): string
    {
        try {
            $r = $this->contas->desbloquear(
                (int) $id,
                (int) Sessao::usuarioId(),
                (string) ($_POST['motivo'] ?? ''),
            );
        } catch (ValidacaoException $e) {
            Flash::erro($e->getMessage());
            View::redirecionar('/admin/contas');
        }

        Flash::sucesso('"' . $r['nome'] . '" voltou para a operação. O motivo ficou na trilha.');
        View::redirecionar('/admin/contas');
    }

    private function telaDeContas(): string
    {
        $filtros = [
            'termo'    => (string) ($_GET['q'] ?? ''),
            'perfil'   => (string) ($_GET['perfil'] ?? ''),
            'situacao' => (string) ($_GET['situacao'] ?? ''),
        ];

        return View::render('admin/contas.html.twig', [
            'ativo'   => 'contas',
            'lista'   => $this->contas->listar($filtros, max(1, (int) ($_GET['pagina'] ?? 1))),
            'filtros' => $filtros,
            'perfis'  => PERFIS_AUTENTICADOS,
        ]);
    }

    public function tratar(string $id): string
    {
        try {
            $r = $this->denuncias->tratar(
                (int) $id,
                (int) Sessao::usuarioId(),
                (string) ($_POST['situacao'] ?? ''),
                ($_POST['providencia'] ?? '') ?: null,
            );
        } catch (ValidacaoException $e) {
            Flash::erro($e->getMessage());
            View::redirecionar('/admin/denuncias/' . (int) $id);
        }

        Flash::sucesso($r['bloqueado']
            ? 'Denúncia tratada e conta bloqueada. Sessões encerradas: ' . $r['sessoes_derrubadas'] . '.'
            : 'Denúncia tratada.');

        View::redirecionar('/admin/denuncias');
    }
}
