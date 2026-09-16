<?php

declare(strict_types=1);

namespace ProLink\Controller;

use ProLink\Repository\UsuarioRepository;
use ProLink\Service\EmpresaCreaService;
use ProLink\Service\ExperienciaService;
use ProLink\Service\PerfilCreaService;
use ProLink\Service\PerfilEmpresaService;
use ProLink\Service\PerfilService;
use ProLink\Service\PreferenciaService;
use ProLink\Service\ValidacaoException;
use ProLink\Service\VisibilidadeService;
use ProLink\Support\Flash;
use ProLink\Support\Preferencias;
use ProLink\Support\Sessao;
use ProLink\Support\View;
use Throwable;

/**
 * O perfil do próprio titular (RF03).
 *
 * O perfil público de terceiros virá em `/perfil/{id}` e usa os mesmos serviços de montagem: a
 * diferença é só o espectador passado, e é a `Visao` que decide o resto. Nenhuma regra de
 * visibilidade mora aqui.
 *
 * ## Um endereço, duas telas, e o perfil da conta é que escolhe
 *
 * Profissional e empresa são perfis diferentes do edital com dados diferentes — um tem RNP,
 * modalidades e acervo próprio; o outro tem registro de pessoa jurídica, quadro técnico e acervo
 * herdado. Cada um tem seu serviço de montagem e seu template. O que não se duplica é a rota:
 * `/perfil` é "o meu perfil" para quem estiver logado, e endereços separados por tipo de conta
 * só dariam ao usuário a chance de abrir o errado.
 */
final class PerfilController
{
    public function __construct(
        private readonly PerfilService $perfis = new PerfilService(),
        private readonly PerfilEmpresaService $perfisEmpresa = new PerfilEmpresaService(),
        private readonly VisibilidadeService $visibilidades = new VisibilidadeService(),
        private readonly PerfilCreaService $perfilCrea = new PerfilCreaService(),
        private readonly EmpresaCreaService $empresaCrea = new EmpresaCreaService(),
        private readonly ExperienciaService $experiencias = new ExperienciaService(),
        private readonly PreferenciaService $preferencias = new PreferenciaService(),
        private readonly UsuarioRepository $usuarios = new UsuarioRepository(),
    ) {
    }

    public function index(): string
    {
        return $this->renderizar();
    }

    /**
     * O perfil de outra pessoa (RF03; destino do "ver perfil completo" do feed).
     *
     * Nenhuma regra de visibilidade mora aqui: o espectador é passado ao serviço e a `Visao`
     * decide campo a campo o que sobra. Abrir o próprio id por esta rota devolve a visão de dono,
     * pela mesma razão — é a `Visao` que compara, não o controller.
     *
     * O template sai do perfil de **quem é visitado**, não de quem visita: uma empresa olhando um
     * profissional tem de ver a tela de profissional.
     */
    public function publico(string $id): string
    {
        $alvoId = (int) $id;

        // Null quando ninguém entrou, e **não** zero: a Visao decide `autenticado` por
        // `!== null`, então um 0 aqui daria ao anônimo o alcance de quem tem conta, entregando
        // o que cada titular abriu só para autenticados. A rota é pública (Anexo I, item 3), e é
        // exatamente por isso que a distinção precisa sobreviver até aqui.
        $espectador = Sessao::usuarioId();

        // Conta inexistente, excluída ou bloqueada não volta de perfisAtivos, e a ausência fecha
        // o perfil — mesmo efeito da exclusão lógica (D05) e do bloqueio da E6.
        $perfilAlvo = $this->usuarios->perfisAtivos([$alvoId])[$alvoId] ?? null;

        if ($perfilAlvo === null) {
            return View::erro(404, 'Perfil não encontrado.');
        }

        $ehEmpresa = $perfilAlvo === PERFIL_EMPRESA;

        $perfil = $ehEmpresa
            ? $this->perfisEmpresa->montar($alvoId, $espectador)
            : $this->perfis->montar($alvoId, $espectador);

        if ($perfil === null) {
            return View::erro(404, 'Perfil não encontrado.');
        }

        return View::render($ehEmpresa ? 'perfil/empresa.html.twig' : 'perfil/index.html.twig',
            $this->variaveis($perfil, $perfil['identidade']['nome'] ?? 'Perfil'));
    }

    // ---------------------------------------------------------------- experiência autodeclarada

