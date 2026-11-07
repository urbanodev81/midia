<?php

namespace UrbanoDev\Midia\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use UrbanoDev\Midia\Acoes\GuardarArquivo;
use UrbanoDev\Midia\Acoes\Quarentena;
use UrbanoDev\Midia\Acoes\Visibilidade;
use UrbanoDev\Midia\Excecoes\ArquivoRecusado;
use UrbanoDev\Midia\Midia as Pacote;
use UrbanoDev\Midia\Models\Midia;
use UrbanoDev\Midia\Tests\TestCase;

class ArquivoPrivadoTest extends TestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();
        Pacote::autorizarDownloadCom(null);
    }

    protected function tearDown(): void
    {
        Pacote::autorizarDownloadCom(null);
        parent::tearDown();
    }

    protected function defineRoutes($router): void
    {
        $router->prefix('painel')->name('painel.')->group(fn () => Pacote::rotas());
    }

    private function guardar(string $bytes = self::PDF, string $nome = 'contrato.pdf', bool $privado = true, ?\DateTimeInterface $expurgoEm = null): Midia
    {
        return ($this->app->make(GuardarArquivo::class))($this->upload($bytes, $nome), privado: $privado, expurgoEm: $expurgoEm);
    }

    private function entrar(int $id = 7): static
    {
        return $this->actingAs(new GenericUser(['id' => $id, 'name' => 'Ana']));
    }

    public function test_pdf_privado_fica_fora_do_disco_publico_e_sem_url_publica(): void
    {
        $midia = $this->guardar();

        $this->assertTrue($midia->privado);
        $this->assertSame('local', $midia->disco);
        $this->assertSame(Midia::PERFIL_ARQUIVO, $midia->perfil);
        $this->assertSame('application/pdf', $midia->mime);
        $this->assertNull($midia->largura);
        $this->assertSame(hash('sha256', self::PDF), $midia->sha256);
        $this->assertStringEndsWith('.pdf', $midia->caminho);
        Storage::disk('local')->assertExists($midia->caminho);
        Storage::disk('public')->assertMissing($midia->caminho);
        $this->assertSame(url("/painel/midia/arquivos/{$midia->uuid}"), $midia->url());
    }

    public function test_tipo_vem_do_conteudo_e_svg_html_nunca_entram(): void
    {
        $casos = [
            'html disfarçado de pdf' => ['<!doctype html><html><script>alert(1)</script></html>', 'nota.pdf'],
            'svg' => ['<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'logo.svg'],
            'php' => ["<?php echo 'oi';", 'foto.jpg'],
            'texto fora da lista' => ['só texto', 'leia.txt'],
        ];

        foreach ($casos as $caso => [$bytes, $nome]) {
            try {
                $this->guardar($bytes, $nome);
                $this->fail("Aceitou: {$caso}");
            } catch (ArquivoRecusado $e) {
                $this->assertStringStartsWith('Esse tipo de arquivo não é aceito. Envie PDF, ZIP, JPG, PNG ou WEBP', $e->getMessage(), $caso);
            }
        }

        // Nem posto na lista de tipos aceitos.
        config(['midia.arquivos.tipos' => ['image/svg+xml' => 'svg', 'text/html' => 'html']]);
        $this->expectException(ArquivoRecusado::class);
        $this->guardar('<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'logo.svg');
    }

    public function test_extensao_gravada_e_a_do_conteudo_nao_a_do_nome(): void
    {
        $midia = $this->guardar(self::PDF, 'planilha.exe');

        $this->assertStringEndsWith('.pdf', $midia->caminho);
        $this->entrar()->get($midia->url())
            ->assertOk()
            ->assertDownload('planilha.pdf');
    }

    public function test_foto_que_chega_como_arquivo_perde_o_gps_e_nao_encolhe(): void
    {
        $bytes = $this->jpeg($this->imagemMarcada(2400, 1600, 50), 1, true);
        $this->assertArrayHasKey('GPSLatitudeRef', (array) @exif_read_data($this->temporario($bytes)));

        $midia = $this->guardar($bytes, 'show.jpg');
        $gravado = Storage::disk('local')->get($midia->caminho);

        $this->assertSame('image/jpeg', $midia->mime);
        $this->assertSame([2400, 1600], [$midia->largura, $midia->altura], 'perfil original não reduz');
        $exif = (array) @exif_read_data($this->temporario($gravado));
        $this->assertArrayNotHasKey('GPSLatitudeRef', $exif);
        $this->assertArrayNotHasKey('Orientation', $exif);
    }

    public function test_limite_de_tamanho(): void
    {
        config(['midia.arquivos.limite_mb' => 0]);

        $this->expectExceptionMessage('O arquivo passa do limite de 0 MB.');
        $this->guardar();
    }

    public function test_download_sai_como_anexo_com_nosniff_e_sem_cache(): void
    {
        $midia = $this->guardar(self::PDF, "re\"lat\nório/../2026.pdf");

        $resposta = $this->entrar()->get($midia->url())->assertOk();

        $this->assertSame(self::PDF, $resposta->streamedContent());
        $this->assertSame('nosniff', $resposta->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', (string) $resposta->headers->get('Cache-Control'));
        $this->assertStringContainsString('sandbox', (string) $resposta->headers->get('Content-Security-Policy'));
        $disposicao = (string) $resposta->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment;', $disposicao);
        $this->assertStringContainsString('2026.pdf', $disposicao);
        $this->assertStringNotContainsString('/', $disposicao);
        $this->assertStringNotContainsString("\n", $disposicao);
    }

    public function test_ver_na_aba_so_para_imagem_e_pdf(): void
    {
        $pdf = $this->guardar();
        $resposta = $this->entrar()->get($pdf->url().'?ver=1')->assertOk();
        $this->assertStringStartsWith('inline', (string) $resposta->headers->get('Content-Disposition'));
        $this->assertNull($resposta->headers->get('Content-Security-Policy'), 'o leitor de PDF do Chrome não abre com sandbox');

        $zip = $this->guardar("PK\x05\x06".str_repeat("\x00", 18), 'fotos.zip');
        $this->assertSame('application/zip', $zip->mime);
        $this->entrar()->get($zip->url().'?ver=1')->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=fotos.zip');
    }

    public function test_outro_cliente_recebe_404_e_sem_cliente_tambem(): void
    {
        config(['midia.escopo.obrigatorio' => true]);
        $this->noEscopo('imobiliaria-a');
        $midia = $this->guardar();
        Storage::disk('local')->assertExists("midias/imobiliaria-a/".now()->format('Y/m')."/{$midia->uuid}.pdf");
        $this->entrar()->get($midia->url())->assertOk();

        $this->noEscopo('imobiliaria-b');
        $this->entrar()->get("/painel/midia/arquivos/{$midia->uuid}")->assertNotFound();

        $this->noEscopo(null);
        $this->entrar()->get("/painel/midia/arquivos/{$midia->uuid}")->assertNotFound();
    }

    public function test_sem_cliente_num_sistema_que_exige_nada_e_gravado(): void
    {
        config(['midia.escopo.obrigatorio' => true]);
        $this->noEscopo(null);

        try {
            $this->guardar();
            $this->fail('Gravou sem cliente.');
        } catch (ArquivoRecusado) {
            $this->assertSame(0, Midia::semEscopo()->count());
            $this->assertSame([], Storage::disk('local')->allFiles());
        }
    }

    public function test_uuid_estranho_e_quarentena_dao_404(): void
    {
        $midia = $this->guardar();

        $this->entrar()->get('/painel/midia/arquivos/nao-e-uuid')->assertNotFound();
        $this->entrar()->get('/painel/midia/arquivos/'.str_repeat('0', 8).'-0000-0000-0000-'.str_repeat('0', 12))->assertNotFound();

        $midia->forceFill(['quarentena_em' => now()])->save();
        $this->entrar()->get($midia->url())->assertNotFound();
    }

    public function test_permissao_e_regra_do_sistema(): void
    {
        $midia = $this->guardar();

        config(['midia.arquivos.permissao' => 'ver-materiais']);
        Gate::define('ver-materiais', fn ($u) => in_array((int) $u->id, [1, 2], true));
        $this->entrar(7)->get($midia->url())->assertForbidden();
        $this->entrar(1)->get($midia->url())->assertOk();

        // "Este anexo é de um chamado que ele vê": regra por registro, só o 1.
        Pacote::autorizarDownloadCom(fn (Midia $m, $u) => $m->is($midia) && (int) $u->id === 1);
        $this->entrar(2)->get($midia->url())->assertForbidden();
        $this->entrar(1)->get($midia->url())->assertOk();

        // Resposta que não é `true` nega (falha fechada).
        Pacote::autorizarDownloadCom(fn () => 1);
        $this->entrar(1)->get($midia->url())->assertForbidden();
    }

    public function test_privar_muda_o_arquivo_de_disco_e_o_link_publico_morre(): void
    {
        $midia = $this->guardar(privado: false);
        $this->assertSame('public', $midia->disco);
        Storage::disk('public')->assertExists($midia->caminho);
        $this->assertStringContainsString('/storage/', $midia->url());

        ($this->app->make(Visibilidade::class))->privar($midia);

        Storage::disk('public')->assertMissing($midia->caminho);
        Storage::disk('local')->assertExists($midia->caminho);
        $this->assertTrue($midia->fresh()->privado);
        $this->entrar()->get($midia->fresh()->url())->assertOk();

        ($this->app->make(Visibilidade::class))->publicar($midia);
        Storage::disk('local')->assertMissing($midia->caminho);
        Storage::disk('public')->assertExists($midia->caminho);
        $this->assertFalse($midia->fresh()->privado);
    }

    public function test_arquivo_nao_aparece_na_galeria_nem_entra_na_faxina(): void
    {
        $privado = $this->guardar();
        $publico = $this->guardar(privado: false);
        Midia::semEscopo()->update(['created_at' => now()->subDay()]);

        $this->entrar()->getJson('/painel/midia/imagens')->assertJsonCount(0, 'imagens');
        $this->assertCount(0, ($this->app->make(Quarentena::class))->candidatas());
        $this->assertNotNull($privado->fresh());
        $this->assertNotNull($publico->fresh());
    }

    public function test_expurgo_so_lista_sem_executar_e_apaga_o_vencido_com(): void
    {
        $vencido = $this->guardar(expurgoEm: now()->subDay());
        $noPrazo = $this->guardar(expurgoEm: now()->addMonth());
        $semPrazo = $this->guardar();

        $this->artisan('midia:expurgar')->expectsOutputToContain('Só listei')->assertSuccessful();
        Storage::disk('local')->assertExists($vencido->caminho);

        $this->artisan('midia:expurgar --executar')->expectsOutputToContain('1 arquivo(s) apagado(s).')->assertSuccessful();
        Storage::disk('local')->assertMissing($vencido->caminho);
        $this->assertNull(Midia::semEscopo()->find($vencido->id));
        $this->assertNotNull($noPrazo->fresh());
        $this->assertNotNull($semPrazo->fresh());
        Storage::disk('local')->assertExists($noPrazo->caminho);
    }

    public function test_url_sem_rota_registrada_avisa_o_que_fazer(): void
    {
        app('router')->setRoutes(new \Illuminate\Routing\RouteCollection);
        $midia = $this->guardar();

        $this->expectExceptionMessage('chame \UrbanoDev\Midia\Midia::rotas()');
        $midia->url();
    }
}
