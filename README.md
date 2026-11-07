# urbanodev/midia

Upload de imagem da casa para qualquer sistema Laravel 12 ou 13. Nenhuma imagem
é publicada como chegou: o GD abre, **endireita** (as 8 orientações do EXIF,
inclusive as espelhadas), **reduz ou recorta** e grava um arquivo novo, **sem
EXIF** — sem o GPS de onde a foto foi tirada, sem o modelo do celular. O tipo é
conferido pelo **conteúdo** (só JPEG, PNG e WebP; SVG e HTML nunca).

Cada imagem vira uma linha em `midias` (uuid, medidas, bytes, sha256, nome
original, quem subiu, escopo do cliente), e cada lugar onde ela aparece vira uma
linha em `midia_usos`. Com isso a galeria mostra "Usada em ..." e a faxina sabe
o que está sobrando — e **move para a quarentena, nunca apaga**.

Nasceu da pendência 255 (07/11/2026), juntando o que estava espalhado: a
experiência do urbisdev (galeria, recorte, medidas), a conferência por conteúdo
e a faxina com carência do Synapse, e o modelo de arquivo do BHD. GD puro, sem
Imagick: roda no cPanel.

**v0.2 cobre imagem pública e arquivo privado** (PDF, ZIP, foto em alta): disco
fora da raiz web, download só pela rota autorizada, troca entre público e
privado que muda o arquivo de disco, e prazo de guarda (LGPD).

## Instalar

```bash
composer config repositories.midia vcs https://github.com/urbanodev81/midia
composer require urbanodev/midia:^0.2
php artisan midia:instalar
```

O `midia:instalar` publica o `config/midia.php` (se ainda não existe), cria as
duas tabelas, faz o `storage:link` e roda o `midia:conferir`. Pode rodar de novo:
não duplica nem quebra. **Ele não liga nada sozinho** — nem rota, nem menu, nem
agendamento.

Banco por tenant (stancl): `MIDIA_CARREGAR_MIGRATIONS=false` e
`php artisan vendor:publish --tag=midia-migrations`, movendo para
`database/migrations/tenant`.

## Conferir o servidor

```bash
php artisan midia:conferir
```

Lista, item por item: gd com JPEG/PNG/WebP, exif (sem ela a foto em pé sai
deitada), `upload_max_filesize` e `post_max_size` contra o `limite_mb` do
pacote, as tabelas, o `public/storage`, se o disco privado está fora da raiz
web e, se o escopo for obrigatório, se há resolver (e avisa quando falta a regra
de download). Sai com erro se faltar algo. **Lembrete que ele não consegue conferir:**
o nginx precisa de `client_max_body_size` maior que o `limite_mb`, senão a tela
recebe 413.

## Sistema com cliente (multi-inquilino ou acesso por cliente)

SSB, USI, Synapse, Movana, BHD, HDU: **ligue antes de subir a primeira imagem.**

```php
// config/midia.php
'escopo' => [
    'coluna' => 'escopo',              // só muda antes da 1ª migration
    'resolver' => \App\Support\EscopoDaMidia::class,   // __invoke(): ?string
    'obrigatorio' => true,
],
```

ou, num service provider: `\UrbanoDev\Midia\Escopo::resolverCom(fn () => tenant('id'));`

O escopo vira coluna da tabela, pasta no disco (`midias/<cliente>/2026/11/...`)
e filtro de **toda** consulta ao model `Midia`. Com `obrigatorio`, sem cliente
identificado nada é gravado e nenhuma linha é lida (falha fechada). Tarefa do
sistema que precisa ver tudo usa `Midia::semEscopo()`. A suíte do pacote testa o
vazamento entre clientes (`tests/Feature/EscopoTest.php`).

Site pequeno (Girls, Duo, WJ, urbisdev): deixe o `resolver` nulo.

## Usar

### No PHP

```php
use UrbanoDev\Midia\Acoes\GuardarImagem;
use UrbanoDev\Midia\Excecoes\ImagemRecusada;

try {
    $midia = app(GuardarImagem::class)($request->file('foto'), perfil: 'foto', colecao: 'blog', userId: $request->user()->id);
} catch (ImagemRecusada $e) {
    // $e->getMessage() já vem pronta para a tela
}

$midia->url();        // /storage/midias/2026/11/<uuid>.webp
$midia->largura;      // 1600
```

