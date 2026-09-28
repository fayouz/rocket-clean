<script setup lang="ts">
import type { Laundry, LinenBatch, LinenKit, LinenType } from '~/types/linen'
import type { Place } from '~/types/place'

// Laundry provider: send a batch (counted with big buttons), count its return (the rest is flagged missing), the
// drop-off e-mail (preview, then explicit sending), costs by usage, and linen to replace through Rocket Stock.
useHead({ title: () => `Blanchisserie · ${useAppConfig().rocket.name}` })
const api = useApi()
const toast = useToast()
const { isAdmin } = useAuth()

const { data, refresh } = await useAsyncData('linen-laundry', async () => {
  const [laundries, batches, types, kits, places, costs, replacements] = await Promise.all([
    api<Laundry[]>('/api/linen/laundries'), api<LinenBatch[]>('/api/linen/batches'), api<LinenType[]>('/api/linen/types'), api<LinenKit[]>('/api/linen/kits'),
    api<Place[]>('/api/places'), api<{ rows: unknown[], totals: { rental: number, personal: number, total: number } }>('/api/linen/costs'),
    api<{ stockConfigured: boolean, items: { placeId: string, placeName: string, typeId: string, typeName: string, lost: number, damaged: number, stockItemId: string | null }[] }>('/api/linen/replacements'),
  ])
  return { laundries, batches, types, kits, places, costs, replacements }
}, { default: () => ({ laundries: [], batches: [], types: [], kits: [], places: [], costs: { rows: [], totals: { rental: 0, personal: 0, total: 0 } }, replacements: { stockConfigured: false, items: [] } }) })
const names = computed(() => Object.fromEntries(data.value.types.map(t => [t.id, t.name])))
const open = computed(() => data.value.batches.filter(b => b.status === 'sent'))
const done = computed(() => data.value.batches.filter(b => b.status === 'returned').slice(0, 10))

async function run<T>(action: () => Promise<T>, title: string): Promise<T | null> {
  try {
    const result = await action()
    toast.add({ title, color: 'success' })
    await refresh()
    return result
  }
  catch (error) {
    toast.add({ title: 'Non enregistré', description: apiErrorMessage(error), color: 'error' })
    return null
  }
}

// Sending a batch.
const sending = ref(false)
const send = reactive({ laundryId: '', placeId: '', usage: 'rental', weightKg: '', note: '', qty: {} as Record<string, number> })
function startSend() {
  Object.assign(send, { laundryId: data.value.laundries[0]?.id ?? '', placeId: data.value.places[0]?.id ?? '', usage: 'rental', weightKg: '', note: '', qty: {} })
  sending.value = true
}
async function confirmSend() {
  const kitIds = new Set(data.value.kits.map(k => k.id))
  const lines = Object.entries(send.qty).filter(([, q]) => q > 0).map(([id, qty]) => kitIds.has(id) ? { kit: id, qty } : { type: id, qty })
  const batch = await run(() => api<LinenBatch>('/api/linen/batches', { method: 'POST', body: { laundryId: send.laundryId, placeId: send.placeId, usage: send.usage, note: send.note || undefined, weightKg: send.weightKg ? Number(send.weightKg.replace(',', '.')) : undefined, lines } }), 'Lot envoyé')
  if (batch) {
    sending.value = false
    if (isAdmin.value) await preview(batch)
  }
}

// Counting a return: prefilled with what was sent.
const returning = ref<LinenBatch | null>(null)
const back = reactive({ returned: {} as Record<string, number>, damaged: {} as Record<string, number>, weightKg: '' })
function startReturn(b: LinenBatch) {
  back.returned = Object.fromEntries(b.sentLines.map(l => [l.typeId, l.qty]))
  back.damaged = {}
  back.weightKg = ''
  returning.value = b
}
async function confirmReturn() {
  const b = returning.value!
  const lines = (m: Record<string, number>) => Object.entries(m).filter(([, q]) => q > 0).map(([type, qty]) => ({ type, qty }))
  if (await run(() => api(`/api/linen/batches/${b.id}/return`, { method: 'POST', body: { returned: lines(back.returned), damaged: lines(back.damaged), weightKg: back.weightKg ? Number(back.weightKg.replace(',', '.')) : undefined } }), 'Retour enregistré')) returning.value = null
}

