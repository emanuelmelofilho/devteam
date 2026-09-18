# Módulo de métricas

## v0.2.18

- Corrige o custo estimado das execuções: os tokens em cache passam a ser cobrados pela tarifa de cache (cerca de um décimo da entrada), e não mais como entrada normal, o que superestimava o custo em cerca de 4 vezes.
- Para o Claude, o worker envia o custo que o próprio Claude Code apura (`cost_usd`), que passa a valer no lugar da estimativa. A tabela de preços só é usada quando esse valor não vem (Codex).
- A tabela `ai_model_prices` ganha o preço do cache (`cached_input_price_per_million`) e é povoada com os preços do Claude e da OpenAI por `php artisan db:seed --class=AiModelPriceSeeder`. O nome do modelo é comparado por prefixo, e vale a linha mais específica.
- O card **Tokens por agente** mostra também o custo em dólares de cada agente.
- Redução de consumo dos workers do Claude: menos ferramentas carregadas (o contexto por passo cai de cerca de 28 mil para 13 mil tokens), prompts v2 pedindo economia de leituras e comandos, teto de gasto por execução (`CLAUDE_MAX_BUDGET_USD`) e nível de esforço opcional (`CLAUDE_EFFORT`).
- O log dos workers passa a mostrar o número de passos e o custo de cada execução.

## v0.2.17

- Adiciona a seção **Uso de tokens por agente**, com um gráfico de colunas por provedor (OpenAI/Codex e Anthropic/Claude), empilhando por dia os tokens de Desenvolvedor, Testador e DevOps.
- Cada gráfico tem tooltip por dia (também acessível pelo teclado, com as setas) e uma visão em tabela com os mesmos valores.
- Adiciona a linha tracejada de **meta diária ideal**. A meta é definida por provedor na própria seção e persiste no backend (`PUT /kanban/token-goals/{provider}`); sem meta, a linha mostra a média dos dias com uso.
- Registra o provedor em cada execução (`agent_executions.provider`), gravado no envio da tarefa para a fila. As execuções anteriores foram classificadas pelo nome do modelo (`claude*` = Claude, demais = Codex).
- O período e o projeto selecionados nos filtros da página valem também para os novos gráficos.
- As cores dos agentes passaram a ser as mesmas em todos os gráficos da página.
