<?php

namespace UrbanoDev\Midia\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use UrbanoDev\Midia\Acoes\Quarentena;
use UrbanoDev\Midia\Models\Midia;

/**
 * Prazo de guarda (LGPD): o arquivo com `expurgo_em` vencido sai de vez.
 * É o ÚNICO comando do pacote que apaga, e só com `--executar`; sem isso só
 * lista. Nada fica agendado sozinho. Quem não grava `expurgo_em` nunca perde nada.
 */
class Expurgar extends Command
{
    protected $signature = 'midia:expurgar {--executar : apaga de verdade; sem isto só lista}';

    protected $description = 'Apaga os arquivos com prazo de guarda vencido (só com --executar)';

    public function handle(Quarentena $quarentena): int
    {
        $vencidos = Midia::semEscopo()->whereNotNull('expurgo_em')->where('expurgo_em', '<=', now())->orderBy('expurgo_em')->get();

        if ($vencidos->isEmpty()) {
            $this->info('Nenhum arquivo com prazo de guarda vencido.');

            return self::SUCCESS;
        }

        $this->line("<comment>Prazo vencido:</comment> {$vencidos->count()}");
        $this->table(
            ['uuid', 'escopo', 'caminho', 'nome original', 'prazo'],
            $vencidos->map(fn (Midia $m) => [
                $m->uuid, $m->escopo() ?? '—', $m->caminho, $m->nome_original ?? '—', $m->expurgo_em?->format('d/m/Y'),
            ])->all(),
        );

        if (! $this->option('executar')) {
            $this->comment('Só listei. Para apagar de vez: midia:expurgar --executar');

            return self::SUCCESS;
        }

        foreach ($vencidos as $midia) {
            // A linha primeiro: arquivo órfão é lixo, linha órfã é link quebrado.
            $caminho = $midia->emQuarentena() ? [$quarentena->disco(), $quarentena->destino($midia)] : [Storage::disk($midia->disco), $midia->caminho];
            $midia->delete();
            $caminho[0]->delete($caminho[1]);
        }

        $this->info($vencidos->count().' arquivo(s) apagado(s).');

        return self::SUCCESS;
    }
}
