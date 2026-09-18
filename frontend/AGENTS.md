# Frontend Development Guidelines

Estas instruções complementam o `AGENTS.md` da raiz e se aplicam a todo o conteúdo de `frontend/`. Em caso de conflito, siga este arquivo para alterações no frontend.

## Contexto técnico

- A aplicação usa React 18 com Create React App (`react-scripts`) e JavaScript.
- As requisições HTTP usam Axios por meio de `src/api.js`.
- Os componentes e telas ficam em `src/components/`.
- Os estilos globais ficam em `src/styles.css`.
- A autenticação usa token Bearer e dados do usuário armazenados no `localStorage`.

Não migre para TypeScript, Vite, Tailwind, CSS-in-JS, React Router ou outra stack sem solicitação explícita. Não adicione dependências quando React, APIs do navegador e os pacotes existentes forem suficientes.

## Comandos

Execute os comandos abaixo a partir de `frontend/`:

```sh
npm install
npm start
npm test -- --watchAll=false
npm run build
```

- Use `npm install` para respeitar e atualizar o `package-lock.json`; não substitua o gerenciador de pacotes.
- Antes de concluir uma mudança funcional, execute os testes em modo não interativo e gere o build de produção.
- Não versione `node_modules/`, `build/`, arquivos de cobertura ou artefatos temporários.

## Organização e implementação

- Use componentes funcionais e hooks.
- Mantenha componentes pequenos e focados. Extraia um componente quando uma seção tiver comportamento próprio ou reutilização clara.
- Preserve o padrão de imports relativos existente.
- Centralize chamadas à API na instância exportada por `src/api.js`; não crie instâncias Axios independentes dentro dos componentes.
- Preserve os contratos existentes da API e trate estados de carregamento, vazio, sucesso e erro nas operações assíncronas.
- Evite duplicar estado que possa ser derivado de props ou de outro estado.
- Ao usar efeitos, declare a limpeza necessária para timers, subscriptions e listeners.
- Não faça refatorações amplas nem reformatação de arquivos fora do escopo da tarefa.

## Interface e estilos

- Reutilize classes e padrões visuais existentes em `src/styles.css` antes de criar novas variações.
- Preserve a responsividade e valide as telas nos tamanhos afetados pela mudança.
- Use HTML semântico e mantenha a navegação por teclado.
- Controles interativos devem ter nome acessível, estado de foco visível e atributos ARIA quando o elemento semântico não for suficiente.
- Não use somente cor para comunicar estado ou resultado.
- Textos exibidos ao usuário devem permanecer em português e seguir o vocabulário já usado na interface.

## API, autenticação e segurança

- Configure a URL da API por `REACT_APP_API_URL`; não fixe URLs específicas de ambientes de homologação ou produção no código.
- Preserve o interceptor de autenticação e o tratamento global de respostas `401` em `src/api.js`.
- Nunca exponha tokens, senhas, chaves, dados pessoais ou detalhes internos em código, mensagens de erro, logs ou arquivos versionados.
- Não renderize conteúdo externo como HTML sem uma necessidade explícita e sanitização adequada.
- Mensagens de erro para o usuário devem ser úteis, sem revelar respostas sensíveis do backend.

## Testes e conclusão

- Para comportamentos novos ou corrigidos, adicione testes focados quando houver infraestrutura compatível ou quando a lógica justificar cobertura automatizada.
- Não altere testes para ocultar regressões; corrija o comportamento da aplicação.
- Considere a tarefa concluída somente quando o fluxo solicitado funcionar, não houver erros relevantes no console, os testes aplicáveis passarem e `npm run build` finalizar com sucesso.
