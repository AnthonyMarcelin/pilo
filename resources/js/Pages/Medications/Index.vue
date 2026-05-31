<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import { Head } from '@inertiajs/vue3'
import { ref } from 'vue'

const props = defineProps({
  active:   { type: Array, default: () => [] },
  inactive: { type: Array, default: () => [] },
})

const editing  = ref({})
const noteText = ref({})
const saving   = ref({})

function openEdit(med) {
  noteText.value[med.normalized] = med.note ?? ''
  editing.value[med.normalized]  = true
}

function cancelEdit(med) {
  editing.value[med.normalized] = false
}

async function saveNote(med) {
  const text = (noteText.value[med.normalized] ?? '').trim()
  saving.value[med.normalized] = true
  try {
    if (text) {
      await fetch(`/medications/${encodeURIComponent(med.normalized)}/note`, {
        method:  'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify({ note: text }),
      })
      med.note = text
    } else {
      await fetch(`/medications/${encodeURIComponent(med.normalized)}/note`, {
        method:  'DELETE',
        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
      })
      med.note = null
    }
    editing.value[med.normalized] = false
  } finally {
    saving.value[med.normalized] = false
  }
}
</script>

<template>
  <AppLayout>
    <Head title="Mes médicaments — Pilo" />

    <div class="px-4 pt-6 pb-2">
      <h1 class="text-xl font-semibold text-slate-800">Mes médicaments</h1>
      <p class="text-sm text-slate-500 mt-0.5">Regroupés par médicament</p>
    </div>

    <!-- ── Actifs ─────────────────────────────────────────────────────────── -->
    <div v-if="active.length > 0" class="px-4 mt-4">
      <h2 class="text-xs font-semibold tracking-widest uppercase text-slate-400 mb-3">
        En cours ({{ active.length }})
      </h2>
      <div class="space-y-3">
        <div
          v-for="med in active"
          :key="med.normalized"
          class="bg-white rounded-xl p-4 shadow-sm border border-slate-100"
        >
          <!-- En-tête -->
          <div class="flex items-start justify-between gap-2 mb-3">
            <div>
              <p class="text-sm font-semibold text-slate-800 leading-snug">{{ med.name }}</p>
              <p v-if="med.dosage" class="text-xs text-slate-500 mt-0.5">{{ med.dosage }}</p>
            </div>
            <span class="flex-shrink-0 text-xs font-medium rounded-full px-2.5 py-0.5 bg-emerald-50 text-emerald-700 border border-emerald-200">
              En cours
            </span>
          </div>

          <!-- Note -->
          <template v-if="!editing[med.normalized]">
            <div v-if="med.note" class="flex items-start gap-2 mb-3">
              <p class="text-xs text-slate-600 leading-relaxed flex-1">{{ med.note }}</p>
              <button
                @click="openEdit(med)"
                class="flex-shrink-0 text-slate-400 hover:text-slate-600 tap-target flex items-center justify-center"
                aria-label="Modifier la note"
              >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                  <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                </svg>
              </button>
            </div>
            <button
              v-else
              @click="openEdit(med)"
              class="flex items-center gap-1.5 text-xs text-slate-400 hover:text-slate-600 transition-colors mb-3"
            >
              <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
              </svg>
              Ajouter une note personnelle
            </button>
          </template>
          <template v-else>
            <textarea
              v-model="noteText[med.normalized]"
              rows="3"
              class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-800 placeholder-slate-400 focus:border-slate-400 focus:bg-white focus:outline-none transition-colors resize-none mb-2"
              placeholder="Effets ressentis, rappels…"
              autofocus
            />
            <div class="flex gap-2 mb-3">
              <button
                @click="saveNote(med)"
                :disabled="saving[med.normalized]"
                class="text-xs font-medium text-white bg-slate-800 rounded-lg px-3 py-1.5 hover:bg-slate-700 disabled:opacity-50 transition-colors"
              >{{ saving[med.normalized] ? 'Enregistrement…' : 'Enregistrer' }}</button>
              <button @click="cancelEdit(med)" class="text-xs text-slate-500 hover:text-slate-700 px-2 transition-colors">
                Annuler
              </button>
            </div>
          </template>

          <!-- Indication BDPM -->
          <div v-if="med.indication" class="rounded-lg bg-slate-50 border border-slate-100 px-3 py-2.5">
            <!-- Générique : mention contextualisée -->
            <p v-if="med.is_generic" class="text-[11px] text-slate-400 italic mb-1">
              Indication du médicament de référence ({{ med.originator_name }}),
              dont {{ med.name }} est un générique.
            </p>
            <p class="text-xs font-medium text-slate-500 mb-1">
              Indication officielle (source ANSM/BDPM)
            </p>
            <p class="text-xs text-slate-600 leading-relaxed">{{ med.indication }}</p>
            <p class="mt-1.5 text-[11px] text-slate-400 italic">
              Usage général de ce médicament — peut différer de votre prescription personnelle.
              Votre médecin fait foi.
            </p>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Terminés ───────────────────────────────────────────────────────── -->
    <div v-if="inactive.length > 0" class="px-4 mt-5 pb-4">
      <h2 class="text-xs font-semibold tracking-widest uppercase text-slate-400 mb-3">
        Terminés ({{ inactive.length }})
      </h2>
      <div class="space-y-3 opacity-60">
        <div
          v-for="med in inactive"
          :key="med.normalized"
          class="bg-white rounded-xl p-4 shadow-sm border border-slate-100"
        >
          <div class="flex items-start justify-between gap-2 mb-2">
            <div>
              <p class="text-sm font-medium text-slate-700 leading-snug line-through">{{ med.name }}</p>
              <p v-if="med.dosage" class="text-xs text-slate-400 mt-0.5">{{ med.dosage }}</p>
            </div>
            <span class="flex-shrink-0 text-xs font-medium rounded-full px-2.5 py-0.5 bg-slate-100 text-slate-500">
              Terminé
            </span>
          </div>
          <p v-if="med.note" class="text-xs text-slate-500 leading-relaxed">{{ med.note }}</p>
        </div>
      </div>
    </div>

    <!-- ── Vide global ────────────────────────────────────────────────────── -->
    <div v-if="active.length === 0 && inactive.length === 0" class="px-4 pb-6 mt-4">
      <div class="rounded-2xl border border-dashed border-slate-200 bg-white px-6 py-10 flex flex-col items-center gap-3 text-center">
        <svg class="h-10 w-10 text-slate-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
          <rect x="7" y="2" width="10" height="20" rx="5" ry="5"/>
          <line x1="7" y1="12" x2="17" y2="12"/>
        </svg>
        <p class="text-sm text-slate-500">Aucun médicament pour l'instant.</p>
        <a href="/prescriptions/create" class="text-sm font-medium text-slate-700 underline underline-offset-2">
          Ajouter une ordonnance
        </a>
      </div>
    </div>

    <div class="h-4"/>
  </AppLayout>
</template>
