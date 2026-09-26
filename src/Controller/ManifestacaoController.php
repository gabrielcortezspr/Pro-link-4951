<?php

declare(strict_types=1);

namespace ProLink\Controller;

use ProLink\Service\InteressadoService;
use ProLink\Service\ManifestacaoService;
use ProLink\Service\ValidacaoException;
use ProLink\Support\Flash;
use ProLink\Support\RespostaInteresse;
use ProLink\Support\Sessao;
use ProLink\Support\View;

/**
 * Manifestação de interesse e o canal que nasce dela (RF05; cenário 4 do Anexo I).
 *
 * Duas pessoas usam estas telas por lados opostos: quem manifestou acompanha o que enviou, e o
 * demandante lê quem se interessou. As rotas de conversa servem às duas, e quem decide se a
 * pessoa é parte é o serviço, nunca o controller.
 *
 * **Manifestar é de quem tem registro no CREA.** O candidato do motor sai de `crea_evidencias`,
 * que deriva de ART: Terceiro não tem acervo e não é candidato de ninguém. Publicar demanda é que
 * é dele.
 */
final class ManifestacaoController
{
    public function __construct(
        private readonly ManifestacaoService $manifestacoes = new ManifestacaoService(),
        private readonly InteressadoService $interessados = new InteressadoService(),
    ) {
    }

    /**
     * A confirmação antes de manifestar, com a prévia do que o demandante vai receber.
     *
     * Existe por causa da decisão do snapshot: ele congela o que o demandante **podia ver**, e
     * quem tem o perfil quase todo fechado enviaria só o nome. Descobrir isso depois seria a
     * pior hora. A tela mostra antes, e o caminho para abrir mais é o próprio perfil.
     */
    public function confirmar(string $id): string
    {
        $usuarioId = (int) Sessao::usuarioId();

        try {
            $demanda = $this->manifestacoes->demandaAberta((int) $id);
        } catch (ValidacaoException $e) {
            // Rascunho, encerrada ou inexistente: a vitrine é o lugar de onde se chega aqui, e
            // é para onde se volta.
            Flash::erro($e->getMessage());
            View::redirecionar('/demandas/abertas');
        }

        // O dono da própria demanda não manifesta interesse nela, e `manifestar()` já recusa —
        // mas só no POST. Sem esta conferência a tela monta a confirmação inteira, com prévia do
        // perfil, para um envio que nunca seria aceito.
        if ((int) $demanda['dem_usu_id'] === $usuarioId) {
            Flash::erro('Você não pode manifestar interesse na própria demanda.');
            View::redirecionar('/demandas/' . (int) $id);
        }

        $previa = $this->manifestacoes->previa($usuarioId, (int) $id);

        // Já manifestou: esta tela não tem o que confirmar, e o POST seria recusado pelo índice
        // único. Levar direto à conversa é o que a pessoa queria de qualquer jeito — e evita um
        // formulário que existe só para dar erro.
        if ($previa['manifestacao_id'] !== null) {
            Flash::aviso('Você já manifestou interesse nesta demanda. O envio vale uma vez por demanda.');
            View::redirecionar('/manifestacoes/' . $previa['manifestacao_id']);
        }

        return $this->telaDeConfirmacao($demanda, $previa);
    }

    /**
     * A tela de confirmação, também usada para devolver o formulário com erro: pergunta
     * obrigatória em branco não pode custar a mensagem que a pessoa já tinha escrito (D78).
     *
     * @param array<string, mixed>  $demanda
     * @param array<string, mixed>  $previa
     * @param array<string, mixed>  $valores o que veio do formulário
     * @param array<string, string> $erros   campo => mensagem
     */
    private function telaDeConfirmacao(array $demanda, array $previa, array $valores = [], array $erros = []): string
    {
        return View::render('manifestacao/confirmar.html.twig', [
            'titulo'    => 'Manifestar interesse',
            'demanda'   => $demanda,
            'previa'    => $previa,
            // O que a demanda pergunta a quem manifesta (D78). A caixa "usar como minhas
            // preferências" só faz sentido para profissional: empresa não tem essa aba.
            'perguntas' => RespostaInteresse::perguntas($demanda),
            'pode_salvar_preferencias' => Sessao::temPerfil(PERFIL_PROFISSIONAL),
            'hoje'      => date('Y-m-d'),
            'valores'   => $valores,
            'erros'     => $erros,
        ]);
    }

