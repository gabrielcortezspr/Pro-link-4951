# Estado do projeto

Sobrescrito a cada fechamento de sessão (`/encerrar`). Nunca acrescentado, histórico é `git log`.
Máximo de ~30 linhas: se passar disso, algo aqui deveria estar num commit ou num doc.

## Última sessão

15/09/2026, tarde e noite. Gabriel: revisão do repositório inteiro, e o feed do demandante.

## Onde parou

Branch `claude/main-branches-status-9k0bun`, seis commits, **não integrada na `main`**.
O motor rodava com quatro das seis dimensões e não tinha porta HTTP; agora tem as seis (D45) e o
feed (D51 a D53, D55). Dois IDOR fechados no caminho. Também: documento cifrado lido num ponto só,
portão de privacidade em lote, CSP conferida por máquina (D50) e endereço de tela relativo (D54).
Verificado: 188 testes · 25 telas · 26 conferências estáticas, 0 violações.

## Próximo passo

**Subir o Docker e rodar os quatro itens de "Lembrar"** — nada abaixo disso vale antes.
Depois, `/admin/sessoes/{id}`: o replay pela semente é o que fecha a prova visual dos itens 10.1
e 12.3, e o mockup está em `docs/mockups/prolink-admin-sessoes.html`.

## Decisões pendentes

- **Servir Bootstrap e as fontes localmente**, fechando a CSP em `'self'` (Consequência da D50):
  hoje a tela aparece sem estilo se a rede do Demo Day filtrar o CDN.
- **Gravar os códigos TOS na sessão** do motor — muda esquema, e sem isso a tela não distingue
  "sem correspondência" de "acrescentado depois da execução".
- MER (`_arq/mer/`) e a declaração de uso de IA (12.3) seguem **sem dono**, ambos obrigatórios na
  entrega. A `decisoes.md` chegou a 55 entradas: a matéria-prima da 12.3 está lá.

## Lembrar

**Mudou configuração que esta sessão não teve como exercitar — o ambiente remoto bloqueia o
download das imagens do Docker. Com o ambiente de pé, nesta ordem:**

1. Abrir uma tela e **olhar o console do navegador**. A CSP foi conferida contra a política real
   num servidor PHP embutido, mas nunca servida pelo nginx; bloqueio de CSP não dá erro visível.
2. `curl -s localhost:8080/saude`. Entrou `try_files $uri =404` no `location ~ \.php$`; se estiver
   errado, **toda** rota dá 404.
3. `verificar-padrao.php` com banco: a parte de HTML renderizado não roda desde a mudança, e as
   cinco telas novas em `amostras.php` estreiam ali. Rode uma compatibilização antes, senão a
   amostra do feed não existe.
4. `verificar-e4.php` e `verificar-e6.php`, que não rodaram.

- **Antes da demonstração: recarregar o banco e rodar `semear-candidatos.php`.** Demanda antiga
  perde o tipo de contrato ao ser editada (Consequência da D45).
- **Escolha a demanda do roteiro pelo índice, nunca pelo tema**: o vínculo ART→TOS da massa é
  aleatório, e o card dirá "mesmo grupo da tabela", com selo, para acervo absurdo. "Localização"
  marca 100% para quase todos — todas as ARTs são de Manaus.
- `verificar-e1.php` falha quando a API responde: CNPJ sintético, D26. Não é regressão.
- `despachar()` existe e nada o chama: nenhum e-mail sai sozinho. Gatilho é da E5.
- Contas: `camila@prolink.local` (admin) e as 17 semeadas, todas com `ProLinkDemo2026!`.
