<?php

namespace UrbanoDev\Midia\Acoes;

use DateTimeInterface;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use UrbanoDev\Midia\Escopo;
use UrbanoDev\Midia\Excecoes\ArquivoRecusado;
use UrbanoDev\Midia\Models\Midia;
use UrbanoDev\Midia\TratarImagem;

/**
 * Guarda um arquivo (PDF, ZIP, foto em alta). Por padrão PRIVADO: disco fora
 * da raiz web, sem URL pública; sai só pela rota `midia.baixar`.
 *
 * - O tipo vem do CONTEÚDO (`finfo`), e a extensão gravada sai da lista
 *   `midia.arquivos.tipos`, nunca do nome que o usuário mandou.
 * - SVG, HTML, XML, JavaScript e PHP são recusados mesmo que estejam na lista:
 *   abertos do nosso domínio, rodam com a sessão de quem abriu.
 * - Foto que chega como arquivo também é regravada pelo GD (sai o GPS).
 *
 * Junta o que o BHD faz nos anexos (disco privado, sha256, nome original só
 * como rótulo, prazo de expurgo) com o escopo por cliente do pacote.
 */
final class GuardarArquivo
{
    /** Nunca, nem configurado. */
    private const PROIBIDOS = '#(svg|html|javascript|ecmascript|php|x-sh|x-msdownload)|^(text|application)/xml$#i';

    private const IMAGENS = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private TratarImagem $tratar) {}

    /**
     * @param  DateTimeInterface|null  $expurgoEm  prazo de guarda (LGPD); nulo = fica
     *
     * @throws ArquivoRecusado com mensagem pronta para a tela
     */
    public function __invoke(
        UploadedFile|string $arquivo,
        ?string $colecao = null,
        int|string|null $userId = null,
        bool $privado = true,
        ?DateTimeInterface $expurgoEm = null,
        ?string $descricao = null,
    ): Midia {
        $escopo = Escopo::paraGravar();
        $caminhoLocal = $arquivo instanceof UploadedFile ? (string) $arquivo->getRealPath() : $arquivo;
        $nomeOriginal = $arquivo instanceof UploadedFile ? $arquivo->getClientOriginalName() : basename($arquivo);

        if (! is_file($caminhoLocal) || ! is_readable($caminhoLocal)) {
            throw new ArquivoRecusado('O arquivo não chegou inteiro. Tente de novo.');
        }

        $limite = (int) config('midia.arquivos.limite_mb', 20);

        if (filesize($caminhoLocal) > $limite * 1024 ** 2) {
            throw new ArquivoRecusado("O arquivo passa do limite de {$limite} MB.");
        }

        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($caminhoLocal);
        $tipos = (array) config('midia.arquivos.tipos', []);

        if (preg_match(self::PROIBIDOS, $mime) === 1 || ! isset($tipos[$mime])) {
            throw new ArquivoRecusado('Esse tipo de arquivo não é aceito. Envie '.self::lista($tipos).'.');
        }

        [$largura, $altura] = [null, null];
        $perfilImagem = config('midia.arquivos.perfil_imagem', 'original');

        if (in_array($mime, self::IMAGENS, true) && $perfilImagem !== null) {
            $imagem = ($this->tratar)($caminhoLocal, (array) config("midia.perfis.{$perfilImagem}"));
            [$bytes, $mime, $extensao, $largura, $altura] = [$imagem->bytes, $imagem->mime, $imagem->extensao, $imagem->largura, $imagem->altura];
        } else {
            $bytes = (string) file_get_contents($caminhoLocal);
            $extensao = preg_replace('/[^a-z0-9]/', '', strtolower((string) $tipos[$mime])) ?: 'bin';

            if (in_array($mime, self::IMAGENS, true)) {
                [$largura, $altura] = array_slice((array) @getimagesize($caminhoLocal), 0, 2) + [null, null];
            }
        }

        $uuid = (string) Str::uuid7();
        $disco = $privado ? (string) config('midia.arquivos.disco_privado', 'local') : (string) config('midia.disco', 'public');
        $caminho = implode('/', array_filter([
            trim((string) config('midia.pasta', 'midias'), '/'),
            Escopo::pasta($escopo),
            now()->format('Y'),
            now()->format('m'),
            "{$uuid}.{$extensao}",
        ]));

        Storage::disk($disco)->put($caminho, $bytes);

        return Midia::query()->create([
            'uuid' => $uuid,
            Escopo::coluna() => $escopo,
            'perfil' => Midia::PERFIL_ARQUIVO,
            'colecao' => $colecao,
            'disco' => $disco,
            'caminho' => $caminho,
            'mime' => $mime,
            'largura' => $largura,
            'altura' => $altura,
            'bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            // Só rótulo para quem olha (e nome do download), nunca caminho.
            'nome_original' => mb_substr(Midia::nomeSeguro($nomeOriginal), 0, 255) ?: null,
            'descricao' => $descricao === null ? null : mb_substr(trim($descricao), 0, 300),
            'user_id' => $userId === null ? null : (string) $userId,
            'privado' => $privado,
            'expurgo_em' => $expurgoEm,
        ]);
    }

    /** @param  array<string, string>  $tipos */
    private static function lista(array $tipos): string
    {
        $nomes = array_values(array_unique(array_map(fn ($e) => strtoupper((string) $e), $tipos)));

        return count($nomes) > 1
            ? implode(', ', array_slice($nomes, 0, -1)).' ou '.end($nomes)
            : ($nomes[0] ?? 'outro arquivo');
    }
}
