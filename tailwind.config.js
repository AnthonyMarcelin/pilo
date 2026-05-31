import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['system-ui', '-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'Roboto', 'Helvetica Neue', 'Arial', 'sans-serif'],
            },
            colors: {
                moment: {
                    morning: { bg: '#fffbeb', border: '#fde68a', label: '#92400e', icon: '#d97706' },
                    noon:    { bg: '#f0fdf4', border: '#bbf7d0', label: '#14532d', icon: '#16a34a' },
                    evening: { bg: '#eef2ff', border: '#c7d2fe', label: '#312e81', icon: '#4f46e5' },
                    bedtime: { bg: '#f5f3ff', border: '#ddd6fe', label: '#4c1d95', icon: '#7c3aed' },
                },
            },
        },
    },

    plugins: [forms],
};