Perfis prontos no config (crie os seus):

| Perfil | Sai em | Tamanho | Para quê |
|---|---|---|---|
| `foto` | WebP | lado maior até 1600 px, sem aumentar | foto no site, no texto |
| `capa` | JPEG | recorte de 1200×630 no centro | prévia de link (WhatsApp e LinkedIn leem JPEG) |
| `logo` | PNG | largura até 600 px, transparência mantida | vai no e-mail (o Outlook não abre WebP) |

Acima de 25 megapixels a imagem é recusada antes de abrir (o GD gastaria
~100 MB). O pacote sobe o `memory_limit` para 384 MB durante o tratamento, se
estiver abaixo.

### Registrar onde a imagem é usada

```php
use UrbanoDev\Midia\Concerns\UsaMidias;
use UrbanoDev\Midia\Models\Midia;

class Texto extends Model
{
    use UsaMidias;

    // Opcional: o que a galeria mostra em "Usada em ..."
    public function rotuloParaMidia(string $campo): string
    {
        return $this->titulo;
    }
}

// ao salvar:
$texto->sincronizarMidias('capa', [$request->input('capa_uuid')]);
$texto->sincronizarMidias('corpo', Midia::uuidsNoTexto($texto->corpo_html));
```

Aceita a `Midia`, o uuid ou a URL. Imagem de outro cliente é ignorada. Soft
delete (arquivar) mantém o uso; só a exclusão de verdade solta. Imagem que
estava na quarentena e voltou a ser usada é restaurada na hora.

### Galeria e envio pela tela

```php
// routes/web.php, DENTRO do grupo autenticado:
Route::middleware(['auth'])->prefix('painel')->name('painel.')->group(function () {
    \UrbanoDev\Midia\Midia::rotas();   // GET/POST painel/midia/imagens
});
```

Com `midia.rotas.permissao` => `'editar site'`, as duas rotas exigem
`Gate::allows('editar site')`. O envio tem `throttle:60,1`.

Os componentes Vue vêm no pacote (sem npm):

```js
import CampoDeImagem from '../../vendor/urbanodev/midia/resources/js/CampoDeImagem.vue';
import GaleriaDeImagens from '../../vendor/urbanodev/midia/resources/js/GaleriaDeImagens.vue';
```

```vue
<CampoDeImagem v-model="form.capa" rotulo="Capa" perfil="capa"
    :url-lista="route('painel.midia.imagens')" :url-envio="route('painel.midia.enviar')" />
```

`CampoDeImagem` devolve `{ uuid, url, descricao }` (ou `null`); mande o `uuid`
ao servidor. `GaleriaDeImagens` sozinha emite `escolher` — serve para o editor
de texto inserir uma imagem. Os dois usam `<dialog>` nativo e CSS próprio. As
cores vêm de `--midia-fundo`, `--midia-texto`, `--midia-suave`, `--midia-linha`,
`--midia-destaque`, `--midia-erro`; sem elas, dos tokens em canais da casa
(`--surface`, `--fg`, `--fg-muted`, `--border`, `--accent`, `--danger`, lidos como
`rgb(var(--surface))`), então claro e escuro seguem o sistema sozinhos.

## Arquivo privado (PDF, ZIP, foto em alta)

```php
use UrbanoDev\Midia\Acoes\GuardarArquivo;

$midia = app(GuardarArquivo::class)(
    $request->file('arquivo'),
    colecao: 'materiais',
    userId: $request->user()->id,
    privado: true,                         // padrão
    expurgoEm: now()->addMonths(24),       // opcional (LGPD); nulo = fica
);

$midia->url();   // privado: /painel/midia/arquivos/{uuid} (com login)
```

O que ele garante:

- **Tipo pelo conteúdo** (`finfo`), aceito só o que está em
  `midia.arquivos.tipos` (padrão: PDF, ZIP, JPG, PNG, WebP). A extensão gravada
  e a do download vêm do conteúdo: `planilha.exe` que é PDF baixa como
  `planilha.pdf`. **SVG, HTML, XML, JavaScript e PHP nunca**, nem postos na lista.
- **Foto também é regravada** (perfil `original`: não reduz, só tira o EXIF/GPS e
  endireita).
