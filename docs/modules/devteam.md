# Devteam

## v0.2.13

- Move o runtime e os logs dos workers para pastas da biblioteca do usuário no macOS.
- Evita o bloqueio de privacidade aplicado a LaunchAgents que gravavam dentro de `Documents`.
- Configura os workers para acessar a API local do Devteam na porta `8001`.
- Inclui as tabelas de cache exigidas pelo limitador de requisições da troca segura da chave Codex.
- Mantém Developer, Tester e DevOps como serviços persistentes gerenciados pelo `launchd`.

## v0.2.0

- Adiciona a área **Logs** à navegação principal.
- Adiciona a central de notificações ao cabeçalho.
- Integra projetos e atividades a um histórico auditável e explicado.
- Padroniza a documentação das mudanças por módulo e por versão.
