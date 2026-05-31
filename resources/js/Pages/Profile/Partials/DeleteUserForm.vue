<script setup>
import Modal from '@/Components/Modal.vue'
import { useForm } from '@inertiajs/vue3'
import { nextTick, ref } from 'vue'

const confirmingDeletion = ref(false)
const passwordInput      = ref(null)

const form = useForm({ password: '' })

function openConfirm() {
    confirmingDeletion.value = true
    nextTick(() => passwordInput.value?.focus())
}

function deleteUser() {
    form.delete(route('profile.destroy'), {
        preserveScroll: true,
        onSuccess: () => closeModal(),
        onError:   () => passwordInput.value?.focus(),
        onFinish:  () => form.reset(),
    })
}

function closeModal() {
    confirmingDeletion.value = false
    form.clearErrors()
    form.reset()
}
</script>

<template>
    <section class="space-y-3">
        <h2 class="text-sm font-semibold text-slate-700">Supprimer le compte</h2>
        <p class="text-xs text-slate-500">
            La suppression est définitive : toutes les ordonnances, images et données seront effacées.
        </p>

        <button
            type="button"
            @click="openConfirm"
            class="rounded-xl border border-red-200 text-red-600 px-4 py-2.5 text-sm font-medium hover:bg-red-50 transition-colors"
        >
            Supprimer mon compte
        </button>

        <Modal :show="confirmingDeletion" @close="closeModal">
            <div class="p-6 space-y-4">
                <h2 class="text-base font-semibold text-slate-800">Confirmer la suppression</h2>
                <p class="text-sm text-slate-500">
                    Cette action est irréversible. Toutes vos ordonnances et données seront définitivement supprimées.
                    Entrez votre mot de passe pour confirmer.
                </p>

                <div>
                    <label class="field-label sr-only">Mot de passe</label>
                    <input
                        ref="passwordInput"
                        v-model="form.password"
                        type="password"
                        class="field-input"
                        placeholder="Mot de passe"
                        @keyup.enter="deleteUser"
                    />
                    <p v-if="form.errors.password" class="field-error">{{ form.errors.password }}</p>
                </div>

                <div class="flex justify-end gap-3 pt-1">
                    <button
                        type="button"
                        @click="closeModal"
                        class="rounded-xl border border-slate-200 text-slate-600 px-4 py-2.5 text-sm hover:bg-slate-50 transition-colors"
                    >
                        Annuler
                    </button>
                    <button
                        type="button"
                        :disabled="form.processing"
                        :class="{ 'opacity-50': form.processing }"
                        @click="deleteUser"
                        class="rounded-xl bg-red-600 text-white px-4 py-2.5 text-sm font-semibold hover:bg-red-700 disabled:cursor-not-allowed transition-colors"
                    >
                        Supprimer définitivement
                    </button>
                </div>
            </div>
        </Modal>
    </section>
</template>