    /**
     * Registra uma experiência (RF03; cenário 1 do Anexo I).
     *
     * Erro de validação **não redireciona**: a tela volta com os erros por campo e com o que a
     * pessoa digitou, como no cadastro. Uma descrição de experiência é texto longo, e perdê-la
     * por causa de uma data mal formatada seria o jeito mais fácil de a pessoa desistir de
     * preencher o perfil.
     */
    public function criarExperiencia(): string
    {
        try {
            $this->experiencias->criar((int) Sessao::usuarioId(), $_POST);
        } catch (ValidacaoException $e) {
            return $this->renderizar($e->erros(), $e->getMessage());
        }

        Flash::sucesso('Experiência registrada. Ela aparece como dado declarado, separada do que '
            . 'o CREA confirma.');
        View::redirecionar('/perfil');
    }

    public function editarExperiencia(string $id): string
    {
        try {
            $this->experiencias->editar((int) Sessao::usuarioId(), (int) $id, $_POST);
        } catch (ValidacaoException $e) {
            return $this->renderizar($e->erros(), $e->getMessage());
        }

        Flash::sucesso('Experiência atualizada.');
        View::redirecionar('/perfil');
    }

    public function excluirExperiencia(string $id): string
    {
        try {
            $this->experiencias->excluir((int) Sessao::usuarioId(), (int) $id);
        } catch (ValidacaoException $e) {
            Flash::erro($e->getMessage());
            View::redirecionar('/perfil');
        }

        Flash::sucesso('Experiência removida do seu perfil.');
        View::redirecionar('/perfil');
    }

    /**
     * Salva as preferências declaradas (resumo, regime de contratação, abrangência).
     *
     * Erro de validação não redireciona, pela mesma razão da experiência: o resumo é texto longo,
     * e perdê-lo por causa de um regime fora da lista seria o jeito mais fácil de a pessoa
     * desistir de preencher — e o perfil sem estes campos é o que deixava duas das seis dimensões
     * do motor fora da conta.
     */
    public function salvarPreferencias(): string
    {
        try {
            $mudou = $this->preferencias->salvar((int) Sessao::usuarioId(), $_POST);
        } catch (ValidacaoException $e) {
            return $this->renderizar($e->erros(), $e->getMessage());
        }

        Flash::sucesso($mudou
            ? 'Preferências atualizadas. Elas entram como dado declarado, com peso menor que o '
                . 'que o CREA confirma.'
            : 'Nada mudou nas suas preferências.');
        View::redirecionar('/perfil');
    }

    /**
     * Salva os níveis de visibilidade de uma vez.
     *
     * O formulário manda `nivel[<chave do alvo>]`, e a chave é a mesma string que
     * `Visibilidade::chave()` produz. O perfil é remontado aqui **só para saber quais alvos esta
     * tela desenhou**: o POST só é aceito para essas chaves. Sem isso, um `name` adulterado no
     * HTML gravava escolha de visibilidade para qualquer `art_id` do banco — não vazava nada,
     * porque a `Visao` de uma tela é sempre a do dono dela, mas escrevia linha e auditoria para
     * alvo que não é do titular. Ignorar em silêncio continua sendo o certo: requisição
     * adulterada não é erro a exibir, e o alvo desconhecido permanece privado.
     *
     * A decisão do que gravar é do serviço, não daqui — inclusive a de não gravar nada quando um
     * nível vem inválido.
     */
    public function definirVisibilidade(): string
    {
        $usuarioId = (int) Sessao::usuarioId();
        $perfil    = $this->montar($usuarioId, Sessao::temPerfil(PERFIL_EMPRESA));

        if ($perfil === null) {
            return View::erro(404, 'Perfil não encontrado.');
        }

        $enviados = $_POST['nivel'] ?? [];

        try {
            $salvos = $this->visibilidades->definirLote(
                $usuarioId,
                is_array($enviados) ? $enviados : [],
                array_keys($perfil['niveis']),
            );
        } catch (ValidacaoException $e) {
            Flash::erro($e->getMessage());
            View::redirecionar('/perfil');
        }

        Flash::sucesso($salvos === 0
            ? 'Nada mudou na visibilidade.'
            : 'Visibilidade atualizada. Nada fica visível sem você escolher.');

        View::redirecionar('/perfil');
    }

    /** O perfil do titular, montado pelo serviço que corresponde ao perfil da conta. */
    private function montar(int $usuarioId, bool $ehEmpresa): ?array
    {
        return $ehEmpresa
            ? $this->perfisEmpresa->montar($usuarioId, $usuarioId)
            : $this->perfis->montar($usuarioId, $usuarioId);
    }

