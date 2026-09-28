<script setup lang="ts">
import type { CleaningTask } from '~/types/place'

// "Mes ménages du jour": the cleanings of a day (and the late ones), made for a phone. By default only the
// signed-in user's; an administrator can show everyone's.
useHead({ title: () => `Ménage · ${useAppConfig().rocket.name}` })
const api = useApi()
const { isAdmin } = useAuth()
const route = useRoute()
const today = new Date().toLocaleDateString('sv-SE')
const date = computed({
  get: () => /^\d{4}-\d{2}-\d{2}$/.test(String(route.query.date)) ? String(route.query.date) : today,
  set: (v: string) => navigateTo({ query: { ...route.query, date: v === today ? undefined : v } }, { replace: true }),
})
const everyone = computed({
  get: () => route.query.all === '1',
  set: (v: boolean) => navigateTo({ query: { ...route.query, all: v ? '1' : undefined } }, { replace: true }),
})
const ALL = 'all'
const type = computed({
  get: () => CLEANING_TYPES.includes(route.query.type as typeof CLEANING_TYPES[number]) ? String(route.query.type) : ALL,
  set: (v: string) => navigateTo({ query: { ...route.query, type: v === ALL ? undefined : v } }, { replace: true }),
})
const typeItems = [{ label: 'Tous les types', value: ALL }, ...CLEANING_TYPES.map(t => ({ label: CLEANING_TYPE_LABEL[t]!, value: t }))]
const { data: tasks, refresh } = await useAsyncData('cleanings', () => api<CleaningTask[]>('/api/cleanings', { query: { date: date.value, mine: everyone.value ? undefined : 1, type: type.value === ALL ? undefined : type.value } }), { default: () => [], watch: [date, everyone, type] })
const replace = (t: CleaningTask) => tasks.value = tasks.value.map(x => x.id === t.id ? t : x)
const shift = (days: number) => {
  const d = new Date(`${date.value}T12:00:00`)
  d.setDate(d.getDate() + days)
  date.value = d.toLocaleDateString('sv-SE')
}
</script>

<template>
  <UDashboardPanel id="menage">
    <template #header>
      <UDashboardNavbar title="Ménage">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton icon="i-lucide-refresh-cw" variant="ghost" @click="refresh()" />
        </template>
      </UDashboardNavbar>
      <UDashboardToolbar>
        <div class="flex w-full flex-wrap items-center justify-between gap-2">
          <div class="flex items-center gap-1">
            <UButton icon="i-lucide-chevron-left" variant="ghost" @click="shift(-1)" />
            <UInput v-model="date" type="date" size="sm" />
            <UButton icon="i-lucide-chevron-right" variant="ghost" @click="shift(1)" />
            <UButton v-if="date !== today" size="sm" variant="link" label="Aujourd’hui" @click="date = today" />
          </div>
          <div class="flex items-center gap-3">
            <USelect v-model="type" :items="typeItems" size="sm" class="w-40" aria-label="Type" />
            <USwitch v-if="isAdmin" v-model="everyone" label="Tout le monde" />
          </div>
        </div>
      </UDashboardToolbar>
    </template>
    <template #body>
      <div class="mx-auto max-w-2xl space-y-3">
        <CleaningCard v-for="t in tasks" :key="t.id" :task="t" @updated="replace" />
        <UCard v-if="!tasks.length">
          <p class="text-sm text-muted">Aucun ménage {{ everyone ? '' : 'pour toi ' }}ce jour-là.</p>
        </UCard>
      </div>
    </template>
  </UDashboardPanel>
</template>
