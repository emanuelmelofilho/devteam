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
