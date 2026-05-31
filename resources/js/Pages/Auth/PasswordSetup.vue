<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    password: '',
    password_confirmation: '',
});

function submit() {
    form.post(route('password.setup.update'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <GuestLayout>
        <Head title="Définir un mot de passe" />
        <div class="mb-4 text-sm text-slate-600 leading-relaxed">
            Pour sécuriser votre compte, définissez un nouveau mot de passe
            (12 caractères minimum).
        </div>
        <form @submit.prevent="submit" class="space-y-5">
            <div>
                <InputLabel for="password" value="Nouveau mot de passe" />
                <TextInput id="password" v-model="form.password" type="password"
                    class="mt-1 block w-full" autocomplete="new-password" autofocus required />
                <InputError :message="form.errors.password" class="mt-1" />
            </div>
            <div>
                <InputLabel for="password_confirmation" value="Confirmer" />
                <TextInput id="password_confirmation" v-model="form.password_confirmation"
                    type="password" class="mt-1 block w-full" autocomplete="new-password" required />
                <InputError :message="form.errors.password_confirmation" class="mt-1" />
            </div>
            <div class="flex items-center justify-end">
                <PrimaryButton :disabled="form.processing">Enregistrer</PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
