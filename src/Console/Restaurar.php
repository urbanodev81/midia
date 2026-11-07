<?php

namespace UrbanoDev\Midia\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use UrbanoDev\Midia\Acoes\Quarentena;
use UrbanoDev\Midia\Models\Midia;

class Restaurar extends Command
{
    protected $signature = 'midia:restaurar {uuid* : um ou mais uuids da quarentena}';

    protected $description = 'Devolve imagens da quarentena para o ar';

    public function handle(Quarentena $quarentena): int
    {
        $falhou = false;

        foreach ((array) $this->argument('uuid') as $uuid) {
            // No Postgres a coluna é uuid: texto fora do formato estouraria a consulta.
            $midia = Str::isUuid($uuid) ? Midia::semEscopo()->where('uuid', strtolower($uuid))->first() : null;

            if ($midia === null) {
                $this->error("{$uuid}: não existe.");
                $falhou = true;

                continue;
            }

            $quarentena->restaurar($midia);
            $this->info("{$uuid}: no ar de novo ({$midia->caminho}).");
        }

        return $falhou ? self::FAILURE : self::SUCCESS;
    }
}
