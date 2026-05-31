<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue'
import { Head, useForm } from '@inertiajs/vue3'

const form = useForm({
    email: '',
    password: '',
    remember: false,
})

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    })
}
</script>

<template>
    <GuestLayout>
        <Head title="Connexion — Pilo" />

        <form @submit.prevent="submit" class="space-y-5">

            <div>
                <label for="email" class="field-label">Adresse e-mail</label>
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    class="field-input"
                    autocomplete="username"
                    autofocus
                    required
                    placeholder="nom@exemple.com"
                />
                <p v-if="form.errors.email" class="field-error">{{ form.errors.email }}</p>
            </div>

            <div>
                <label for="password" class="field-label">Mot de passe</label>
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    class="field-input"
                    autocomplete="current-password"
                    required
                    placeholder="••••••••"
                />
                <p v-if="form.errors.password" class="field-error">{{ form.errors.password }}</p>
            </div>

            <label class="flex items-center gap-2 cursor-pointer select-none">
                <input
                    type="checkbox"
                    v-model="form.remember"
                    class="rounded border-slate-300 text-slate-700 focus:ring-slate-400"
                />
                <span class="text-sm text-slate-600">Se souvenir de moi</span>
            </label>

            <button
                type="submit"
                :disabled="form.processing"
                class="w-full rounded-xl bg-slate-800 text-white py-3 text-sm font-semibold shadow-sm hover:bg-slate-700 disabled:opacity-60 disabled:cursor-not-allowed transition-colors"
            >
                <span v-if="form.processing">Connexion…</span>
                <span v-else>Se connecter</span>
            </button>

        </form>
    </GuestLayout>
</template>
