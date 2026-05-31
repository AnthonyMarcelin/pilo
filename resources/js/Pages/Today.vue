<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head } from '@inertiajs/vue3'
import { computed } from 'vue'

const props = defineProps({
    todayLabel: { type: String, default: '' },
    regimen: {
        type: Object,
        default: () => ({ fixed: { morning: [], noon: [], evening: [], bedtime: [] }, asNeeded: [], special: [] }),
    },
    alerts: { type: Array, default: () => [] },
})

const moments = [
    {
        key:     'morning',
        label:   'Matin',
        bg:      '#fffbeb',
        border:  '#fde68a',
        heading: '#92400e',
        icon:    'sun-small',
    },
    {
        key:     'noon',
        label:   'Midi',
        bg:      '#f0fdf4',
        border:  '#bbf7d0',
        heading: '#14532d',
        icon:    'sun-large',
    },
    {
        key:     'evening',
        label:   'Soir',
        bg:      '#eef2ff',
        border:  '#c7d2fe',
        heading: '#312e81',
        icon:    'moon',
    },
    {
        key:     'bedtime',
        label:   'Coucher',
        bg:      '#f5f3ff',
        border:  '#ddd6fe',
        heading: '#4c1d95',
        icon:    'bed',
    },
]

const hasAnyEntry = computed(() =>
    moments.some(m => (props.regimen.fixed[m.key] ?? []).length > 0)
    || props.regimen.asNeeded.length > 0
    || props.regimen.special.length > 0
)

function formatQty(qty) {
    if (qty == null) return ''
    const whole = Math.floor(qty)
    const frac  = Math.round((qty - whole) * 10) / 10
    if (frac === 0.5) return whole > 0 ? `${whole}½` : '½'
    return String(Number.isInteger(qty) ? qty : qty.toFixed(1).replace('.', ','))
}

function alertMessage(alert) {
    if (alert.type !== 'renewal') return ''
    return alert.daysLeft > 0
        ? `Bientôt plus de ${alert.medication} (~${alert.daysLeft} j) — pense à renouveler`
        : `Plus de ${alert.medication} — pense à renouveler`
}
</script>

