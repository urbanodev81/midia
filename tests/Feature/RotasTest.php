<?php

namespace UrbanoDev\Midia\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use UrbanoDev\Midia\Midia as Pacote;
use UrbanoDev\Midia\Models\Midia;
use UrbanoDev\Midia\Tests\Fixtures\Texto;
use UrbanoDev\Midia\Tests\TestCase;

class RotasTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->prefix('painel')->name('painel.')->group(fn () => Pacote::rotas());
    }

    private function entrar(int $id = 7): static
    {
        return $this->actingAs(new GenericUser(['id' => $id, 'name' => 'Ana']));
    }

    public function test_nada_de_rota_sem_o_sistema_registrar(): void
    {
        $this->assertTrue(app('router')->has('painel.midia.enviar'));
        $this->assertTrue(app('router')->has('painel.midia.imagens'));
    }

    public function test_envia_e_aparece_na_galeria_com_os_usos(): void
    {
        $resposta = $this->entrar()->postJson('/painel/midia/imagens', ['arquivo' => $this->fotoUpload(), 'colecao' => 'blog'])
            ->assertCreated()
            ->assertJsonPath('perfil', 'foto')
            ->assertJsonPath('largura', 800);

        $midia = Midia::query()->where('uuid', $resposta->json('uuid'))->firstOrFail();
        $this->assertSame('7', $midia->user_id);
        Texto::create(['titulo' => 'Bem-vindo'])->sincronizarMidias('capa', [$midia]);

        $this->entrar()->getJson('/painel/midia/imagens?colecao=blog')
            ->assertOk()
            ->assertJsonPath('imagens.0.uuid', $midia->uuid)
            ->assertJsonPath('imagens.0.usos', ['Bem-vindo (capa)']);
        $this->entrar()->getJson('/painel/midia/imagens?colecao=outra')->assertJsonCount(0, 'imagens');
    }

    public function test_recusa_com_mensagem_para_a_tela(): void
    {
        $svg = $this->upload('<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'logo.svg');

        $this->entrar()->postJson('/painel/midia/imagens', ['arquivo' => $svg])
            ->assertStatus(422)
            ->assertJsonPath('errors.arquivo.0', 'Envie uma imagem JPG, PNG ou WebP.');

        $this->entrar()->postJson('/painel/midia/imagens', ['arquivo' => $this->fotoUpload(), 'perfil' => 'banner'])
            ->assertStatus(422)->assertJsonPath('errors.perfil.0', 'Tipo de imagem desconhecido.');

        config(['midia.limite_mb' => 0]);
        $this->entrar()->postJson('/painel/midia/imagens', ['arquivo' => $this->fotoUpload()])
            ->assertStatus(422)->assertJsonPath('errors.arquivo.0', 'A imagem passa do limite de 0 MB.');
    }

    public function test_galeria_respeita_o_escopo(): void
    {
        $this->noEscopo('a');
        $this->entrar()->postJson('/painel/midia/imagens', ['arquivo' => $this->fotoUpload()])->assertCreated();

        $this->noEscopo('b');
        $this->entrar()->getJson('/painel/midia/imagens')->assertJsonCount(0, 'imagens');
    }

    public function test_permissao_do_config_e_exigida(): void
    {
        config(['midia.rotas.permissao' => 'editar-site']);
        Gate::define('editar-site', fn ($user) => (int) $user->id === 1);

        $this->entrar(7)->getJson('/painel/midia/imagens')->assertForbidden();
        $this->entrar(7)->postJson('/painel/midia/imagens', ['arquivo' => $this->fotoUpload()])->assertForbidden();
        $this->entrar(1)->getJson('/painel/midia/imagens')->assertOk();
    }
}
