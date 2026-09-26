<?php

declare(strict_types=1);

namespace ProLink\Controller;

use ProLink\Service\CompatibilizacaoService;
use ProLink\Service\DemandaService;
use ProLink\Service\ManifestacaoService;
use ProLink\Service\PainelDemandaService;
use ProLink\Service\ModeloConviteService;
use ProLink\Support\ModeloConvite;
use ProLink\Repository\UsuarioRepository;
use ProLink\Service\ValidacaoException;
use ProLink\Support\Flash;
use ProLink\Support\Requisicao;
use ProLink\Support\Sessao;
use ProLink\Support\View;

/**
 * O feed de compatíveis de uma demanda (RF04; cenário 3 do Anexo I).
 *
 * É o primeiro e único caminho HTTP até `CompatibilizacaoService`: até aqui o motor só era
 * alcançado por `scripts/verificar-e4.php`.
 *
 * **GET relê, POST recalcula.** A separação não é estética. `executar()` grava uma sessão e sorteia
 * uma semente nova a cada chamada; se o GET a chamasse, a lista se reembaralharia a cada vez que o
 * demandante voltasse à página, e `mat_sessoes` ganharia uma linha por pageview — a trilha que o
 * item 12.3 pede viraria ruído. Então navegar relê a última sessão gravada, e só o botão de
 * atualizar manda calcular de novo.
 *
 * **O feed é do dono da demanda.** `exigirPropria()` dá a mesma resposta para "não existe" e "não é
 * sua", registrando a tentativa em `sis_auditoria` (OWASP A01).
 */
final class CompativelController
{
    public function __construct(
        private readonly DemandaService $demandas = new DemandaService(),
        private readonly CompatibilizacaoService $motor = new CompatibilizacaoService(),
        private readonly ManifestacaoService $manifestacoes = new ManifestacaoService(),
        private readonly PainelDemandaService $painel = new PainelDemandaService(),
    ) {
    }

    /** Relê a última sessão gravada; calcula a primeira se a demanda nunca foi compatibilizada. */
    public function index(string $id): string
    {
        $usuarioId = (int) Sessao::usuarioId();

        try {
            $demanda = $this->demandas->exigirPropria($usuarioId, (int) $id);
            $sessao  = $this->motor->paraDemanda((int) $id, $usuarioId, Requisicao::ip());

            // A tela precisa dizer de quais atividades o pool saiu, com a descrição da tabela e
            // não só o código: é o contexto que torna a lista explicável. `exigirPropria()` faz
            // o portão de dono e devolve só a linha da demanda; `comTos()` traz os códigos.
            $demanda = ($this->demandas->comTos((int) $id) ?? $demanda) + ['tos' => []];
        } catch (ValidacaoException $e) {
            // Demanda sem código TOS cai aqui, e a mensagem do serviço já diz o que fazer. O
            // destino é o detalhe da demanda, que é onde os códigos são escolhidos.
            Flash::erro($e->getMessage());
            View::redirecionar('/demandas/' . (int) $id);
        }

        // O feed mostra só quem ainda falta avaliar (D87): quem já é contato (candidatura ou
        // convite) ou foi dispensado sai dele, porque a empresa já decidiu sobre essa pessoa. A
        // sessão gravada continua com o conjunto inteiro; o recorte é só desta tela.
        $jaAnalisados = $this->painel->jaAnalisados((int) $id);
        $total        = count($sessao['candidatos']);

        $sessao['candidatos'] = array_values(array_filter(
            $sessao['candidatos'],
            static fn (array $c): bool => !isset($jaAnalisados[(int) ($c['usuario_id'] ?? 0)]),
        ));

        // A mensagem do convite vem montada para cada perfil, a partir do modelo da conta (D88):
        // a empresa pode enviar como está, editar só para aquela pessoa, ou mudar o modelo.
        $modelo = (new ModeloConviteService())->modelo($usuarioId);
        $local  = trim(((string) ($demanda['dem_local_municipio'] ?? '')) !== ''
            ? $demanda['dem_local_municipio'] . '/' . $demanda['dem_local_uf']
            : (string) ($demanda['dem_local_uf'] ?? ''));

        foreach ($sessao['candidatos'] as $i => $c) {
            $sessao['candidatos'][$i]['mensagem_convite'] = ModeloConvite::montar($modelo['texto'], [
                'nome'    => (string) ($c['nome'] ?? ''),
                'empresa' => ($c['tipo'] ?? 'P') === 'E',
                'demanda' => (string) $demanda['dem_titulo'],
                'local'   => $local,
            ]);
        }

        return View::render('demanda/compativeis.html.twig', [
            'modelo_convite' => $modelo,
            'titulo'      => 'Compatíveis',
            'demanda'     => $demanda,
            'sessao'      => $sessao,
            'painel'      => $this->painel->painel($usuarioId, (int) $id),
            'no_conjunto' => $total,
            'dispensados' => $this->painel->dispensados($usuarioId, (int) $id),
        ]);
    }

