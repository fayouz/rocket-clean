<script setup lang="ts">
import type { LinenAlert, LinenArrival, LinenState, LinenSummary, LinenType } from '~/types/linen'

// Linen overview: alerts, then per place the linen by state (reserve / logement), clean kits against the par
// level, and whether the next arrivals have enough clean kits. Administrators can count the linen (inventory).
useHead({ title: () => `Linge · ${useAppConfig().rocket.name}` })
const api = useApi()
const toast = useToast()
const { isAdmin } = useAuth()
const route = useRoute()
const place = computed(() => typeof route.query.place === 'string' ? route.query.place : undefined)

const { data, refresh } = await useAsyncData('linen-overview', async () => {
  const [summaries, readiness, alerts, types] = await Promise.all([
    api<LinenSummary[]>('/api/linen/summary', { query: { place: place.value } }),
    api<{ placeId: string, arrivals: LinenArrival[] }[]>('/api/linen/readiness', { query: { place: place.value } }),
    api<LinenAlert[]>('/api/linen/alerts'),
    api<LinenType[]>('/api/linen/types'),
  ])
  return { summaries, readiness: Object.fromEntries(readiness.map(r => [r.placeId, r.arrivals])) as Record<string, LinenArrival[]>, alerts, types }
}, { default: () => ({ summaries: [], readiness: {} as Record<string, LinenArrival[]>, alerts: [], types: [] }), watch: [place] })

// Inventory: counted quantities of one state for each type.
const counting = ref<LinenSummary | null>(null)
const countState = ref<LinenState>('clean')
const counted = reactive<Record<string, number>>({})
watch([counting, countState], () => {
  for (const t of counting.value?.types ?? []) counted[t.typeId] = t.states[countState.value]
})
const saving = ref(false)
async function saveCount() {
  if (!counting.value) return
  saving.value = true
  try {
    await api(`/api/linen/places/${counting.value.placeId}/counts`, { method: 'PUT', body: Object.entries(counted).map(([type, qty]) => ({ type, state: countState.value, qty })) })
    counting.value = null
    await refresh()
    toast.add({ title: 'Inventaire enregistré', color: 'success' })
  }
  catch (error) {
    toast.add({ title: 'Non enregistré', description: apiErrorMessage(error), color: 'error' })
  }
  saving.value = false
}
</script>

<template>
  <UDashboardPanel id="linge">
    <template #header>
      <UDashboardNavbar title="Linge">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton v-if="place" size="sm" variant="ghost" label="Tous les lieux" to="/linge" />
          <UButton icon="i-lucide-refresh-cw" variant="ghost" aria-label="Actualiser" @click="refresh()" />
        </template>
      </UDashboardNavbar>
    </template>
    <template #body>
      <div class="mx-auto max-w-4xl space-y-4">
        <div v-if="data.alerts.length" class="space-y-2">
          <UAlert
            v-for="(a, i) in data.alerts" :key="i" :color="a.level" variant="subtle"
            :icon="a.type === 'kits' ? 'i-lucide-bed-double' : a.type === 'batch_overdue' ? 'i-lucide-truck' : 'i-lucide-shirt'"
            :title="a.placeName" :description="a.message" :actions="[{ label: 'Voir', to: a.link, variant: 'link' }]"
          />
        </div>

        <UCard v-for="s in data.summaries" :key="s.placeId">
          <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
              <NuxtLink :to="`/linge?place=${s.placeId}`" class="font-semibold">{{ s.placeName }}</NuxtLink>
              <UButton v-if="isAdmin" size="sm" variant="soft" icon="i-lucide-clipboard-list" label="Inventaire" @click="counting = s" />
            </div>
          </template>
          <div class="space-y-4">
            <div class="grid gap-3 sm:grid-cols-2">
              <div v-for="(states, loc) in s.byLocation" :key="loc">
                <p class="mb-1 text-xs font-medium text-muted">{{ LINEN_LOCATION_LABEL[loc] }}</p>
                <LinenStates :states="states" />
              </div>
            </div>
            <div v-if="s.kits.length">
              <p class="mb-1 text-xs font-medium text-muted">Kits propres / niveau cible</p>
              <div class="flex flex-wrap gap-2">
                <UBadge v-for="k in s.kits" :key="k.kitId" :color="k.cleanKits >= k.units * 2 ? 'success' : k.cleanKits >= k.units ? 'warning' : 'error'" variant="outline">
                  {{ k.kitName }} : {{ k.cleanKits }} propres · cible {{ k.parLevel }}
                </UBadge>
              </div>
            </div>
            <div v-if="data.readiness[s.placeId]?.length">
              <p class="mb-1 text-xs font-medium text-muted">Prochaines arrivées</p>
              <ul class="space-y-1">
                <li v-for="a in data.readiness[s.placeId]" :key="a.from" class="flex items-center justify-between gap-2 text-sm">
                  <span>{{ dayFr(a.from) }} <span class="text-muted">{{ a.kits.map(k => `${k.kitName} ${k.available}/${k.needed}`).join(' · ') }}</span></span>
                  <UBadge :color="READINESS_COLOR[a.status]" variant="subtle">{{ READINESS_LABEL[a.status] }}</UBadge>
                </li>
              </ul>
            </div>
            <details>
              <summary class="cursor-pointer text-xs text-muted">Détail par type</summary>
              <div class="mt-2 space-y-2">
                <div v-for="t in s.types" :key="t.typeId" class="flex flex-wrap items-center justify-between gap-2">
                  <span class="text-sm">{{ t.name }}</span>
                  <LinenStates :states="t.states" />
                </div>
              </div>
            </details>
          </div>
        </UCard>
        <UCard v-if="!data.summaries.length">
          <p class="text-sm text-muted">Aucun linge suivi. Commence par les <NuxtLink to="/linge/kits" class="text-primary">kits et besoins</NuxtLink>.</p>
        </UCard>
      </div>

      <UModal :open="counting !== null" :title="`Inventaire · ${counting?.placeName ?? ''}`" @update:open="v => !v && (counting = null)">
        <template #body>
          <div class="space-y-3">
            <USelect v-model="countState" :items="LINEN_STATES.map(s => ({ label: LINEN_STATE_LABEL[s], value: s }))" class="w-full" aria-label="État compté" />
            <div class="divide-y divide-default">
              <LinenStepper v-for="t in data.types" :key="t.id" v-model="counted[t.id]" :label="t.name" :max="100000" />
            </div>
            <UButton block size="lg" label="Enregistrer l’inventaire" :loading="saving" @click="saveCount" />
          </div>
        </template>
      </UModal>
    </template>
  </UDashboardPanel>
</template>
