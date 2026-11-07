<?php

namespace UrbanoDev\Midia\Acoes;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use UrbanoDev\Midia\Escopo;
use UrbanoDev\Midia\Models\Midia;
use UrbanoDev\Midia\TratarImagem;

/**
 * Trata e guarda uma imagem enviada. Devolve a linha de `midias`.
 *
 * Caminho: `{pasta}/{escopo}/{AAAA}/{MM}/{uuid}.{ext}` (sem escopo, sem o
 * pedaço do meio). O nome original nunca vai para a URL.
 */
final class GuardarImagem
{
    public function __construct(private TratarImagem $tratar) {}

    /**
     * @throws \UrbanoDev\Midia\Excecoes\ImagemRecusada com mensagem pronta para a tela
     */
    public function __invoke(
        UploadedFile|string $arquivo,
        string $perfil = 'foto',
        ?string $colecao = null,
        int|string|null $userId = null,
        ?string $descricao = null,
    ): Midia {
        $config = config("midia.perfis.{$perfil}");

        if (! is_array($config)) {
            throw new InvalidArgumentException("Perfil de imagem desconhecido: {$perfil}.");
        }

        $escopo = Escopo::paraGravar();
        $caminhoLocal = $arquivo instanceof UploadedFile ? (string) $arquivo->getRealPath() : $arquivo;
        $nomeOriginal = $arquivo instanceof UploadedFile ? $arquivo->getClientOriginalName() : basename($arquivo);

        $imagem = ($this->tratar)($caminhoLocal, $config);

        $uuid = (string) Str::uuid7();
        $disco = (string) config('midia.disco', 'public');
        $caminho = implode('/', array_filter([
            trim((string) config('midia.pasta', 'midias'), '/'),
            Escopo::pasta($escopo),
            now()->format('Y'),
            now()->format('m'),
            "{$uuid}.{$imagem->extensao}",
        ]));

        Storage::disk($disco)->put($caminho, $imagem->bytes);

        return Midia::query()->create([
            'uuid' => $uuid,
            Escopo::coluna() => $escopo,
            'perfil' => $perfil,
            'colecao' => $colecao,
            'disco' => $disco,
            'caminho' => $caminho,
            'mime' => $imagem->mime,
            'largura' => $imagem->largura,
            'altura' => $imagem->altura,
            'bytes' => strlen($imagem->bytes),
            'sha256' => hash('sha256', $imagem->bytes),
            'nome_original' => mb_substr($nomeOriginal, 0, 255) ?: null,
            'descricao' => $descricao === null ? null : mb_substr(trim($descricao), 0, 300),
            'user_id' => $userId === null ? null : (string) $userId,
        ]);
    }
}