<template>
  <AppLayout>
    <Head title="Aujourd'hui — Pilo" />

    <!-- ── En-tête ── -->
    <div class="px-4 pt-6 pb-3">
      <h1 class="text-xl font-semibold text-slate-800">Aujourd'hui</h1>
      <p v-if="todayLabel" class="text-sm text-slate-400 mt-0.5 capitalize">{{ todayLabel }}</p>
    </div>

    <!-- ── Bandeau d'alertes ── -->
    <div v-if="alerts.length > 0" class="px-4 pb-3 space-y-2">
      <div
        v-for="(alert, i) in alerts"
        :key="i"
        class="flex items-start gap-3 rounded-xl px-4 py-3"
        style="background-color: #fff7ed; border: 1px solid #fed7aa;"
      >
        <svg class="h-4 w-4 flex-shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="#c2410c" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
          <line x1="12" y1="9" x2="12" y2="13"/>
          <line x1="12" y1="17" x2="12.01" y2="17"/>
        </svg>
        <p class="text-sm leading-snug" style="color: #9a3412;">{{ alertMessage(alert) }}</p>
      </div>
    </div>

    <!-- ── Grille 4 moments ── -->
    <div class="px-4 pb-3">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <section
          v-for="moment in moments"
          :key="moment.key"
          class="rounded-2xl border overflow-hidden"
          :style="{ backgroundColor: moment.bg, borderColor: moment.border }"
        >
          <!-- En-tête de la carte -->
          <div class="flex items-center gap-2 px-4 pt-4 pb-3">
            <!-- Icône matin : petit soleil -->
            <svg v-if="moment.icon === 'sun-small'" class="h-4 w-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" :stroke="moment.heading" stroke-width="2" stroke-linecap="round">
              <circle cx="12" cy="12" r="4"/>
              <line x1="12" y1="2" x2="12" y2="4"/>
              <line x1="12" y1="20" x2="12" y2="22"/>
              <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>
              <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
              <line x1="2" y1="12" x2="4" y2="12"/>
              <line x1="20" y1="12" x2="22" y2="12"/>
              <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
              <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
            </svg>
            <!-- Icône midi : grand soleil -->
            <svg v-else-if="moment.icon === 'sun-large'" class="h-4 w-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" :stroke="moment.heading" stroke-width="2" stroke-linecap="round">
              <circle cx="12" cy="12" r="5"/>
              <line x1="12" y1="1" x2="12" y2="3"/>
              <line x1="12" y1="21" x2="12" y2="23"/>
              <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>
              <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
              <line x1="1" y1="12" x2="3" y2="12"/>
              <line x1="21" y1="12" x2="23" y2="12"/>
              <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
              <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
            </svg>
            <!-- Icône soir : lune -->
            <svg v-else-if="moment.icon === 'moon'" class="h-4 w-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" :stroke="moment.heading" stroke-width="2" stroke-linecap="round">
              <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
            </svg>
            <!-- Icône coucher : lit -->
            <svg v-else-if="moment.icon === 'bed'" class="h-4 w-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" :stroke="moment.heading" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M3 9V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v4"/>
              <path d="M3 9h18v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9z"/>
              <path d="M7 9V7a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v2"/>
            </svg>
            <h2
              class="text-xs font-semibold tracking-widest uppercase"
              :style="{ color: moment.heading }"
            >{{ moment.label }}</h2>
          </div>

          <!-- Entrées médicaments -->
          <div class="px-4 pb-4 space-y-2">
            <template v-if="(regimen.fixed[moment.key] ?? []).length > 0">
              <div
                v-for="entry in regimen.fixed[moment.key]"
                :key="entry.id"
                class="flex flex-col gap-0.5"
                :class="{ 'opacity-40': entry.isTerminated }"
              >
                <!-- Nom + dose -->
                <div class="flex items-baseline justify-between gap-2">
                  <span
                    class="text-sm font-medium text-slate-800 leading-snug"
                    :class="{ 'line-through': entry.isTerminated }"
                  >{{ entry.name }}</span>
                  <span
                    v-if="!entry.isTerminated && entry.qty > 0"
                    class="text-sm font-semibold tabular-nums flex-shrink-0"
                    :style="{ color: moment.heading }"
                  >× {{ formatQty(entry.qty) }}</span>
                </div>

                <!-- Label de phase dégressive (item actif) -->
                <div v-if="!entry.isTerminated && entry.hasTapering && entry.dayInPhase" class="flex flex-col gap-0.5">
                  <span class="text-xs text-slate-500">
                    jour {{ entry.dayInPhase }}/{{ entry.phaseDurationDays }}
                  </span>
                  <span v-if="entry.nextChangeNote" class="text-xs" :style="{ color: moment.heading }">
                    {{ entry.nextChangeNote }}
                  </span>
                </div>

                <!-- Label terminé -->
                <span v-if="entry.isTerminated" class="text-xs text-slate-500">
                  {{ entry.endDateLabel }} — à renouveler ?
                </span>
              </div>
            </template>
            <p v-else class="text-sm text-slate-500 italic">Aucun médicament</p>
          </div>
        </section>
      </div>
    </div>

    <!-- ── Au besoin ── -->
    <div v-if="regimen.asNeeded.length > 0" class="px-4 pb-3">
      <section class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
        <div class="flex items-center gap-2 px-4 pt-4 pb-3">
          <svg class="h-4 w-4 flex-shrink-0 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="8" x2="12" y2="12"/>
            <line x1="12" y1="16" x2="12.01" y2="16"/>
          </svg>
          <h2 class="text-xs font-semibold tracking-widest uppercase text-slate-500">Au besoin</h2>
        </div>
        <div class="px-4 pb-4 space-y-3">
          <div v-for="entry in regimen.asNeeded" :key="entry.id" class="flex flex-col gap-0.5">
            <span class="text-sm font-medium text-slate-800">{{ entry.name }}</span>
            <span v-if="entry.condition" class="text-xs text-slate-500">{{ entry.condition }}</span>
            <span v-if="entry.maxPerDay" class="text-xs text-slate-400">
              max {{ formatQty(entry.maxPerDay) }} /jour
            </span>
          </div>
        </div>
      </section>
    </div>

    <!-- ── Prises particulières ── -->
    <div v-if="regimen.special.length > 0" class="px-4 pb-6">
      <section class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
        <div class="flex items-center gap-2 px-4 pt-4 pb-3">
          <svg class="h-4 w-4 flex-shrink-0 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="8" y1="6" x2="21" y2="6"/>
            <line x1="8" y1="12" x2="21" y2="12"/>
            <line x1="8" y1="18" x2="21" y2="18"/>
            <line x1="3" y1="6" x2="3.01" y2="6"/>
            <line x1="3" y1="12" x2="3.01" y2="12"/>
            <line x1="3" y1="18" x2="3.01" y2="18"/>
          </svg>
          <h2 class="text-xs font-semibold tracking-widest uppercase text-slate-500">Prises particulières</h2>
        </div>
        <div class="px-4 pb-4 space-y-3">
          <div v-for="entry in regimen.special" :key="entry.id" class="flex flex-col gap-0.5">
            <span class="text-sm font-medium text-slate-800">{{ entry.name }}</span>
            <span class="text-xs text-slate-500 leading-snug">{{ entry.posologieBrute }}</span>
          </div>
        </div>
      </section>
    </div>

    <!-- ── État vide global ── -->
    <div v-if="!hasAnyEntry" class="px-4 pb-6">
      <div class="rounded-2xl border border-dashed border-slate-200 bg-white px-6 py-10 flex flex-col items-center gap-3 text-center">
        <svg class="h-10 w-10 text-slate-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
          <rect x="7" y="2" width="10" height="20" rx="5" ry="5"/>
          <line x1="7" y1="12" x2="17" y2="12"/>
        </svg>
        <p class="text-sm text-slate-500">Aucun traitement en cours.</p>
        <a href="/prescriptions/create" class="text-sm font-medium text-slate-700 underline underline-offset-2">
          Ajouter une ordonnance
        </a>
      </div>
    </div>

    <!-- Espacement bas pour éviter la nav -->
    <div class="h-4" />

  </AppLayout>
</template>