    /** Ação explícita do demandante: nova sessão, nova semente, nova ordem. */
    public function atualizar(string $id): never
    {
        $usuarioId = (int) Sessao::usuarioId();

        try {
            $this->demandas->exigirPropria($usuarioId, (int) $id);
            $this->motor->paraDemanda((int) $id, $usuarioId, Requisicao::ip(), true);
        } catch (ValidacaoException $e) {
            Flash::erro($e->getMessage());
            View::redirecionar('/demandas/' . (int) $id);
        }

        Flash::sucesso('Lista atualizada. A ordem é sorteada a cada consulta.');
        View::redirecionar('/demandas/' . (int) $id . '/compativeis');
    }

    /**
     * O demandante registra interesse num candidato do pool (Anexo I, item 3).
     *
     * O feed era declaradamente passivo: quem publicava a demanda via o compatível e não tinha o
     * que fazer com ele além de esperar. O Anexo I dá ao Terceiro, e por consequência a todo
     * demandante, o direito de "registrar interesse em profissional ou empresa", e este é o
     * caminho.
     *
     * As guardas moram no serviço, inclusive a de que a demanda é de quem age.
     */
    public function registrarInteresse(string $id): never
    {
        try {
            $this->manifestacoes->registrarInteresse(
                (int) Sessao::usuarioId(),
                (int) $id,
                (int) ($_POST['candidato'] ?? 0),
                (string) ($_POST['mensagem'] ?? ''),
            );
        } catch (ValidacaoException $e) {
            Flash::erro($e->getMessage());
            View::redirecionar('/demandas/' . (int) $id . '/compativeis');
        }

        // Fica no feed, e não salta para Contatos (D87): quem convida está avaliando perfil por
        // perfil, e perder o lugar a cada convite quebra justamente essa tarefa. O convidado sai
        // da lista e o próximo aparece.
        Flash::sucesso(sprintf(
            'Convite enviado para %s. A pessoa foi avisada por e-mail, e a conversa está em Contatos.',
            $this->nome((int) ($_POST['candidato'] ?? 0)),
        ));
        View::redirecionar('/demandas/' . (int) $id . '/compativeis');
    }

    /**
     * Salva o modelo de mensagem de convite da conta, ou volta ao padrão (D88). Fica sob a demanda
     * só para voltar ao feed de onde a pessoa veio; o modelo é da conta, e vale em toda demanda.
     */
    public function salvarModelo(string $id): never
    {
        try {
            (new ModeloConviteService())->salvar(
                (int) Sessao::usuarioId(),
                ($_POST['restaurar'] ?? '') === '1' ? '' : (string) ($_POST['modelo'] ?? ''),
            );
        } catch (ValidacaoException $e) {
            Flash::erro($e->getMessage());
            View::redirecionar('/demandas/' . (int) $id . '/compativeis');
        }

        Flash::sucesso(($_POST['restaurar'] ?? '') === '1'
            ? 'O modelo de convite voltou ao padrão da plataforma.'
            : 'Modelo de convite salvo. Ele vale para os próximos convites, em todas as suas demandas.');
        View::redirecionar('/demandas/' . (int) $id . '/compativeis');
    }

    /** "Dispensar": tira o perfil da lista de quem falta avaliar, sem avisar ninguém (D87). */
    public function dispensar(string $id): never
    {
        $candidato = (int) ($_POST['candidato'] ?? 0);

        try {
            $this->painel->dispensar((int) Sessao::usuarioId(), (int) $id, $candidato);
        } catch (ValidacaoException $e) {
            Flash::erro($e->getMessage());
            View::redirecionar('/demandas/' . (int) $id . '/compativeis');
        }

        Flash::info(sprintf('%s saiu da lista. Dá para trazer de volta em Dispensados.', $this->nome($candidato)));
        View::redirecionar('/demandas/' . (int) $id . '/compativeis');
    }

    /** Desfaz a dispensa: o perfil volta à lista de quem falta avaliar. */
    public function desfazerDispensa(string $id): never
    {
        $candidato = (int) ($_POST['candidato'] ?? 0);

        try {
            $this->painel->desfazerDispensa((int) Sessao::usuarioId(), (int) $id, $candidato);
        } catch (ValidacaoException $e) {
            Flash::erro($e->getMessage());
            View::redirecionar('/demandas/' . (int) $id . '/compativeis');
        }

        Flash::sucesso(sprintf('%s voltou para a lista de perfis a avaliar.', $this->nome($candidato)));
        View::redirecionar('/demandas/' . (int) $id . '/compativeis');
    }

    /** O nome que a mensagem de retorno usa; genérico se a conta não for achada. */
    private function nome(int $usuarioId): string
    {
        $usuario = $usuarioId > 0 ? (new UsuarioRepository())->porId($usuarioId) : null;

        return $usuario === null ? 'O perfil' : \ProLink\Support\Rotulos::nomeProprio((string) $usuario['usu_nome']);
    }
}
