<?php

declare(strict_types=1);

namespace ProLink\Support;

use DateTimeImmutable;

/**
 * As preferências da demanda viram perguntas para quem manifesta interesse (D78).
 *
 * A empresa diz, ao publicar, o regime de contrato, o local da obra e até quando precisa que o
 * trabalho comece. Quem manifesta interesse responde a cada uma, e a empresa vê as respostas lado
 * a lado com o que pediu, na lista de interessados. Antes disso, o motor só sabia dessas coisas
 * se o profissional tivesse preenchido a aba Preferências por conta própria, sem saber que alguma
 * demanda ia perguntar, e o cartão dizia "não medida nesta sessão".
 *
 * **As respostas não entram no motor.** O conjunto de compatíveis é montado antes de alguém
 * manifestar, e ninguém entra nele respondendo "sim" a tudo. Elas servem à decisão da empresa, na
 * hora de avaliar quem se apresentou. Por isso o quadro diz "atende" ou "não atende", e nunca
 * vira porcentagem nem nota.
 *
 * Classe sem estado e sem banco: a regra mora aqui e tem teste.
 */
final class RespostaInteresse
{
    public const SIM       = 'S';
    public const NAO       = 'N';
    public const CONVERSAR = 'C';

    /** O prazo mais distante que se aceita como resposta de início. */
    private const INICIO_MAXIMO = '+2 years';

    /**
     * Quais perguntas a demanda faz.
     *
     * Contrato só quando a empresa escolheu um regime: "qualquer regime" não tem o que perguntar.
     * Região só quando a obra tem local. Início sempre, porque "quando você pode começar" ajuda a
     * empresa a decidir mesmo com o prazo em aberto.
     *
     * @param array<string, mixed> $demanda linha de `pro_demandas`
     * @return array{contrato: ?string, local: ?array{uf: string, municipio: ?string}, inicio_ate: ?string}
     */
    public static function perguntas(array $demanda): array
    {
        $contrato = $demanda['dem_tipo_contrato'] ?? null;
        $uf       = $demanda['dem_local_uf'] ?? null;

        return [
            'contrato'   => ($contrato === null || $contrato === '' || $contrato === Preferencias::QUALQUER)
                ? null
                : (string) $contrato,
            'local'      => ($uf === null || $uf === '')
                ? null
                : ['uf' => (string) $uf, 'municipio' => ($demanda['dem_local_municipio'] ?? null) ?: null],
            'inicio_ate' => ($demanda['dem_inicio_ate'] ?? null) ?: null,
        ];
    }

    /**
     * Confere as respostas do formulário contra as perguntas que a demanda faz.
     *
     * Pergunta feita é resposta obrigatória: um campo em branco deixaria a empresa sem saber se a
     * pessoa não viu a pergunta ou não quis responder. Pergunta que a demanda não faz é ignorada,
     * mesmo que o formulário a mande.
     *
     * @param array<string, mixed> $entrada campos crus: aceita_contrato, atende_local, inicio_em
     * @param array<string, mixed> $demanda
     * @return array{aceita_contrato: ?string, atende_local: ?string, inicio_em: string}
     * @throws \ProLink\Service\ValidacaoException
     */
    public static function validar(array $entrada, array $demanda, string $hoje): array
    {
        $perguntas = self::perguntas($demanda);
        $contrato  = mb_strtoupper(trim((string) ($entrada['aceita_contrato'] ?? '')));
        $local     = mb_strtoupper(trim((string) ($entrada['atende_local'] ?? '')));
        $inicio    = trim((string) ($entrada['inicio_em'] ?? ''));

        $v = new Validacao();

        if ($perguntas['contrato'] !== null) {
            $v->entre('aceita_contrato', $contrato, [self::SIM, self::NAO, self::CONVERSAR],
                'Diga se você aceita o regime de contrato desta demanda.');
        }

        if ($perguntas['local'] !== null) {
            $v->entre('atende_local', $local, [self::SIM, self::NAO],
                'Diga se você atende a região desta obra.');
        }

        $data   = DateTimeImmutable::createFromFormat('!Y-m-d', $inicio);
        $valida = $data !== false && $data->format('Y-m-d') === $inicio;
        $limite = (new DateTimeImmutable($hoje))->modify(self::INICIO_MAXIMO)->format('Y-m-d');

        $v->exigir('inicio_em', $valida, 'Informe a data em que você pode começar.');

        if ($valida) {
            $v->exigir('inicio_em', $inicio >= substr($hoje, 0, 10),
                'A data de início não pode ser anterior a hoje.');
            $v->exigir('inicio_em', $inicio <= $limite,
                'Informe uma data de início nos próximos dois anos.');
        }

        $v->lancarSeInvalido();

        return [
            'aceita_contrato' => $perguntas['contrato'] === null ? null : $contrato,
            'atende_local'    => $perguntas['local'] === null ? null : $local,
            'inicio_em'       => $inicio,
        ];
    }

