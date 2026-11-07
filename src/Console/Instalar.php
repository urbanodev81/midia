<?php

namespace UrbanoDev\Midia\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Um comando instala tudo, e rodar de novo não quebra nem duplica:
 * publica o config (se ainda não existe), cria as tabelas, faz o
 * storage:link quando o disco é o público e roda o `midia:conferir`.
 * Não liga rota, menu nem agendamento.
 */
class Instalar extends Command
{
    protected $signature = 'midia:instalar';

    protected $description = 'Instala o pacote de mídia (config, tabelas, storage:link) e confere o servidor';

    public function handle(): int
    {
        if (file_exists(config_path('midia.php'))) {
            $this->line('  config/midia.php já existe — mantido.');
        } else {
            $this->call('vendor:publish', ['--tag' => 'midia-config']);
        }

        if (! config('midia.carregar_migrations')) {
            $this->warn('  MIDIA_CARREGAR_MIGRATIONS=false (banco por tenant): publique com `vendor:publish --tag=midia-migrations` e mova para database/migrations/tenant.');
        } elseif (Schema::hasTable('midias')) {
            $this->line('  tabelas já existem — mantidas.');
        } else {
            // Só as migrations do pacote: nunca roda as pendentes do sistema junto.
            $this->call('migrate', ['--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true, '--force' => true]);
        }

        if (config('midia.disco') === 'public' && ! file_exists(public_path('storage'))) {
            $this->call('storage:link');
        }

        $this->newLine();
        $resultado = $this->call('midia:conferir');

        $this->newLine();
        $this->line('Próximos passos (nada disso liga sozinho):');
        $this->line('  1. Rotas da galeria: \\UrbanoDev\\Midia\\Midia::rotas() dentro do grupo autenticado.');
        $this->line('  2. Nos models que mostram imagem: use \\UrbanoDev\\Midia\\Concerns\\UsaMidias e chame sincronizarMidias() ao salvar.');
        $this->line('  3. Faxina (opcional): agende `midia:faxinar --executar`. Ela move para a quarentena, não apaga.');
        $this->line('  4. Sistema com cliente: midia.escopo.resolver + midia.escopo.obrigatorio=true ANTES de subir a primeira imagem.');

        return $resultado;
    }
}
