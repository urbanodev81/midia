<?php

namespace UrbanoDev\Midia\Acoes;

use Illuminate\Support\Facades\Storage;
use LogicException;
use UrbanoDev\Midia\Models\Midia;
use UrbanoDev\Midia\Suporte\Transferencia;

/**
 * Troca um arquivo entre público e privado MUDANDO O ARQUIVO DE DISCO, não só
 * o link. Ao ficar privado, o endereço público antigo para de responder na
 * hora (o defeito da pendência 257: o "privado" só trocava o link).
 */
final class Visibilidade
{
    public function privar(Midia $midia): Midia
    {
        return $this->levar($midia, true, (string) config('midia.arquivos.disco_privado', 'local'));
    }

    public function publicar(Midia $midia): Midia
    {
        return $this->levar($midia, false, (string) config('midia.disco', 'public'));
    }

    private function levar(Midia $midia, bool $privado, string $disco): Midia
    {
        if ($midia->emQuarentena()) {
            throw new LogicException('Restaure da quarentena antes de trocar a visibilidade.');
        }

        if ($midia->privado === $privado && $midia->disco === $disco) {
            return $midia;
        }

        if ($midia->disco !== $disco) {
            Transferencia::mover(Storage::disk($midia->disco), Storage::disk($disco), $midia->caminho, $midia->caminho);
        }

        $midia->forceFill(['privado' => $privado, 'disco' => $disco])->save();

        return $midia;
    }
}