    public function enviar(string $id): string
    {
        try {
            $this->manifestacoes->manifestar(
                (int) Sessao::usuarioId(),
                (int) $id,
                (string) ($_POST['mensagem'] ?? ''),
                [
                    'aceita_contrato' => $_POST['aceita_contrato'] ?? '',
                    'atende_local'    => $_POST['atende_local'] ?? '',
                    'inicio_em'       => $_POST['inicio_em'] ?? '',
                ],
                ($_POST['salvar_preferencias'] ?? '') === '1',
            );
        } catch (ValidacaoException $e) {
            // Erro de campo volta para a mesma tela, com o que foi digitado. Recusa que não é de
            // campo (demanda encerrada, limite por hora, já manifestou) continua indo pelo
            // caminho de antes, que sabe para onde mandar cada caso.
            if ($e->erros() !== []) {
                $usuarioId = (int) Sessao::usuarioId();

                try {
                    $demanda = $this->manifestacoes->demandaAberta((int) $id);
                } catch (ValidacaoException) {
                    Flash::erro($e->getMessage());
                    View::redirecionar('/demandas/abertas');
                }

                Flash::erro('Confira as respostas marcadas abaixo.');

                return $this->telaDeConfirmacao(
                    $demanda,
                    $this->manifestacoes->previa($usuarioId, (int) $id),
                    $_POST,
                    $e->erros(),
                );
            }

            Flash::erro($e->getMessage());
            View::redirecionar('/demandas/' . (int) $id . '/manifestar');
        }

        Flash::sucesso('Interesse manifestado. O demandante foi avisado por e-mail.');
        View::redirecionar('/manifestacoes');
    }

    /** O que eu enviei, do lado de quem manifestou. */
    public function minhas(): string
    {
        return View::render('manifestacao/minhas.html.twig', [
            'titulo'        => 'Meus interesses',
            'manifestacoes' => $this->interessados->minhas((int) Sessao::usuarioId()),
        ]);
    }

    /** Quem se interessou por uma demanda minha. */
    public function interessados(string $id): string
    {
        try {
            $dados = $this->interessados->daDemanda((int) Sessao::usuarioId(), (int) $id);
        } catch (ValidacaoException $e) {
            Flash::erro($e->getMessage());
            View::redirecionar('/demandas');
        }

        return View::render('manifestacao/interessados.html.twig', [
            'titulo'       => 'Contatos',
            'demanda'      => $dados['demanda'],
            'interessados' => $dados['interessados'],
            'painel'       => (new \ProLink\Service\PainelDemandaService())->painel((int) Sessao::usuarioId(), (int) $id),
        ]);
    }

    /** O endereço antigo de Interessados continua funcionando: leva a Contatos (D87). */
    public function interessadosAntigo(string $id): never
    {
        View::redirecionar('/demandas/' . (int) $id . '/contatos');
    }

    /** O perfil congelado e a conversa. Serve às duas partes. */
    public function ver(string $id): string
    {
        try {
            $dados = $this->interessados->abrir((int) Sessao::usuarioId(), (int) $id);
        } catch (ValidacaoException $e) {
            return View::erro(404, $e->getMessage());
        }

        return View::render('manifestacao/ver.html.twig', [
            'titulo' => 'Manifestação',
            ...$dados,
        ]);
    }

    public function responder(string $id): never
    {
        try {
            $this->interessados->responder(
                (int) Sessao::usuarioId(),
                (int) $id,
                (string) ($_POST['corpo'] ?? ''),
            );
        } catch (ValidacaoException $e) {
            Flash::erro($e->getMessage());
            View::redirecionar('/manifestacoes/' . (int) $id);
        }

        View::redirecionar('/manifestacoes/' . (int) $id);
    }
}
