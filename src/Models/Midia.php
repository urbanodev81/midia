<?php

namespace UrbanoDev\Midia\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use UrbanoDev\Midia\Escopo;
use UrbanoDev\Midia\Midia as Pacote;

/**
 * Uma imagem ou arquivo guardado pelo pacote. Toda consulta já vem filtrada pelo escopo
 * da requisição; sem escopo num sistema que o exige, não vem nada.
 * Para tarefa de sistema (faxina, migração), use `Midia::semEscopo()`.
 *
 * @property int $id
 * @property string $uuid
 * @property string $perfil
 * @property ?string $colecao
 * @property string $disco
 * @property string $caminho
 * @property string $mime
 * @property ?int $largura
 * @property ?int $altura
 * @property int $bytes
 * @property string $sha256
 * @property ?string $nome_original
 * @property ?string $descricao
 * @property ?string $user_id
 * @property bool $privado
 * @property ?\Illuminate\Support\Carbon $quarentena_em
 * @property ?\Illuminate\Support\Carbon $expurgo_em
 */
class Midia extends Model
{
    /** O perfil de tudo que entrou por `GuardarArquivo` (fica fora da faxina). */
    public const PERFIL_ARQUIVO = 'arquivo';

    /** O que pode abrir na aba (`?ver=1`); o resto sempre baixa. */
    private const ABRE_NA_ABA = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    protected $table = 'midias';

    protected $guarded = ['id'];

    protected $casts = [
        'largura' => 'integer',
        'altura' => 'integer',
        'bytes' => 'integer',
        'privado' => 'boolean',
        'quarentena_em' => 'datetime',
        'expurgo_em' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('midia-escopo', function (Builder $query) {
            $escopo = Escopo::atual();
            $coluna = $query->qualifyColumn(Escopo::coluna());

            if ($escopo !== null) {
                $query->where($coluna, $escopo);
            } elseif (Escopo::obrigatorio()) {
                // Falha fechada: sem cliente identificado, nenhuma linha.
                $query->whereRaw('1 = 0');
            }
        });
    }

    /** Sem o filtro de escopo: só para tarefa do sistema, nunca para a tela. */
    public static function semEscopo(): Builder
    {
        return static::query()->withoutGlobalScope('midia-escopo');
    }

    public function usos(): HasMany
    {
        return $this->hasMany(MidiaUso::class, 'midia_id');
    }

    public function escopo(): ?string
    {
        return $this->getAttribute(Escopo::coluna());
    }

    public function emQuarentena(): bool
    {
        return $this->quarentena_em !== null;
    }

    /** Pública: o endereço do disco. Privada: a rota `midia.baixar` (com login). */
    public function url(): string
    {
        return $this->privado
            ? Pacote::urlDeDownload($this)
            : Storage::disk($this->disco)->url($this->caminho);
    }

    public function ehImagem(): bool
    {
        return $this->largura !== null;
    }

    public function scopeAtivas(Builder $query): Builder
    {
        return $query->whereNull('quarentena_em');
    }

    /** O que a galeria mostra: imagem pública, fora da quarentena. */
    public function scopeDaGaleria(Builder $query): Builder
    {
        return $query->whereNull('quarentena_em')->where('privado', false)->whereNotNull('largura');
    }

    /**
     * O arquivo para o navegador. Sempre com `nosniff` e sem cache
     * compartilhado; `attachment` (baixa) a não ser que `$naAba` e o tipo
     * seja imagem ou PDF. O nome do download termina na extensão REAL.
     */
    public function resposta(bool $naAba = false): Response
    {
        $naAba = $naAba && in_array($this->mime, self::ABRE_NA_ABA, true);
        $extensao = pathinfo($this->caminho, PATHINFO_EXTENSION);
        $base = pathinfo(self::nomeSeguro($this->nome_original ?? ''), PATHINFO_FILENAME);
        $nome = ($base !== '' ? Str::limit($base, 120, '') : 'arquivo').'.'.$extensao;

        $cabecalhos = [
            'Content-Type' => $this->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
        ];

        // O visualizador de PDF do Chrome não abre com sandbox: PDF na aba vai sem CSP.
        if (! ($naAba && $this->mime === 'application/pdf')) {
            $cabecalhos['Content-Security-Policy'] = "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox";
        }

        return Storage::disk($this->disco)->response($this->caminho, $nome, $cabecalhos, $naAba ? 'inline' : 'attachment');
    }

    /** Nome de arquivo sem pasta, sem caractere de controle e sem barra. */
    public static function nomeSeguro(string $nome): string
    {
        $nome = basename(str_replace('\\', '/', $nome));
        $nome = (string) preg_replace('/[\x00-\x1F\x7F"]+/u', '', $nome);

        return trim($nome, " .\t");
    }

    /** O uuid a partir da Midia, do próprio uuid ou da URL do arquivo. */
    public static function uuidDe(mixed $valor): ?string
    {
        if ($valor instanceof self) {
            return $valor->uuid;
        }

        if (! is_string($valor)) {
            return null;
        }

        // O ÚLTIMO uuid: na URL o escopo (tenant em uuid) vem antes do nome do arquivo.
        return preg_match_all('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $valor, $m) > 0
            ? strtolower(end($m[0]))
            : null;
    }

    /**
     * Os uuids das imagens do pacote citadas num texto (HTML do blog, Markdown).
     * Só conta URL dentro da pasta do pacote, para não confundir com outro uuid.
     *
     * @return list<string>
     */
    public static function uuidsNoTexto(?string $texto): array
    {
        if ($texto === null || $texto === '') {
            return [];
        }

        // A pasta do pacote e as `pastas_antigas` (imagens registradas sem mover, de antes do pacote).
        $pastas = array_filter(array_map(
            fn ($p) => preg_quote(trim((string) $p, '/'), '#'),
            [config('midia.pasta', 'midias'), ...(array) config('midia.pastas_antigas', [])],
        ), fn ($p) => $p !== '');
        $pasta = '(?:'.implode('|', $pastas).')';
        preg_match_all("#/{$pasta}/(?:[A-Za-z0-9_-]+/)*([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\.(?:webp|jpg|png)#i", $texto, $m);

        return array_values(array_unique(array_map('strtolower', $m[1])));
    }
}
