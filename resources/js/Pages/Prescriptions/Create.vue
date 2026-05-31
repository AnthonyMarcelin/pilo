<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head, Link } from '@inertiajs/vue3'
</script>

<template>
  <AppLayout>
    <Head title="Ajouter — Pilo" />

    <div class="px-4 pt-6 pb-2">
      <h1 class="text-xl font-semibold text-slate-800">Ajouter</h1>
      <p class="text-sm text-slate-400 mt-0.5">Scanner ou saisir une ordonnance</p>
    </div>

    <div class="px-4 py-4 space-y-3">

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

      <!-- Scanner (Phase 7) -->
      <div class="flex items-start gap-4 bg-white rounded-xl p-4 shadow-sm border border-slate-100 opacity-50 select-none">
        <div class="flex-shrink-0 w-10 h-10 rounded-full bg-slate-100 flex items-center justify-center text-slate-400">
          <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
            <circle cx="12" cy="13" r="4"/>
          </svg>
        </div>
        <div class="flex-1 min-w-0">
          <p class="font-medium text-slate-500">Scanner l'ordonnance</p>
          <p class="text-sm text-slate-400 mt-0.5">Lecture automatique par IA locale — disponible en Phase 7.</p>
        </div>
      </div>

    </div>
  </AppLayout>
</template>
