<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

const props = defineProps({
  prescriptions: { type: Array, default: () => [] },
})

const page = usePage()
const flash = computed(() => page.props.flash ?? {})

const activeTab = ref('active')

const tabs = [
  { key: 'active',     label: 'Actives' },
  { key: 'terminated', label: 'Terminées' },
  { key: 'archived',   label: 'Archivées' },
]

const grouped = computed(() => ({
  active:     props.prescriptions.filter(p => p.status === 'active'),
  terminated: props.prescriptions.filter(p => p.status === 'terminated'),
  archived:   props.prescriptions.filter(p => p.status === 'archived'),
}))

const current = computed(() => grouped.value[activeTab.value] ?? [])

function tabCount(key) {
  return grouped.value[key]?.length ?? 0
}

function formatDate(dateStr) {
  if (!dateStr) return null
  const [y, m, d] = dateStr.split('-')
  return `${d}/${m}/${y}`
}

function sourceLabel(sourceType) {
  return sourceType === 'scan' ? 'scan' : 'manuel'
}
</script>

<template>
  <AppLayout>
    <Head title="Ordonnances — Pilo" />

    <!-- En-tête -->
    <div class="px-4 pt-6 pb-2">
      <h1 class="text-xl font-semibold text-slate-800">Ordonnances</h1>
    </div>

    <!-- Flash : succès -->
    <div
      v-if="flash.success"
      class="mx-4 mb-2 rounded-xl px-4 py-3 text-sm"
      style="background-color:#f0fdf4; border:1px solid #bbf7d0; color:#15803d;"
    >
      {{ flash.success }}
    </div>

    <!-- Flash : doublons détectés (signalement doux) -->
    <div
      v-if="flash.duplicate_warnings?.length"
      class="mx-4 mb-2 rounded-xl px-4 py-3 space-y-1"
      style="background-color:#fff7ed; border:1px solid #fed7aa;"
    >
      <p class="text-sm font-medium" style="color:#9a3412;">Doublon détecté</p>
      <p
        v-for="(w, i) in flash.duplicate_warnings"
        :key="i"
        class="text-sm"
        style="color:#9a3412;"
      >{{ w }}</p>
      <p class="text-xs mt-1" style="color:#c2410c;">
        Un médicament peut figurer légitimement sur deux ordonnances. C'est vous qui décidez.
      </p>
    </div>

    <!-- Onglets -->
    <div class="flex border-b border-slate-100 mx-4 mb-1">
      <button
        v-for="tab in tabs"
        :key="tab.key"
        @click="activeTab = tab.key"
        class="flex-1 py-2.5 text-xs font-medium transition-colors"
        :class="activeTab === tab.key
          ? 'border-b-2 border-slate-800 text-slate-800'
          : 'text-slate-400 hover:text-slate-600'"
      >
        {{ tab.label }}
        <span
          v-if="tabCount(tab.key) > 0"
          class="ml-1 rounded-full px-1.5 py-0.5 text-xs"
          :class="activeTab === tab.key ? 'bg-slate-800 text-white' : 'bg-slate-100 text-slate-500'"
        >{{ tabCount(tab.key) }}</span>
      </button>
    </div>

    <!-- Liste -->
    <div class="px-4 pt-2 pb-24 space-y-3">

      <!-- Vide -->
      <div v-if="current.length === 0" class="py-12 text-center">
        <p class="text-sm text-slate-400 italic">
          <template v-if="activeTab === 'active'">Aucune ordonnance active.</template>
          <template v-else-if="activeTab === 'terminated'">Aucune ordonnance terminée.</template>
          <template v-else>Aucune ordonnance archivée.</template>
        </p>
        <Link
          v-if="activeTab === 'active'"
          :href="route('prescriptions.create')"
          class="mt-3 inline-block text-sm text-slate-500 underline"
        >Ajouter une ordonnance</Link>
      </div>

      <!-- Cartes -->
      <Link
        v-for="p in current"
        :key="p.id"
        :href="route('prescriptions.show', p.id)"
        class="block bg-white rounded-xl p-4 shadow-sm hover:shadow-md transition-shadow"
      >
        <div class="flex items-start justify-between gap-2">
          <div class="min-w-0 flex-1">
            <p class="font-medium text-slate-800 truncate">
              {{ p.prescriber_name ?? 'Prescripteur inconnu' }}
            </p>
            <p class="text-xs text-slate-400 mt-0.5">
              {{ formatDate(p.prescribed_at) ?? 'Date inconnue' }}
              <span class="mx-1 text-slate-200">·</span>
              <span>{{ sourceLabel(p.source_type) }}</span>
            </p>
          </div>

          <!-- Badges status (onglet terminées/archivées) -->
          <span
            v-if="p.status === 'terminated'"
            class="flex-shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
            style="background-color:#f1f5f9; color:#64748b;"
          >terminée</span>
          <span
            v-else-if="p.status === 'archived'"
            class="flex-shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
            style="background-color:#f8fafc; color:#94a3b8;"
          >archivée</span>
        </div>

        <!-- Médicaments -->
        <div v-if="p.item_names.length > 0" class="mt-2 flex flex-wrap gap-1.5">
          <span
            v-for="name in p.item_names"
            :key="name"
            class="rounded-md px-2 py-0.5 text-xs"
            style="background-color:#f1f5f9; color:#475569;"
          >{{ name }}</span>
          <span
            v-if="p.items_count > 3"
            class="rounded-md px-2 py-0.5 text-xs"
            style="background-color:#f1f5f9; color:#94a3b8;"
          >+{{ p.items_count - 3 }}</span>
        </div>
        <p v-else class="mt-2 text-xs text-slate-300 italic">Aucun médicament</p>

        <!-- Chevron -->
        <div class="mt-2 flex justify-end">
          <svg class="w-4 h-4 text-slate-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="9 18 15 12 9 6"/>
          </svg>
        </div>
      </Link>

    </div>
  </AppLayout>
</template>
