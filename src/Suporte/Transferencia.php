<?php

namespace UrbanoDev\Midia\Suporte;

use Illuminate\Contracts\Filesystem\Filesystem;

/** Muda um arquivo de disco sem risco de perder: copia, confere e só então tira da origem. */
final class Transferencia
{
    /** @return bool false quando a origem já não existe (não trava quem chamou) */
    public static function mover(Filesystem $de, Filesystem $para, string $origem, string $destino): bool
    {
        $conteudo = $de->get($origem);

        if ($conteudo === null) {
            return false;
        }

        $para->put($destino, $conteudo);

        if ($para->get($destino) !== $conteudo) {
            throw new \RuntimeException("A cópia de {$origem} não conferiu; a origem foi mantida.");
        }

        $de->delete($origem);

        return true;
    }
}
