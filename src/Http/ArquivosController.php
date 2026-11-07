<?php

namespace UrbanoDev\Midia\Http;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use UrbanoDev\Midia\Midia as Pacote;
use UrbanoDev\Midia\Models\Midia;

/**
 * O único caminho de saída do arquivo privado. Registre com `Midia::rotas()`
 * DENTRO do grupo autenticado. Na ordem:
 *
 * 1. uuid fora do formato, de outro cliente (escopo) ou na quarentena: 404;
 * 2. `midia.arquivos.permissao` (ou `midia.rotas.permissao`) no Gate: 403;
 * 3. o `autorizar` do sistema (ex.: "este anexo é de um chamado que ele vê"): 403.
 *
 * `?ver=1` abre na aba (só imagem e PDF); o padrão é baixar.
 */
class ArquivosController extends Controller
{
    public function baixar(Request $request, string $uuid): Response
    {
        // No Postgres a coluna é uuid: texto fora do formato estouraria a consulta.
        $midia = Str::isUuid($uuid)
            ? Midia::query()->ativas()->where('uuid', strtolower($uuid))->first()
            : null;

        abort_if($midia === null, 404);

        if ($permissao = config('midia.arquivos.permissao') ?? config('midia.rotas.permissao')) {
            Gate::authorize($permissao);
        }

        abort_unless(Pacote::podeBaixar($midia, $request->user()), 403);

        return $midia->resposta($request->boolean('ver'));
    }
}
