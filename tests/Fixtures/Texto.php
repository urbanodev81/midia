<?php

namespace UrbanoDev\Midia\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use UrbanoDev\Midia\Concerns\UsaMidias;

class Texto extends Model
{
    use SoftDeletes, UsaMidias;

    protected $table = 'textos';

    protected $guarded = ['id'];

    public function rotuloParaMidia(string $campo): string
    {
        return "{$this->titulo} ({$campo})";
    }
}
