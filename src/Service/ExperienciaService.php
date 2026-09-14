<?php

declare(strict_types=1);

namespace ProLink\Service;

use PDO;
use ProLink\Repository\AcervoRepository;
use ProLink\Repository\ExperienciaRepository;
use ProLink\Repository\ProfissionalRepository;
use ProLink\Support\Auditoria;
use ProLink\Support\Database;
use ProLink\Support\Validacao;

/**
 * Experiência autodeclarada do profissional (RF03; edital Anexo I item 3; proposta, diferencial 2).
 *
 * É o outro lado do `PortfolioService`. Lá o serviço pergunta ao CREA e grava a resposta; aqui ele
 * recebe o que a pessoa escreveu e grava sem conferir nada — porque não há o que conferir. A
 * plataforma não valida o relato, ela apenas não o confunde com o dado verificado.
 *
 * ## A única coisa conferida é a posse da ART
 *
 * `exp_art_id` é opcional, e essa opcionalidade é o ponto: o edital pede experiência "com ou sem
 * ART/CAT", e quem trabalhou sem registro tem de caber na plataforma. Mas quando o campo vem
 * preenchido ele não é mais autodeclaração — é uma afirmação sobre um documento do CREA, e
 * afirmar que a ART AM…001 sustenta o meu relato quando ela é de outra pessoa transformaria o
 * bloco de dado declarado num jeito de pegar emprestada a evidência alheia.
 *
 * Por isso a ART informada é conferida contra o acervo do próprio profissional. A chave
 * estrangeira do banco não resolve isto: ela garante que a ART **existe**, e existir não é
 * pertencer. É a mesma lição da D27, no formulário ao lado — validar a forma do identificador
 * não é validar o direito a ele.
 *
 * ## Edição e exclusão conferem o dono antes de tudo
 *
 * `exp_id` chega pelo formulário, então toda operação sobre uma experiência existente começa
 * perguntando se ela é de quem está pedindo. Negativa vira `ACESSO_NEGADO` em `sis_auditoria`,
 * e não um 404 mudo: tentativa de mexer no dado alheio é justamente o que a trilha existe para
 * registrar (edital 8.5g).
 */
final class ExperienciaService
{
    /** Limites das colunas em `estrutura.sql`, repetidos aqui para virarem mensagem de campo. */
    private const TITULO_MAXIMO    = 190;
    private const DESCRICAO_MAXIMA = 5000;

    public function __construct(
        private readonly ExperienciaRepository $experiencias = new ExperienciaRepository(),
        private readonly ProfissionalRepository $profissionais = new ProfissionalRepository(),
        private readonly AcervoRepository $acervo = new AcervoRepository(),
    ) {
    }

    /**
     * @param array<string, mixed> $entrada campos crus do formulário
     * @return int id da experiência criada
     * @throws ValidacaoException
     */
    public function criar(int $usuarioId, array $entrada): int
    {
        $profissional = $this->exigirProfissional($usuarioId);
        $dados        = $this->validar($entrada, (string) $profissional['prf_rnp']);

        return Database::transacao(function (PDO $pdo) use ($usuarioId, $profissional, $dados): int {
            $id = $this->experiencias->criar([
                'profissional_id' => (int) $profissional['prf_id'],
                ...$dados,
            ]);

            Auditoria::registrar(
                Auditoria::CRIAR, 'pro_experiencias', $id, null, null,
                ['titulo' => $dados['titulo'], 'art_id' => $dados['art_id']],
                $usuarioId, $pdo,
            );

            return $id;
        });
    }

    /**
     * @param array<string, mixed> $entrada
     * @throws ValidacaoException
     */
    public function editar(int $usuarioId, int $experienciaId, array $entrada): void
    {
        $profissional = $this->exigirProfissional($usuarioId);
        $atual        = $this->exigirPropria($usuarioId, $experienciaId, (int) $profissional['prf_id']);
        $dados        = $this->validar($entrada, (string) $profissional['prf_rnp']);

        Database::transacao(function (PDO $pdo) use ($usuarioId, $experienciaId, $atual, $dados): void {
            $this->experiencias->atualizar($experienciaId, $dados);

            // Versionamento do dado autodeclarado: a trilha guarda o antes e o depois, que é o
            // que a operação atômica 4 da proposta pede e o que o item 8.5g cobra.
            Auditoria::registrar(
                Auditoria::EDITAR, 'pro_experiencias', $experienciaId, null,
                ['titulo' => $atual['exp_titulo'], 'descricao' => $atual['exp_descricao'],
                 'art_id' => $atual['exp_art_id'], 'dt_inicio' => $atual['exp_dt_inicio'],
                 'dt_fim' => $atual['exp_dt_fim']],
                $dados,
                $usuarioId, $pdo,
            );
        });
    }

