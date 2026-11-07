<?php

namespace UrbanoDev\Midia\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use UrbanoDev\Midia\Acoes\GuardarImagem;
use UrbanoDev\Midia\Console\Conferir;
use UrbanoDev\Midia\Tests\TestCase;

class ComandosTest extends TestCase
{
    public function test_instalar_roda_duas_vezes_sem_quebrar(): void
    {
        $this->artisan('midia:instalar')->expectsOutputToContain('midia:faxinar')->run();
        $this->artisan('midia:instalar')->expectsOutputToContain('tabelas já existem')->run();
        $this->assertFileExists(config_path('midia.php'));
        @unlink(config_path('midia.php'));
    }

    public function test_conferir_mostra_cada_item(): void
    {
        $this->artisan('midia:conferir')
            ->expectsOutputToContain('gd lê e grava WebP')
            ->expectsOutputToContain('upload_max_filesize')
            ->expectsOutputToContain('tabelas midias e midia_usos')
            ->run();
    }

    public function test_storage_servido_pelo_nginx_nao_cobra_o_link(): void
    {
        config(['midia.storage_link' => false]);
        $this->artisan('midia:conferir')
            ->expectsOutputToContain('midia.storage_link = false')
            ->doesntExpectOutputToContain('public/storage aponta')
            ->run();
    }

    public function test_conferir_acusa_escopo_obrigatorio_sem_resolver(): void
    {
        config(['midia.escopo.obrigatorio' => true]);
        $this->artisan('midia:conferir')->expectsOutputToContain('escopo obrigatório e com resolver')->assertFailed();
    }

    public function test_bytes_do_php_ini(): void
    {
        $this->assertSame(8 * 1024 ** 2, Conferir::bytes('8M'));
        $this->assertSame(1024 ** 3, Conferir::bytes('1G'));
        $this->assertSame(PHP_INT_MAX, Conferir::bytes('0'));
    }

    public function test_faxinar_so_lista_sem_executar_e_restaurar_devolve(): void
    {
        $midia = ($this->app->make(GuardarImagem::class))($this->fotoUpload());
        $midia->forceFill(['created_at' => now()->subHours(3)])->save();

        $this->artisan('midia:faxinar')->expectsOutputToContain($midia->uuid)->expectsOutputToContain('Só listei')->assertSuccessful();
        Storage::disk('public')->assertExists($midia->caminho);

        $this->artisan('midia:faxinar --executar')->assertSuccessful();
        Storage::disk('public')->assertMissing($midia->caminho);
        $this->artisan('midia:faxinar --lista')->expectsOutputToContain($midia->uuid)->assertSuccessful();

        $this->artisan('midia:restaurar', ['uuid' => [$midia->uuid]])->assertSuccessful();
        Storage::disk('public')->assertExists($midia->caminho);
        $this->artisan('midia:restaurar', ['uuid' => ['nao-existe']])->assertFailed();
    }
}
