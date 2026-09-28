<script setup lang="ts">
import type { LinenType, LinenWash } from '~/types/linen'
import type { CleaningAssignee, Place } from '~/types/place'

// In-house washing (machine, séchage, pliage): planned by an administrator, assigned like a cleaning; marking it
// done moves its linen from dirty to clean.
useHead({ title: () => `Lavages · ${useAppConfig().rocket.name}` })
const api = useApi()
const toast = useToast()
const { isAdmin } = useAuth()
const today = new Date().toLocaleDateString('sv-SE')
const date = ref(today)
const mine = ref(!isAdmin.value)

const { data: washes, refresh } = await useAsyncData('linen-washes', () => api<LinenWash[]>('/api/linen/washes', { query: { date: date.value, mine: mine.value ? 1 : undefined } }), { default: () => [], watch: [date, mine] })
const { data: types } = await useAsyncData('linen-types', () => api<LinenType[]>('/api/linen/types'), { default: () => [] })
const names = computed(() => Object.fromEntries(types.value.map(t => [t.id, t.name])))
const { data: places } = await useAsyncData('places', () => api<Place[]>('/api/places'), { default: () => [] })
const { data: assignees } = await useAsyncData('assignees', () => isAdmin.value ? api<CleaningAssignee[]>('/api/cleaning-assignees') : Promise.resolve([]), { default: () => [] })

async function patch(w: LinenWash, body: Record<string, unknown>) {
  try {
    const updated = await api<LinenWash>(`/api/linen/washes/${w.id}`, { method: 'PATCH', body })
    washes.value = washes.value.map(x => x.id === w.id ? updated : x)
  }
  catch (error) {
    toast.add({ title: 'Non enregistré', description: apiErrorMessage(error), color: 'error' })
  }
}

const creating = ref(false)
const form = reactive({ placeId: places.value[0]?.id, label: 'Lessive', time: '14:00', assigneeId: '', lines: {} as Record<string, number> })
async function create() {
  try {
    await api('/api/linen/washes', { method: 'POST', body: {
      placeId: form.placeId, label: form.label, scheduledAt: new Date(`${date.value}T${form.time}:00`).toISOString(),
      assigneeId: form.assigneeId || null, lines: Object.entries(form.lines).filter(([, q]) => q > 0).map(([type, qty]) => ({ type, qty })),
    } })
    creating.value = false
    form.lines = {}
    await refresh()
  }
  catch (error) {
    toast.add({ title: 'Non créée', description: apiErrorMessage(error), color: 'error' })
  }
}
</script>

<template>
  <UDashboardPanel id="linge-lavages">
    <template #header>
      <UDashboardNavbar title="Lavages">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton v-if="isAdmin" icon="i-lucide-plus" label="Lessive" @click="creating = true" />
        </template>
      </UDashboardNavbar>
      <UDashboardToolbar>
        <div class="flex w-full flex-wrap items-center justify-between gap-2">
          <UInput v-model="date" type="date" size="sm" />
          <USwitch v-model="mine" label="Les miennes" />
        </div>
      </UDashboardToolbar>
    </template>
    <template #body>
      <div class="mx-auto max-w-2xl space-y-3">
        <UCard v-for="w in washes" :key="w.id">
          <div class="flex items-start justify-between gap-2">
            <div>
              <p class="font-semibold">{{ w.placeName }} · {{ w.label }}</p>
              <p class="text-sm text-muted">{{ dayFr(w.scheduledAt) }} {{ hourFr(w.scheduledAt) }} · {{ w.assignee?.name ?? 'Non attribuée' }}</p>
              <p class="text-xs text-muted">{{ linenLines(w.lines, names) }}</p>
            </div>
            <UBadge :color="CLEANING_STATUS_COLOR[w.status]" variant="subtle">{{ CLEANING_STATUS_LABEL[w.status] }}</UBadge>
          </div>
          <div class="mt-3 grid grid-cols-3 gap-2">
            <UButton
              v-for="(done, step) in w.steps" :key="step" block size="lg" :label="WASH_STEP_LABEL[step]" :icon="done ? 'i-lucide-check' : 'i-lucide-circle'"
              :variant="done ? 'solid' : 'outline'" :disabled="w.status === 'done'" @click="patch(w, { steps: { [step]: !done } })"
            />
          </div>
          <UButton v-if="w.status !== 'done'" class="mt-2" block size="xl" color="success" icon="i-lucide-check-check" label="Terminé : linge propre" @click="patch(w, { status: 'done' })" />
        </UCard>
        <UCard v-if="!washes.length">
          <p class="text-sm text-muted">Aucune lessive ce jour-là.</p>
        </UCard>
      </div>

      <UModal v-model:open="creating" title="Nouvelle lessive">
        <template #body>
          <div class="space-y-3">
            <USelect v-model="form.placeId" :items="places.map(p => ({ label: p.name, value: p.id }))" class="w-full" aria-label="Lieu" />
            <div class="flex gap-2">
              <UInput v-model="form.label" class="flex-1" aria-label="Libellé" />
              <UInput v-model="form.time" type="time" aria-label="Heure" />
            </div>
            <USelect v-model="form.assigneeId" :items="[{ label: 'Non attribuée', value: '' }, ...assignees.map(a => ({ label: a.name, value: a.id }))]" class="w-full" aria-label="Attribuée à" />
            <div class="divide-y divide-default">
              <LinenStepper v-for="t in types" :key="t.id" v-model="form.lines[t.id]" :label="t.name" />
            </div>
            <UButton block size="lg" label="Créer" @click="create" />
          </div>
        </template>
      </UModal>
    </template>
  </UDashboardPanel>
</template>
