<template>
  <!--
    Logo Pilo : capsule pharmaceutique coupée en deux.
    Moitié gauche = ambre (matin), moitié droite = indigo (soir).
    Passe width/height ou class pour contrôler la taille.
  -->
  <svg viewBox="0 0 56 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" v-bind="$attrs">
    <!-- Moitié gauche (ambre) -->
    <path d="M14 2H28V22H14A10 10 0 0 1 14 2Z" fill="#fbbf24"/>
    <!-- Moitié droite (indigo) -->
    <path d="M28 2H42A10 10 0 0 1 42 22H28V2Z" fill="#818cf8"/>
    <!-- Séparateur blanc -->
    <line x1="28" y1="2" x2="28" y2="22" stroke="white" stroke-width="1.5"/>
    <!-- Contour capsule -->
    <path d="M14 2H42A10 10 0 0 1 42 22H14A10 10 0 0 1 14 2Z"
          stroke="#e2e8f0" stroke-width="1.5" fill="none"/>
  </svg>
</template>

<script>
export default { inheritAttrs: false }
</script>