    /**
     * A própria tela, com ou sem erros de um formulário que acabou de falhar.
     *
     * `valores` é o POST cru, para o formulário devolver o que a pessoa digitou. Não há senha
     * nesta tela, então não há o que remover antes de devolver.
     *
     * @param array<string, string> $erros
     */
    private function renderizar(array $erros = [], ?string $aviso = null): string
    {
        $usuarioId = (int) Sessao::usuarioId();
        $ehEmpresa = Sessao::temPerfil(PERFIL_EMPRESA);
        $perfil    = $this->montar($usuarioId, $ehEmpresa);

        if ($perfil === null) {
            return View::erro(404, 'Perfil não encontrado.');
        }

        return View::render($ehEmpresa ? 'perfil/empresa.html.twig' : 'perfil/index.html.twig',
            $this->variaveis($perfil, 'Meu perfil', $erros, $aviso));
    }

    /**
     * As variáveis que as duas telas de perfil esperam, para o dono e para o visitante.
     *
     * Um lugar só porque `strict_variables` está ligado em desenvolvimento: chave faltando é
     * exceção, não string vazia, e a tela do visitante percorre os mesmos blocos da tela do dono
     * — o que muda é `perfil.eh_dono`, que a `Visao` resolveu lá no serviço. O vocabulário e os
     * níveis de visibilidade viajam mesmo para o visitante: os blocos que os consomem estão atrás
     * de `eh_dono`, e passá-los custa menos que espalhar `default()` pelos templates.
     *
     * @param  array<string, mixed>  $perfil
     * @param  array<string, string> $erros
     * @return array<string, mixed>
     */
    private function variaveis(array $perfil, string $titulo, array $erros = [], ?string $aviso = null): array
    {
        return [
            'titulo'  => $titulo,
            'perfil'  => $perfil,
            'erros'   => $erros,
            'aviso'   => $aviso,
            'valores' => $erros === [] ? [] : $_POST,
            'niveis_possiveis' => [
                VISIBILIDADE_PRIVADO     => 'Só eu',
                VISIBILIDADE_AUTENTICADO => 'Quem tem conta',
                VISIBILIDADE_PUBLICO     => 'Qualquer pessoa',
            ],

            // O vocabulário das duas dimensões autodeclaradas, para o formulário oferecer a
            // mesma lista fechada que o motor compara. Sai de Support\Preferencias, e não de uma
            // lista escrita no template: opção nova no vocabulário aparece sozinha na tela.
            'contratos_possiveis' => Preferencias::CONTRATOS,
            'ufs_possiveis'       => Preferencias::UFS,
            'abrangencia_qualquer' => Preferencias::QUALQUER,
            'abrangencia_atual'    => Preferencias::ufsDaAbrangencia(
                $perfil['campos']['DISPONIBILIDADE'] ?? null,
            ),
        ];
    }

    /**
     * Resolve a pendência da D20: valida o registro no CREA depois do cadastro.
     *
     * Serve aos dois estados incompletos, dos dois lados — sem linha em `pro_profissionais` /
     * `pro_empresas` (a API estava fora do ar quando a conta nasceu) e com a data de
     * sincronização nula (a identidade entrou, o acervo não). O documento não é pedido de novo:
     * o serviço decifra o que já está guardado.
     */
    public function validarRegistro(): string
    {
        $usuarioId = (int) Sessao::usuarioId();
        $ehEmpresa = Sessao::temPerfil(PERFIL_EMPRESA);

        try {
            $resultado = $ehEmpresa
                ? $this->empresaCrea->vincularEmpresa($usuarioId)
                : $this->perfilCrea->vincularProfissional($usuarioId);
        } catch (ValidacaoException $e) {
            Flash::erro($e->getMessage());
            View::redirecionar('/perfil');
        } catch (Throwable $e) {
            error_log('Falha ao validar registro do usuário ' . $usuarioId . ': ' . $e->getMessage());
            Flash::erro('Não conseguimos falar com o CREA agora. Tente de novo em alguns minutos.');
            View::redirecionar('/perfil');
        }

        if ($resultado['aviso'] !== null) {
            Flash::aviso($resultado['aviso']);
            View::redirecionar('/perfil');
        }

        Flash::sucesso($ehEmpresa
            ? sprintf(
                'Registro da empresa validado no CREA (registro %s). Quadro técnico com %d '
                . 'vínculo(s) vigente(s) e %d ART(s) no acervo.',
                $resultado['registro_crea'],
                $resultado['vigentes'],
                $resultado['arts'],
            )
            : sprintf(
                'Registro validado no CREA (RNP %s). Seu acervo tem %d %s.',
                $resultado['rnp'],
                $resultado['arts'],
                $resultado['arts'] === 1 ? 'ART' : 'ARTs',
            ));

        View::redirecionar('/perfil');
    }
}
