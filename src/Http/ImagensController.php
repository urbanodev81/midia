<?php

namespace UrbanoDev\Midia\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use UrbanoDev\Midia\Acoes\GuardarImagem;
use UrbanoDev\Midia\Excecoes\ImagemRecusada;
use UrbanoDev\Midia\Models\Midia;
use UrbanoDev\Midia\Models\MidiaUso;

/**
 * As duas rotas que a galeria usa. Registre com `Midia::rotas()` DENTRO do
 * grupo autenticado do painel: o pacote não abre rota sozinho.
 */
class ImagensController extends Controller
{
    /** GET: a galeria (as imagens do escopo, fora da quarentena, mais novas primeiro). */
    public function index(Request $request): JsonResponse
    {
        $this->autorizar();

        $imagens = Midia::query()->daGaleria()
            ->when($request->filled('colecao'), fn ($q) => $q->where('colecao', $request->string('colecao')))
            // `perfil`: o CampoDeImagem de capa só oferece capa (foto de 1600 px não tem o recorte).
            ->when($request->filled('perfil'), fn ($q) => $q->where('perfil', $request->string('perfil')))
            ->with('usos.usuario')
            ->latest('id')
            ->limit((int) config('midia.rotas.galeria_maximo', 200))
            ->get()
            ->map(fn (Midia $m) => self::paraTela($m) + [
                'usos' => $m->usos->map(fn (MidiaUso $u) => $u->rotulo())->unique()->values()->all(),
            ]);

        return response()->json(['imagens' => $imagens]);
    }

    /** POST `arquivo` (+ `perfil`, `colecao`, `descricao`): trata, guarda e devolve. */
    public function store(Request $request, GuardarImagem $guardar): JsonResponse
    {
        $this->autorizar();

        $limite = (int) config('midia.limite_mb', 8);

        $dados = $request->validate([
            'arquivo' => ['required', 'file', 'max:'.($limite * 1024)],
            'perfil' => ['nullable', 'string', Rule::in(array_keys((array) config('midia.perfis', [])))],
            'colecao' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9_.-]+$/'],
            'descricao' => ['nullable', 'string', 'max:300'],
        ], [
            'arquivo.required' => 'Escolha uma imagem.',
            'arquivo.file' => 'A imagem não chegou inteira. Tente de novo.',
            'arquivo.max' => "A imagem passa do limite de {$limite} MB.",
            'perfil.in' => 'Tipo de imagem desconhecido.',
        ]);

        try {
            $midia = $guardar(
                $request->file('arquivo'),
                $dados['perfil'] ?? 'foto',
                $dados['colecao'] ?? null,
                $request->user()?->getAuthIdentifier(),
                $dados['descricao'] ?? null,
            );
        } catch (ImagemRecusada $e) {
            throw ValidationException::withMessages(['arquivo' => $e->getMessage()]);
        }

        return response()->json(self::paraTela($midia) + ['usos' => []], 201);
    }

    /** @return array<string, mixed> */
    public static function paraTela(Midia $m): array
    {
        return [
            'uuid' => $m->uuid,
            'url' => $m->url(),
            'nome' => $m->nome_original,
            'descricao' => $m->descricao,
            'perfil' => $m->perfil,
            'largura' => $m->largura,
            'altura' => $m->altura,
            'bytes' => $m->bytes,
        ];
    }

    private function autorizar(): void
    {
        if ($permissao = config('midia.rotas.permissao')) {
            Gate::authorize($permissao);
        }
    }
}
