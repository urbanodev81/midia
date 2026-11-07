<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $escopo = config('midia.escopo.coluna', 'escopo');

        Schema::create('midias', function (Blueprint $table) use ($escopo) {
            $table->id();
            $table->uuid('uuid')->unique();
            // Cliente dono (tenant, prestadora). Nulo em site sem escopo.
            $table->string($escopo, 64)->nullable();
            $table->string('perfil', 40);
            $table->string('colecao', 60)->nullable();
            $table->string('disco', 40);
            $table->string('caminho');
            $table->string('mime', 40);
            $table->unsignedInteger('largura');
            $table->unsignedInteger('altura');
            $table->unsignedInteger('bytes');
            $table->char('sha256', 64);
            $table->string('nome_original')->nullable();
            // Última descrição (alt) usada: a galeria sugere de novo.
            $table->string('descricao', 300)->nullable();
            // Inteiro ou uuid, conforme o sistema.
            $table->string('user_id', 36)->nullable();
            $table->timestamp('quarentena_em')->nullable();
            $table->timestamps();

            $table->index([$escopo, 'colecao']);
            $table->index(['quarentena_em', 'created_at']);
            $table->index('sha256');
        });

        Schema::create('midia_usos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('midia_id')->constrained('midias')->cascadeOnDelete();
            $table->string('usuario_type');
            $table->string('usuario_id', 36);
            $table->string('campo', 60);
            $table->timestamps();

            $table->unique(['midia_id', 'usuario_type', 'usuario_id', 'campo']);
            $table->index(['usuario_type', 'usuario_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('midia_usos');
        Schema::dropIfExists('midias');
    }
};
