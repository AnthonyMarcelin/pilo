<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head } from '@inertiajs/vue3'
</script>

<template>
  <AppLayout>
    <Head title="Mes médicaments — Pilo" />

    <div class="px-4 pt-6 pb-2">
      <h1 class="text-xl font-semibold text-slate-800">Mes médicaments</h1>
      <p class="text-sm text-slate-400 mt-0.5">Actifs · terminés</p>
    </div>

    <div class="px-4 py-4 text-center">
      <p class="text-sm text-slate-400 italic py-12">
        La liste de vos médicaments apparaîtra ici.<br>
        <span class="text-slate-300">Ajoutez une ordonnance via le bouton +</span>
      </p>
    </div>
  </AppLayout>
</template>
