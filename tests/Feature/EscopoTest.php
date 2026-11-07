<?php

namespace UrbanoDev\Midia\Tests\Feature;

use UrbanoDev\Midia\Acoes\GuardarImagem;
use UrbanoDev\Midia\Excecoes\ImagemRecusada;
use UrbanoDev\Midia\Models\Midia;
use UrbanoDev\Midia\Tests\Fixtures\Texto;
use UrbanoDev\Midia\Tests\TestCase;

/** Vazamento entre clientes: a regra que mais importa no pacote. */
class EscopoTest extends TestCase
{
    private function guardar(): Midia
    {
        return ($this->app->make(GuardarImagem::class))($this->fotoUpload());
    }

    public function test_cada_cliente_ve_so_as_proprias_imagens(): void
    {
        $this->noEscopo('clinica-a');
        $daA = $this->guardar();
        $this->noEscopo('clinica-b');
        $daB = $this->guardar();

        $this->assertStringStartsWith('midias/clinica-b/', $daB->caminho);
        $this->assertSame([$daB->uuid], Midia::query()->pluck('uuid')->all());
        $this->assertNull(Midia::query()->where('uuid', $daA->uuid)->first());

        $this->noEscopo('clinica-a');
        $this->assertSame([$daA->uuid], Midia::query()->pluck('uuid')->all());
        $this->assertSame(2, Midia::semEscopo()->count());
    }

    public function test_nao_registra_uso_de_imagem_de_outro_cliente(): void
    {
        $this->noEscopo('clinica-a');
        $daA = $this->guardar();

        $this->noEscopo('clinica-b');
        $texto = Texto::create(['titulo' => 'Da B']);
        $this->assertSame([], $texto->sincronizarMidias('capa', [$daA->uuid, $daA->url()]));
        $this->assertSame(0, $texto->usosDeMidia()->count());
    }

    public function test_escopo_obrigatorio_sem_cliente_falha_fechado(): void
    {
        $this->noEscopo('clinica-a');
        $this->guardar();

        config(['midia.escopo.obrigatorio' => true]);
        $this->noEscopo(null);

        $this->assertSame(0, Midia::query()->count(), 'sem cliente não lê nada');

        $this->expectException(ImagemRecusada::class);
        $this->guardar();
    }

    public function test_escopo_por_classe_no_config(): void
    {
        config(['midia.escopo.resolver' => ResolverDeTeste::class]);
        $this->assertSame('do-config', $this->guardar()->escopo());
    }

    public function test_escopo_estranho_nao_vira_caminho_fora_da_pasta(): void
    {
        $this->noEscopo('../../etc');
        $midia = $this->guardar();

        $this->assertStringNotContainsString('..', $midia->caminho);
        $this->assertMatchesRegularExpression('#^midias/e[0-9a-f]{32}/#', $midia->caminho);
        $this->assertSame('../../etc', $midia->escopo());
    }
}

class ResolverDeTeste
{
    public function __invoke(): string
    {
        return 'do-config';
    }
}
