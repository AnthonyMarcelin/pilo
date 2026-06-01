<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import PhaseEditor from '@/Components/PhaseEditor.vue'
import { Head, Link, useForm, usePage } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

const props = defineProps({
  draft:    { type: Object, default: null },
  scanId:   { type: String, default: null },
  imageUrl: { type: String, default: null },
})

const page = usePage()
const flash = computed(() => page.props.flash ?? {})
const showScanImage = ref(true)

// ── Helpers ──────────────────────────────────────────────────────────────────

function emptyPhase() {
  return { duration_days: null, morning: null, noon: null, evening: null, bedtime: null }
}

function emptyItem() {
  return {
    medication_name: '',
    dosage: '',
    intake_type: 'fixe',
    posologie_brute: '',
    condition: '',
    max_per_day: null,
    qsp_days: null,
    duration_days: null,
    start_date: '',
    boxes_count: null,
    phases: [emptyPhase()],
  }
}

function hydrateItem(raw) {
  // Pour les items si_besoin / autre : phases = [] (PhaseEditor non affiché,
  // et phases: [{duration_days: null}] ferait échouer la validation serveur).
  const isFixe = (raw.intake_type ?? 'fixe') === 'fixe'
  return {
    ...emptyItem(),
    ...raw,
    phases: isFixe
      ? (raw.phases?.length > 0
          ? raw.phases.map(p => ({ ...emptyPhase(), ...p }))
          : [emptyPhase()])
      : [],
  }
}

const rawItems = (props.draft?.items ?? []).map(hydrateItem)

// ── Formulaire ───────────────────────────────────────────────────────────────

const form = useForm({
  prescriber_name: props.draft?.prescriber_name ?? '',
  prescribed_at:   props.draft?.prescribed_at   ?? '',
  notes:           props.draft?.notes           ?? '',
  source_image:    null,
  scan_id:         props.scanId ?? null,
  items:           rawItems.length > 0 ? rawItems : [emptyItem()],
})

const imageInput = ref(null)
const imagePreviewName = ref(null)

function handleImage(e) {
  const file = e.target.files[0] ?? null
  form.source_image   = file
  imagePreviewName.value = file?.name ?? null
}

// ── Gestion des items ─────────────────────────────────────────────────────────

function addItem() {
  form.items.push(emptyItem())
}

function removeItem(index) {
  if (form.items.length > 1) form.items.splice(index, 1)
}

function setIntakeType(item, type) {
  item.intake_type = type
  // Fixe → garder au moins un palier. Si besoin / Autre → pas de phases.
  item.phases = type === 'fixe' ? (item.phases?.length > 0 ? item.phases : [emptyPhase()]) : []
}

// ── Soumission ────────────────────────────────────────────────────────────────

function submit() {
  // Strip défensif : ne jamais envoyer de phases pour les items non-fixe.
  // Évite que phases:[{duration_days:null}] (valeur par défaut d'emptyItem)
  // ne déclenche la validation serveur sur duration_days pour si_besoin/autre.
  form.transform(data => ({
    ...data,
    items: data.items.map(item => ({
      ...item,
      phases: item.intake_type === 'fixe' ? item.phases : [],
    })),
  })).post(route('prescriptions.store'), {
    forceFormData: true,
    preserveScroll: true,
  })
}

// ── Utilitaires erreurs ───────────────────────────────────────────────────────

function err(path) {
  return form.errors[path] ?? null
}

function itemErr(i, field) {
  return err(`items.${i}.${field}`)
}

const intakeTypes = [
  { value: 'fixe',      label: 'Fixe' },
  { value: 'si_besoin', label: 'Si besoin' },
  { value: 'autre',     label: 'Autre' },
]
</script>

