<?php

namespace UrbanoDev\Midia\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase as Orchestra;
use UrbanoDev\Midia\Escopo;
use UrbanoDev\Midia\MidiaServiceProvider;

abstract class TestCase extends Orchestra
{
    /** @var list<string> */
    private array $temporarios = [];

    protected function setUp(): void
    {
        parent::setUp();
        Escopo::resolverCom(null);
        Storage::fake('public');
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Escopo::resolverCom(null);
        array_map(fn ($f) => @unlink($f), $this->temporarios);
        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [MidiaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');

        // `phpunit -c tests/phpunit.pgsql.xml` roda a mesma suíte num Postgres.
        if (getenv('MIDIA_TESTE_PGSQL')) {
            $app['config']->set('database.default', 'pgsql');
            $app['config']->set('database.connections.pgsql', [
                'driver' => 'pgsql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: '5432',
                'database' => getenv('DB_DATABASE') ?: 'midia',
                'username' => getenv('DB_USERNAME') ?: 'postgres',
                'password' => getenv('DB_PASSWORD') ?: '',
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ]);
        }
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }

    protected function temporario(string $bytes, string $extensao = 'jpg'): string
    {
        $caminho = tempnam(sys_get_temp_dir(), 'midia').'.'.$extensao;
        file_put_contents($caminho, $bytes);
        $this->temporarios[] = $caminho;

        return $caminho;
    }

    /**
     * Imagem com um quadrado vermelho no canto superior esquerdo (o resto azul):
     * dá para saber para onde ela girou ou espelhou.
     */
    protected function imagemMarcada(int $largura = 60, int $altura = 40, int $lado = 20): \GdImage
    {
        $img = imagecreatetruecolor($largura, $altura);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 0, 0, 255));
        imagefilledrectangle($img, 0, 0, $lado - 1, $lado - 1, (int) imagecolorallocate($img, 255, 0, 0));

        return $img;
    }

    protected function jpeg(\GdImage $img, ?int $orientacao = null, bool $gps = false): string
    {
        ob_start();
        imagejpeg($img, null, 95);
        $bytes = (string) ob_get_clean();

        if ($orientacao === null && ! $gps) {
            return $bytes;
        }

        // SOI + APP1 (Exif) + o resto do JPEG que o GD gerou.
        return substr($bytes, 0, 2).$this->app1($orientacao, $gps).substr($bytes, 2);
    }

    /** Segmento APP1 com IFD0 (Orientation) e, se pedido, um IFD de GPS com latitude. */
    protected function app1(?int $orientacao, bool $gps): string
    {
        $entradas = [];
        if ($orientacao !== null) {
            $entradas[] = pack('nnN', 0x0112, 3, 1).pack('nn', $orientacao, 0);
        }
        $quantas = count($entradas) + ($gps ? 1 : 0);
        $offsetGps = 8 + 2 + 12 * $quantas + 4;
        if ($gps) {
            $entradas[] = pack('nnNN', 0x8825, 4, 1, $offsetGps);
        }

        $tiff = "MM\x00\x2A".pack('N', 8).pack('n', $quantas).implode('', $entradas).pack('N', 0);

        if ($gps) {
            // GPSLatitudeRef = "S" e GPSLongitudeRef = "W" (ASCII, cabem no campo de valor).
            $tiff .= pack('n', 2)
                .pack('nnN', 0x0001, 2, 2)."S\x00\x00\x00"
                .pack('nnN', 0x0003, 2, 2)."W\x00\x00\x00"
                .pack('N', 0);
        }

        $dados = "Exif\x00\x00".$tiff;

        return "\xFF\xE1".pack('n', strlen($dados) + 2).$dados;
    }

    protected function png(\GdImage $img): string
    {
        imagesavealpha($img, true);
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    protected function upload(string $bytes, string $nome = 'foto.jpg'): UploadedFile
    {
        $caminho = $this->temporario($bytes, pathinfo($nome, PATHINFO_EXTENSION) ?: 'bin');

        return new UploadedFile($caminho, $nome, null, null, true);
    }

    protected function fotoUpload(int $largura = 800, int $altura = 600, string $nome = 'foto.jpg'): UploadedFile
    {
        return $this->upload($this->jpeg($this->imagemMarcada($largura, $altura, 50)), $nome);
    }

    protected function noEscopo(?string $escopo): void
    {
        Escopo::resolverCom(fn () => $escopo);
    }

    /** Cor dominante de um pixel: 'vermelho', 'azul' ou 'outro'. */
    protected function cor(\GdImage $img, int $x, int $y): string
    {
        $rgb = imagecolorat($img, $x, $y);
        [$r, $b] = [($rgb >> 16) & 0xFF, $rgb & 0xFF];

        return match (true) {
            $r > 180 && $b < 90 => 'vermelho',
            $b > 180 && $r < 90 => 'azul',
            default => 'outro',
        };
    }
}
