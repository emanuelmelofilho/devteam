# Módulo de atividades


## v0.2.20

- Adiciona o campo **Tipo de alteração** ao criar uma tarefa: Nova funcionalidade, Melhoria de funcionalidade ou Correção de funcionalidade. A escolha é obrigatória no formulário e aparece no detalhe da tarefa.
- O tipo define qual número da versão o DevOps aumenta ao publicar, seguindo o versionamento semântico (os números à direita voltam a zero): nova funcionalidade sobe o primeiro (1.0.12 → 2.0.0), melhoria sobe o segundo (1.0.12 → 1.1.0) e correção sobe o terceiro (1.0.12 → 1.0.13).
- As tarefas existentes e as criadas sem tipo pela API contam como correção, que é o comportamento anterior (versão de correção).
- Remove a raia **Bloqueado** do quadro. A tarefa continua podendo ser bloqueada pelo orquestrador, mas deixa de ser exibida no quadro.
- Remove a central de notificações do cabeçalho.

## v0.2.15

- Persiste branch e hash do commit nos metadados da tarefa ao encaminhá-la para revisão.
- Evita que a transição para o Tester seja interrompida pela ausência da coluna de metadados.

## v0.2.12

- Remove o limite mínimo de 600 caracteres da solicitação ao criar uma tarefa.
- Mantém a descrição obrigatória, permitindo solicitações curtas quando forem suficientes.

## v0.2.8

- Organiza cada projeto do quadro de atividades em um acordeão independente.
- Mantém o primeiro projeto aberto inicialmente e permite expandir ou recolher os demais pelo cabeçalho.
- Preserva no cabeçalho recolhido a logo, as tecnologias, a descrição e o total de tarefas do projeto.
