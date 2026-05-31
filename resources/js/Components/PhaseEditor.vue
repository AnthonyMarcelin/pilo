<script setup>
import { computed } from 'vue'

const props = defineProps({
  phases: { type: Array, required: true },
  errors: { type: Object, default: () => ({}) },
  itemIndex: { type: Number, required: true },
})

const emit = defineEmits(['update:phases'])

const isSimple = computed(() => props.phases.length === 1)

const totalDays = computed(() =>
  props.phases.reduce((sum, p) => sum + (parseInt(p.duration_days) || 0), 0)
)

const moments = [
  { key: 'morning', label: 'Matin' },
  { key: 'noon',    label: 'Midi' },
  { key: 'evening', label: 'Soir' },
  { key: 'bedtime', label: 'Coucher' },
]

function updatePhase(phaseIndex, field, raw) {
  const value = raw === '' ? null : (field === 'duration_days' ? parseInt(raw) : parseFloat(raw))
  const updated = props.phases.map((p, i) =>
    i === phaseIndex ? { ...p, [field]: isNaN(value) ? null : value } : p
  )
  emit('update:phases', updated)
}

function addPhase() {
  const last = props.phases[props.phases.length - 1]
  emit('update:phases', [
    ...props.phases,
    { duration_days: null, morning: last.morning, noon: last.noon, evening: last.evening, bedtime: last.bedtime },
  ])
}

function removePhase(index) {
  if (props.phases.length <= 1) return
  emit('update:phases', props.phases.filter((_, i) => i !== index))
}

function phaseError(phaseIndex, field) {
  return props.errors[`items.${props.itemIndex}.phases.${phaseIndex}.${field}`]
}
</script>

<template>
  <!-- ─── Mode simple : 1 palier → présentation à plat ─────────────────── -->
  <div v-if="isSimple">
    <div class="grid grid-cols-2 gap-3 mb-4">
      <div>
        <label class="field-label">Durée (jours)</label>
        <input
          type="number"
          min="1"
          :value="phases[0].duration_days ?? ''"
          @input="updatePhase(0, 'duration_days', $event.target.value)"
          class="field-input"
          placeholder="Ex : 30"
        />
        <p v-if="phaseError(0, 'duration_days')" class="field-error">{{ phaseError(0, 'duration_days') }}</p>
      </div>
    </div>

    <div>
      <p class="field-label">Doses quotidiennes</p>
      <div class="grid grid-cols-4 gap-2">
        <div v-for="m in moments" :key="m.key">
          <label class="block text-xs text-slate-400 text-center mb-1">{{ m.label }}</label>
          <input
            type="number"
            step="0.5"
            min="0"
            :value="phases[0][m.key] ?? ''"
            @input="updatePhase(0, m.key, $event.target.value)"
            class="field-input text-center px-1"
            placeholder="—"
          />
        </div>
      </div>
    </div>

    <button
      type="button"
      @click="addPhase"
      class="mt-4 flex items-center gap-1.5 text-sm text-slate-400 hover:text-slate-600 transition-colors"
    >
      <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
      </svg>
      Ajouter un palier dégressif
    </button>
  </div>

  <!-- ─── Mode dégressif : N paliers → cartes numérotées ──────────────── -->
  <div v-else>
    <!-- Badge dégressif -->
    <div class="flex items-center gap-2 mb-3">
      <span class="inline-flex items-center gap-1 text-xs font-medium text-amber-700 bg-amber-50 border border-amber-200 rounded-full px-2.5 py-0.5">
        <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <polyline points="6 9 12 15 18 9"/>
        </svg>
        Dégressif · {{ phases.length }} paliers · {{ totalDays }} j au total
      </span>
    </div>

    <div class="space-y-2">
      <div
        v-for="(phase, i) in phases"
        :key="i"
        class="border border-slate-200 rounded-lg p-3 bg-slate-50"
      >
        <!-- En-tête du palier -->
        <div class="flex items-center justify-between mb-3">
          <span class="text-sm font-semibold text-slate-700">Palier {{ i + 1 }}</span>
          <button
            type="button"
            @click="removePhase(i)"
            :disabled="phases.length <= 1"
            class="w-7 h-7 flex items-center justify-center rounded-full text-slate-400 hover:text-red-500 hover:bg-red-50 disabled:opacity-25 disabled:cursor-not-allowed transition-colors"
            :aria-label="`Supprimer le palier ${i + 1}`"
          >
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="3 6 5 6 21 6"/>
              <path d="M19 6l-1 14H6L5 6"/>
              <path d="M10 11v6M14 11v6"/>
              <path d="M9 6V4h6v2"/>
            </svg>
          </button>
        </div>

        <!-- Durée du palier -->
        <div class="mb-3">
          <label class="field-label">Durée (jours)</label>
          <input
            type="number"
            min="1"
            :value="phase.duration_days ?? ''"
            @input="updatePhase(i, 'duration_days', $event.target.value)"
            class="field-input w-28"
            placeholder="Ex : 7"
          />
          <p v-if="phaseError(i, 'duration_days')" class="field-error">{{ phaseError(i, 'duration_days') }}</p>
        </div>

        <!-- Grille de doses -->
        <div>
          <p class="field-label">Doses</p>
          <div class="grid grid-cols-4 gap-2">
            <div v-for="m in moments" :key="m.key">
              <label class="block text-xs text-slate-400 text-center mb-1">{{ m.label }}</label>
              <input
                type="number"
                step="0.5"
                min="0"
                :value="phase[m.key] ?? ''"
                @input="updatePhase(i, m.key, $event.target.value)"
                class="field-input text-center px-1"
                placeholder="—"
              />
            </div>
          </div>
        </div>
      </div>
    </div>

    <button
      type="button"
      @click="addPhase"
      class="mt-3 flex items-center gap-1.5 text-sm text-slate-400 hover:text-slate-600 transition-colors"
    >
      <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
      </svg>
      Ajouter un palier
    </button>
  </div>
</template>
