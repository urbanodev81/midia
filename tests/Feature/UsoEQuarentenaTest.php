<?php

namespace UrbanoDev\Midia\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use UrbanoDev\Midia\Acoes\GuardarImagem;
use UrbanoDev\Midia\Acoes\Quarentena;
use UrbanoDev\Midia\Models\Midia;
use UrbanoDev\Midia\Tests\Fixtures\Texto;
use UrbanoDev\Midia\Tests\TestCase;

class UsoEQuarentenaTest extends TestCase
{
    private function guardar(int $minutosAtras = 0): Midia
    {
        $midia = ($this->app->make(GuardarImagem::class))($this->fotoUpload());
        $midia->forceFill(['created_at' => now()->subMinutes($minutosAtras)])->save();

        return $midia;
    }

    public function test_registra_e_solta_os_usos_por_campo(): void
    {
        [$a, $b] = [$this->guardar(), $this->guardar()];
        $texto = Texto::create(['titulo' => 'Primeiro']);

        $texto->sincronizarMidias('capa', [$a]);
        $texto->sincronizarMidias('corpo', [$a->url(), $b->uuid, null]);
        $this->assertSame(3, $texto->usosDeMidia()->count());

        $texto->sincronizarMidias('corpo', [$b->uuid]);
        $this->assertSame([$a->id], $texto->usosDeMidia()->where('campo', 'capa')->pluck('midia_id')->all());
        $this->assertSame([$b->id], $texto->usosDeMidia()->where('campo', 'corpo')->pluck('midia_id')->all());

        $texto->sincronizarMidias('corpo', []);
        $this->assertSame(1, $texto->usosDeMidia()->count());
    }

    public function test_arquivar_mantem_o_uso_e_excluir_de_verdade_solta(): void
    {
        $midia = $this->guardar();
        $texto = Texto::create(['titulo' => 'Arquivável']);
        $texto->sincronizarMidias('capa', [$midia]);

        $texto->delete();
        $this->assertSame(1, $midia->usos()->count(), 'soft delete não solta');

        $texto->forceDelete();
        $this->assertSame(0, $midia->usos()->count());
    }

    public function test_faxina_move_para_a_quarentena_so_o_que_passou_da_carencia_sem_uso(): void
    {
        $velhaSemUso = $this->guardar(180);
        $recente = $this->guardar(10);
        $velhaEmUso = $this->guardar(180);
        Texto::create(['titulo' => 'T'])->sincronizarMidias('capa', [$velhaEmUso]);

        $quarentena = $this->app->make(Quarentena::class);
        $this->assertSame([$velhaSemUso->id], $quarentena->candidatas()->pluck('id')->all());

        $quarentena->mover($velhaSemUso->fresh());

        Storage::disk('public')->assertMissing($velhaSemUso->caminho);
        Storage::disk('local')->assertExists('midia-quarentena/'.$velhaSemUso->caminho);
        $this->assertNotNull($velhaSemUso->fresh()->quarentena_em, 'a linha fica, marcada');
        Storage::disk('public')->assertExists($recente->caminho);
        Storage::disk('public')->assertExists($velhaEmUso->caminho);
        $this->assertSame([], $quarentena->candidatas()->pluck('id')->all());
        $this->assertSame([$velhaSemUso->id], $quarentena->lista()->pluck('id')->all());
        $this->assertFalse(Midia::query()->ativas()->whereKey($velhaSemUso->id)->exists(), 'some da galeria');
    }

    public function test_restaurar_devolve_e_usar_de_novo_restaura_sozinho(): void
    {
        $quarentena = $this->app->make(Quarentena::class);
        $midia = $this->guardar(180);
        $quarentena->mover($midia);

        $quarentena->restaurar($midia->fresh());
        Storage::disk('public')->assertExists($midia->caminho);
        Storage::disk('local')->assertMissing('midia-quarentena/'.$midia->caminho);
        $this->assertNull($midia->fresh()->quarentena_em);

        $quarentena->mover($midia->fresh());
        Texto::create(['titulo' => 'Voltou'])->sincronizarMidias('capa', [$midia->uuid]);
        Storage::disk('public')->assertExists($midia->caminho);
        $this->assertNull($midia->fresh()->quarentena_em);
    }
}
