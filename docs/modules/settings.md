# Módulo de configurações

## v0.2.10

- Adiciona um card para cadastrar ou alterar a chave da API da OpenAI usada pelo Codex.
- Armazena a credencial criptografada no banco de dados com a chave de criptografia do backend.
- Nunca devolve a chave completa para o frontend e apresenta apenas os quatro últimos caracteres.
- Registra a alteração no histórico do Devteam sem incluir o conteúdo da credencial.

## v0.2.11

- Integra a chave cadastrada às execuções dos workers do Codex.
- Usa tokens temporários, vinculados à tarefa, com expiração de cinco minutos e uso único.
- Mantém a chave fora do frontend, das mensagens do RabbitMQ e dos logs.
- Cria uma autenticação efêmera do Codex para cada execução e remove seus arquivos ao final.

## v0.2.16

- Adiciona a escolha do provedor dos agentes (Codex ou Claude) na tela de Configurações; o Codex continua sendo o padrão.
- Adiciona um card para cadastrar ou alterar a chave da API da Anthropic usada pelo Claude Code, com a mesma proteção da chave da OpenAI: criptografada no banco e exibida apenas pelos quatro últimos caracteres.
- Registra no histórico do Devteam a troca de provedor e a atualização da chave, sem incluir o conteúdo da credencial.
- A escolha vale a partir da próxima tarefa enviada aos workers; o provedor viaja na mensagem da fila e o worker pede ao backend somente a credencial temporária desse provedor.
- Sem chave cadastrada para o provedor escolhido, o worker usa o login do CLI já existente no computador.

