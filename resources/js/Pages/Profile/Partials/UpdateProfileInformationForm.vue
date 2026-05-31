<script setup>
import { useForm, usePage } from '@inertiajs/vue3'

defineProps({
    mustVerifyEmail: { type: Boolean },
    status:          { type: String },
})

const user = usePage().props.auth.user

const form = useForm({
    name:  user.name,
    email: user.email,
})
</script>

<template>
    <section class="space-y-4">
        <h2 class="text-sm font-semibold text-slate-700">Informations du compte</h2>

        <form @submit.prevent="form.patch(route('profile.update'))" class="space-y-4">
            <div>
                <label for="name" class="field-label">Nom</label>
                <input
                    id="name"
                    v-model="form.name"
                    type="text"
                    class="field-input"
                    autocomplete="name"
                    required
                    autofocus
                />
                <p v-if="form.errors.name" class="field-error">{{ form.errors.name }}</p>
            </div>

            <div>
                <label for="email" class="field-label">Adresse e-mail</label>
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    class="field-input"
                    autocomplete="username"
                    required
                />
                <p v-if="form.errors.email" class="field-error">{{ form.errors.email }}</p>
            </div>

            <div class="flex items-center gap-4">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-xl bg-slate-800 text-white px-4 py-2.5 text-sm font-semibold hover:bg-slate-700 disabled:opacity-60 transition-colors"
                >
                    Enregistrer
                </button>
                <p v-if="form.recentlySuccessful" class="text-sm text-emerald-600">Enregistré.</p>
            </div>
        </form>
    </section>
</template>