// Drop-off e-mail: preview first, then sent only on explicit confirmation.
const email = ref<{ batch: LinenBatch, to: string[], subject: string, htmlBody: string, alreadySent: boolean } | null>(null)
async function preview(b: LinenBatch) {
  email.value = { batch: b, ...await api<{ to: string[], subject: string, htmlBody: string, alreadySent: boolean }>(`/api/linen/batches/${b.id}/email`) }
}
async function sendEmail() {
  if (await run(() => api(`/api/linen/batches/${email.value!.batch.id}/email`, { method: 'POST', body: { confirm: true } }), 'E-mail envoyé')) email.value = null
}

// Laundry providers.
const laundryForm = ref<Omit<Partial<Laundry>, 'orderEmail'> & { orderEmail?: string, priceEuros?: string } | null>(null)
async function saveLaundry() {
  const f = laundryForm.value!
  const body = { name: f.name, orderEmail: f.orderEmail || null, pricing: f.pricing, pricePerKg: toCents(f.priceEuros ?? ''), turnaroundDays: Number(f.turnaroundDays ?? 3) }
  if (await run(() => api(f.id ? `/api/linen/laundries/${f.id}` : '/api/linen/laundries', { method: f.id ? 'PATCH' : 'POST', body }), 'Blanchisserie enregistrée')) laundryForm.value = null
}

async function replace(item: { placeId: string, typeId: string, lost: number, damaged: number }) {
  if (!confirm('Enregistrer la sortie dans Rocket Stock pour que ce linge apparaisse dans le prochain panier d’achat ?')) return
  await run(() => api('/api/linen/replacements', { method: 'POST', body: { placeId: item.placeId, type: item.typeId, qty: item.lost + item.damaged, key: linenKey() } }), 'Ajouté à Rocket Stock')
}
const config = useRuntimeConfig()
const auth = useAuth()
async function exportCsv() {
  const csv = await $fetch<string>('/api/linen/costs', { baseURL: config.public.apiBase as string, query: { format: 'csv' }, headers: { Authorization: auth.authorizationHeader() ?? '' }, responseType: 'text' })
  const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }))
  const a = document.createElement('a')
  a.href = url
  a.download = 'linge-couts.csv'
  a.click()
  URL.revokeObjectURL(url)
}
</script>

