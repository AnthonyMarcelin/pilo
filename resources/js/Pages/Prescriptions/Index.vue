<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head } from '@inertiajs/vue3'
</script>
<template>
  <AppLayout>
    <Head title="Ordonnances — Pilo" />
    <div class="px-4 pt-6 pb-2">
      <h1 class="text-xl font-semibold text-slate-800">Ordonnances</h1>
      <p class="text-sm text-slate-400 mt-0.5">Actives · terminées · archivées</p>
    </div>
    <div class="px-4 py-4 text-center py-12">
      <p class="text-sm text-slate-400 italic">Aucune ordonnance pour l'instant.</p>
      <p class="text-xs text-slate-300 mt-1">Ajoutez-en une via le bouton +</p>
    </div>
  </AppLayout>
</template>
