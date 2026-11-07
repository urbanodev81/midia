<script setup>
/*
 * Galeria do pacote urbanodev/midia (veio do editor do blog do urbisdev, 05/11/2026).
 *
 * Abre num <dialog> nativo com as imagens do escopo (GET urlLista) e o
 * "Subir nova" no topo (POST urlEnvio). Escolhida a imagem, pede a descrição
 * (vem a última usada) quando `pedirDescricao`. Não depende de nada do
 * sistema: nem Modal, nem axios, nem Ziggy. Cores pelas variáveis --midia-*
 * (ver README), com as do sistema como reserva.
 *
 * Emite `escolher` com { uuid, url, descricao, largura, altura, nome }.
 */
import { computed, nextTick, ref, watch } from 'vue';

const props = defineProps({
    aberto: { type: Boolean, default: false },
    urlLista: { type: String, required: true },
    urlEnvio: { type: String, required: true },
    perfil: { type: String, default: 'foto' },
    colecao: { type: String, default: null },
    limiteMb: { type: Number, default: 8 },
    pedirDescricao: { type: Boolean, default: true },
    titulo: { type: String, default: 'Imagens' },
});
const emit = defineEmits(['fechar', 'escolher']);

const dialogo = ref(null);
const imagens = ref(null);
const erroLista = ref('');
const busca = ref('');
const enviando = ref(false);
const erroEnvio = ref('');
const escolhida = ref(null);
const descricao = ref('');
const erroDescricao = ref('');
const arquivoRef = ref(null);
const descricaoRef = ref(null);

function cabecalhos() {
    const h = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    const xsrf = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));
    if (xsrf) h['X-XSRF-TOKEN'] = decodeURIComponent(xsrf.split('=')[1]);
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (!xsrf && meta) h['X-CSRF-TOKEN'] = meta.content;
    return h;
}

function comColecao(url) {
    if (!props.colecao) return url;
    return url + (url.includes('?') ? '&' : '?') + 'colecao=' + encodeURIComponent(props.colecao);
}

async function carregar() {
    erroLista.value = '';
    imagens.value = null;
    try {
        const r = await fetch(comColecao(props.urlLista), { headers: cabecalhos(), credentials: 'same-origin' });
        if (!r.ok) throw new Error(String(r.status));
        imagens.value = (await r.json()).imagens;
    } catch {
        erroLista.value = 'Não deu para carregar as imagens. Feche e abra de novo.';
        imagens.value = [];
    }
}

watch(() => props.aberto, (aberto) => {
    if (aberto) {
        escolhida.value = null;
        busca.value = '';
        erroEnvio.value = '';
        dialogo.value?.showModal();
        carregar();
    } else {
        dialogo.value?.close();
    }
}, { flush: 'post' });

const filtradas = computed(() => {
    const termo = busca.value.trim().toLowerCase();
    if (!imagens.value || termo === '') return imagens.value ?? [];
    return imagens.value.filter((i) => [i.nome, i.descricao, ...(i.usos ?? [])].some((t) => (t ?? '').toLowerCase().includes(termo)));
});

function escolher(imagem) {
    if (!props.pedirDescricao) {
        emit('escolher', { ...imagem });
        return;
    }
    escolhida.value = imagem;
    descricao.value = imagem.descricao ?? '';
    erroDescricao.value = '';
    nextTick(() => descricaoRef.value?.focus());
}

async function enviar(evento) {
    const arquivo = evento.target.files?.[0];
    evento.target.value = '';
    if (!arquivo) return;
    erroEnvio.value = '';
    if (arquivo.size > props.limiteMb * 1024 * 1024) {
        erroEnvio.value = `A imagem passa do limite de ${props.limiteMb} MB.`;
        return;
    }
    enviando.value = true;
    try {
        const dados = new FormData();
        dados.append('arquivo', arquivo);
        dados.append('perfil', props.perfil);
        if (props.colecao) dados.append('colecao', props.colecao);
        const r = await fetch(props.urlEnvio, { method: 'POST', body: dados, headers: cabecalhos(), credentials: 'same-origin' });
        if (r.status === 413) throw new Error(`A imagem passa do limite de ${props.limiteMb} MB.`);
        const corpo = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(corpo.errors?.arquivo?.[0] ?? corpo.message ?? 'A imagem não subiu. Tente de novo.');
        imagens.value = [corpo, ...(imagens.value ?? []).filter((i) => i.uuid !== corpo.uuid)];
        escolher(corpo);
    } catch (e) {
        erroEnvio.value = e.message && !/^\d+$/.test(e.message) ? e.message : 'A imagem não subiu. Tente de novo.';
    } finally {
        enviando.value = false;
    }
}

