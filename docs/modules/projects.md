# Módulo de projetos

## v0.2.14

- Corrige a exibição da logo nas listagens de projetos e atividades quando a interface é acessada por hostname ou IP diferente de `localhost`.
- Resolve o caminho da imagem usando a origem configurada da API e exibe a inicial do projeto caso o arquivo não possa ser carregado.

## v0.2.0

- Adiciona seletor nativo de diretório no cadastro e mantém a entrada manual de caminho.
- Adiciona seleção visual de tecnologia de frontend, backend e banco de dados.
- Exibe as tecnologias escolhidas, com suas marcas visuais, na listagem de projetos.
- Persiste a stack escolhida e permite alterá-la na edição do projeto.

## v0.2.1

- Remodela a seleção de tecnologias em três faixas visuais para frontend, backend e banco de dados.
- Exibe logos oficiais em cards individuais, com realce claro da tecnologia selecionada.
- Adiciona descrições por categoria e rolagem horizontal dos cards em telas pequenas.

## v0.2.2

- Move o cadastro e a edição de projetos para uma modal dedicada.
- Reduz moderadamente os logos nos seletores para equilibrar a hierarquia visual.
- Exibe as logos da stack ao lado do nome do projeto na listagem de projetos e no quadro de atividades.

## v0.2.3

- Adiciona espaçamento abaixo do nome e das tecnologias nas listagens de projetos e atividades.

## v0.2.4

- Substitui as ações textuais de alterar e excluir por ícones acessíveis.
- Adiciona tooltips azuis e vermelhos de acordo com a ação de cada botão.

## v0.2.5

- Compacta os cards e logos das tecnologias dentro da modal de projetos.
- Adiciona Symfony e Zend às opções de backend.
- Adiciona SQL Server e Oracle às opções de banco de dados.

## v0.2.6

- Permite enviar, pré-visualizar, substituir e remover a logo do projeto.
- Exibe a logo na listagem de projetos e no cabeçalho do projeto no quadro de atividades.

## v0.2.7

- Copia as logos diretamente para `public/project-logos` dentro do Devteam.
- Remove a dependência do link simbólico de armazenamento para exibir as logos.
- Migra automaticamente as imagens já cadastradas para o novo diretório público.
