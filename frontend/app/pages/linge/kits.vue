<script setup lang="ts">
import type { LinenKit, LinenLine, LinenNeed, LinenType } from '~/types/linen'
import type { Place } from '~/types/place'

// Types of linen, kits (composition) and the needs of each place (kits per bed/bathroom and par level).
useHead({ title: () => `Kits et besoins · ${useAppConfig().rocket.name}` })
const api = useApi()
const toast = useToast()
const { isAdmin } = useAuth()

const { data: types, refresh: refreshTypes } = await useAsyncData('linen-types', () => api<LinenType[]>('/api/linen/types'), { default: () => [] })
const { data: kits, refresh: refreshKits } = await useAsyncData('linen-kits', () => api<LinenKit[]>('/api/linen/kits'), { default: () => [] })
const { data: places } = await useAsyncData('places', () => api<Place[]>('/api/places'), { default: () => [] })
const names = computed(() => Object.fromEntries(types.value.map(t => [t.id, t.name])))

async function run(action: () => Promise<unknown>, done: string) {
  try {
    await action()
    toast.add({ title: done, color: 'success' })
    return true
  }
  catch (error) {
    toast.add({ title: 'Non enregistré', description: apiErrorMessage(error), color: 'error' })
    return false
  }
}

// Types.
const typeForm = reactive({ name: '', weightGrams: '', stockItemId: '' })
async function addType() {
  if (await run(() => api('/api/linen/types', { method: 'POST', body: { name: typeForm.name, weightGrams: typeForm.weightGrams ? Number(typeForm.weightGrams) : null, stockItemId: typeForm.stockItemId || null, position: types.value.length } }), 'Type ajouté')) {
    Object.assign(typeForm, { name: '', weightGrams: '', stockItemId: '' })
    await refreshTypes()
  }
}
async function removeType(t: LinenType) {
  if (confirm(`Supprimer « ${t.name} » ?`) && await run(() => api(`/api/linen/types/${t.id}`, { method: 'DELETE' }), 'Type supprimé')) await refreshTypes()
}

// Kits.
const editing = ref<{ id?: string, name: string, lines: Record<string, number> } | null>(null)
const editKit = (k?: LinenKit) => editing.value = { id: k?.id, name: k?.name ?? '', lines: Object.fromEntries((k?.lines ?? []).map(l => [l.typeId, l.qty])) }
async function saveKit() {
  const e = editing.value!
  const lines: LinenLine[] = Object.entries(e.lines).filter(([, q]) => q > 0).map(([typeId, qty]) => ({ typeId, qty }))
  if (await run(() => api(e.id ? `/api/linen/kits/${e.id}` : '/api/linen/kits', { method: e.id ? 'PATCH' : 'POST', body: { name: e.name, lines } }), 'Kit enregistré')) {
    editing.value = null
    await refreshKits()
  }
}
async function removeKit(k: LinenKit) {
  if (confirm(`Supprimer « ${k.name} » (et des besoins des lieux) ?`) && await run(() => api(`/api/linen/kits/${k.id}`, { method: 'DELETE' }), 'Kit supprimé')) await refreshKits()
}

// Needs of a place.
const placeId = ref<string | undefined>(places.value[0]?.id)
const needs = ref<Record<string, { units: number, kitsPerUnit: number }>>({})
watch([placeId, kits], async () => {
  if (!placeId.value) return
  const list = await api<LinenNeed[]>(`/api/linen/places/${placeId.value}/needs`).catch(() => [])
  needs.value = Object.fromEntries(kits.value.map(k => [k.id, { units: list.find(n => n.kitId === k.id)?.units ?? 0, kitsPerUnit: list.find(n => n.kitId === k.id)?.kitsPerUnit ?? 3 }]))
}, { immediate: true })
async function saveNeeds() {
  const body = Object.entries(needs.value).filter(([, n]) => n.units > 0).map(([kitId, n]) => ({ kitId, ...n }))
  await run(() => api(`/api/linen/places/${placeId.value}/needs`, { method: 'PUT', body }), 'Besoins enregistrés')
}
</script>

