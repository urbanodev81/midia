<?php

namespace UrbanoDev\Midia\Acoes;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use UrbanoDev\Midia\Models\Midia;
use UrbanoDev\Midia\Suporte\Transferencia;

/**
 * Faxina sem apagar (regra da casa: conteúdo não se exclui).
 *
 * Imagem sem nenhum uso registrado e mais velha que a carência vai para o
 * disco da quarentena (fora do ar), com o mesmo caminho relativo. A linha
 * fica, marcada com `quarentena_em`. `restaurar()` devolve.
 *
 * Só imagem guardada por `GuardarImagem`. Arquivo (`GuardarArquivo`, perfil
 * `arquivo`) pertence a um registro do sistema e nunca entra na faxina.
 */
final class Quarentena
{
    /** @return Collection<int, Midia> */
    public function candidatas(): Collection
    {
        $limite = now()->subMinutes((int) config('midia.faxina.carencia_minutos', 120));

        return Midia::semEscopo()
            ->whereNull('quarentena_em')
            ->where('perfil', '!=', Midia::PERFIL_ARQUIVO)
            ->where('created_at', '<', $limite)
            ->whereDoesntHave('usos')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, Midia> as que estão na quarentena agora */
    public function lista(): Collection
    {
        return Midia::semEscopo()->whereNotNull('quarentena_em')->orderBy('quarentena_em')->get();
    }

    public function mover(Midia $midia): void
    {
        if ($midia->emQuarentena()) {
            return;
        }

        Transferencia::mover(Storage::disk($midia->disco), $this->disco(), $midia->caminho, $this->destino($midia));
        $midia->forceFill(['quarentena_em' => now()])->save();
    }

    public function restaurar(Midia $midia): void
    {
        if (! $midia->emQuarentena()) {
            return;
        }

        Transferencia::mover($this->disco(), Storage::disk($midia->disco), $this->destino($midia), $midia->caminho);
        $midia->forceFill(['quarentena_em' => null])->save();
    }

    public function disco(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk((string) config('midia.faxina.disco', 'local'));
    }

    public function destino(Midia $midia): string
    {
        return trim((string) config('midia.faxina.pasta', 'midia-quarentena'), '/').'/'.$midia->caminho;
    }
}
