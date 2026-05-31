<script setup>
import PiloLogo from '@/Components/PiloLogo.vue'
</script>

<template>
  <div class="min-h-dvh bg-slate-50 flex flex-col items-center justify-center px-4 py-12">

    <!-- Logo + nom -->
    <div class="flex items-center gap-3 mb-8">
      <PiloLogo class="h-8 w-auto" />
      <span class="text-2xl font-semibold tracking-tight text-slate-800">Pilo</span>
    </div>

    <!-- Carte -->
    <div class="w-full max-w-sm bg-white rounded-2xl shadow-sm border border-slate-100 px-6 py-8">
      <slot />
    </div>

    <!-- Avertissement médical -->
    <p class="mt-8 text-xs text-slate-400 text-center max-w-xs leading-relaxed">
      Aide-mémoire uniquement — pas un avis médical.<br>
      Toujours vérifier avec l'ordonnance d'origine.
    </p>

  </div>
</template>
