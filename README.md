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

**v0.1 cobre imagem pública.** Arquivo privado (PDF, anexo com rota autorizada)
vem na v0.2.

## Instalar

```bash
composer config repositories.midia vcs https://github.com/urbanodev81/midia
composer require urbanodev/midia:0.1.0
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
pacote, as tabelas, o `public/storage` e, se o escopo for obrigatório, se há
resolver. Sai com erro se faltar algo. **Lembrete que ele não consegue conferir:**
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
2. Guarde os arquivos: `storage/app/public/midias` e `storage/app/midia-quarentena`.
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
vazamento, usos, quarentena, rotas e comandos.
