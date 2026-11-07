<?php

namespace UrbanoDev\Midia;

use GdImage;
use UrbanoDev\Midia\Excecoes\ImagemRecusada;

/**
 * O coração do pacote: nenhuma imagem é guardada como chegou. O GD abre,
 * endireita, reduz ou recorta e grava um arquivo NOVO. Isso resolve de uma vez:
 *
 * - **Localização e aparelho.** A foto de celular traz o GPS de onde foi tirada
 *   no EXIF. O arquivo regravado não leva metadado nenhum; não há lista de
 *   campos a lembrar.
 * - **Arquivo que finge ser imagem.** O tipo vem do CONTEÚDO (`getimagesize`),
 *   não da extensão. Só JPEG, PNG e WebP. SVG nunca (é XML com script dentro).
 * - **Foto deitada ou espelhada.** A orientação do EXIF (as 8, inclusive as
 *   espelhadas 2, 4, 5 e 7) é aplicada ANTES de o EXIF ser descartado.
 * - **Peso e foto-bomba.** Acima de `pixels_maximos` nem abre; o resto sai no
 *   tamanho do perfil.
 *
 * Junta o que estava em quatro lugares: urbisdev (GuardaImagens, ImagemDoSite),
 * Synapse (ImagemDoSite) e BHD (Redesenho). GD puro, para rodar no cPanel.
 */
final class TratarImagem
{
    private const TIPOS = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    private const MIME = ['webp' => 'image/webp', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];

    private const EXTENSAO = ['webp' => 'webp', 'jpeg' => 'jpg', 'png' => 'png'];

    /**
     * @param  array{formato: string, lado_maximo?: int, largura_maxima?: int, recorte?: array{0: int, 1: int}, menor_lado_minimo?: int}  $perfil
     */
    public function __invoke(string $arquivo, array $perfil): ImagemTratada
    {
        $formato = $perfil['formato'] ?? 'webp';

        if (! isset(self::MIME[$formato])) {
            throw new \InvalidArgumentException("Formato de saída desconhecido: {$formato}.");
        }

        $info = @getimagesize($arquivo);

        if ($info === false || ! in_array($info[2], self::TIPOS, true)) {
            throw new ImagemRecusada('Envie uma imagem JPG, PNG ou WebP.');
        }

        [$largura, $altura] = $info;
        $maximo = (int) config('midia.pixels_maximos', 25_000_000);

        if ($largura * $altura > $maximo) {
            throw new ImagemRecusada('A imagem é grande demais (acima de '.intdiv($maximo, 1_000_000).' megapixels). Reduza e envie de novo.');
        }

        $minimo = (int) ($perfil['menor_lado_minimo'] ?? 1);

        if (min($largura, $altura) < $minimo) {
            throw new ImagemRecusada("A imagem é pequena demais: o menor lado precisa ter pelo menos {$minimo} pixels.");
        }

        $this->garantirMemoria();

        $origem = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($arquivo),
            IMAGETYPE_PNG => @imagecreatefrompng($arquivo),
            // WebP animado não abre no GD: cai na recusa abaixo, de propósito.
            IMAGETYPE_WEBP => @imagecreatefromwebp($arquivo),
        };

        if (! $origem instanceof GdImage) {
            throw new ImagemRecusada('Não foi possível ler essa imagem. Tente outra, ou salve de novo em JPG.');
        }

        if (! imageistruecolor($origem)) {
            imagepalettetotruecolor($origem);
        }

        if ($info[2] === IMAGETYPE_JPEG) {
            $origem = self::endireitar($origem, self::orientacao($arquivo));
        }

        [$nova, $l, $a] = $this->redimensionar($origem, $perfil, $formato);

        ob_start();
        match ($formato) {
            'webp' => imagewebp($nova, null, (int) config('midia.qualidade.webp', 82)),
            'jpeg' => imagejpeg($nova, null, (int) config('midia.qualidade.jpeg', 85)),
            'png' => imagepng($nova, null, 9),
        };
        $bytes = (string) ob_get_clean();

        return new ImagemTratada($bytes, self::MIME[$formato], self::EXTENSAO[$formato], $l, $a);
    }

    /** Orientação EXIF (1 a 8) de um JPEG; 1 quando não há ou não dá para ler. */
    public static function orientacao(string $arquivo): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }

        $exif = @exif_read_data($arquivo);
        $valor = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        return $valor >= 1 && $valor <= 8 ? $valor : 1;
    }

    /**
     * Aplica a orientação. No GD, ângulo positivo gira no sentido anti-horário.
     * 5 = transposta (gira 90° horário e espelha); 7 = transversa (90° anti-horário e espelha).
     */
    public static function endireitar(GdImage $imagem, int $orientacao): GdImage
    {
        $angulo = match ($orientacao) {
            3 => 180,
            5, 6 => -90,
            7, 8 => 90,
            default => 0,
        };

        if ($angulo !== 0) {
            $girada = imagerotate($imagem, $angulo, 0);
            $imagem = $girada instanceof GdImage ? $girada : $imagem;
        }

        match ($orientacao) {
            2, 5, 7 => imageflip($imagem, IMG_FLIP_HORIZONTAL),
            4 => imageflip($imagem, IMG_FLIP_VERTICAL),
            default => null,
        };

        return $imagem;
    }

    /** @return array{0: GdImage, 1: int, 2: int} */
    private function redimensionar(GdImage $origem, array $perfil, string $formato): array
    {
        [$w, $h] = [imagesx($origem), imagesy($origem)];
        [$cx, $cy, $cw, $ch] = [0, 0, $w, $h];

        if (isset($perfil['recorte'])) {
            [$nw, $nh] = array_map('intval', $perfil['recorte']);
            // Maior retângulo com a proporção pedida, no centro da foto.
            $escala = min($w / $nw, $h / $nh);
            [$cw, $ch] = [max(1, (int) round($nw * $escala)), max(1, (int) round($nh * $escala))];
            [$cx, $cy] = [intdiv($w - $cw, 2), intdiv($h - $ch, 2)];
        } else {
            $fator = 1.0;
            if (isset($perfil['lado_maximo'])) {
                $fator = min($fator, $perfil['lado_maximo'] / max($w, $h));
            }
            if (isset($perfil['largura_maxima'])) {
                $fator = min($fator, $perfil['largura_maxima'] / $w);
            }
            [$nw, $nh] = [max(1, (int) round($w * $fator)), max(1, (int) round($h * $fator))];
        }

        $nova = imagecreatetruecolor($nw, $nh);

        if ($formato === 'jpeg') {
            // JPEG não tem transparência: sem fundo branco o PNG transparente vira preto.
            imagefill($nova, 0, 0, (int) imagecolorallocate($nova, 255, 255, 255));
        } else {
            imagealphablending($nova, false);
            imagesavealpha($nova, true);
            imagefill($nova, 0, 0, (int) imagecolorallocatealpha($nova, 0, 0, 0, 127));
        }

        imagecopyresampled($nova, $origem, 0, 0, $cx, $cy, $nw, $nh, $cw, $ch);

        return [$nova, $nw, $nh];
    }

    /** Original + girada + reduzida cabem em 384 MB; o padrão de 128 MB não. Só sobe, nunca desce. */
    private function garantirMemoria(): void
    {
        $atual = ini_get('memory_limit');

        if ($atual === '-1' || $atual === false) {
            return;
        }

        $bytes = (int) $atual * match (strtolower(substr($atual, -1))) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };

        if ($bytes < 384 * 1024 ** 2) {
            @ini_set('memory_limit', '384M');
        }
    }
}