function confirmar() {
    if (descricao.value.trim() === '') {
        erroDescricao.value = 'Descreva a imagem: é o que o leitor de tela lê para quem não enxerga.';
        descricaoRef.value?.focus();
        return;
    }
    emit('escolher', { ...escolhida.value, descricao: descricao.value.trim() });
}
</script>

<template>
    <dialog ref="dialogo" class="midia-galeria" :aria-label="titulo" @close="emit('fechar')" @cancel.prevent="emit('fechar')">
        <header class="mg-topo">
            <h2>{{ escolhida ? 'Descreva a imagem' : titulo }}</h2>
            <button type="button" class="mg-fechar" aria-label="Fechar" @click="emit('fechar')">
                <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" /></svg>
            </button>
        </header>

        <form v-if="escolhida" class="mg-passo2" @submit.prevent="confirmar">
            <img :src="escolhida.url" alt="" class="mg-previa" />
            <div>
                <label for="mg-descricao" class="mg-rotulo">Descrição da imagem <span aria-hidden="true">*</span></label>
                <input id="mg-descricao" ref="descricaoRef" v-model="descricao" type="text" maxlength="300" placeholder="O que a imagem mostra"
                       class="mg-campo" :aria-invalid="erroDescricao ? 'true' : 'false'" aria-describedby="mg-descricao-ajuda" />
                <p id="mg-descricao-ajuda" class="mg-ajuda">É o que o leitor de tela lê. {{ escolhida.descricao ? 'Veio da última vez que ela foi usada.' : '' }}</p>
                <p v-if="erroDescricao" class="mg-erro" role="alert">{{ erroDescricao }}</p>
                <div class="mg-acoes">
                    <button type="submit" class="mg-botao">Usar esta imagem</button>
                    <button type="button" class="mg-link" @click="escolhida = null">Escolher outra</button>
                </div>
            </div>
        </form>

        <div v-else class="mg-passo1">
            <div class="mg-barra">
                <button type="button" class="mg-botao" :disabled="enviando" @click="arquivoRef?.click()">{{ enviando ? 'Enviando…' : 'Subir nova' }}</button>
                <input ref="arquivoRef" type="file" accept="image/jpeg,image/png,image/webp" class="mg-oculto" tabindex="-1" aria-hidden="true" @change="enviar" />
                <span class="mg-ajuda">JPG, PNG ou WebP até {{ limiteMb }} MB. O site reduz e tira a localização da foto.</span>
                <label class="mg-busca">
                    <span class="mg-oculto">Buscar imagem</span>
                    <input v-model="busca" type="search" placeholder="Buscar por nome ou descrição" class="mg-campo" />
                </label>
                <p v-if="erroEnvio" class="mg-erro" role="alert">{{ erroEnvio }}</p>
            </div>
            <div class="mg-grade-rolagem">
                <p v-if="imagens === null" class="mg-ajuda" role="status">Carregando as imagens…</p>
                <p v-else-if="erroLista" class="mg-erro" role="alert">{{ erroLista }}</p>
                <p v-else-if="filtradas.length === 0" class="mg-ajuda">{{ busca ? 'Nenhuma imagem com esse nome.' : 'Nenhuma imagem ainda. Suba a primeira.' }}</p>
                <ul v-else class="mg-grade" aria-label="Imagens">
                    <li v-for="img in filtradas" :key="img.uuid">
                        <button type="button" class="mg-cartao" @click="escolher(img)">
                            <img :src="img.url" alt="" loading="lazy" />
                            <span class="mg-legenda">
                                <strong>{{ img.descricao || img.nome || 'Sem nome' }}</strong>
                                <span>{{ img.usos?.length ? 'Usada em ' + img.usos.join(', ') : 'Ainda não usada' }}</span>
                            </span>
                        </button>
                    </li>
                </ul>
            </div>
        </div>
    </dialog>
</template>

