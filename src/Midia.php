<?php

namespace UrbanoDev\Midia;

use Closure;
use Illuminate\Support\Facades\Route;
use LogicException;
use UrbanoDev\Midia\Http\ArquivosController;
use UrbanoDev\Midia\Http\ImagensController;
use UrbanoDev\Midia\Models\Midia as MidiaModel;

/** Ponto de entrada do sistema para o que não liga sozinho. */
final class Midia
{
    private static ?Closure $autorizador = null;

    /**
     * Registra a galeria, o envio e o download do privado. Chame dentro do grupo autenticado:
     *
     *     Route::middleware(['auth'])->prefix('painel')->name('painel.')->group(function () {
     *         \UrbanoDev\Midia\Midia::rotas();   // painel.midia.imagens / .enviar / .baixar
     *     });
     */
    public static function rotas(string $prefixo = 'midia'): void
    {
        Route::prefix($prefixo)->name('midia.')->group(function () {
            Route::get('imagens', [ImagensController::class, 'index'])->name('imagens');
            Route::post('imagens', [ImagensController::class, 'store'])->middleware('throttle:60,1')->name('enviar');
            Route::get('arquivos/{uuid}', [ArquivosController::class, 'baixar'])->middleware('throttle:120,1')->name('baixar');
        });
    }

    /**
     * Quem pode baixar um arquivo privado, além do escopo e da permissão.
     * Ex.: fn (Midia $m, $usuario) => Anexo::where('midia_id', $m->id)->first()?->chamado->visivelPara($usuario)
     */
    public static function autorizarDownloadCom(?callable $regra): void
    {
        self::$autorizador = $regra === null ? null : Closure::fromCallable($regra);
    }

    public static function temAutorizador(): bool
    {
        return self::$autorizador !== null || config('midia.arquivos.autorizar') !== null;
    }

    public static function podeBaixar(MidiaModel $midia, mixed $usuario): bool
    {
        $regra = self::$autorizador;

        if ($regra === null && ($classe = config('midia.arquivos.autorizar'))) {
            $regra = app($classe);
        }

        return $regra === null || $regra($midia, $usuario) === true;
    }

    /** O endereço da rota de download. Acha a rota pela ação: vale com `route:cache`. */
    public static function urlDeDownload(MidiaModel $midia): string
    {
        $rota = app('router')->getRoutes()->getByAction(ArquivosController::class.'@baixar');

        if ($rota === null || $rota->getName() === null) {
            throw new LogicException('Arquivo privado sem rota: chame \UrbanoDev\Midia\Midia::rotas() dentro do grupo autenticado.');
        }

        return route($rota->getName(), ['uuid' => $midia->uuid]);
    }
}
