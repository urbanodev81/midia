<?php

namespace UrbanoDev\Midia\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use UrbanoDev\Midia\Excecoes\ImagemRecusada;
use UrbanoDev\Midia\Tests\TestCase;
use UrbanoDev\Midia\TratarImagem;

class TratarImagemTest extends TestCase
{
    private function tratar(string $bytes, array $perfil = ['formato' => 'png'], string $ext = 'jpg')
    {
        return ($this->app->make(TratarImagem::class))($this->temporario($bytes, $ext), $perfil);
    }

    /** @return array<string, array{int, int, int, string}> orientação => largura, altura, canto do vermelho */
    public static function orientacoes(): array
    {
        return [
            '1 normal' => [1, 60, 40, 'se'],
            '2 espelhada' => [2, 60, 40, 'sd'],
            '3 de ponta-cabeça' => [3, 60, 40, 'id'],
            '4 espelhada na vertical' => [4, 60, 40, 'ie'],
            '5 transposta' => [5, 40, 60, 'se'],
            '6 em pé (celular)' => [6, 40, 60, 'sd'],
            '7 transversa' => [7, 40, 60, 'id'],
            '8 em pé ao contrário' => [8, 40, 60, 'ie'],
        ];
    }

    #[DataProvider('orientacoes')]
    public function test_aplica_as_oito_orientacoes_do_exif(int $orientacao, int $largura, int $altura, string $canto): void
    {
        $saida = $this->tratar($this->jpeg($this->imagemMarcada(), $orientacao));
        $img = imagecreatefromstring($saida->bytes);

        $this->assertSame([$largura, $altura], [$saida->largura, $saida->altura]);
        $this->assertSame([$largura, $altura], [imagesx($img), imagesy($img)]);

        $pontos = ['se' => [5, 5], 'sd' => [$largura - 6, 5], 'ie' => [5, $altura - 6], 'id' => [$largura - 6, $altura - 6]];
        foreach ($pontos as $nome => [$x, $y]) {
            $this->assertSame($nome === $canto ? 'vermelho' : 'azul', $this->cor($img, $x, $y), "canto {$nome} na orientação {$orientacao}");
        }
    }

    public function test_tira_o_exif_e_o_gps_da_foto(): void
    {
        $entrada = $this->jpeg($this->imagemMarcada(), 1, gps: true);
        $this->assertArrayHasKey('GPSLatitudeRef', (array) @exif_read_data($this->temporario($entrada)), 'a foto de teste precisa ter GPS');

        foreach (['webp', 'jpeg', 'png'] as $formato) {
            $saida = $this->tratar($entrada, ['formato' => $formato]);
            $this->assertStringNotContainsString('Exif', $saida->bytes, "formato {$formato}");
        }

        $jpeg = $this->tratar($entrada, ['formato' => 'jpeg']);
        $exif = @exif_read_data($this->temporario($jpeg->bytes));
        $this->assertArrayNotHasKey('GPSLatitudeRef', is_array($exif) ? $exif : []);
    }

    public function test_recusa_o_que_nao_e_jpeg_png_ou_webp_pelo_conteudo(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        $html = '<html><body><script>alert(1)</script></body></html>';
        ob_start();
        imagegif($this->imagemMarcada());
        $gif = (string) ob_get_clean();

        foreach (['svg' => $svg, 'html com nome de jpg' => $html, 'gif' => $gif] as $caso => $bytes) {
            try {
                $this->tratar($bytes);
                $this->fail("deveria recusar: {$caso}");
            } catch (ImagemRecusada $e) {
                $this->assertSame('Envie uma imagem JPG, PNG ou WebP.', $e->getMessage());
            }
        }
    }

    public function test_recusa_foto_bomba_antes_de_abrir(): void
    {
        // Só o cabeçalho de um PNG de 10.000 × 10.000 (100 MP): abrir custaria 400 MB.
        $ihdr = 'IHDR'.pack('NNCCCCC', 10_000, 10_000, 8, 2, 0, 0, 0);
        $bomba = "\x89PNG\r\n\x1a\n".pack('N', 13).$ihdr.pack('N', crc32($ihdr));

        $this->expectException(ImagemRecusada::class);
        $this->expectExceptionMessage('acima de 25 megapixels');
        $this->tratar($bomba, ext: 'png');
    }

    public function test_recusa_imagem_pequena_demais_para_o_perfil(): void
    {
        $this->expectException(ImagemRecusada::class);
        $this->expectExceptionMessage('pelo menos 200 pixels');
        $this->tratar($this->jpeg($this->imagemMarcada()), ['formato' => 'webp', 'menor_lado_minimo' => 200]);
    }

    public function test_foto_reduz_pelo_lado_maior_sem_aumentar(): void
    {
        $grande = $this->tratar($this->jpeg($this->imagemMarcada(2000, 3000, 100)), config('midia.perfis.foto'));
        $this->assertSame([1067, 1600], [$grande->largura, $grande->altura]);
        $this->assertSame('image/webp', $grande->mime);
        $this->assertSame('webp', $grande->extensao);

        $pequena = $this->tratar($this->jpeg($this->imagemMarcada(800, 600, 50)), config('midia.perfis.foto'));
        $this->assertSame([800, 600], [$pequena->largura, $pequena->altura]);
    }

    public function test_capa_recorta_no_tamanho_exato_em_jpeg(): void
    {
        $capa = $this->tratar($this->jpeg($this->imagemMarcada(1000, 1000, 50)), config('midia.perfis.capa'));

        $this->assertSame([1200, 630], [$capa->largura, $capa->altura]);
        $this->assertSame('image/jpeg', $capa->mime);
        $this->assertSame([1200, 630, IMAGETYPE_JPEG], array_slice(getimagesizefromstring($capa->bytes), 0, 3));
    }

    public function test_logo_mantem_a_transparencia_e_jpeg_ganha_fundo_branco(): void
    {
        $img = imagecreatetruecolor(800, 200);
        imagealphablending($img, false);
        imagefill($img, 0, 0, (int) imagecolorallocatealpha($img, 0, 0, 0, 127));
        $png = $this->png($img);

        $logo = $this->tratar($png, config('midia.perfis.logo'), 'png');
        $saida = imagecreatefromstring($logo->bytes);
        $this->assertSame([600, 150], [$logo->largura, $logo->altura]);
        $this->assertSame(127, (imagecolorat($saida, 10, 10) >> 24) & 0x7F, 'o fundo continua transparente');

        $jpeg = imagecreatefromstring($this->tratar($png, ['formato' => 'jpeg'], 'png')->bytes);
        $this->assertGreaterThan(240, imagecolorat($jpeg, 10, 10) & 0xFF, 'transparente vira branco, não preto');
    }

    public function test_aceita_webp_e_png_de_entrada(): void
    {
        ob_start();
        imagewebp($this->imagemMarcada(), null, 90);
        $webp = (string) ob_get_clean();

        $this->assertSame(60, $this->tratar($webp, ['formato' => 'webp'], 'webp')->largura);
        $this->assertSame(60, $this->tratar($this->png($this->imagemMarcada()), ['formato' => 'webp'], 'png')->largura);
    }
}
