<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { ref } from 'vue'

const props = defineProps({
  prescription: { type: Object, required: true },
})

const showImage = ref(true)
const archiving = ref(false)

function formatDate(dateStr) {
  if (!dateStr) return null
  const [y, m, d] = dateStr.split('-')
  return `${d}/${m}/${y}`
}

function formatQty(qty) {
  if (qty == null) return null
  const n = Number(qty)
  if (isNaN(n)) return null
  return n % 1 === 0 ? String(n) : n.toFixed(1).replace('.', ',')
}

function momentLabel(key) {
  return { morning: 'Matin', noon: 'Midi', evening: 'Soir', bedtime: 'Coucher' }[key] ?? key
}

function itemMoments(item) {
  return ['morning', 'noon', 'evening', 'bedtime']
    .filter(m => item[m] != null && Number(item[m]) > 0)
    .map(m => ({ label: momentLabel(m), qty: formatQty(item[m]) }))
}

function archive() {
  if (archiving.value) return
  archiving.value = true
  router.post(route('prescriptions.archive', props.prescription.id), {}, {
    onFinish: () => { archiving.value = false },
  })
}

const intakeLabel = { fixe: 'Fixe', si_besoin: 'Si besoin', autre: 'Autre' }
const sourceLabel  = { scan: 'Scan IA', manual: 'Saisie manuelle' }
</script>

