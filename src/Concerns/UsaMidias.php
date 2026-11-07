<?php

namespace UrbanoDev\Midia\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use UrbanoDev\Midia\Acoes\Quarentena;
use UrbanoDev\Midia\Models\Midia;
use UrbanoDev\Midia\Models\MidiaUso;

/**
 * Para o model que mostra imagens (texto do blog, lançamento, site da clínica).
 *
 *     $texto->sincronizarMidias('capa', [$request->capa_uuid]);
 *     $texto->sincronizarMidias('corpo', Midia::uuidsNoTexto($texto->corpo_html));
 *
 * Registra onde cada imagem está, para a galeria dizer "Usada em ..." e a
 * faxina saber o que pode ir para a quarentena. Só aceita imagem do escopo
 * da requisição. Imagem que estava na quarentena e voltou a ser usada é
 * restaurada na hora. Defina `rotuloParaMidia(string $campo): string` para
 * a galeria mostrar um nome legível.
 */
trait UsaMidias
{
    public static function bootUsaMidias(): void
    {
        // Só a exclusão de verdade solta as imagens; o "arquivar" (soft delete) mantém.
        static::deleted(function ($model) {
            if (! method_exists($model, 'isForceDeleting') || $model->isForceDeleting()) {
                $model->usosDeMidia()->delete();
            }
        });
    }

    public function usosDeMidia(): MorphMany
    {
        return $this->morphMany(MidiaUso::class, 'usuario');
    }

    /**
     * @param  iterable<Midia|string|null>  $midias  Midia, uuid ou URL; nulos são ignorados
     * @return list<string> os uuids que ficaram registrados
     */
    public function sincronizarMidias(string $campo, iterable $midias): array
    {
        $uuids = [];
        foreach ($midias as $m) {
            if (($uuid = Midia::uuidDe($m)) !== null) {
                $uuids[$uuid] = true;
            }
        }

        $encontradas = $uuids === [] ? collect() : Midia::query()->whereIn('uuid', array_keys($uuids))->get();
        $ids = $encontradas->pluck('id')->all();

        $this->usosDeMidia()->where('campo', $campo)->whereNotIn('midia_id', $ids ?: [0])->delete();

        foreach ($encontradas as $midia) {
            if ($midia->emQuarentena()) {
                app(Quarentena::class)->restaurar($midia);
            }

            MidiaUso::query()->firstOrCreate([
                'midia_id' => $midia->id,
                'usuario_type' => $this->getMorphClass(),
                'usuario_id' => (string) $this->getKey(),
                'campo' => $campo,
            ]);
        }

        return $encontradas->pluck('uuid')->values()->all();
    }
}
