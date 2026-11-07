<?php

namespace UrbanoDev\Midia\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use UrbanoDev\Midia\Acoes\GuardarImagem;
use UrbanoDev\Midia\Models\Midia;
use UrbanoDev\Midia\Tests\TestCase;

class GuardarImagemTest extends TestCase
{
    public function test_guarda_a_imagem_tratada_com_as_medidas_na_tabela(): void
    {
        $midia = ($this->app->make(GuardarImagem::class))($this->fotoUpload(2400, 1800, 'Férias na praia.JPG'), 'foto', 'blog', 7, ' Mar azul ');

        $this->assertMatchesRegularExpression('#^midias/\d{4}/\d{2}/[0-9a-f-]{36}\.webp$#', $midia->caminho);
        $this->assertStringNotContainsString('Férias', $midia->caminho, 'o nome original não vai para a URL');
        Storage::disk('public')->assertExists($midia->caminho);

        $bytes = Storage::disk('public')->get($midia->caminho);
        $this->assertSame(hash('sha256', $bytes), $midia->sha256);
        $this->assertSame(strlen($bytes), $midia->bytes);
        $this->assertSame([1600, 1200, 'image/webp'], [$midia->largura, $midia->altura, $midia->mime]);
        $this->assertSame(['foto', 'blog', 'Férias na praia.JPG', 'Mar azul', '7', null], [
            $midia->perfil, $midia->colecao, $midia->nome_original, $midia->descricao, $midia->user_id, $midia->escopo(),
        ]);
        $this->assertSame($midia->uuid, Midia::uuidDe($midia->url()));
    }

    public function test_perfil_desconhecido_e_erro_de_programacao(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ($this->app->make(GuardarImagem::class))($this->fotoUpload(), 'banner');
    }

    public function test_acha_os_uuids_das_imagens_do_pacote_num_texto(): void
    {
        $a = '0196f3a1-0000-7000-8000-000000000001';
        $b = '0196f3a1-0000-7000-8000-000000000002';
        $html = "<p><img src=\"/storage/midias/2026/11/{$a}.webp\"> e <img src=\"https://x.com/storage/midias/clinica-9/2026/11/{$b}.jpg\">"
            .' e um uuid que não é imagem nossa: /pedido/0196f3a1-0000-7000-8000-000000000003'
            ."<img src=\"/storage/midias/2026/11/{$a}.webp\"></p>";

        $this->assertSame([$a, $b], Midia::uuidsNoTexto($html));
        $this->assertSame([], Midia::uuidsNoTexto(null));
    }

    public function test_uuid_da_url_e_o_do_arquivo_mesmo_com_escopo_em_uuid(): void
    {
        $tenant = '11111111-1111-4111-8111-111111111111';
        $arquivo = '0196f3a1-0000-7000-8000-00000000000a';

        $this->assertSame($arquivo, Midia::uuidDe("/storage/midias/{$tenant}/2026/11/{$arquivo}.webp"));
    }
}
