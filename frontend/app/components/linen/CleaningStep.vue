<script setup lang="ts">
import type { CleaningLinenView } from '~/types/linen'

// "Linge" step of a cleaning: kits (or single pieces) removed from the beds and bathrooms, placed, found damaged.
// Sent as one report with a random key (idempotent): without network it is queued on the phone and sent later.
// With "token" it goes through the secret link (/api/public/cleaning/<token>/linen), else the signed-in API.
const props = defineProps<{ cleaningId: string, token?: string }>()
const api = useApi()
const config = useRuntimeConfig()
const toast = useToast()
const path = computed(() => props.token ? `/api/public/cleaning/${encodeURIComponent(props.token)}/linen` : `/api/cleanings/${props.cleaningId}/linen`)
const cacheKey = computed(() => `linen:${props.token ?? props.cleaningId}`)

const view = ref<CleaningLinenView | null>(null)
const call = <T,>(method: 'GET' | 'POST', body?: Record<string, unknown>) => props.token
  ? $fetch<T>(path.value, { baseURL: config.public.apiBase as string, method, body, headers: { Accept: 'application/json' } })
  : api<T>(path.value, { method, body })
onMounted(async () => {
  try {
    view.value = await call<CleaningLinenView>('GET')
    offlineSave(cacheKey.value, view.value)
  }
  catch {
    view.value = offlineLoad<CleaningLinenView>(cacheKey.value)
  }
})

const ACTIONS = [
  { key: 'removed', label: 'Retiré (sale)', icon: 'i-lucide-arrow-up-from-line' },
  { key: 'placed', label: 'Mis en place', icon: 'i-lucide-arrow-down-to-line' },
  { key: 'damaged', label: 'Abîmé', icon: 'i-lucide-triangle-alert' },
] as const
type Action = typeof ACTIONS[number]['key']
const action = ref<Action>('removed')
const pieces = ref(false)
const qty = reactive<Record<Action, Record<string, number>>>({ removed: {}, placed: {}, damaged: {} })
const total = computed(() => ACTIONS.reduce((n, a) => n + Object.values(qty[a.key]).reduce((x, y) => x + y, 0), 0))
const busy = ref(false)
const pending = ref(false)
const names = computed(() => Object.fromEntries((view.value?.types ?? []).map(t => [t.id, t.name])))
const kitIds = computed(() => new Set((view.value?.kits ?? []).map(k => k.id)))
const done = computed(() => {
  const sums: Record<string, Record<string, number>> = {}
  for (const m of view.value?.movements ?? []) {
    const a = m.to === 'dirty' ? 'removed' : m.to === 'damaged' ? 'damaged' : 'placed'
    sums[a] ??= {}
    sums[a]![m.typeName] = (sums[a]![m.typeName] ?? 0) + m.qty
  }
  return sums
})

async function send() {
  const body: Record<string, unknown> = { key: linenKey() }
  for (const a of ACTIONS) {
    body[a.key] = Object.entries(qty[a.key]).filter(([, n]) => n > 0).map(([id, n]) => kitIds.value.has(id) ? { kit: id, qty: n } : { type: id, qty: n })
  }
  busy.value = true
  try {
    view.value = await call<CleaningLinenView>('POST', body)
    offlineSave(cacheKey.value, view.value)
    toast.add({ title: 'Linge enregistré', color: 'success' })
    reset()
  }
  catch (error) {
    if (isNetworkError(error)) {
      offlineEnqueue({ kind: props.token ? 'public' : 'api', path: path.value, method: 'POST', body, key: `linen:${body.key}` })
      pending.value = true
      toast.add({ title: 'Hors ligne', description: 'Le linge sera envoyé au retour du réseau.', color: 'warning' })
      reset()
    }
    else {
      toast.add({ title: 'Non enregistré', description: apiErrorMessage(error), color: 'error' })
    }
  }
  finally {
    busy.value = false
  }
}
function reset() {
  for (const a of ACTIONS) qty[a.key] = {}
}
</script>

<template>
  <section>
    <h3 class="mb-2 text-sm font-semibold">Linge</h3>
    <p v-if="!view" class="text-xs text-muted">Chargement du linge…</p>
    <p v-else-if="!view.kits.length && !view.types.length" class="text-xs text-muted">Aucun linge suivi.</p>
    <div v-else class="space-y-3">
      <div class="grid grid-cols-3 gap-2">
        <UButton
          v-for="a in ACTIONS" :key="a.key" block size="lg" :icon="a.icon" :label="a.label"
          :color="a.key === 'damaged' ? 'error' : 'primary'" :variant="action === a.key ? 'solid' : 'outline'" @click="action = a.key"
        />
      </div>
      <div class="divide-y divide-default">
        <LinenStepper v-for="k in view.kits" :key="k.id" v-model="qty[action][k.id]" :label="k.name" :hint="linenLines(k.lines, names)" />
      </div>
      <UButton size="sm" variant="link" :icon="pieces ? 'i-lucide-chevron-up' : 'i-lucide-chevron-down'" :label="pieces ? 'Masquer les pièces' : 'Pièces à l’unité'" @click="pieces = !pieces" />
      <div v-if="pieces" class="divide-y divide-default">
        <LinenStepper v-for="t in view.types" :key="t.id" v-model="qty[action][t.id]" :label="t.name" />
      </div>
      <UButton block size="xl" icon="i-lucide-check" :label="total ? `Enregistrer (${total})` : 'Enregistrer'" :disabled="!total" :loading="busy" @click="send" />
      <UAlert v-if="pending" color="warning" variant="subtle" icon="i-lucide-cloud-off" description="Linge en attente d’envoi (hors ligne)." />
      <div v-if="view.movements.length" class="space-y-1 text-xs text-muted">
        <p v-for="a in ACTIONS.filter(x => done[x.key])" :key="a.key">
          <span class="font-medium">{{ a.label }} :</span> {{ Object.entries(done[a.key]!).map(([n, q]) => `${q} × ${n}`).join(', ') }}
        </p>
      </div>
    </div>
  </section>
</template>
