# Módulo de logs

## v0.2.0

- Registra criação, edição e exclusão de projetos.
- Registra criação, início, troca de etapa e finalização de atividades.
- Explica cada evento em texto legível, incluindo projeto, atividade, responsável, resumo e versão quando disponíveis.
- Permite consultar o histórico de todo o Devteam ou filtrar um projeto específico.
- Preserva no histórico global a explicação de projetos removidos.

## v0.2.9

- Separa o histórico do Devteam dos eventos de todos os projetos e de cada projeto individual.
- Substitui a linha do tempo por uma listagem paginada com 5 registros por padrão e opções de 10, 20 e 50.
- Adiciona uma visualização detalhada do registro com descrição, branch e hash do commit.
- Passa a capturar branch e hash informados pelos workers durante o fluxo das atividades.

## v0.2.19

- Cada passo executado por um agente (Desenvolvedor, Testador ou DevOps) passa a guardar o resultado completo: o que foi feito, a solução adotada, o resultado, a mensagem do commit, os arquivos alterados (com linhas somadas e removidas) e o diff do commit.
- O worker lê o commit direto do git depois de cada execução, então os arquivos e o diff vêm do repositório e não dependem do que o agente descreveu. O diff é limitado a 60 mil caracteres.
- O Desenvolvedor passa a explicar a solução no campo `solution` (prompt v3): a causa ou necessidade, a abordagem escolhida e por quê.
- Cada registro de log fica ligado à execução que o gerou. Passos em que a atividade permanece na mesma etapa (por exemplo, publicação bloqueada) também são registrados, com o evento **Etapa executada**.
- Na listagem, a coluna Responsável mostra o agente (com "IA") quando o passo veio de um worker.
- Ao clicar em um passo, o detalhe mostra quem executou (agente, modelo e quem acionou), o resultado, o que foi feito, a solução adotada, o commit, os arquivos, os testes, o diff (carregado sob demanda) e os dados da execução (tentativa, duração, tokens e custo).
- Registros anteriores a esta versão continuam como antes: só o resumo dentro do texto.