    /** Exclusão lógica: a linha fica, com `exp_status = 'X'` (item 8.6j). */
    public function excluir(int $usuarioId, int $experienciaId): void
    {
        $profissional = $this->exigirProfissional($usuarioId);
        $atual        = $this->exigirPropria($usuarioId, $experienciaId, (int) $profissional['prf_id']);

        Database::transacao(function (PDO $pdo) use ($usuarioId, $experienciaId, $atual): void {
            $this->experiencias->excluir($experienciaId);

            // O título vai no registro: uma linha de auditoria que só diz "A → X" obriga quem
            // for tratar uma denúncia (E6) a caçar o conteúdo excluído para saber do que se
            // tratava.
            Auditoria::registrar(
                Auditoria::EXCLUIR, 'pro_experiencias', $experienciaId, 'exp_status',
                ['status' => STATUS_ATIVO, 'titulo' => $atual['exp_titulo']],
                STATUS_EXCLUIDO, $usuarioId, $pdo,
            );
        });
    }

    // ---------------------------------------------------------------- interno

    /**
     * Valida e normaliza a entrada do formulário.
     *
     * @param array<string, mixed> $entrada
     * @param string $rnp acervo contra o qual a ART informada é conferida
     * @return array{titulo: string, descricao: ?string, art_id: ?int,
     *               dt_inicio: ?string, dt_fim: ?string}
     */
    private function validar(array $entrada, string $rnp): array
    {
        $titulo    = trim((string) ($entrada['titulo'] ?? ''));
        $descricao = trim((string) ($entrada['descricao'] ?? ''));
        $inicio    = trim((string) ($entrada['dt_inicio'] ?? ''));
        $fim       = trim((string) ($entrada['dt_fim'] ?? ''));
        $artBruto  = trim((string) ($entrada['art_id'] ?? ''));

        $v = new Validacao();

        $v->obrigatorio('titulo', $titulo, 'Descreva a experiência em uma linha.')
          ->tamanhoMaximo('titulo', $titulo, self::TITULO_MAXIMO,
              sprintf('No máximo %d caracteres.', self::TITULO_MAXIMO));

        $v->tamanhoMaximo('descricao', $descricao, self::DESCRICAO_MAXIMA,
            sprintf('No máximo %d caracteres.', self::DESCRICAO_MAXIMA));

        $v->data('dt_inicio', $inicio, 'Data de início inválida. Use uma data real.')
          ->data('dt_fim', $fim, 'Data de término inválida. Use uma data real.');

        // Só compara o intervalo se as duas datas passaram: comparar com uma data inválida
        // acrescentaria um segundo erro no mesmo campo, e o primeiro é o que explica o problema.
        if ($v->valido() && $inicio !== '' && $fim !== '' && $fim < $inicio) {
            $v->exigir('dt_fim', false, 'O término não pode ser anterior ao início.');
        }

        $artId = null;

        if ($artBruto !== '') {
            $artId = ctype_digit($artBruto) ? (int) $artBruto : 0;

            $v->exigir('art_id', $artId > 0 && $this->artEhDoProfissional($artId, $rnp),
                'Esta ART não está no seu acervo. Só dá para vincular uma ART que é sua.');
        }

        $v->lancarSeInvalido();

        return [
            'titulo'    => $titulo,
            'descricao' => $descricao === '' ? null : $descricao,
            'art_id'    => $artId,
            'dt_inicio' => $inicio === '' ? null : $inicio,
            'dt_fim'    => $fim === '' ? null : $fim,
        ];
    }

    /**
     * A ART informada está no acervo deste RNP?
     *
     * Uma leitura do acervo por conferência, e não um `SELECT` novo por ART: o acervo já é lido
     * inteiro pela tela do perfil, é pequeno por definição (o de uma pessoa) e a comparação em
     * memória evita mais um caminho de SQL para manter.
     */
    private function artEhDoProfissional(int $artId, string $rnp): bool
    {
        foreach ($this->acervo->porRnp($rnp) as $art) {
            if ((int) $art['art_id'] === $artId) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function exigirProfissional(int $usuarioId): array
    {
        $profissional = $this->profissionais->porUsuario($usuarioId);

        if ($profissional === null) {
            throw new ValidacaoException(
                'Seu registro no CREA ainda não foi validado. Valide-o pelo seu perfil antes de '
                . 'registrar experiências.'
            );
        }

        return $profissional;
    }

    /**
     * A experiência existe, está ativa e é deste profissional?
     *
     * Os três desfechos negativos devolvem a **mesma** mensagem, de propósito: distinguir "não
     * existe" de "não é sua" contaria a quem tentou que aquele `exp_id` pertence a alguém
     * (OWASP A01).
     *
     * @return array<string, mixed>
     */
    private function exigirPropria(int $usuarioId, int $experienciaId, int $profissionalId): array
    {
        $experiencia = $this->experiencias->porId($experienciaId);

        $minha = $experiencia !== null
            && (int) $experiencia['exp_prf_id'] === $profissionalId
            && $experiencia['exp_status'] === STATUS_ATIVO;

        if ($minha) {
            return $experiencia;
        }

        Auditoria::registrar(
            Auditoria::ACESSO_NEGADO, 'pro_experiencias', $experienciaId, null, null,
            ['tentou' => $usuarioId], $usuarioId,
        );

        throw new ValidacaoException('Experiência não encontrada no seu perfil.');
    }
}
