# Dependências de terceiros

Exigido pelo edital, Anexo I, item 8.3.2e, e pelo item 6 da minuta de licença institucional
(Anexo V): componentes de terceiros permanecem sob suas licenças originais e devem ser
integralmente declarados.

## Runtime

| Componente | Versão | Licença | Para quê |
|---|---|---|---|
| PHP | 8.2 | PHP License 3.01 | exigido pelo item 8.1.1a |
| MariaDB | 10.11 | GPL-2.0 | exigido pelo item 8.1.2a |
| nginx | 1.27 | BSD-2-Clause | servidor web |

## Composer

Versões **travadas** pelo `composer.lock`, que é o que a banca recebe. O `composer.json` declara
faixas (`^3.8`); o que instala é o lock, e é ele que está aqui.

### Diretas

| Pacote | Versão | Licença | Para quê |
|---|---|---|---|
| `twig/twig` | 3.28.0 | BSD-3-Clause | template engine; item 8.2 veda PHP no HTML |
| `vlucas/phpdotenv` | 5.7.0 | BSD-3-Clause | leitura do `.env`; item 8.3.1j |
| `phpmailer/phpmailer` | 6.12.0 | LGPL-2.1-only | envio SMTP da RF07 |
| `phpunit/phpunit` (dev) | 10.5.x | BSD-3-Clause | testes; não vai no pacote de produção |

### Transitivas

Instaladas como dependência das acima, e presentes em `vendor/`. Declaradas porque o item 8.3.2e
pede a relação de bibliotecas de terceiros, e o Anexo V exige declaração integral — não só do que
foi escolhido diretamente.

| Pacote | Versão | Licença | Vem de |
|---|---|---|---|
| `symfony/polyfill-ctype` | 1.37.0 | MIT | `vlucas/phpdotenv` |
| `symfony/polyfill-mbstring` | 1.38.2 | MIT | `vlucas/phpdotenv`, `twig/twig` |
| `symfony/polyfill-php80` | 1.37.0 | MIT | `vlucas/phpdotenv` |
| `symfony/deprecation-contracts` | 3.7.1 | MIT | `twig/twig` |
| `graham-campbell/result-type` | 1.2.0 | MIT | `vlucas/phpdotenv` |
| `phpoption/phpoption` | 1.10.0 | **Apache-2.0** | `vlucas/phpdotenv` |

Reproduzível a qualquer momento: `composer licenses --no-dev`.

## Front-end

Tudo servido pela própria aplicação. **Nenhuma tela faz requisição a origem externa**, e a
Content-Security-Policy do nginx está fechada em `'self'`, o que torna isso conferível: se algum
template voltasse a apontar para um CDN, o navegador bloquearia e a tela quebraria.

| Componente | Versão | Licença | Onde |
|---|---|---|---|
| Bootstrap (CSS e JS bundle) | 5.3.3 | MIT | `public/assets/vendor/bootstrap/` |
| Inter | subconjuntos latin e latin-ext, pesos 400/500/600/700 | SIL OFL 1.1 | `public/assets/vendor/fontes/` |
| Space Grotesk | subconjuntos latin e latin-ext, pesos 500/600/700 | SIL OFL 1.1 | `public/assets/vendor/fontes/` |

**Por que versionado no repositório e não baixado na instalação.** O Demo Day é presencial e o
item 8.8 pede execução reproduzível: a aparência da plataforma não pode depender da rede do
auditório nem de um CDN continuar no ar. São 988 KB, e a licença das três famílias permite
redistribuição, inclusive embutida.

Das fontes foram baixados só os subconjuntos `latin` e `latin-ext` — cirílico, grego e vietnamita
somariam mais de vinte arquivos que nenhuma tela desta plataforma usa.

## Extensões de PHP

`pdo_mysql`, `mbstring`, `intl`, `zip`, `opcache`, `openssl`, `curl`, `json`. Todas instaladas
pelo `docker/php/Dockerfile`.

## Ferramentas de desenvolvimento (não vão para produção)

| Componente | Para quê |
|---|---|
| Mailpit | captura os e-mails da RF07 em desenvolvimento, sem SMTP real |
| Composer | gerenciamento de dependências; item 8.1.1d |

## Compatibilidade de licença

Todas as licenças acima — MIT, BSD-3-Clause, Apache-2.0, LGPL-2.1-only, SIL OFL 1.1, GPL-2.0 para
o servidor de banco e PHP License 3.01 — são permissivas ou de copyleft fraco, e compatíveis com o
uso institucional previsto no Anexo V. Nenhum componente sob licença restritiva ou de uso
comercial condicionado foi incorporado.

Dois pontos que merecem menção explícita, porque são os únicos que não são permissivos simples:

- **`phpmailer/phpmailer` é LGPL-2.1-only.** A biblioteca é usada sem modificação, por chamada de
  API, o que é o caso que a LGPL permite sem contaminar o trabalho que a usa. Se ela vier a ser
  modificada, as alterações precisam ser publicadas sob LGPL.
- **MariaDB é GPL-2.0**, e roda como serviço separado, em contêiner próprio, acessado por
  protocolo de rede. Não há vinculação de código.
