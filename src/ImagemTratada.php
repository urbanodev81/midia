<?php

namespace UrbanoDev\Midia;

/** O arquivo regravado, ainda em memória. */
final readonly class ImagemTratada
{
    public function __construct(
        public string $bytes,
        public string $mime,
        public string $extensao,
        public int $largura,
        public int $altura,
    ) {}
}
