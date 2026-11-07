<?php

namespace UrbanoDev\Midia\Console;

use Illuminate\Console\Command;
use UrbanoDev\Midia\Acoes\Quarentena;
use UrbanoDev\Midia\Models\Midia;

/**
 * Lista (padrão) ou move para a quarentena (`--executar`) as imagens sem uso
 * há mais que a carência. `--lista` mostra o que já está lá. Nada é apagado.
 */
class Faxinar extends Command
{
    protected $signature = 'midia:faxinar
        {--executar : move de verdade; sem isto só lista}
        {--lista : mostra o que já está na quarentena}';

    protected $description = 'Move para a quarentena as imagens sem uso (não apaga nada)';

    public function handle(Quarentena $quarentena): int
    {
        if ($this->option('lista')) {
            $this->tabela($quarentena->lista(), 'Na quarentena');

            return self::SUCCESS;
        }

        $candidatas = $quarentena->candidatas();

        if ($candidatas->isEmpty()) {
            $this->info('Nenhuma imagem sem uso além da carência.');

            return self::SUCCESS;
        }

        $this->tabela($candidatas, 'Sem uso há mais de '.config('midia.faxina.carencia_minutos', 120).' min');

        if (! $this->option('executar')) {
            $this->comment('Só listei. Para mover para a quarentena: midia:faxinar --executar');

            return self::SUCCESS;
        }

        $candidatas->each(fn (Midia $m) => $quarentena->mover($m));
        $this->info($candidatas->count().' imagem(ns) na quarentena. Para devolver: midia:restaurar <uuid>');

        return self::SUCCESS;
    }

    private function tabela($midias, string $titulo): void
    {
        $this->line("<comment>{$titulo}:</comment> {$midias->count()}");
        $this->table(
            ['uuid', 'escopo', 'caminho', 'nome original', 'criada', 'quarentena'],
            $midias->map(fn (Midia $m) => [
                $m->uuid, $m->escopo() ?? '—', $m->caminho, $m->nome_original ?? '—',
                $m->created_at?->format('d/m/Y H:i'), $m->quarentena_em?->format('d/m/Y H:i') ?? '—',
            ])->all(),
        );
    }
}