<template>
  <AppLayout>
    <Head title="Ordonnance — Pilo" />

    <!-- En-tête -->
    <div class="flex items-center gap-3 px-4 pt-6 pb-3">
      <Link
        :href="route('prescriptions.index')"
        class="w-8 h-8 flex items-center justify-center rounded-full text-slate-500 hover:bg-slate-200 transition-colors"
        aria-label="Retour"
      >
        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <polyline points="15 18 9 12 15 6"/>
        </svg>
      </Link>
      <div class="min-w-0 flex-1">
        <h1 class="text-xl font-semibold text-slate-800 truncate">
          {{ prescription.prescriber_name ?? 'Prescripteur inconnu' }}
        </h1>
        <div class="flex items-center gap-2 mt-0.5 flex-wrap">
          <span class="text-sm text-slate-400">
            {{ formatDate(prescription.prescribed_at) ?? 'Date inconnue' }}
          </span>
          <span class="text-slate-200 text-xs">·</span>
          <span class="text-xs text-slate-400">{{ sourceLabel[prescription.source_type] ?? prescription.source_type }}</span>
          <span
            v-if="prescription.status === 'terminated'"
            class="rounded-full px-2 py-0.5 text-xs font-medium"
            style="background-color:#f1f5f9; color:#64748b;"
          >terminée</span>
          <span
            v-else-if="prescription.status === 'archived'"
            class="rounded-full px-2 py-0.5 text-xs font-medium"
            style="background-color:#f8fafc; color:#94a3b8;"
          >archivée</span>
          <span
            v-else
            class="rounded-full px-2 py-0.5 text-xs font-medium"
            style="background-color:#f0fdf4; color:#15803d;"
          >active</span>
        </div>
      </div>
    </div>

    <div class="px-4 pb-28 space-y-4">

      <!-- Image de l'ordonnance -->
      <div v-if="prescription.has_image">
        <button
          type="button"
          @click="showImage = !showImage"
          class="flex items-center gap-2 text-sm text-slate-500 hover:text-slate-700 transition-colors"
        >
          <svg class="w-4 h-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
            <circle cx="12" cy="13" r="4"/>
          </svg>
          <span>{{ showImage ? 'Masquer l\'ordonnance' : 'Voir l\'ordonnance' }}</span>
        </button>
        <div v-if="showImage" class="mt-2 rounded-xl border border-slate-100">
          <img
            :src="route('prescriptions.image', prescription.id)"
            alt="Image de l'ordonnance"
            class="w-full h-auto block rounded-xl max-h-[70vh] object-contain"
          />
        </div>
      </div>

      <!-- Notes -->
      <div v-if="prescription.notes" class="bg-white rounded-xl p-4 shadow-sm">
        <p class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-1">Notes</p>
        <p class="text-sm text-slate-700 whitespace-pre-wrap">{{ prescription.notes }}</p>
      </div>

      <!-- Médicaments -->
      <div v-if="prescription.items.length > 0" class="space-y-3">
        <p class="text-xs font-semibold text-slate-500 uppercase tracking-wide">
          Médicaments ({{ prescription.items.length }})
        </p>

        <div
          v-for="item in prescription.items"
          :key="item.id"
          class="bg-white rounded-xl p-4 shadow-sm space-y-2"
        >
          <!-- Nom + badge type -->
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="font-medium text-slate-800 leading-snug">{{ item.medication_name }}</p>
              <p v-if="item.dosage" class="text-xs text-slate-400 mt-0.5">{{ item.dosage }}</p>
            </div>
            <span
              class="flex-shrink-0 rounded-md px-2 py-0.5 text-xs font-medium"
              :style="item.intake_type === 'fixe'
                ? 'background-color:#eff6ff; color:#1d4ed8;'
                : item.intake_type === 'si_besoin'
                ? 'background-color:#fefce8; color:#854d0e;'
                : 'background-color:#f8fafc; color:#64748b;'"
            >{{ intakeLabel[item.intake_type] ?? item.intake_type }}</span>
          </div>

          <!-- Grille des doses (fixe) -->
          <div v-if="item.intake_type === 'fixe' && itemMoments(item).length > 0" class="flex flex-wrap gap-2">
            <span
              v-for="m in itemMoments(item)"
              :key="m.label"
              class="rounded-md px-2 py-1 text-xs"
              style="background-color:#f1f5f9; color:#334155;"
            >{{ m.label }} × {{ m.qty }}</span>
          </div>

          <!-- Condition (si besoin) -->
          <p v-if="item.condition" class="text-sm text-slate-600 italic">{{ item.condition }}</p>
          <p v-if="item.max_per_day" class="text-xs text-slate-400">Max {{ item.max_per_day }}/j</p>

          <!-- Posologie brute -->
          <p class="text-xs text-slate-500 border-l-2 border-slate-100 pl-2 leading-relaxed">
            {{ item.posologie_brute }}
          </p>

          <!-- Dates -->
          <div class="grid grid-cols-2 gap-x-4 gap-y-1 pt-1">
            <div v-if="item.start_date">
              <p class="text-xs text-slate-400">Début</p>
              <p class="text-xs font-medium text-slate-700">{{ formatDate(item.start_date) }}</p>
            </div>
            <div v-if="item.end_date">
              <p class="text-xs text-slate-400">Fin de traitement</p>
              <p class="text-xs font-medium text-slate-700">{{ formatDate(item.end_date) }}</p>
            </div>
            <div v-if="item.stock_end_date" class="col-span-2">
              <p class="text-xs text-slate-400">~Fin de stock</p>
              <p class="text-xs font-medium text-slate-700">{{ formatDate(item.stock_end_date) }}</p>
            </div>
          </div>
        </div>
      </div>

      <div v-else class="py-6 text-center">
        <p class="text-sm text-slate-400 italic">Aucun médicament dans cette ordonnance.</p>
      </div>

      <!-- Action archivage -->
      <div v-if="prescription.status !== 'archived'" class="pt-2">
        <button
          type="button"
          @click="archive"
          :disabled="archiving"
          class="w-full rounded-xl border border-slate-200 py-3 text-sm text-slate-500 hover:text-slate-700 hover:border-slate-300 transition-colors disabled:opacity-50"
        >
          <span v-if="archiving">Archivage…</span>
          <span v-else>Archiver cette ordonnance</span>
        </button>
        <p class="text-xs text-slate-300 text-center mt-1.5">
          L'archivage est définitif. L'ordonnance restera consultable.
        </p>
      </div>

    </div>
  </AppLayout>
</template>
