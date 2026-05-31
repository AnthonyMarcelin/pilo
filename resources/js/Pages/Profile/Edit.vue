<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import DeleteUserForm from './Partials/DeleteUserForm.vue'
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue'
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue'
import { Head } from '@inertiajs/vue3'

defineProps({
    mustVerifyEmail: { type: Boolean },
    status:          { type: String },
})
</script>

<template>
    <AppLayout>
        <Head title="Mon profil — Pilo" />

        <div class="px-4 pt-6 pb-2">
            <h1 class="text-xl font-semibold text-slate-800">Mon profil</h1>
            <p class="text-sm text-slate-500 mt-0.5">Paramètres du compte</p>
        </div>

        <div class="px-4 pb-32 space-y-4 mt-3">
            <div class="bg-white rounded-xl p-4 shadow-sm">
                <UpdateProfileInformationForm
                    :must-verify-email="mustVerifyEmail"
                    :status="status"
                />
            </div>

            <div class="bg-white rounded-xl p-4 shadow-sm">
                <UpdatePasswordForm />
            </div>

            <div class="bg-white rounded-xl p-4 shadow-sm">
                <DeleteUserForm />
            </div>
        </div>
    </AppLayout>
</template>
