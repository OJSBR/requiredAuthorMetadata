# Required Author Metadata — OJS / OMP plugin

[![OJS](https://img.shields.io/badge/OJS-3.5-brightgreen)](https://pkp.sfu.ca/ojs/)
[![OMP](https://img.shields.io/badge/OMP-3.5-brightgreen)](https://pkp.sfu.ca/omp/)
[![Version](https://img.shields.io/badge/version-1.0.1.0-blue)](version.xml)
[![License](https://img.shields.io/badge/license-GPL--3.0-lightgrey)](LICENSE)

**⬇️ Install package:** [OJS / OMP 3.5](https://github.com/OJSBR/requiredAuthorMetadata/releases/download/1.0.1.0/requiredAuthorMetadata-1.0.1.0.tar.gz) — or browse all [Releases](../../releases).

A generic plugin for **OJS** and **OMP** that lets a journal require the **affiliation** and
the **biography** of every contributor of a submission — each one on its own — and refuse to
let the submission be completed while either is missing. Whoever runs the journal can be
left out of it, so an editor keeps the autonomy to record and to correct a submission that
is still incomplete.

> **Developed and maintained by [OJSBR](https://ojsbr.com).** See the
> [Credits & authorship](#credits--authorship) section below.

## Compatibility & branches

| Application | Branch | Plugin release |
|-------------|--------|----------------|
| OJS 3.5.x and OMP 3.5.x | [`stable-3_5_0`](../../tree/stable-3_5_0) *(default)* | 1.0.1.0 |

The same package serves both applications.

## What it does

- Requires the **affiliation** (institution) of every author and co-author of a submission,
  if the journal asks for it.
- Requires the **biography summary** of every author and co-author, if the journal asks for
  it — independently of the affiliation.
- Optionally **stops the submission from being completed** while any contributor is missing
  one of them, naming who is missing what, where the wizard shows its own errors.
- Optionally **leaves journal managers and section editors out of all of it**, so the
  editorial side keeps its autonomy. This one is on by default.
- Changes nothing until a box is ticked: a journal that enables the plugin and chooses
  nothing requires nothing. The plugin never writes a setting on its own.

The core already requires one author field this way — the competing interests statement,
through **Settings → Workflow → Metadata**. This plugin is the same idea for two more
fields, plus the gate at the end of the submission.

## Installation

1. Install via **Settings → Website → Plugins → Upload A New Plugin**, or extract the folder
   into `plugins/generic/` so you get `plugins/generic/requiredAuthorMetadata/`.
2. Enable **Required author metadata** under the *Generic* plugins list.

## Configuration

**Settings → Website → Plugins → Required author metadata → Settings.**

| Setting | Default | What it does |
| --- | --- | --- |
| Affiliation (institution) | off | The affiliation is required whenever a contributor is saved — in the submission wizard and in *Edit contributor*. |
| Biography summary | off | The same for the biography, in the language of the submission. |
| Do not let the submission be completed while a contributor is missing one of them | off | The *Submit* button is refused, and the contributors panel of the review step says who is missing what. |
| Journal managers and section editors are exempt | **on** | Those two roles are left out of the three checks above. An assistant follows the rule like everybody else. |

The first two settings decide what is asked for; the third decides whether an incomplete
submission can still be completed. Requiring a field without the third setting still keeps
the form honest: a contributor cannot be saved without it, but a submission whose
contributors were recorded before the journal changed its mind is not blocked.

## How it works (technical)

Three hooks, in the order the author meets them, and no core template is replaced:

1. `Form::config::before` → marks the `affiliations` and `biography` fields **that already
   exist** in the `ContributorForm` as required. The asterisk lands on the right label and
   the form checks the field before sending anything. No other field is touched. Mind the
   signature: the core fires this one with `Hook::run()`, which spreads its arguments, so the
   form arrives as the second parameter — and a callback declared otherwise raises a
   `TypeError` that the core catches and only writes to the error log, leaving the plugin
   silently inert. The test suite fires the hook the way the core does, for that reason.
2. `Author::validate` → refuses the save. This is the guarantee: the form is a courtesy and
   the REST endpoint is what stores the data. A save that carries no key for the field at
   all is still checked against what the contributor would be left with.
3. `Submission::validateSubmit` → refuses to complete the submission. The message goes under
   the **`contributors`** key — the same one the core uses for its own contributor errors —
   so it is shown in the contributors panel of the review step, and it is **appended**, never
   assigned, so that it lives beside whatever another plugin has held against the same
   submission.

The exemption is read once, from the roles the acting user holds in that journal
(`ROLE_ID_MANAGER`, `ROLE_ID_SUB_EDITOR`), and honoured everywhere the plugin acts.

**The required mark.** The biography gets the application's own mark from `isRequired`.
The affiliations field of PKP 3.5 draws its own heading and ignores what the form says —
the prop is not declared by the component and ends up as an attribute on the element — so
that one label is marked by a style of the plugin's own, in the same colour the application
uses for every other required field. Since 1.0.1.0 both are marked.

**One language, not all of them.** The institution is required in the language of the
submission. The affiliation editor of the application offers the name in the other languages
of the journal as well, and says how many are filled ("1 of 3 languages"), but adding an
institution with a single language works and is all this plugin asks for.

**What counts as missing.** An affiliation entry with neither a name in any language nor an
organization identifier (ROR) is not an affiliation; a contributor with no entry at all is
missing it. A biography is missing when it is empty in the language of the submission once
the markup is stripped — an empty paragraph, or the non-breaking space a rich text editor
leaves behind, is empty.

**Living with other plugins.** This plugin only ever adds errors under its own keys, and
returns `Hook::CONTINUE`, so it neither hides nor is hidden by another plugin on the same
hooks. With OJSBR's [orcidManualEntry](https://github.com/OJSBR/orcidManualEntry) enabled and
both rules on, the author sees both reasons at once, not whichever ran last — there is a test
for exactly that.

## Tests

- **PHPUnit** (`tests/*Test.php`, on `PKP\tests\PKPTestCase`): the plugin class against the
  installed PKP and its registry, that every imported class exists, the defaults of each
  setting, what counts as a missing affiliation (no entry, an entry with nothing in it, a
  name of spaces, an identifier alone) and as a missing biography (`<p>&nbsp;</p>` included),
  who is exempt, the keys the rules are hung on, and the 38 translations. From the OJS root:

  ```bash
  lib/pkp/lib/vendor/bin/phpunit --configuration lib/pkp/tests/phpunit.xml --no-coverage "$PWD/plugins/generic/requiredAuthorMetadata/tests"
  ```

  The suite also runs against the **database** of the installation: a submission is created
  with a contributor who has neither field, the core's own validation of the last step is
  called, and each field has to be reported on its own — with the journal manager completing
  it anyway while the exemption is on, and held to the rule once it is off. The submission it
  creates is deleted, and the test skips itself where the installation looks like a live site.

- **Cypress** (`cypress/tests/functional/RequiredAuthorMetadata.cy.js`, run by
  [pkp-github-actions](https://github.com/pkp/pkp-github-actions) on every push): enables the
  plugin, reads and saves its settings form, then works through the REST endpoints the
  contributor form uses — a contributor with neither field refused with both errors, one with
  only the affiliation refused for the biography alone, one with both accepted, nothing held
  where the journal asks for nothing, the submission turned down by the very request the
  *Submit* button makes and let through once the journal stops asking, and the autonomy of
  whoever runs the journal. It works on an installation with no submission of its own: it
  creates one and deletes it, and puts the settings back as it found them.

- Both suites are run by `.github/actions/tests.sh`, so a failure in either one fails the job.
- Verified on OJS 3.5.0.3 and OMP 3.5.0.3 (29 unit tests and 7 browser tests on each), with
  the orcidManualEntry plugin enabled alongside it.

Tests are kept in the repository and are not part of the release package.

## Credits & authorship

- **Developed and maintained by** [OJSBR](https://ojsbr.com) — original plugin.
- Distributed under the **GNU GPL v3**, the same license as OJS.

## AI use

Generative AI (Claude Opus 5, by Anthropic) was used to write and run tests, improve the code
and bring it in line with PKP standards. Every change is reviewed and tested by OJSBR, which
is responsible for the published releases.

## Contributing

Issues and pull requests are welcome.

## License

Distributed under the **GNU GPL v3**. See [`LICENSE`](LICENSE) and `docs/COPYING`.

---

## 🇧🇷 Português

Plugin genérico para **OJS** e **OMP** que permite à revista exigir a **afiliação** e a
**biografia** de todos os autores e coautores de uma submissão — cada uma separadamente — e
impedir a conclusão da submissão enquanto faltar alguma. Quem administra a revista pode ficar
de fora da exigência, de modo que o editor mantém autonomia para cadastrar e corrigir uma
submissão ainda incompleta.

> **Desenvolvido e mantido pela [OJSBR](https://ojsbr.com).** Veja os
> [Créditos e autoria](#créditos-e-autoria).

### Compatibilidade e branches

| Aplicação | Branch | Versão do plugin |
|-----------|--------|------------------|
| OJS 3.5.x e OMP 3.5.x | [`stable-3_5_0`](../../tree/stable-3_5_0) *(padrão)* | 1.0.1.0 |

O mesmo pacote serve as duas aplicações.

### O que faz

- Exige a **afiliação** (instituição) de todos os autores e coautores da submissão, se a
  revista pedir.
- Exige o **resumo da biografia** de todos, se a revista pedir — independente da afiliação.
- Opcionalmente **impede a conclusão da submissão** enquanto faltar em algum autor, dizendo
  de quem e do quê, no lugar onde o assistente mostra os próprios erros.
- Opcionalmente **deixa gestores da revista e editores de seção fora de tudo isso**, para o
  lado editorial manter sua autonomia. Essa opção já vem ligada.
- Não muda nada até alguém marcar uma caixa: revista que habilita o plugin e não escolhe
  nada não exige nada. O plugin nunca grava configuração por conta própria.

O núcleo já exige um campo de autoria assim — a declaração de conflito de interesses, em
**Configurações → Fluxo de Trabalho → Metadados**. Este plugin é a mesma ideia para mais dois
campos, com o bloqueio no fim da submissão.

### Instalação

Instale em **Configurações → Website → Plugins → Enviar um novo plugin**, ou extraia a pasta
em `plugins/generic/` (ficando `plugins/generic/requiredAuthorMetadata/`). Depois ative
**Metadados obrigatórios de autoria** na lista de plugins *Genéricos*.

### Configuração

**Configurações → Website → Plugins → Metadados obrigatórios de autoria → Configurações.**

| Opção | Padrão | O que faz |
| --- | --- | --- |
| Afiliação (instituição) | desligada | A afiliação passa a ser exigida sempre que um autor é gravado — no assistente de submissão e em *Editar contribuidor*. |
| Resumo da biografia | desligada | O mesmo para a biografia, no idioma da submissão. |
| Impedir a conclusão da submissão enquanto faltar em algum autor | desligada | O botão *Enviar* é recusado e o painel de contribuidores da etapa de revisão diz de quem falta o quê. |
| Gestores da revista e editores de seção ficam isentos | **ligada** | Esses dois papéis ficam fora das três conferências acima. O assistente de edição segue a regra como qualquer um. |

As duas primeiras opções decidem o que é pedido; a terceira decide se uma submissão
incompleta ainda pode ser concluída. Exigir um campo sem ligar a terceira já mantém o
cadastro honesto: o autor não é gravado sem ele, mas uma submissão cujos autores foram
cadastrados antes de a revista mudar de ideia não fica travada.

### Como funciona (técnico)

Três hooks, na ordem em que o autor os encontra, sem substituir nenhum template do núcleo:

1. `Form::config::before` → marca como obrigatórios os campos `affiliations` e `biography`
   **que já existem** no `ContributorForm`. O asterisco cai no rótulo certo e o próprio
   formulário confere antes de enviar. Nenhum outro campo é tocado. Atenção à assinatura: o
   núcleo dispara esse hook com `Hook::run()`, que **espalha** os argumentos, então o
   formulário chega como segundo parâmetro — e um callback declarado de outro jeito estoura
   `TypeError`, que o núcleo captura e só escreve no error_log, deixando o plugin inerte e
   calado. É por isso que a suíte dispara o hook exatamente como o núcleo dispara.
2. `Author::validate` → recusa a gravação. É essa a garantia: o formulário é conveniência, e
   quem grava é o endpoint REST. Gravação que não manda a chave do campo também é conferida
   pelo que sobraria no contribuidor.
3. `Submission::validateSubmit` → recusa a conclusão. A mensagem vai na chave
   **`contributors`**, a mesma que o núcleo usa para os erros de autoria, e é **acrescentada**,
   nunca atribuída, para conviver com o que outro plugin já tenha posto contra a mesma
   submissão.

A isenção é lida uma vez, dos papéis que a pessoa tem naquela revista
(`ROLE_ID_MANAGER`, `ROLE_ID_SUB_EDITOR`), e vale em todo lugar onde o plugin age.

**O asterisco.** A biografia recebe a marca do próprio aplicativo pelo `isRequired`. O campo
de afiliações do 3.5 desenha o próprio cabeçalho e ignora o que o formulário diz — o
componente não declara essa propriedade, que acaba virando atributo no elemento —, então
aquele rótulo é marcado por um estilo do plugin, na mesma cor que o aplicativo usa em todo
campo obrigatório. Desde a 1.0.1.0 os dois aparecem marcados.

**Um idioma, não todos.** A instituição é exigida no idioma da submissão. O editor de
afiliações do aplicativo oferece o nome nos outros idiomas da revista e informa quantos estão
preenchidos ("1 de 3 idiomas"), mas incluir a instituição com um idioma só funciona — e é só
isso que este plugin cobra.

**O que conta como faltando.** Entrada de afiliação sem nome em nenhum idioma e sem
identificador (ROR) não é afiliação; quem não tem entrada nenhuma está sem. A biografia está
faltando quando está vazia no idioma da submissão depois de tirar a marcação — parágrafo
vazio, ou o espaço não separável que o editor de texto rico deixa, é vazio.

**Convivência com outros plugins.** Este plugin só acrescenta erro nas chaves dele e devolve
`Hook::CONTINUE`: não esconde nem é escondido por outro plugin nos mesmos hooks. Com o
[orcidManualEntry](https://github.com/OJSBR/orcidManualEntry) ligado e as duas regras ativas,
o autor vê os dois motivos ao mesmo tempo, não o último — e há teste exatamente para isso.

### Testes

PHPUnit em `tests/` e Cypress em `cypress/tests/functional/` (rodado pelo
[pkp-github-actions](https://github.com/pkp/pkp-github-actions) a cada push), com os comandos
da seção em inglês. A suíte roda também contra o **banco** da instalação — submissão criada
com autor sem os dois campos, validação do núcleo chamada, cada campo cobrado por si, o gestor
concluindo assim mesmo enquanto a isenção está ligada e preso à regra quando ela é desligada —
e, no navegador, prova a recusa do contribuidor pelos mesmos endpoints REST do formulário e o
bloqueio pela própria requisição do botão *Enviar*. Verificado no OJS 3.5.0.3 e no OMP
3.5.0.3: 29 testes de unidade e 7 de navegador em cada, com o orcidManualEntry ligado junto.

Os testes ficam no repositório e não fazem parte do pacote da release.

### Créditos e autoria

- **Desenvolvido e mantido pela** [OJSBR](https://ojsbr.com) — plugin autoral.
- Distribuído sob a **GNU GPL v3**, a mesma licença do OJS.

### Uso de IA

Foi usada IA generativa (Claude Opus 5, da Anthropic) para escrever e rodar testes, melhorar o
código e alinhá-lo aos padrões da PKP. Toda mudança é revisada e testada pela OJSBR, que
responde pelas releases publicadas.

### Licença

Distribuído sob a **GNU GPL v3**. Veja [`LICENSE`](LICENSE) e `docs/COPYING`.
