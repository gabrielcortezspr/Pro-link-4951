# Dependências de terceiros

Exigido pelo edital, Anexo I, item 8.3.2e, e pelo item 6 da minuta de licença institucional
(Anexo V): componentes de terceiros permanecem sob suas licenças originais e devem ser
integralmente declarados.

## Runtime

| Componente | Versão | Licença | Para quê |
|---|---|---|---|
| PHP | 8.2.33 | PHP License 3.01 | exigido pelo item 8.1.1a |
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
| `phpunit/phpunit` (dev) | 10.5.64 | BSD-3-Clause | testes; não vai no pacote de produção |

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

### Transitivas de desenvolvimento

Instaladas por `phpunit/phpunit` e presentes em `vendor/` na máquina de quem desenvolve. Não são
servidas, não entram no contêiner de produção e o `.zip` da entrega não carrega `vendor/`. Estão
aqui porque o Anexo V pede declaração integral, e a lista completa sai de `composer licenses`:

| Pacote | Versão | Licença |
|---|---|---|
| `myclabs/deep-copy` | 1.14.0 | MIT |
| `nikic/php-parser` | 5.8.0 | BSD-3-Clause |
| `phar-io/manifest` | 2.0.4 | BSD-3-Clause |
| `phar-io/version` | 3.2.1 | BSD-3-Clause |
| `phpunit/php-code-coverage` | 10.1.16 | BSD-3-Clause |
| `phpunit/php-file-iterator` | 4.1.0 | BSD-3-Clause |
| `phpunit/php-invoker` | 4.0.0 | BSD-3-Clause |
| `phpunit/php-text-template` | 3.0.1 | BSD-3-Clause |
| `phpunit/php-timer` | 6.0.0 | BSD-3-Clause |
| `sebastian/cli-parser` | 2.0.1 | BSD-3-Clause |
| `sebastian/code-unit` | 2.0.0 | BSD-3-Clause |
| `sebastian/code-unit-reverse-lookup` | 3.0.0 | BSD-3-Clause |
| `sebastian/comparator` | 5.0.5 | BSD-3-Clause |
| `sebastian/complexity` | 3.2.0 | BSD-3-Clause |
| `sebastian/diff` | 5.1.1 | BSD-3-Clause |
| `sebastian/environment` | 6.1.0 | BSD-3-Clause |
| `sebastian/exporter` | 5.1.4 | BSD-3-Clause |
| `sebastian/global-state` | 6.0.2 | BSD-3-Clause |
| `sebastian/lines-of-code` | 2.0.2 | BSD-3-Clause |
| `sebastian/object-enumerator` | 5.0.0 | BSD-3-Clause |
| `sebastian/object-reflector` | 3.0.0 | BSD-3-Clause |
| `sebastian/recursion-context` | 5.0.2 | BSD-3-Clause |
| `sebastian/type` | 4.0.0 | BSD-3-Clause |
| `sebastian/version` | 4.0.1 | BSD-3-Clause |
| `theseer/tokenizer` | 1.3.1 | BSD-3-Clause |

Todas BSD-3-Clause ou MIT.

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
auditório nem de um CDN continuar no ar. São 953 KB em `public/assets/vendor/`, e a licença das
três famílias permite redistribuição, inclusive embutida.

Das fontes foram baixados só os subconjuntos `latin` e `latin-ext` — cirílico, grego e vietnamita
somariam mais de vinte arquivos que nenhuma tela desta plataforma usa.

## Extensões de PHP

`pdo_mysql`, `mbstring`, `intl`, `zip`, `opcache`, `openssl`, `curl`, `json`. Todas instaladas
pelo `docker/php/Dockerfile`.

## Ferramentas de desenvolvimento (não vão para produção)

| Componente | Versão | Licença | Para quê |
|---|---|---|---|
| Mailpit | latest | MIT | captura os e-mails da RF07 em desenvolvimento, sem SMTP real |
| Composer | 2.10.3 | MIT | gerenciamento de dependências; item 8.1.1d |
| PHPUnit | 10.5.64 | BSD-3-Clause | a suíte de `tests/`; vem por `composer require --dev` e já está na tabela do Composer acima |
| Node.js e npm | 18+ | MIT | só para rodar o Playwright e `scripts/mer-pdf.mjs`; nada de JavaScript da aplicação é compilado |
| `@playwright/test` | 1.62.0 | Apache-2.0 | a suíte de ponta a ponta em `e2e/`: os seis cenários do Anexo I, as sondas de autorização, a régua de fidelidade ao desenho, as telas em 390px e os quatro vídeos |
| Python 3 | 3.11+ | PSF | `scripts/atualizar_tos.py` (carga da TOS) e `scripts/gerar-mer.py` (o MER) |

Nenhuma destas é servida pela aplicação nem entra no contêiner de produção. O Playwright roda na
máquina de quem desenvolve, contra a aplicação já de pé, e o diretório `e2e/node_modules/` está no
`.gitignore`: o `.zip` da entrega não o carrega, como não carrega `vendor/`.

O Chromium que o Playwright baixa é do próprio projeto Chromium, BSD-3-Clause com componentes de
terceiros nas respectivas licenças, e também não é distribuído por nós.

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
