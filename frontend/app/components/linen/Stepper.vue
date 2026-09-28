<script setup lang="ts">
// Quantity with big − / + buttons (gloved hands, phone): v-model is the number.
const model = defineModel<number>({ default: 0 })
const props = withDefaults(defineProps<{ label: string, max?: number, hint?: string }>(), { max: 999, hint: undefined })
const set = (v: number) => model.value = Math.max(0, Math.min(props.max, v))
</script>

<template>
  <div class="flex min-h-14 items-center justify-between gap-3">
    <div class="min-w-0">
      <p class="truncate text-sm font-medium">{{ label }}</p>
      <p v-if="hint" class="text-xs text-muted">{{ hint }}</p>
    </div>
    <div class="flex shrink-0 items-center gap-2">
      <UButton size="xl" square variant="outline" icon="i-lucide-minus" :aria-label="`Moins de ${label}`" :disabled="model <= 0" @click="set(model - 1)" />
      <span class="w-10 text-center text-lg font-semibold tabular-nums">{{ model }}</span>
      <UButton size="xl" square variant="outline" icon="i-lucide-plus" :aria-label="`Plus de ${label}`" :disabled="model >= max" @click="set(model + 1)" />
    </div>
  </div>
</template>
