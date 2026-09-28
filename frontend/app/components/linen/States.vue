<script setup lang="ts">
import type { LinenStates } from '~/types/linen'

// Badges of the quantities by state (zeros hidden unless "all").
const props = defineProps<{ states: LinenStates, all?: boolean }>()
const shown = computed(() => LINEN_STATES.filter(s => props.all || props.states[s] > 0))
</script>

<template>
  <div class="flex flex-wrap gap-1">
    <UBadge v-for="s in shown" :key="s" :color="LINEN_STATE_COLOR[s]" variant="subtle">{{ LINEN_STATE_LABEL[s] }} {{ states[s] }}</UBadge>
    <span v-if="!shown.length" class="text-xs text-muted">Aucun linge compté.</span>
  </div>
</template>
