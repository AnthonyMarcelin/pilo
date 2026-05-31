<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head } from '@inertiajs/vue3'

defineProps({
    todayLabel: { type: String, default: '' },
})
</script>

<template>
  <AppLayout>
    <Head title="Aujourd'hui — Pilo" />

    <div class="px-4 pt-6 pb-2">
      <h1 class="text-xl font-semibold text-slate-800 capitalize">Aujourd'hui</h1>
      <p v-if="todayLabel" class="text-sm text-slate-400 mt-0.5 capitalize">{{ todayLabel }}</p>
    </div>

    <div class="px-4 py-2 space-y-3 pb-6">

      <!-- Matin -->
      <section class="rounded-2xl border overflow-hidden"
               style="background-color: #fffbeb; border-color: #fde68a;">
        <div class="flex items-center gap-2 px-4 pt-4 pb-3">
          <svg class="h-4 w-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round">
            <circle cx="12" cy="12" r="4"/>
            <line x1="12" y1="2" x2="12" y2="4"/><line x1="12" y1="20" x2="12" y2="22"/>
            <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>
          </svg>
          <h2 class="text-xs font-semibold tracking-widest uppercase" style="color: #92400e;">Matin</h2>
        </div>
        <div class="px-4 pb-4">
          <p class="text-sm text-slate-400 italic">Aucun médicament — ajoutez une ordonnance</p>
        </div>
      </section>

      <!-- Midi -->
      <section class="rounded-2xl border overflow-hidden"
               style="background-color: #f0fdf4; border-color: #bbf7d0;">
        <div class="flex items-center gap-2 px-4 pt-4 pb-3">
          <svg class="h-4 w-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" stroke-linecap="round">
            <circle cx="12" cy="12" r="5"/>
            <line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/>
          </svg>
          <h2 class="text-xs font-semibold tracking-widest uppercase" style="color: #14532d;">Midi</h2>
        </div>
        <div class="px-4 pb-4">
          <p class="text-sm text-slate-400 italic">Aucun médicament — ajoutez une ordonnance</p>
        </div>
      </section>

      <!-- Soir -->
      <section class="rounded-2xl border overflow-hidden"
               style="background-color: #eef2ff; border-color: #c7d2fe;">
        <div class="flex items-center gap-2 px-4 pt-4 pb-3">
          <svg class="h-4 w-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="#4f46e5" stroke-width="2" stroke-linecap="round">
            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
          </svg>
          <h2 class="text-xs font-semibold tracking-widest uppercase" style="color: #312e81;">Soir</h2>
        </div>
        <div class="px-4 pb-4">
          <p class="text-sm text-slate-400 italic">Aucun médicament — ajoutez une ordonnance</p>
        </div>
      </section>

      <!-- Coucher -->
      <section class="rounded-2xl border overflow-hidden"
               style="background-color: #f5f3ff; border-color: #ddd6fe;">
        <div class="flex items-center gap-2 px-4 pt-4 pb-3">
          <svg class="h-4 w-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2" stroke-linecap="round">
            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
            <circle cx="9" cy="7" r="4"/>
          </svg>
          <h2 class="text-xs font-semibold tracking-widest uppercase" style="color: #4c1d95;">Coucher</h2>
        </div>
        <div class="px-4 pb-4">
          <p class="text-sm text-slate-400 italic">Aucun médicament — ajoutez une ordonnance</p>
        </div>
      </section>

    </div>
  </AppLayout>
</template>
