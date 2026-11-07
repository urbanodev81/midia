<?php

namespace UrbanoDev\Midia;

use Closure;
use UrbanoDev\Midia\Excecoes\ImagemRecusada;

/**
 * Quem é o cliente da requisição (tenant, prestadora, workspace).
 *
 * Site pequeno: nada configurado, `atual()` é null e o pacote roda sem escopo.
 * Sistema com cliente: `midia.escopo.resolver` (classe invocável) ou
 * `Escopo::resolverCom(fn () => ...)` num service provider, e
 * `midia.escopo.obrigatorio` => true para falhar fechado.
 */
final class Escopo
{
    private static ?Closure $resolver = null;

    public static function resolverCom(?callable $resolver): void
    {
        self::$resolver = $resolver === null ? null : Closure::fromCallable($resolver);
    }

    public static function temResolver(): bool
    {
        return self::$resolver !== null || config('midia.escopo.resolver') !== null;
    }

    public static function coluna(): string
    {
        return (string) config('midia.escopo.coluna', 'escopo');
    }

    public static function obrigatorio(): bool
    {
        return (bool) config('midia.escopo.obrigatorio', false);
    }

    public static function atual(): ?string
    {
        $resolver = self::$resolver;

        if ($resolver === null && ($classe = config('midia.escopo.resolver'))) {
            $resolver = app($classe);
        }

        if ($resolver === null) {
            return null;
        }

        $valor = $resolver();

        return $valor === null || $valor === '' ? null : (string) $valor;
    }

    /** O escopo para gravar agora; sem ele, num sistema que exige, recusa. */
    public static function paraGravar(): ?string
    {
        $escopo = self::atual();

        if ($escopo === null && self::obrigatorio()) {
            throw new ImagemRecusada('Não foi possível identificar a conta para guardar a imagem. Entre de novo e tente outra vez.');
        }

        return $escopo;
    }

    /** Pedaço de pasta seguro a partir do escopo (nunca deixa `../` passar). */
    public static function pasta(?string $escopo): ?string
    {
        if ($escopo === null) {
            return null;
        }

        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $escopo) === 1
            ? $escopo
            : 'e'.substr(hash('sha256', $escopo), 0, 32);
    }
}
