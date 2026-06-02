<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head, router } from '@inertiajs/vue3'
import { onMounted, onUnmounted, ref } from 'vue'
import axios from 'axios'

const props = defineProps({
  scanId:  { type: String, required: true },
  message: { type: String, default: 'Lecture de l\'ordonnance en cours…' },
})

const elapsed       = ref(0)
const errorMsg      = ref(null)
const isCreditError = ref(false)
let   timer     = null
let   pollTimer = null

onMounted(() => {
  timer     = setInterval(() => elapsed.value++, 1000)
  pollTimer = setInterval(poll, 3000)
})

onUnmounted(() => {
  clearInterval(timer)
  clearInterval(pollTimer)
})

async function poll() {
  try {
    const { data } = await axios.get(route('scans.status', props.scanId))

    if (data.status === 'done') {
      clearInterval(timer)
      clearInterval(pollTimer)
      router.visit(route('scans.form', props.scanId))
    } else if (data.status === 'failed') {
      clearInterval(timer)
      clearInterval(pollTimer)
      const msg = data.error_message || 'Lecture impossible.'
      isCreditError.value = msg.toLowerCase().includes('crédits mistral')
      errorMsg.value = msg
    }
  } catch {
    // Erreur réseau temporaire — on continue de poller
  }
}

function goManual() {
  router.visit(route('prescriptions.create.manual'))
}

function formatTime(s) {
  const m = Math.floor(s / 60)
  const sec = s % 60
  return m > 0 ? `${m} min ${sec} s` : `${sec} s`
}
</script>

<template>
  <AppLayout>
    <Head title="Lecture en cours — Pilo" />

    <div class="flex flex-col items-center justify-center min-h-[60dvh] px-6 py-12 text-center">

      <!-- Icone + spinner -->
      <div class="relative mb-6">
        <div v-if="!errorMsg" class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center">
          <svg class="w-8 h-8 text-slate-500 animate-pulse" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
            <circle cx="12" cy="13" r="4"/>
          </svg>
        </div>
        <div v-else class="w-16 h-16 rounded-full bg-amber-50 flex items-center justify-center">
          <svg class="w-8 h-8 text-amber-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="8" x2="12" y2="12"/>
            <line x1="12" y1="16" x2="12.01" y2="16"/>
          </svg>
        </div>
      </div>

      <!-- Titre -->
      <h1 class="text-lg font-semibold text-slate-800 mb-2">
        <span v-if="!errorMsg">Lecture de l'ordonnance…</span>
        <span v-else>Lecture impossible</span>
      </h1>

      <!-- Sous-titre -->
      <p v-if="!errorMsg" class="text-sm text-slate-400 max-w-xs">
        L'image est analysée par Mistral (service externe).
        Comptez <strong class="text-slate-500">moins de 30 secondes</strong>.
        Laissez la page ouverte — elle se met à jour automatiquement.
      </p>
      <!-- Erreur crédits Mistral épuisés -->
      <div v-else-if="isCreditError" class="max-w-xs space-y-2">
        <p class="text-sm font-medium text-red-700">
          Crédits Mistral insuffisants
        </p>
        <p class="text-xs text-red-600 leading-relaxed">
          Le quota de l'API OCR est épuisé. Rechargez votre compte sur
          <span class="font-medium">console.mistral.ai</span>,
          puis relancez le scan.
        </p>
        <p class="text-xs text-slate-500 italic">
          En attendant, utilisez la saisie manuelle ci-dessous.
        </p>
      </div>
      <!-- Erreur générique -->
      <p v-else class="text-sm text-amber-700 max-w-xs">
        {{ errorMsg }} Vous pouvez saisir l'ordonnance manuellement.
      </p>

      <!-- Chrono -->
      <p v-if="!errorMsg" class="mt-4 text-xs text-slate-300 tabular-nums">
        {{ formatTime(elapsed) }}
      </p>

      <!-- Barre de progression indéterminée -->
      <div v-if="!errorMsg" class="mt-6 w-48 h-1.5 bg-slate-100 rounded-full overflow-hidden">
        <div class="h-full bg-slate-400 rounded-full animate-scan-progress" />
      </div>

      <!-- Action de fallback -->
      <button
        @click="goManual"
        class="mt-8 text-sm text-slate-400 hover:text-slate-600 underline underline-offset-2 transition-colors"
      >
        {{ errorMsg ? 'Saisir manuellement' : 'Annuler et saisir manuellement' }}
      </button>

    </div>
  </AppLayout>
</template>

<style scoped>
@keyframes scan-progress {
  0%   { transform: translateX(-100%); }
  100% { transform: translateX(200%); }
}
.animate-scan-progress {
  width: 50%;
  animation: scan-progress 1.8s ease-in-out infinite;
}
</style>