<template>
  <AppLayout>
    <Head title="Nouvelle ordonnance — Pilo" />

    <!-- En-tête page -->
    <div class="flex items-center gap-3 px-4 pt-6 pb-2">
      <Link
        :href="route('prescriptions.create')"
        class="w-8 h-8 flex items-center justify-center rounded-full text-slate-500 hover:bg-slate-200 transition-colors"
        aria-label="Retour"
      >
        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <polyline points="15 18 9 12 15 6"/>
        </svg>
      </Link>
      <div>
        <h1 class="text-xl font-semibold text-slate-800">Saisie manuelle</h1>
        <p class="text-sm text-slate-400">Remplissez puis validez avant d'enregistrer</p>
      </div>
    </div>

    <!-- Flash : erreur scan -->
    <div
      v-if="flash.scan_error"
      class="mx-4 mb-2 rounded-xl px-4 py-3 text-sm"
      style="background-color:#fff7ed; border:1px solid #fed7aa; color:#9a3412;"
    >
      {{ flash.scan_error }}
    </div>

    <!-- Image du scan (affiché si imageUrl transmis par ScanController) -->
    <div v-if="imageUrl" class="px-4 mb-2">
      <button
        type="button"
        @click="showScanImage = !showScanImage"
        class="flex items-center gap-2 text-sm text-slate-500 hover:text-slate-700 transition-colors"
      >
        <svg class="w-4 h-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
          <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
          <circle cx="12" cy="13" r="4"/>
        </svg>
        <span>{{ showScanImage ? 'Masquer l\'ordonnance' : 'Voir l\'ordonnance originale' }}</span>
      </button>
      <div v-if="showScanImage" class="mt-2 rounded-xl overflow-hidden border border-slate-100">
        <img :src="imageUrl" alt="Ordonnance originale" class="w-full object-contain max-h-[50vh]" />
      </div>
    </div>

    <form @submit.prevent="submit" class="px-4 pb-32 space-y-4 mt-2">

      <!-- ── Ordonnance ────────────────────────────────────────────────── -->
      <section class="bg-white rounded-xl p-4 shadow-sm space-y-3">
        <h2 class="text-sm font-semibold text-slate-700">Détails de l'ordonnance</h2>

        <div>
          <label class="field-label">Prescripteur</label>
          <input
            v-model="form.prescriber_name"
            type="text"
            class="field-input"
            placeholder="Dr. Dupont"
          />
          <p v-if="err('prescriber_name')" class="field-error">{{ err('prescriber_name') }}</p>
        </div>

        <div>
          <label class="field-label">Date de l'ordonnance</label>
          <input
            v-model="form.prescribed_at"
            type="date"
            class="field-input"
          />
          <p v-if="err('prescribed_at')" class="field-error">{{ err('prescribed_at') }}</p>
        </div>

        <div>
          <label class="field-label">Notes (facultatif)</label>
          <textarea
            v-model="form.notes"
            rows="2"
            class="field-input resize-none"
            placeholder="Contexte, renouvellement, etc."
          />
        </div>

        <!-- Photo sans OCR (masqué si image déjà fournie par scan) -->
        <div v-if="!imageUrl">
          <label class="field-label">Photo de l'ordonnance (sans lecture IA)</label>
          <input
            ref="imageInput"
            type="file"
            accept="image/*"
            capture="environment"
            class="hidden"
            @change="handleImage"
          />
          <button
            type="button"
            @click="imageInput.click()"
            class="flex items-center gap-2 text-sm text-slate-500 border border-dashed border-slate-300 rounded-lg px-3 py-2.5 w-full hover:border-slate-400 hover:text-slate-700 transition-colors"
          >
            <svg class="w-4 h-4 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
              <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
              <circle cx="12" cy="13" r="4"/>
            </svg>
            <span class="truncate">{{ imagePreviewName ?? 'Joindre une photo' }}</span>
          </button>
          <p v-if="err('source_image')" class="field-error">{{ err('source_image') }}</p>
        </div>
      </section>

      <!-- ── Items ─────────────────────────────────────────────────────── -->
      <section
        v-for="(item, i) in form.items"
        :key="i"
        class="bg-white rounded-xl p-4 shadow-sm space-y-4"
      >
        <!-- En-tête item -->
        <div class="flex items-center justify-between">
          <h2 class="text-sm font-semibold text-slate-700">Médicament {{ i + 1 }}</h2>
          <button
            v-if="form.items.length > 1"
            type="button"
            @click="removeItem(i)"
            class="w-7 h-7 flex items-center justify-center rounded-full text-slate-400 hover:text-red-500 hover:bg-red-50 transition-colors"
            aria-label="Supprimer ce médicament"
          >
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="3 6 5 6 21 6"/>
              <path d="M19 6l-1 14H6L5 6"/>
              <path d="M10 11v6M14 11v6"/>
              <path d="M9 6V4h6v2"/>
            </svg>
          </button>
        </div>

        <!-- Nom + dosage -->
        <div class="grid grid-cols-5 gap-2">
          <div class="col-span-3">
            <label class="field-label">Nom <span class="text-red-400">*</span></label>
            <input
              v-model="item.medication_name"
              type="text"
              class="field-input"
              placeholder="Paracétamol"
              autocomplete="off"
            />
            <p v-if="itemErr(i, 'medication_name')" class="field-error">{{ itemErr(i, 'medication_name') }}</p>
          </div>
          <div class="col-span-2">
            <label class="field-label">Dosage</label>
            <input
              v-model="item.dosage"
              type="text"
              class="field-input"
              placeholder="500 mg"
            />
          </div>
        </div>

        <!-- Sélecteur de type de prise -->
        <div>
          <label class="field-label">Type de prise</label>
          <div class="inline-flex rounded-lg border border-slate-200 overflow-hidden w-full">
            <button
              v-for="t in intakeTypes"
              :key="t.value"
              type="button"
              @click="setIntakeType(item, t.value)"
              class="flex-1 py-2 text-xs font-medium transition-colors"
              :class="item.intake_type === t.value
                ? 'bg-slate-800 text-white'
                : 'bg-white text-slate-500 hover:bg-slate-50'"
            >
              {{ t.label }}
            </button>
          </div>
        </div>

        <!-- ── Fixe : éditeur de paliers ──────────────────────────────── -->
        <div v-if="item.intake_type === 'fixe'" class="space-y-3">
          <PhaseEditor
            v-model:phases="item.phases"
            :errors="form.errors"
            :item-index="i"
          />

          <!-- Champs secondaires (QSP, date début, boîtes) -->
          <details class="group">
            <summary class="text-xs text-slate-400 cursor-pointer hover:text-slate-600 transition-colors select-none flex items-center gap-1">
              <svg class="w-3.5 h-3.5 group-open:rotate-90 transition-transform" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                <polyline points="9 18 15 12 9 6"/>
              </svg>
              Stock &amp; dates (facultatif)
            </summary>
            <div class="mt-3 grid grid-cols-2 gap-3">
              <div>
                <label class="field-label">QSP (jours)</label>
                <input
                  v-model.number="item.qsp_days"
                  type="number"
                  min="1"
                  class="field-input"
                  placeholder="90"
                />
              </div>
              <div>
                <label class="field-label">Nbre de boîtes</label>
                <input
                  v-model.number="item.boxes_count"
                  type="number"
                  min="0"
                  class="field-input"
                  placeholder="1"
                />
              </div>
              <div class="col-span-2">
                <label class="field-label">Date de début</label>
                <input
                  v-model="item.start_date"
                  type="date"
                  class="field-input"
                />
                <p class="text-xs text-slate-400 mt-1">Par défaut : date de l'ordonnance</p>
              </div>
            </div>
          </details>

          <p v-if="itemErr(i, 'phases')" class="field-error">{{ itemErr(i, 'phases') }}</p>
        </div>

        <!-- ── Si besoin ──────────────────────────────────────────────── -->
        <div v-if="item.intake_type === 'si_besoin'" class="space-y-3">
          <div>
            <label class="field-label">Condition / indication</label>
            <textarea
              v-model="item.condition"
              rows="2"
              class="field-input resize-none"
              placeholder="Si douleur, si anxiété…"
            />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="field-label">Dose max / jour</label>
              <input
                v-model.number="item.max_per_day"
                type="number"
                min="0"
                step="0.5"
                class="field-input"
                placeholder="Ex : 4"
              />
            </div>
            <div>
              <label class="field-label">Durée prescrite (jours)</label>
              <input
                v-model.number="item.duration_days"
                type="number"
                min="1"
                class="field-input"
                placeholder="Facultatif"
              />
            </div>
          </div>
        </div>

        <!-- ── Autre ──────────────────────────────────────────────────── -->
        <div v-if="item.intake_type === 'autre'" class="space-y-3">
          <div>
            <label class="field-label">Durée prescrite (jours)</label>
            <input
              v-model.number="item.duration_days"
              type="number"
              min="1"
              class="field-input"
              placeholder="Facultatif"
            />
          </div>
          <p class="text-xs text-slate-400">
            Utilisez la posologie en texte ci-dessous pour décrire le rythme.
          </p>
        </div>

        <!-- ── Posologie brute — TOUJOURS visible ─────────────────────── -->
        <div>
          <label class="field-label">
            Posologie (texte libre) <span class="text-red-400">*</span>
          </label>
          <textarea
            v-model="item.posologie_brute"
            rows="2"
            class="field-input resize-none"
            placeholder="Telle que rédigée sur l'ordonnance…"
          />
          <p v-if="itemErr(i, 'posologie_brute')" class="field-error">{{ itemErr(i, 'posologie_brute') }}</p>
        </div>
      </section>

      <!-- ── Ajouter un médicament ───────────────────────────────────── -->
      <button
        type="button"
        @click="addItem"
        class="w-full flex items-center justify-center gap-2 rounded-xl border-2 border-dashed border-slate-200 py-3 text-sm text-slate-400 hover:border-slate-300 hover:text-slate-600 transition-colors"
      >
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
        </svg>
        Ajouter un médicament
      </button>

      <!-- ── Bouton de validation ────────────────────────────────────── -->
      <div class="pt-2">
        <p class="text-xs text-slate-400 text-center mb-3">
          Vérifiez chaque ligne avant d'enregistrer. Aucune donnée n'est transmise à l'extérieur.
        </p>
        <button
          type="submit"
          :disabled="form.processing"
          class="w-full rounded-xl bg-slate-800 text-white py-3.5 text-sm font-semibold shadow-sm hover:bg-slate-700 disabled:opacity-60 disabled:cursor-not-allowed transition-colors"
        >
          <span v-if="form.processing">Enregistrement…</span>
          <span v-else>Enregistrer l'ordonnance</span>
        </button>
        <div v-if="form.hasErrors" class="text-xs text-red-500 mt-2 rounded-lg bg-red-50 border border-red-200 px-3 py-2">
          <p class="font-medium mb-1">Corrigez les erreurs suivantes :</p>
          <ul class="list-disc list-inside space-y-0.5">
            <li v-for="(msg, field) in form.errors" :key="field">
              <span class="font-mono text-red-400 mr-1">{{ field }}</span>{{ msg }}
            </li>
          </ul>
        </div>
      </div>

    </form>
  </AppLayout>
</template>
