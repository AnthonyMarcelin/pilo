<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { ref } from 'vue'

const scanInput = ref(null)
const uploading = ref(false)

function openScanner() {
  scanInput.value?.click()
}

async function onImageSelected(e) {
  const file = e.target.files[0]
  if (! file) return

  uploading.value = true

  const form = new FormData()
  form.append('image', file)

  try {
    // Utilise Inertia router.post pour bénéficier du CSRF Inertia automatique
    router.post(route('scans.store'), form, {
      forceFormData: true,
      onError: () => {
        uploading.value = false
        alert('Erreur lors de l\'envoi de l\'image. Vérifiez le format (JPG, PNG).')
      },
    })
  } catch {
    uploading.value = false
  }
}
</script>

<template>
  <AppLayout>
    <Head title="Ajouter — Pilo" />

    <!--
      Input fichier caché — PAS d'attribut capture.
      Sur iOS/Safari, sans capture, l'OS affiche le menu natif :
        • Photothèque
        • Prendre une photo ou vidéo
        • Parcourir (Fichiers / iCloud Drive)
      accept inclut .heic pour les photos iPhone non converties en JPEG.
    -->
    <input
      ref="scanInput"
      type="file"
      accept="image/*,.heic,.heif"
      class="hidden"
      @change="onImageSelected"
    />

    <div class="px-4 pt-6 pb-2">
      <h1 class="text-xl font-semibold text-slate-800">Ajouter</h1>
      <p class="text-sm text-slate-400 mt-0.5">Scanner ou saisir une ordonnance</p>
    </div>

    <div class="px-4 py-4 space-y-3">

      <!-- Scanner l'ordonnance -->
      <button
        type="button"
        @click="openScanner"
        :disabled="uploading"
        class="w-full flex items-start gap-4 bg-white rounded-xl p-4 shadow-sm border border-slate-100 hover:border-slate-300 transition-colors active:bg-slate-50 disabled:opacity-60 disabled:cursor-not-allowed text-left"
      >
        <div class="flex-shrink-0 w-10 h-10 rounded-full bg-slate-800 flex items-center justify-center text-white">
          <svg v-if="!uploading" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
            <circle cx="12" cy="13" r="4"/>
          </svg>
          <svg v-else class="w-5 h-5 animate-spin" viewBox="0 0 24 24" fill="none">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"/>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
          </svg>
        </div>
        <div class="flex-1 min-w-0">
          <p class="font-medium text-slate-800">
            {{ uploading ? 'Envoi…' : 'Scanner l\'ordonnance' }}
          </p>
          <p class="text-sm text-slate-400 mt-0.5">
            Lecture automatique par IA locale. Vous vérifierez avant d'enregistrer.
          </p>
        </div>
        <svg class="flex-shrink-0 w-5 h-5 text-slate-300 self-center" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <polyline points="9 18 15 12 9 6"/>
        </svg>
      </button>

      <!-- Saisir manuellement -->
      <Link
        :href="route('prescriptions.create.manual')"
        class="flex items-start gap-4 bg-white rounded-xl p-4 shadow-sm border border-slate-100 hover:border-slate-300 transition-colors active:bg-slate-50"
      >
        <div class="flex-shrink-0 w-10 h-10 rounded-full bg-slate-100 flex items-center justify-center text-slate-600">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
          </svg>
        </div>
        <div class="flex-1 min-w-0">
          <p class="font-medium text-slate-800">Saisir manuellement</p>
          <p class="text-sm text-slate-400 mt-0.5">Remplissez le formulaire vous-même. Vous pouvez joindre une photo de l'ordonnance.</p>
        </div>
        <svg class="flex-shrink-0 w-5 h-5 text-slate-300 self-center" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <polyline points="9 18 15 12 9 6"/>
        </svg>
      </Link>

    </div>
  </AppLayout>
</template>
