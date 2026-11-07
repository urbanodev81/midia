<?php

return [
    // false em sistema com banco por tenant (stancl): publique as migrations
    // (`vendor:publish --tag=midia-migrations`) para database/migrations/tenant.
    'carregar_migrations' => env('MIDIA_CARREGAR_MIGRATIONS', true),

    // Onde a imagem tratada fica. Imagem pública = disco com URL (o `public`
    // precisa do `php artisan storage:link`).
    'disco' => env('MIDIA_DISCO', 'public'),
    'pasta' => 'midias',

    // Teto do arquivo enviado. O `midia:conferir` compara com o
    // upload_max_filesize e o post_max_size do PHP (e o nginx precisa de
    // client_max_body_size maior que isto, senão a tela recebe 413).
    'limite_mb' => 8,

    // O GD abre a imagem inteira na memória (4 bytes por pixel). 25 MP ≈ 100 MB.
    'pixels_maximos' => 25_000_000,

    /*
     * Escopo por cliente (tenant, prestadora, workspace). Site pequeno deixa
     * `resolver` nulo e não pensa nisso. Sistema multi-inquilino ou com
     * acesso por cliente PRECISA ligar:
     *
     * - `resolver`: classe com __invoke(): ?string que devolve o cliente da
     *   requisição (ou chame Escopo::resolverCom(fn () => ...) num provider);
     * - `obrigatorio` => true: sem cliente, nada é gravado e nenhuma linha é
     *   lida (falha fechada, nunca "mostra tudo").
     *
     * O escopo vira coluna da tabela, pasta no disco e filtro de toda consulta.
     * O nome da coluna só vale ANTES da primeira migration.
     */
    'escopo' => [
        'coluna' => 'escopo',
        'resolver' => null,
        'obrigatorio' => false,
    ],

    /*
     * Perfis = para que serve a imagem. Cada envio escolhe um.
     *
     * - formato: webp (site), jpeg (prévia de link: WhatsApp e LinkedIn leem),
     *   png (logo: vai no e-mail, e o Outlook não abre WebP);
     * - lado_maximo: reduz até o lado maior caber, sem aumentar;
     * - largura_maxima: idem, só pela largura;
     * - recorte: [largura, altura] exatos, maior retângulo no centro;
     * - menor_lado_minimo: abaixo disso a imagem é recusada (sai borrada).
     */
    'perfis' => [
        'foto' => ['formato' => 'webp', 'lado_maximo' => 1600, 'menor_lado_minimo' => 200],
        'capa' => ['formato' => 'jpeg', 'recorte' => [1200, 630], 'menor_lado_minimo' => 300],
        'logo' => ['formato' => 'png', 'largura_maxima' => 600, 'menor_lado_minimo' => 64],
    ],

    'qualidade' => ['webp' => 82, 'jpeg' => 85],

    /*
     * Faxina: imagem sem uso registrado há mais que a carência vai para a
     * quarentena (outro disco, fora do ar) e fica listada. Nada é apagado.
     * Não roda sozinha: agende `midia:faxinar --executar` se quiser.
     */
    'faxina' => [
        'carencia_minutos' => 120,
        'disco' => 'local',
        'pasta' => 'midia-quarentena',
    ],

    // Rotas prontas (Midia::rotas() dentro do grupo do painel). Com
    // `permissao`, o controller exige Gate::allows(permissao).
    'rotas' => [
        'permissao' => null,
        'galeria_maximo' => 200,
    ],
];
