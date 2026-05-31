<script setup>
import { useForm } from '@inertiajs/vue3'
import { ref } from 'vue'

const currentPasswordInput = ref(null)
const passwordInput        = ref(null)

const form = useForm({
    current_password:      '',
    password:              '',
    password_confirmation: '',
})

function updatePassword() {
    form.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onError: () => {
            if (form.errors.password) {
                form.reset('password', 'password_confirmation')
                passwordInput.value?.focus()
            }
            if (form.errors.current_password) {
                form.reset('current_password')
                currentPasswordInput.value?.focus()
            }
        },
    })
}
</script>

<template>
    <section class="space-y-4">
        <h2 class="text-sm font-semibold text-slate-700">Changer le mot de passe</h2>

        <form @submit.prevent="updatePassword" class="space-y-4">
            <div>
                <label for="current_password" class="field-label">Mot de passe actuel</label>
                <input
                    id="current_password"
                    ref="currentPasswordInput"
                    v-model="form.current_password"
                    type="password"
                    class="field-input"
                    autocomplete="current-password"
                />
                <p v-if="form.errors.current_password" class="field-error">{{ form.errors.current_password }}</p>
            </div>

            <div>
                <label for="new_password" class="field-label">Nouveau mot de passe</label>
                <input
                    id="new_password"
                    ref="passwordInput"
                    v-model="form.password"
                    type="password"
                    class="field-input"
                    autocomplete="new-password"
                />
                <p v-if="form.errors.password" class="field-error">{{ form.errors.password }}</p>
            </div>

            <div>
                <label for="password_confirmation" class="field-label">Confirmer le mot de passe</label>
                <input
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    type="password"
                    class="field-input"
                    autocomplete="new-password"
                />
                <p v-if="form.errors.password_confirmation" class="field-error">{{ form.errors.password_confirmation }}</p>
            </div>

            <div class="flex items-center gap-4">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-xl bg-slate-800 text-white px-4 py-2.5 text-sm font-semibold hover:bg-slate-700 disabled:opacity-60 transition-colors"
                >
                    Mettre à jour
                </button>
                <p v-if="form.recentlySuccessful" class="text-sm text-emerald-600">Mis à jour.</p>
            </div>
        </form>
    </section>
</template>
