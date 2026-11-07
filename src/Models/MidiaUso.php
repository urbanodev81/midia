<?php

namespace UrbanoDev\Midia\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** "Esta imagem está no campo X do registro Y." Imagem sem uso nenhum é candidata à faxina. */
class MidiaUso extends Model
{
    protected $table = 'midia_usos';

    protected $guarded = ['id'];

    public function midia(): BelongsTo
    {
        return $this->belongsTo(Midia::class, 'midia_id')->withoutGlobalScope('midia-escopo');
    }

    public function usuario(): MorphTo
    {
        return $this->morphTo();
    }

    /** Rótulo para a galeria ("Usada em ..."): `rotuloParaMidia()` do model, se houver. */
    public function rotulo(): string
    {
        $dono = $this->usuario;

        if ($dono !== null && method_exists($dono, 'rotuloParaMidia')) {
            return (string) $dono->rotuloParaMidia($this->campo);
        }

        return class_basename($this->usuario_type).' #'.$this->usuario_id;
    }
}
