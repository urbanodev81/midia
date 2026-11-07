# Changelog

## [Não lançado]

### Adicionado
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
