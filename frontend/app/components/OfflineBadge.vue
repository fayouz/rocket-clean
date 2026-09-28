<script setup lang="ts">
// "Hors ligne" while the network is down or the page shows its last saved data; the number of changes waiting.
const props = defineProps<{ stale?: boolean }>()
const pwa = usePwa()
const show = computed(() => props.stale || !pwa.online.value || pwa.pending.value > 0)
</script>

<template>
  <UBadge v-if="show" color="warning" variant="subtle" icon="i-lucide-wifi-off">
    {{ pwa.online.value && !stale ? 'Envoi en attente' : 'Hors ligne' }}<span v-if="pwa.pending.value"> · {{ pwa.pending.value }} en attente</span>
  </UBadge>
</template>