    /**
     * O que a empresa pediu e o que a pessoa respondeu, linha a linha, para a lista de
     * interessados e para a conversa.
     *
     * `atende` é true, false ou null (quando não há o que comparar: prefere conversar, prazo em
     * aberto, ou pergunta não respondida). A tela dá cor a true e false e deixa null neutro.
     *
     * @param array<string, mixed> $demanda
     * @param array<string, mixed> $manifestacao linha de `pro_manifestacoes`
     * @return list<array{chave: string, rotulo: string, pedido: string, resposta: string, atende: ?bool}>
     */
    public static function quadro(array $demanda, array $manifestacao): array
    {
        $perguntas = self::perguntas($demanda);
        $linhas    = [];

        if ($perguntas['contrato'] !== null) {
            $resposta = $manifestacao['man_aceita_contrato'] ?? null;

            $linhas[] = [
                'chave'    => 'contrato',
                'rotulo'   => 'Contrato',
                'pedido'   => Preferencias::CONTRATOS[$perguntas['contrato']] ?? $perguntas['contrato'],
                'resposta' => match ($resposta) {
                    self::SIM       => 'Aceita',
                    self::NAO       => 'Não aceita',
                    self::CONVERSAR => 'Prefere conversar',
                    default         => 'Não respondeu',
                },
                'atende'   => match ($resposta) {
                    self::SIM => true,
                    self::NAO => false,
                    default   => null,
                },
            ];
        }

        if ($perguntas['local'] !== null) {
            $resposta = $manifestacao['man_atende_local'] ?? null;
            $lugar    = $perguntas['local']['municipio'] !== null
                ? $perguntas['local']['municipio'] . '/' . $perguntas['local']['uf']
                : $perguntas['local']['uf'];

            $linhas[] = [
                'chave'    => 'local',
                'rotulo'   => 'Local da obra',
                'pedido'   => $lugar,
                'resposta' => match ($resposta) {
                    self::SIM => 'Atende a região',
                    self::NAO => 'Não atende a região',
                    default   => 'Não respondeu',
                },
                'atende'   => match ($resposta) {
                    self::SIM => true,
                    self::NAO => false,
                    default   => null,
                },
            ];
        }

        $inicio = ($manifestacao['man_inicio_em'] ?? null) ?: null;
        $ate    = $perguntas['inicio_ate'];

        $linhas[] = [
            'chave'    => 'inicio',
            'rotulo'   => 'Início',
            'pedido'   => $ate === null ? 'Prazo em aberto' : 'Até ' . self::data($ate),
            'resposta' => $inicio === null ? 'Não informou' : 'Pode começar em ' . self::data($inicio),
            'atende'   => ($inicio === null || $ate === null) ? null : substr($inicio, 0, 10) <= substr($ate, 0, 10),
        ];

        return $linhas;
    }

    private static function data(string $iso): string
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', substr($iso, 0, 10));

        return $d === false ? $iso : $d->format('d/m/Y');
    }
}
