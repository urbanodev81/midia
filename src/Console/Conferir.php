<?php

namespace UrbanoDev\Midia\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use UrbanoDev\Midia\Escopo;

/** Confere se o servidor dá conta (GD, WebP, EXIF, limites do PHP, link do storage). Só lê. */
class Conferir extends Command
{
    protected $signature = 'midia:conferir';

    protected $description = 'Confere extensões do PHP, limites de upload, tabelas e escopo do pacote de mídia';

    public function handle(): int
    {
        $falhas = 0;
        $ok = function (bool $passou, string $texto, string $conserto = '') use (&$falhas): void {
            if ($passou) {
                $this->line("  <info>ok</info>    {$texto}");

                return;
            }
            $falhas++;
            $this->line("  <error>falta</error> {$texto}".($conserto !== '' ? " — {$conserto}" : ''));
        };

        $gd = function_exists('gd_info') ? gd_info() : [];
        $ok($gd !== [], 'extensão gd', 'instale php-gd');
        $ok(! empty($gd['JPEG Support']), 'gd lê e grava JPEG', 'gd compilado com libjpeg');
        $ok(! empty($gd['PNG Support']), 'gd lê e grava PNG', 'gd compilado com libpng');
        $ok(! empty($gd['WebP Support']), 'gd lê e grava WebP', 'gd compilado com libwebp');
        $ok(function_exists('exif_read_data'), 'extensão exif (endireita a foto do celular)', 'instale php-exif; sem ela a foto em pé sai deitada');

        $limite = (int) config('midia.limite_mb', 8) * 1024 ** 2;
        foreach (['upload_max_filesize', 'post_max_size'] as $chave) {
            $valor = (string) ini_get($chave);
            $ok(self::bytes($valor) >= $limite, "{$chave} = {$valor} (o pacote aceita até ".config('midia.limite_mb', 8).' MB)', "suba para pelo menos {$this->mb($limite)} no php.ini");
        }
        $this->line('  <comment>lembre</comment> o nginx precisa de client_max_body_size acima de '.$this->mb($limite).', senão a tela recebe 413.');

        $ok(Schema::hasTable('midias') && Schema::hasTable('midia_usos'), 'tabelas midias e midia_usos', 'php artisan migrate');

        $disco = (string) config('midia.disco', 'public');
        if ($disco === 'public') {
            $ok(is_link(public_path('storage')) || is_dir(public_path('storage')), 'public/storage aponta para o disco público', 'php artisan storage:link');
        }

        if (Escopo::obrigatorio()) {
            $ok(Escopo::temResolver(), 'escopo obrigatório e com resolver', 'defina midia.escopo.resolver ou Escopo::resolverCom()');
        } else {
            $this->line('  <comment>info</comment> sem escopo por cliente (certo para site pequeno; sistema multi-inquilino precisa ligar).');
        }

        $this->newLine();
        $falhas === 0 ? $this->info('Tudo certo.') : $this->error("{$falhas} item(ns) a resolver.");

        return $falhas === 0 ? self::SUCCESS : self::FAILURE;
    }

    public static function bytes(string $valor): int
    {
        $valor = trim($valor);
        if ($valor === '' || $valor === '-1' || $valor === '0') {
            return PHP_INT_MAX;
        }

        return (int) $valor * match (strtolower(substr($valor, -1))) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };
    }

    private function mb(int $bytes): string
    {
        return intdiv($bytes, 1024 ** 2).' MB';
    }
}
