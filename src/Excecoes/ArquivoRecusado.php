<?php

namespace UrbanoDev\Midia\Excecoes;

use RuntimeException;

/**
 * Envio recusado. A mensagem já está pronta para a tela, em português e sem
 * detalhe técnico. `ImagemRecusada` é um caso dela: pegar esta pega as duas.
 */
class ArquivoRecusado extends RuntimeException {}
