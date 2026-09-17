<?php

declare(strict_types=1);

namespace ProLink\Controller;

use ProLink\Service\CompatibilizacaoService;
use ProLink\Service\DemandaService;
use ProLink\Service\ManifestacaoService;
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

        return View::render('demanda/compativeis.html.twig', [
            'titulo'  => 'Compatíveis',
            'demanda' => $demanda,
            'sessao'  => $sessao,
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

        Flash::sucesso(
            'Interesse registrado. A pessoa foi avisada por e-mail e pode responder por aqui. '
            . 'O perfil dela ficou guardado como estava agora.'
        );
        View::redirecionar('/demandas/' . (int) $id . '/interessados');
    }
}
