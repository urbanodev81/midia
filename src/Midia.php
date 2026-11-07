<?php

namespace UrbanoDev\Midia;

use Illuminate\Support\Facades\Route;
use UrbanoDev\Midia\Http\ImagensController;

/** Ponto de entrada do sistema para o que não liga sozinho. */
final class Midia
{
    /**
     * Registra a galeria e o envio. Chame dentro do grupo autenticado:
     *
     *     Route::middleware(['auth'])->prefix('painel')->name('painel.')->group(function () {
     *         \UrbanoDev\Midia\Midia::rotas();   // painel.midia.imagens / painel.midia.enviar
     *     });
     */
    public static function rotas(string $prefixo = 'midia'): void
    {
        Route::prefix($prefixo)->name('midia.')->group(function () {
            Route::get('imagens', [ImagensController::class, 'index'])->name('imagens');
            Route::post('imagens', [ImagensController::class, 'store'])->middleware('throttle:60,1')->name('enviar');
        });
    }
}
