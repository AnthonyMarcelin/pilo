<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'

const page = usePage()
const url = computed(() => page.url)

function isActive(path) {
  if (path === '/today') return url.value === '/today' || url.value === '/'
  return url.value.startsWith(path)
}
</script>

<template>
  <div class="min-h-dvh bg-slate-50 flex flex-col antialiased">

    <main
      class="flex-1 overflow-y-auto"
      style="padding-bottom: calc(3.5rem + env(safe-area-inset-bottom, 0px))"
    >
      <slot />
    </main>

    <nav
      class="fixed bottom-0 inset-x-0 z-10 bg-white border-t border-slate-100"
      style="padding-bottom: env(safe-area-inset-bottom, 0px)"
    >
      <div class="flex h-14 items-center justify-around">

        <Link
          href="/today"
          class="tap-target flex flex-col items-center justify-center gap-0.5 flex-1 text-xs font-medium transition-colors duration-150"
          :class="isActive('/today') ? 'text-slate-800' : 'text-slate-400 hover:text-slate-600'"
        >
          <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="4" width="18" height="18" rx="2"/>
            <line x1="16" y1="2" x2="16" y2="6"/>
            <line x1="8" y1="2" x2="8" y2="6"/>
            <line x1="3" y1="10" x2="21" y2="10"/>
          </svg>
          <span>Aujourd'hui</span>
        </Link>

        <Link
          href="/medications"
          class="tap-target flex flex-col items-center justify-center gap-0.5 flex-1 text-xs font-medium transition-colors duration-150"
          :class="isActive('/medications') ? 'text-slate-800' : 'text-slate-400 hover:text-slate-600'"
        >
          <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <rect x="7" y="2" width="10" height="20" rx="5" ry="5"/>
            <line x1="7" y1="12" x2="17" y2="12"/>
          </svg>
          <span>Médicaments</span>
        </Link>

        <Link
          href="/prescriptions/create"
          class="tap-target flex flex-col items-center justify-center gap-0.5 flex-1 text-xs font-medium transition-colors duration-150"
          :class="isActive('/prescriptions/create') ? 'text-slate-700' : 'text-slate-400 hover:text-slate-600'"
        >
          <div class="flex items-center justify-center w-8 h-8 rounded-full bg-slate-800 text-white transition-colors duration-150">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
              <line x1="12" y1="5" x2="12" y2="19"/>
              <line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
          </div>
          <span>Ajouter</span>
        </Link>

        <Link
          href="/prescriptions"
          class="tap-target flex flex-col items-center justify-center gap-0.5 flex-1 text-xs font-medium transition-colors duration-150"
          :class="isActive('/prescriptions') ? 'text-slate-800' : 'text-slate-400 hover:text-slate-600'"
        >
          <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
            <polyline points="14 2 14 8 20 8"/>
            <line x1="16" y1="13" x2="8" y2="13"/>
            <line x1="16" y1="17" x2="8" y2="17"/>
          </svg>
          <span>Ordonnances</span>
        </Link>

      </div>
    </nav>
  </div>
</template>