<template>
  <UDashboardPanel id="linge-kits">
    <template #header>
      <UDashboardNavbar title="Kits et besoins">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>
    </template>
    <template #body>
      <div class="mx-auto max-w-4xl space-y-4">
        <UCard>
          <template #header>
            <div class="flex items-center justify-between">
              <h2 class="font-semibold">Kits</h2>
              <UButton v-if="isAdmin" size="sm" icon="i-lucide-plus" label="Nouveau kit" @click="editKit()" />
            </div>
          </template>
          <ul class="divide-y divide-default">
            <li v-for="k in kits" :key="k.id" class="flex items-center justify-between gap-2 py-2">
              <div>
                <p class="font-medium">{{ k.name }}</p>
                <p class="text-xs text-muted">{{ linenLines(k.lines, names) }}</p>
              </div>
              <div v-if="isAdmin" class="flex gap-1">
                <UButton size="sm" variant="ghost" icon="i-lucide-pencil" aria-label="Modifier" @click="editKit(k)" />
                <UButton size="sm" variant="ghost" color="error" icon="i-lucide-trash-2" aria-label="Supprimer" @click="removeKit(k)" />
              </div>
            </li>
          </ul>
          <p v-if="!kits.length" class="text-sm text-muted">Aucun kit.</p>
        </UCard>

        <UCard v-if="places.length && kits.length">
          <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
              <h2 class="font-semibold">Besoins du lieu</h2>
              <USelect v-model="placeId" :items="places.map(p => ({ label: p.name, value: p.id }))" class="w-56" aria-label="Lieu" />
            </div>
          </template>
          <p class="mb-3 text-xs text-muted">Nombre de lits ou salles de bain qui utilisent chaque kit (un kit chacun par arrivée), et nombre de kits possédés par lit (niveau cible : en place, propre, au lavage).</p>
          <div v-for="k in kits" :key="k.id" class="grid gap-2 border-b border-default py-2 sm:grid-cols-2">
            <LinenStepper v-if="needs[k.id]" v-model="needs[k.id]!.units" :label="k.name" hint="lits / salles de bain" :max="50" />
            <LinenStepper v-if="needs[k.id]" v-model="needs[k.id]!.kitsPerUnit" label="Kits par lit" :hint="`cible ${needs[k.id]!.units * needs[k.id]!.kitsPerUnit}`" :max="10" />
          </div>
          <UButton v-if="isAdmin" class="mt-3" label="Enregistrer les besoins" @click="saveNeeds" />
        </UCard>

        <UCard>
          <template #header>
            <h2 class="font-semibold">Types de linge</h2>
          </template>
          <ul class="divide-y divide-default">
            <li v-for="t in types" :key="t.id" class="flex items-center justify-between gap-2 py-2">
              <div>
                <p>{{ t.name }}</p>
                <p class="text-xs text-muted"><span v-if="t.weightGrams">{{ t.weightGrams }} g</span><span v-if="t.stockItemId"> · lié à Rocket Stock</span></p>
              </div>
              <UButton v-if="isAdmin" size="sm" variant="ghost" color="error" icon="i-lucide-trash-2" aria-label="Supprimer" @click="removeType(t)" />
            </li>
          </ul>
          <form v-if="isAdmin" class="mt-3 flex flex-wrap items-end gap-2" @submit.prevent="addType">
            <UFormField label="Nom"><UInput v-model="typeForm.name" required placeholder="Drap housse" /></UFormField>
            <UFormField label="Poids (g)"><UInput v-model="typeForm.weightGrams" type="number" min="1" class="w-28" /></UFormField>
            <UFormField label="Article Rocket Stock (id)"><UInput v-model="typeForm.stockItemId" placeholder="optionnel" /></UFormField>
            <UButton type="submit" icon="i-lucide-plus" label="Ajouter" />
          </form>
        </UCard>
      </div>

      <UModal :open="editing !== null" :title="editing?.id ? 'Modifier le kit' : 'Nouveau kit'" @update:open="v => !v && (editing = null)">
        <template #body>
          <div v-if="editing" class="space-y-3">
            <UFormField label="Nom"><UInput v-model="editing.name" class="w-full" placeholder="Kit lit double" /></UFormField>
            <div class="divide-y divide-default">
              <LinenStepper v-for="t in types" :key="t.id" v-model="editing.lines[t.id]" :label="t.name" :max="20" />
            </div>
            <UButton block size="lg" label="Enregistrer" @click="saveKit" />
          </div>
        </template>
      </UModal>
    </template>
  </UDashboardPanel>
</template>