- **Privado de verdade:** fica no disco `midia.arquivos.disco_privado` (padrão
  `local`, em `storage/app/private`), sem URL pública. Sai só pela rota
  `midia.baixar`, com `Content-Disposition: attachment`, `nosniff`,
  `Cache-Control: private, no-store` e CSP com `sandbox`. `?ver=1` abre na aba
  (só imagem e PDF).
- Limite próprio: `midia.arquivos.limite_mb` (20). Confira o PHP e o nginx.
- **Não entra na galeria nem na faxina**: o arquivo pertence a um registro do
  sistema (material, anexo), não a um texto.

### Quem baixa

A rota `midia.baixar` vem no `Midia::rotas()`, dentro do grupo autenticado. Na ordem:

1. uuid de outro cliente (escopo), inválido ou na quarentena → **404**;
2. `midia.arquivos.permissao` no Gate (nulo = usa `midia.rotas.permissao`) → **403**;
3. a regra do sistema, quando existe → **403** se não devolver `true`:

```php
// AppServiceProvider::boot() — ex.: anexo de chamado só para quem vê o chamado
\UrbanoDev\Midia\Midia::autorizarDownloadCom(
    fn (\UrbanoDev\Midia\Models\Midia $midia, $usuario) =>
        Anexo::where('midia_id', $midia->id)->first()?->chamado?->visivelPara($usuario) === true
);
```

Sem regra, basta estar logado no grupo e ser do mesmo cliente. Num sistema com
escopo obrigatório, o `midia:conferir` avisa quando não há regra.

Para servir pelo controller do próprio sistema (rota dele, regra dele):
`return $midia->resposta();` dá a mesma resposta segura.

### Público ↔ privado

```php
use UrbanoDev\Midia\Acoes\Visibilidade;

app(Visibilidade::class)->privar($midia);    // o arquivo SAI do disco público: o link antigo morre na hora
app(Visibilidade::class)->publicar($midia);  // volta para o disco público
```

Trocar só o link não basta: quem guardou o endereço público continuaria baixando
(foi o defeito da pendência 257).

### Prazo de guarda (o único comando que apaga)

```bash
php artisan midia:expurgar              # só lista o que passou do `expurgo_em`
php artisan midia:expurgar --executar   # apaga arquivo e linha, de vez
```

Só vale para quem gravou `expurgoEm`. Não roda sozinho; agendar é decisão do sistema.

## Faxina (quarentena, nunca apaga)

```bash
php artisan midia:faxinar              # só lista o que está sem uso há mais de 2 h
php artisan midia:faxinar --executar   # move para a quarentena
php artisan midia:faxinar --lista      # o que já está na quarentena
php artisan midia:restaurar <uuid> [<uuid>...]
```

A quarentena fica no disco `local`, em `midia-quarentena/` (fora do ar), com o
mesmo caminho. A linha da tabela fica, marcada com `quarentena_em`, e some da
galeria. Não roda sozinha; para agendar:

```php
// routes/console.php
Schedule::command('midia:faxinar --executar')->dailyAt('04:10');
```

**Só ligue a faxina depois que TODO lugar que mostra imagem chamar
`sincronizarMidias()`** — imagem usada sem registro parece sobra.

## Desinstalar

1. Tire as chamadas a `Midia::rotas()`, `UsaMidias` e os componentes.
2. Guarde os arquivos: `storage/app/public/midias`, `storage/app/private/midias`
   (arquivos privados) e `storage/app/private/midia-quarentena`.
3. `php artisan migrate:rollback --path=vendor/urbanodev/midia/database/migrations`
   (apaga `midias` e `midia_usos` — faça backup antes).
4. `composer remove urbanodev/midia` e apague `config/midia.php`.

## Testes

```bash
scripts/testar.sh
```

Roda a suíte em SQLite e num Postgres descartável (só precisa de Docker e da
imagem `sail-8.4/app`, que tem gd, exif e webp). Cobre as 8 orientações, a
retirada do GPS, SVG/HTML/GIF recusados, foto-bomba, os três perfis, escopo e
vazamento, usos, quarentena, rotas e comandos; e, no arquivo privado, tipo pelo
conteúdo, SVG/HTML/PHP recusados, cabeçalhos do download, 404 entre clientes,
permissão e regra do sistema, troca de disco e expurgo.
