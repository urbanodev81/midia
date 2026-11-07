# Changelog

## [Não lançado]

### Adicionado
- **v0.2.2:** `midia.url_relativa`: `$midia->url()` sem o domínio, para gravar dentro de texto.
- **v0.2.2:** galeria filtra por `perfil` (`?perfil=capa`; o `CampoDeImagem` só oferece imagem do
  próprio perfil, então capa não recebe foto sem recorte). `GaleriaDeImagens`: slot `passo2` para
  campos do sistema, `tituloPasso2`, `rotuloUsar`, `soDoPerfil`, item sem `uuid` (chave na `url`) e
  `selo` no cartão.
- **v0.2.2:** `midia.storage_link` (padrão `true`): com `false`, para quem serve `/storage/` por alias
  no nginx, o `midia:instalar` não cria o link e o `midia:conferir` não cobra. O `midia:instalar`
  não para mais quando o `storage:link` falha: avisa e segue.
- **v0.2.1:** `midia.pastas_antigas` — pastas de imagens que já existiam antes do pacote e foram
  registradas em `midias` sem mover o arquivo; o `Midia::uuidsNoTexto()` passa a reconhecer o
  endereço antigo dentro do texto (sem isso, a faxina acharia "sem uso" a imagem que está no ar).
- **Arquivo privado (v0.2):** `GuardarArquivo` — tipo pelo conteúdo (`finfo`) contra a lista
  `midia.arquivos.tipos` (PDF, ZIP, JPG, PNG, WebP), SVG/HTML/XML/JS/PHP recusados mesmo se
  configurados, extensão gravada pelo conteúdo, foto regravada pelo perfil novo `original`
  (formato `mesmo`, sem reduzir), limite próprio de 20 MB, disco privado, prazo `expurgo_em`.
- Rota `midia.baixar` (no `Midia::rotas()`): 404 fora do escopo, inválido ou na quarentena;
  permissão `midia.arquivos.permissao`; regra do sistema por `Midia::autorizarDownloadCom()` ou
  `midia.arquivos.autorizar` (só `true` libera). `Midia::resposta()`: attachment, `nosniff`,
  `no-store`, CSP com sandbox; `?ver=1` abre imagem e PDF na aba.
- `Visibilidade::privar()`/`publicar()`: muda o arquivo de disco (o link público morre).
- `midia:expurgar` (lista; apaga só com `--executar`) e, no `midia:conferir`, disco privado
  fora da raiz web e aviso de regra de download ausente.
- `ArquivoRecusado` (a `ImagemRecusada` passa a ser um caso dela); `qualidade` por perfil.

### Mudado
- Coluna `mime` com 120 letras, `largura`/`altura` anuláveis, colunas `privado` e `expurgo_em`
  (na própria migration: a v0.1 não foi instalada em lugar nenhum).
- A galeria lista só imagem pública; a faxina ignora o perfil `arquivo`; a quarentena
  confere a cópia e para com erro se ela não bater, em vez de seguir calada.
- `TratarImagem`: tipo pelo conteúdo (só JPEG, PNG, WebP), teto de 25 MP antes de abrir,
  as 8 orientações do EXIF (inclusive as espelhadas 2, 4, 5 e 7), reencode sem metadado,
  perfis `foto` (WebP 1600), `capa` (JPEG 1200×630 no centro) e `logo` (PNG 600, transparente).
- `GuardarImagem` e as tabelas `midias` (uuid, escopo, medidas, bytes, sha256, nome original,
  descrição, autor) e `midia_usos`; caminho `midias/{escopo}/{AAAA}/{MM}/{uuid}.{ext}`.
- Escopo por cliente configurável (coluna, resolver, obrigatório com falha fechada) e
  `Midia::semEscopo()` para tarefa do sistema.
- `UsaMidias` (`sincronizarMidias`, `Midia::uuidsNoTexto`) e a faxina em quarentena
  (`midia:faxinar`, `midia:restaurar`): move, não apaga.
- `midia:instalar` (idempotente) e `midia:conferir`.
- `Midia::rotas()` (galeria e envio, com permissão opcional) e os componentes
  `GaleriaDeImagens` e `CampoDeImagem` (`<dialog>` nativo, sem dependência do sistema).
- Nasceu da pendência 255: junta urbisdev (`GuardaImagens`, `ImagemDoSite`, galeria),
  Synapse (`ImagemDoSite`) e BHD (`Redesenho`).
