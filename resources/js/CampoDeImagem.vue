<script setup>
/*
 * Campo de formulário para UMA imagem (capa, logo, foto da apresentação).
 * Mostra a prévia, abre a GaleriaDeImagens e devolve pelo v-model um objeto
 * { uuid, url, descricao } (ou null). O formulário manda o `uuid` ao servidor,
 * que chama $model->sincronizarMidias('<campo>', [$uuid]).
 *
 *   <CampoDeImagem v-model="form.capa" rotulo="Capa" perfil="capa"
 *       :url-lista="route('painel.midia.imagens')" :url-envio="route('painel.midia.enviar')" />
 */
import { ref } from 'vue';
import GaleriaDeImagens from './GaleriaDeImagens.vue';

const modelo = defineModel({ type: Object, default: null });
const props = defineProps({
    rotulo: { type: String, required: true },
    urlLista: { type: String, required: true },
    urlEnvio: { type: String, required: true },
    perfil: { type: String, default: 'foto' },
    colecao: { type: String, default: null },
    limiteMb: { type: Number, default: 8 },
    ajuda: { type: String, default: '' },
    erro: { type: String, default: '' },
    obrigatorio: { type: Boolean, default: false },
});

const aberto = ref(false);
const id = 'campo-imagem-' + Math.random().toString(36).slice(2, 9);

function escolher(imagem) {
    modelo.value = { uuid: imagem.uuid, url: imagem.url, descricao: imagem.descricao ?? '' };
    aberto.value = false;
}
</script>

<template>
    <div class="midia-campo" role="group" :aria-labelledby="id + '-rotulo'" :aria-describedby="ajuda || erro ? id + '-ajuda' : undefined">
        <span :id="id + '-rotulo'" class="mc-rotulo">{{ rotulo }}<span v-if="obrigatorio" aria-hidden="true"> *</span></span>
        <div class="mc-corpo">
            <img v-if="modelo?.url" :src="modelo.url" :alt="modelo.descricao || ''" class="mc-previa" />
            <span v-else class="mc-vazio">Nenhuma imagem</span>
            <div class="mc-acoes">
                <button type="button" class="mc-botao" @click="aberto = true">{{ modelo ? 'Trocar' : 'Escolher imagem' }}</button>
                <button v-if="modelo && !obrigatorio" type="button" class="mc-link" @click="modelo = null">Remover</button>
                <span v-if="modelo?.descricao" class="mc-desc">“{{ modelo.descricao }}”</span>
            </div>
        </div>
        <p v-if="ajuda || erro" :id="id + '-ajuda'" :class="erro ? 'mc-erro' : 'mc-ajuda'" :role="erro ? 'alert' : undefined">{{ erro || ajuda }}</p>
        <GaleriaDeImagens :aberto="aberto" :url-lista="urlLista" :url-envio="urlEnvio" :perfil="perfil" :colecao="colecao"
                          :limite-mb="limiteMb" :titulo="rotulo" @fechar="aberto = false" @escolher="escolher" />
    </div>
</template>

<style scoped>
.midia-campo {
    --mc-texto: var(--midia-texto, rgb(var(--fg, 31 41 55)));
    --mc-suave: var(--midia-suave, rgb(var(--fg-muted, 107 114 128)));
    --mc-linha: var(--midia-linha, rgb(var(--border, 229 231 235)));
    --mc-destaque: var(--midia-destaque, rgb(var(--accent, 37 99 235)));
    --mc-erro: var(--midia-erro, rgb(var(--danger, 185 28 28)));
    color: var(--mc-texto);
    font-size: 0.875rem;
}
.mc-rotulo { display: block; font-weight: 600; }
.mc-corpo { display: flex; flex-wrap: wrap; align-items: center; gap: 0.75rem; margin-top: 0.375rem; }
.mc-previa, .mc-vazio { width: 10rem; aspect-ratio: 4 / 3; border: 1px solid var(--mc-linha); border-radius: 0.375rem; object-fit: cover; }
.mc-vazio { display: grid; place-items: center; color: var(--mc-suave); border-style: dashed; }
.mc-acoes { display: flex; flex-direction: column; align-items: flex-start; gap: 0.25rem; }
.mc-botao { min-height: 40px; padding: 0 1rem; border: 1px solid var(--mc-destaque); border-radius: 0.375rem; background: transparent; color: var(--mc-destaque); font-weight: 600; cursor: pointer; }
.mc-link { min-height: 32px; padding: 0; border: 0; background: transparent; color: var(--mc-suave); text-decoration: underline; cursor: pointer; }
.mc-desc { max-width: 16rem; color: var(--mc-suave); }
.mc-botao:focus-visible, .mc-link:focus-visible { outline: 2px solid var(--mc-destaque); outline-offset: 2px; }
.mc-ajuda { margin: 0.25rem 0 0; color: var(--mc-suave); }
.mc-erro { margin: 0.25rem 0 0; color: var(--mc-erro); }
</style>