<style scoped>
.midia-galeria {
    --mg-fundo: var(--midia-fundo, rgb(var(--surface, 255 255 255)));
    --mg-texto: var(--midia-texto, rgb(var(--fg, 31 41 55)));
    --mg-suave: var(--midia-suave, rgb(var(--fg-muted, 107 114 128)));
    --mg-linha: var(--midia-linha, rgb(var(--border, 229 231 235)));
    --mg-destaque: var(--midia-destaque, rgb(var(--accent, 37 99 235)));
    --mg-sobre-destaque: var(--midia-sobre-destaque, rgb(var(--fg-on-accent, 255 255 255)));
    --mg-erro: var(--midia-erro, rgb(var(--danger, 185 28 28)));
    width: min(64rem, calc(100vw - 2rem));
    max-height: 85vh;
    padding: 0;
    border: 1px solid var(--mg-linha);
    border-radius: 0.5rem;
    background: var(--mg-fundo);
    color: var(--mg-texto);
}
.midia-galeria[open] { display: flex; flex-direction: column; }
.midia-galeria::backdrop { background: rgb(0 0 0 / 0.5); }
.mg-topo { display: flex; align-items: center; gap: 0.75rem; padding: 1rem 1.25rem; border-bottom: 1px solid var(--mg-linha); }
.mg-topo h2 { margin: 0; font-size: 1.125rem; font-weight: 700; }
.mg-fechar { margin-left: auto; padding: 0.5rem; border: 0; border-radius: 0.375rem; background: transparent; color: var(--mg-suave); cursor: pointer; }
.mg-passo1 { display: flex; flex-direction: column; min-height: 0; }
.mg-passo2 { display: grid; gap: 1.25rem; padding: 1.25rem; overflow-y: auto; }
@media (min-width: 768px) { .mg-passo2 { grid-template-columns: 1fr 1.1fr; } }
.mg-previa { width: 100%; max-height: 50vh; object-fit: contain; border: 1px solid var(--mg-linha); border-radius: 0.375rem; }
.mg-barra { display: flex; flex-wrap: wrap; align-items: center; gap: 0.75rem; padding: 0.75rem 1.25rem; border-bottom: 1px solid var(--mg-linha); font-size: 0.875rem; }
.mg-busca { margin-left: auto; }
.mg-grade-rolagem { overflow-y: auto; padding: 1.25rem; }
.mg-grade { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.75rem; margin: 0; padding: 0; list-style: none; }
@media (min-width: 640px) { .mg-grade { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@media (min-width: 1024px) { .mg-grade { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
.mg-cartao { display: block; width: 100%; padding: 0; overflow: hidden; border: 1px solid var(--mg-linha); border-radius: 0.375rem; background: var(--mg-fundo); color: inherit; text-align: left; cursor: pointer; }
.mg-cartao:hover { border-color: var(--mg-suave); }
.mg-cartao img { display: block; width: 100%; aspect-ratio: 4 / 3; object-fit: cover; }
.mg-legenda { display: block; padding: 0.375rem 0.5rem; font-size: 0.75rem; }
.mg-legenda strong, .mg-legenda span { display: block; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
.mg-legenda span { color: var(--mg-suave); }
.mg-rotulo { display: block; font-weight: 600; font-size: 0.875rem; }
.mg-campo { width: 100%; min-height: 40px; margin-top: 0.25rem; padding: 0 0.625rem; border: 1px solid var(--mg-linha); border-radius: 0.375rem; background: var(--mg-fundo); color: var(--mg-texto); font-size: 0.875rem; }
.mg-busca .mg-campo { width: 15rem; margin-top: 0; }
.mg-ajuda { margin: 0.25rem 0 0; color: var(--mg-suave); font-size: 0.875rem; }
.mg-erro { width: 100%; margin: 0.25rem 0 0; color: var(--mg-erro); font-size: 0.875rem; }
.mg-acoes { display: flex; flex-wrap: wrap; gap: 0.75rem; padding-top: 1rem; }
.mg-botao { display: inline-flex; align-items: center; min-height: 40px; padding: 0 1rem; border: 0; border-radius: 0.375rem; background: var(--mg-destaque); color: var(--mg-sobre-destaque); font-weight: 600; cursor: pointer; }
.mg-botao:disabled { opacity: 0.6; cursor: default; }
.mg-link { min-height: 40px; padding: 0 0.5rem; border: 0; background: transparent; color: var(--mg-suave); text-decoration: underline; cursor: pointer; }
.mg-botao:focus-visible, .mg-link:focus-visible, .mg-cartao:focus-visible, .mg-fechar:focus-visible, .mg-campo:focus-visible { outline: 2px solid var(--mg-destaque); outline-offset: 2px; }
.mg-oculto { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
</style>
