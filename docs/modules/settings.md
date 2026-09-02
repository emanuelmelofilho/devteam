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