<template>
  <UDashboardPanel id="linge-blanchisserie">
    <template #header>
      <UDashboardNavbar title="Blanchisserie">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton icon="i-lucide-truck" label="Envoyer un lot" :disabled="!data.laundries.length" @click="startSend" />
        </template>
      </UDashboardNavbar>
    </template>
    <template #body>
      <div class="mx-auto max-w-4xl space-y-4">
        <UCard>
          <template #header><h2 class="font-semibold">Lots en cours</h2></template>
          <div class="space-y-3">
            <div v-for="b in open" :key="b.id" class="flex flex-wrap items-center justify-between gap-2 border-b border-default pb-3">
              <div>
                <p class="font-medium">{{ b.placeName }} → {{ b.laundry.name }}</p>
                <p class="text-xs text-muted">Envoyé {{ dayFr(b.sentAt) }} · attendu {{ dayFr(b.expectedAt) }}<span v-if="b.weightGrams"> · {{ (b.weightGrams / 1000).toFixed(1) }} kg</span> · {{ LINEN_USAGE_LABEL[b.usage] }}</p>
                <p class="text-xs text-muted">{{ linenLines(b.sentLines, names) }}</p>
              </div>
              <div class="flex flex-wrap items-center gap-2">
                <UBadge v-if="b.overdue" color="error" variant="solid">En retard</UBadge>
                <UButton v-if="isAdmin" size="sm" variant="ghost" icon="i-lucide-mail" :label="b.emailSentAt ? 'E-mail envoyé' : 'E-mail'" @click="preview(b)" />
                <UButton size="lg" icon="i-lucide-package-check" label="Compter le retour" @click="startReturn(b)" />
              </div>
            </div>
            <p v-if="!open.length" class="text-sm text-muted">Aucun lot en blanchisserie.</p>
          </div>
        </UCard>

        <UCard v-if="done.length">
          <template #header><h2 class="font-semibold">Derniers retours</h2></template>
          <ul class="divide-y divide-default">
            <li v-for="b in done" :key="b.id" class="py-2">
              <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="text-sm">{{ b.placeName }} · {{ b.laundry.name }} · {{ dayFr(b.returnedAt!) }}</span>
                <span class="text-sm font-medium">{{ euros(b.cost) }}</span>
              </div>
              <p v-for="d in b.discrepancies" :key="d.typeId" class="text-xs text-error">{{ d.typeName }} : <span v-if="d.missing">{{ d.missing }} manquant(s)</span><span v-if="d.missing && d.damaged"> · </span><span v-if="d.damaged">{{ d.damaged }} abîmé(s)</span></p>
            </li>
          </ul>
        </UCard>

        <div class="grid gap-4 sm:grid-cols-2">
          <UCard>
            <template #header>
              <div class="flex items-center justify-between"><h2 class="font-semibold">Coûts du mois</h2><UButton size="sm" variant="ghost" icon="i-lucide-download" label="CSV" @click="exportCsv" /></div>
            </template>
            <p class="text-sm">Location : <b>{{ euros(data.costs.totals.rental) }}</b></p>
            <p class="text-sm">Personnel : <b>{{ euros(data.costs.totals.personal) }}</b></p>
            <p class="text-sm text-muted">Total : {{ euros(data.costs.totals.total) }}</p>
          </UCard>
          <UCard>
            <template #header><h2 class="font-semibold">À remplacer</h2></template>
            <ul class="space-y-2">
              <li v-for="r in data.replacements.items" :key="r.placeId + r.typeId" class="flex items-center justify-between gap-2 text-sm">
                <span>{{ r.placeName }} · {{ r.typeName }} <span class="text-muted">({{ r.lost }} perdu(s), {{ r.damaged }} abîmé(s))</span></span>
                <UButton v-if="isAdmin && data.replacements.stockConfigured && r.stockItemId" size="sm" variant="soft" icon="i-lucide-shopping-cart" label="Rocket Stock" @click="replace(r)" />
              </li>
            </ul>
            <p v-if="!data.replacements.items.length" class="text-sm text-muted">Rien à remplacer.</p>
            <p v-else-if="!data.replacements.stockConfigured" class="mt-2 text-xs text-muted">Rocket Stock n’est pas configuré : rachat à noter à la main.</p>
          </UCard>
        </div>

        <UCard>
          <template #header>
            <div class="flex items-center justify-between">
              <h2 class="font-semibold">Blanchisseries</h2>
              <UButton v-if="isAdmin" size="sm" icon="i-lucide-plus" label="Ajouter" @click="laundryForm = { pricing: 'kg', turnaroundDays: 3 }" />
            </div>
          </template>
          <ul class="divide-y divide-default">
            <li v-for="l in data.laundries" :key="l.id" class="flex items-center justify-between gap-2 py-2">
              <div>
                <p>{{ l.name }}</p>
                <p class="text-xs text-muted">{{ l.orderEmail ?? 'sans e-mail' }} · {{ l.pricing === 'kg' ? `${euros(l.pricePerKg)} / kg` : 'à la pièce' }} · {{ l.turnaroundDays }} j</p>
              </div>
              <UButton v-if="isAdmin" size="sm" variant="ghost" icon="i-lucide-pencil" aria-label="Modifier" @click="laundryForm = { ...l, orderEmail: l.orderEmail ?? '', priceEuros: l.pricePerKg === null ? '' : String(l.pricePerKg / 100) }" />
            </li>
          </ul>
          <p v-if="!data.laundries.length" class="text-sm text-muted">Aucune blanchisserie.</p>
        </UCard>
      </div>

      <UModal v-model:open="sending" title="Envoyer un lot">
        <template #body>
          <div class="space-y-3">
            <USelect v-model="send.laundryId" :items="data.laundries.map(l => ({ label: l.name, value: l.id }))" class="w-full" aria-label="Blanchisserie" />
            <USelect v-model="send.placeId" :items="data.places.map(p => ({ label: p.name, value: p.id }))" class="w-full" aria-label="Lieu" />
            <div class="flex gap-2">
              <USelect v-model="send.usage" :items="[{ label: 'Location', value: 'rental' }, { label: 'Personnel', value: 'personal' }]" class="flex-1" aria-label="Usage" />
              <UInput v-model="send.weightKg" inputmode="decimal" placeholder="Poids (kg)" class="w-32" aria-label="Poids en kg" />
            </div>
            <p class="text-xs font-medium text-muted">Kits</p>
            <LinenStepper v-for="k in data.kits" :key="k.id" v-model="send.qty[k.id]" :label="k.name" :hint="linenLines(k.lines, names)" />
            <p class="text-xs font-medium text-muted">Pièces</p>
            <LinenStepper v-for="t in data.types" :key="t.id" v-model="send.qty[t.id]" :label="t.name" />
            <UTextarea v-model="send.note" :rows="2" class="w-full" placeholder="Note pour la blanchisserie" />
            <UButton block size="xl" icon="i-lucide-truck" label="Envoyer" @click="confirmSend" />
          </div>
        </template>
      </UModal>

      <UModal :open="returning !== null" title="Compter le retour" @update:open="v => !v && (returning = null)">
        <template #body>
          <div v-if="returning" class="space-y-3">
            <p class="text-xs text-muted">Ce qui ne revient pas (ni propre, ni abîmé) est compté manquant.</p>
            <div v-for="l in returning.sentLines" :key="l.typeId" class="rounded-md border border-default p-2">
              <p class="text-sm font-semibold">{{ names[l.typeId] }} <span class="font-normal text-muted">({{ l.qty }} envoyés)</span></p>
              <LinenStepper v-model="back.returned[l.typeId]" label="Propres" :max="l.qty - (back.damaged[l.typeId] ?? 0)" />
              <LinenStepper v-model="back.damaged[l.typeId]" label="Abîmés" :max="l.qty - (back.returned[l.typeId] ?? 0)" />
              <p v-if="l.qty - (back.returned[l.typeId] ?? 0) - (back.damaged[l.typeId] ?? 0) > 0" class="text-xs text-error">{{ l.qty - (back.returned[l.typeId] ?? 0) - (back.damaged[l.typeId] ?? 0) }} manquant(s)</p>
            </div>
            <UInput v-model="back.weightKg" inputmode="decimal" placeholder="Poids facturé (kg, optionnel)" class="w-full" aria-label="Poids facturé" />
            <UButton block size="xl" icon="i-lucide-package-check" label="Valider le retour" @click="confirmReturn" />
          </div>
        </template>
      </UModal>

      <UModal :open="email !== null" title="E-mail de dépôt" @update:open="v => !v && (email = null)">
        <template #body>
          <div v-if="email" class="space-y-3">
            <p class="text-sm"><b>À :</b> {{ email.to.join(', ') || 'aucune adresse' }}</p>
            <p class="text-sm"><b>Objet :</b> {{ email.subject }}</p>
            <!-- eslint-disable-next-line vue/no-v-html -- built by the API from escaped values -->
            <div class="rounded-md border border-default p-3 text-sm" v-html="email.htmlBody" />
            <UAlert v-if="email.alreadySent" color="success" variant="subtle" description="Déjà envoyé pour ce lot." />
            <UButton v-else block size="lg" icon="i-lucide-send" label="Envoyer cet e-mail" :disabled="!email.to.length" @click="sendEmail" />
          </div>
        </template>
      </UModal>

      <UModal :open="laundryForm !== null" title="Blanchisserie" @update:open="v => !v && (laundryForm = null)">
        <template #body>
          <div v-if="laundryForm" class="space-y-3">
            <UFormField label="Nom"><UInput v-model="laundryForm.name" class="w-full" /></UFormField>
            <UFormField label="E-mail de commande"><UInput v-model="laundryForm.orderEmail" type="email" class="w-full" /></UFormField>
            <div class="flex gap-2">
              <UFormField label="Tarif"><USelect v-model="laundryForm.pricing" :items="[{ label: 'Au kg', value: 'kg' }, { label: 'À la pièce', value: 'piece' }]" /></UFormField>
              <UFormField v-if="laundryForm.pricing === 'kg'" label="€ / kg"><UInput v-model="laundryForm.priceEuros" inputmode="decimal" class="w-24" /></UFormField>
              <UFormField label="Délai (j)"><UInput v-model="laundryForm.turnaroundDays" type="number" min="0" class="w-20" /></UFormField>
            </div>
            <UButton block size="lg" label="Enregistrer" @click="saveLaundry" />
          </div>
        </template>
      </UModal>
    </template>
  </UDashboardPanel>
</template>
