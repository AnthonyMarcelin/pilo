<script setup>
// Piste 1 — Capsule bicolore (ambre/indigo)
// Utiliser en remplacement si la piste 3 n'est pas retenue.
</script>
<!--
  PISTE 1 — Capsule bicolore
  Contrôlée par class (ex. class="h-8 w-auto").

  <svg viewBox="0 0 56 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <path d="M14 2H28V22H14A10 10 0 0 1 14 2Z" fill="#fbbf24"/>
    <path d="M28 2H42A10 10 0 0 1 42 22H28V2Z" fill="#818cf8"/>
    <line x1="28" y1="2" x2="28" y2="22" stroke="white" stroke-width="1.5"/>
    <path d="M14 2H42A10 10 0 0 1 42 22H14A10 10 0 0 1 14 2Z" stroke="#e2e8f0" stroke-width="1.5" fill="none"/>
  </svg>
-->

<!--
  PISTE 2 — Initiale P dans un cercle lavande

  <svg viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <circle cx="50" cy="50" r="46" fill="#ede9fe" stroke="#ddd6fe" stroke-width="4"/>
    <rect x="30" y="24" width="14" height="52" rx="7" fill="#4c1d95"/>
    <path d="M44 24H62Q76 24 76 38Q76 52 62 52H44Z" fill="#4c1d95"/>
    <path d="M47 29H60Q68 29 68 38Q68 47 60 47H47Z" fill="#ede9fe"/>
  </svg>
-->

<!--
  PISTE 3 — Pilulier 4 cases (DÉFAUT IMPLÉMENTÉ ci-dessous)
  Carré arrondi : ambre matin / vert midi / indigo soir / violet coucher.
  Chaque case : micro-pilule en couleur d'accent.
  Croix blanche séparatrice.
  Taille contrôlée par class (carré, ex. class="h-10 w-10").
-->
<template>
  <svg
    viewBox="0 0 100 100"
    fill="none"
    xmlns="http://www.w3.org/2000/svg"
    aria-hidden="true"
    v-bind="$attrs"
  >
    <defs>
      <clipPath id="pilo-logo-clip">
        <rect width="100" height="100" rx="24"/>
      </clipPath>
    </defs>

    <g clip-path="url(#pilo-logo-clip)">
      <!-- 4 quadrants pastel (= 4 moments du pilulier) -->
      <rect x="0"  y="0"  width="50" height="50" fill="#fde68a"/>
      <rect x="50" y="0"  width="50" height="50" fill="#bbf7d0"/>
      <rect x="0"  y="50" width="50" height="50" fill="#c7d2fe"/>
      <rect x="50" y="50" width="50" height="50" fill="#ddd6fe"/>

      <!-- Micro-pilule dans chaque case (couleur d'accent forte, contraste AA) -->
      <rect x="13"   y="20.5" width="24" height="9" rx="4.5" fill="#92400e"/>
      <rect x="63"   y="20.5" width="24" height="9" rx="4.5" fill="#14532d"/>
      <rect x="13"   y="70.5" width="24" height="9" rx="4.5" fill="#312e81"/>
      <rect x="63"   y="70.5" width="24" height="9" rx="4.5" fill="#4c1d95"/>

      <!-- Séparateur croix blanche -->
      <rect x="47.5" y="0"  width="5" height="100" fill="white"/>
      <rect x="0"    y="47.5" width="100" height="5" fill="white"/>
    </g>

    <!-- Contour externe -->
    <rect width="100" height="100" rx="24" stroke="#e2e8f0" stroke-width="2"/>
  </svg>
</template>

<script>
export default { inheritAttrs: false }
</script>
