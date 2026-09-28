<script setup lang="ts">
import type { CleaningAssignee, CleaningTask, CleaningType, OccupiedPeriod } from '~/types/place'
import type { TemplateLine } from '~/utils/assistant/parser'
import { formatTemplateLine, parseTemplateLine } from '~/utils/assistant/parser'

// "Ménage" tab of a place: its cleanings; an administrator plans new ones and edits the checklist template
// (copied into each new cleaning, existing ones keep theirs), picks the assignee among the accounts (who then gets
// an e-mail with the secret link) and turns the e-mail notifications on or off.
const props = defineProps<{ placeId: string }>()
const api = useApi()
const toast = useToast()
const { isAdmin } = useAuth()

const { data: tasks, refresh } = await useAsyncData(`cleanings-${props.placeId}`, () => api<CleaningTask[]>(`/api/places/${props.placeId}/cleanings`), { default: () => [] })
const replace = (t: CleaningTask) => tasks.value = tasks.value.map(x => x.id === t.id ? t : x)
const typeItems = CLEANING_TYPES.map(t => ({ label: CLEANING_TYPE_LABEL[t]!, value: t }))

// Checklist template of the place, one per type of cleaning (a type without one uses the "Location" template).
const checklistType = ref<CleaningType>('rental')
// One point per line: "Label | synonyms, for, the, voice | photo: Area" (utils/assistant/parser.ts, parseTemplateLine).
const { data: checklist } = await useAsyncData(`cleaning-checklist-${props.placeId}`, () => api<TemplateLine[]>(`/api/places/${props.placeId}/cleaning-checklist`, { query: { type: checklistType.value, details: 1 } }), { default: () => [], watch: [checklistType] })
const toText = (lines: TemplateLine[]) => lines.map(formatTemplateLine).join('\n')
const checklistText = ref(toText(checklist.value))
watch(checklist, v => checklistText.value = toText(v))
async function saveChecklist() {
  try {
    const items = checklistText.value.split('\n').map(parseTemplateLine).filter(l => l !== null)
    checklist.value = await api<TemplateLine[]>(`/api/places/${props.placeId}/cleaning-checklist`, { method: 'PUT', query: { type: checklistType.value, details: 1 }, body: { items } })
    toast.add({ title: 'Checklist enregistrée', color: 'success' })
  }
  catch (error) {
    toast.add({ title: 'Non enregistrée', description: apiErrorMessage(error), color: 'error' })
  }
}

const { data: assignees } = await useAsyncData('cleaning-assignees', () => isAdmin.value ? api<CleaningAssignee[]>('/api/cleaning-assignees') : Promise.resolve([]), { default: () => [] })
// Reka's select refuses an empty value: "none" stands for no assignee.
const NONE = 'none'
const assigneeItems = computed(() => [{ label: 'Non attribué', value: NONE }, ...assignees.value.map(a => ({ label: a.name && a.name !== a.email ? `${a.name} (${a.email})` : a.email, value: a.id }))])

const NOTIFICATIONS = [
  { key: 'assignment', label: 'À l’attribution (à la personne, avec le lien)' },
  { key: 'late', label: 'Ménages en retard (chaque matin, aux admins)' },
  { key: 'summary', label: 'Bilan du jour (chaque soir, aux admins)' },
  { key: 'report', label: 'Compte rendu de chaque ménage terminé (aux admins)' },
] as const
const { data: notifications } = await useAsyncData('cleaning-settings', () => isAdmin.value ? api<Record<string, boolean>>('/api/cleaning-settings') : Promise.resolve({} as Record<string, boolean>), { default: (): Record<string, boolean> => ({}) })
async function setNotification(key: string, value: boolean) {
  try {
    notifications.value = await api<Record<string, boolean>>('/api/cleaning-settings', { method: 'PUT', body: { [key]: value } })
  }
  catch (error) {
    toast.add({ title: 'Non enregistré', description: apiErrorMessage(error), color: 'error' })
  }
}

// Default cost per type (cents), copied into new cleanings; stays of the place pushed by Rocket Host or a PMS.
const { data: costs } = await useAsyncData(`cleaning-costs-${props.placeId}`, () => api<Record<string, number | null>>(`/api/places/${props.placeId}/cleaning-costs`), { default: (): Record<string, number | null> => ({}) })
const costText = reactive<Record<string, string>>(Object.fromEntries(CLEANING_TYPES.map(t => [t, costs.value[t] != null ? String(costs.value[t]! / 100) : ''])))
async function saveCosts() {
  try {
    costs.value = await api<Record<string, number | null>>(`/api/places/${props.placeId}/cleaning-costs`, { method: 'PUT', body: Object.fromEntries(CLEANING_TYPES.map(t => [t, toCents(costText[t])])) })
    toast.add({ title: 'Coûts enregistrés', color: 'success' })
  }
  catch (error) {
    toast.add({ title: 'Non enregistrés', description: apiErrorMessage(error), color: 'error' })
  }
}
const { data: occupancy } = await useAsyncData(`occupancy-${props.placeId}`, () => api<OccupiedPeriod[]>(`/api/places/${props.placeId}/occupancy`), { default: () => [] })
const upcomingStays = computed(() => occupancy.value.filter(p => new Date(p.until) > new Date()).slice(0, 6))

const form = reactive({ label: 'Ménage', type: 'personal' as CleaningType, date: new Date().toLocaleDateString('sv-SE'), from: '11:00', until: '16:00', assigneeId: NONE, cost: '' })
async function create() {
  try {
    await api(`/api/places/${props.placeId}/cleanings`, {
      method: 'POST',
      body: {
        label: form.label,
        type: form.type,
        ...(form.cost.trim() !== '' ? { cost: toCents(form.cost) } : {}),
        scheduledAt: new Date(`${form.date}T${form.from}`).toISOString(),
        dueAt: form.until ? new Date(`${form.date}T${form.until}`).toISOString() : null,
        ...(form.assigneeId !== NONE ? { assigneeId: form.assigneeId } : {}),
      },
    })
    await refresh()
  }
  catch (error) {
    toast.add({ title: 'Non créé', description: apiErrorMessage(error), color: 'error' })
  }
}

async function reassign(task: CleaningTask, assigneeId: string) {
  try {
    replace(await api<CleaningTask>(`/api/cleanings/${task.id}`, { method: 'PATCH', body: { assigneeId: assigneeId === NONE ? null : assigneeId } }))
  }
  catch (error) {
    toast.add({ title: 'Non attribué', description: apiErrorMessage(error), color: 'error' })
  }
}

async function remove(task: CleaningTask) {
  try {
    await api(`/api/cleanings/${task.id}`, { method: 'DELETE' })
    await refresh()
  }
  catch (error) {
    toast.add({ title: 'Non supprimé', description: apiErrorMessage(error), color: 'error' })
  }
}
</script>

<template>
  <div class="grid gap-4 lg:grid-cols-3">
    <div class="space-y-3 lg:col-span-2">
      <div v-for="t in tasks" :key="t.id" class="flex items-start gap-2">
        <CleaningCard :task="t" :manage="isAdmin" class="flex-1" @updated="replace" />
        <div v-if="isAdmin" class="flex flex-col items-end gap-1">
          <USelect :model-value="t.assignee?.id ?? NONE" :items="assigneeItems" size="sm" class="w-44" aria-label="Personne" @update:model-value="v => reassign(t, String(v ?? NONE))" />
          <UButton icon="i-lucide-trash-2" color="error" variant="ghost" aria-label="Supprimer" @click="remove(t)" />
        </div>
      </div>
      <UCard v-if="!tasks.length"><p class="text-sm text-muted">Aucun ménage planifié pour ce lieu.</p></UCard>
    </div>
    <div v-if="isAdmin" class="space-y-4">
      <UCard>
        <template #header><b class="text-sm">Planifier un ménage</b></template>
        <div class="space-y-2">
          <UInput v-model="form.label" placeholder="Libellé" class="w-full" />
          <USelect v-model="form.type" :items="typeItems" class="w-full" aria-label="Type" />
          <UInput v-model="form.date" type="date" class="w-full" />
          <div class="flex gap-2">
            <UInput v-model="form.from" type="time" class="flex-1" />
            <UInput v-model="form.until" type="time" class="flex-1" />
          </div>
          <USelect v-model="form.assigneeId" :items="assigneeItems" placeholder="Personne (optionnel)" class="w-full" />
          <UInput v-model="form.cost" inputmode="decimal" placeholder="Coût en € (défaut du lieu)" class="w-full" />
          <UButton block icon="i-lucide-plus" label="Planifier" @click="create" />
        </div>
      </UCard>
      <UCard>
        <template #header><b class="text-sm">Checklist du lieu</b></template>
        <USelect v-model="checklistType" :items="typeItems" size="sm" class="mb-2 w-full" aria-label="Type de ménage" />
        <p class="mb-2 text-xs text-muted">Un point par ligne, recopiée dans chaque nouveau ménage de ce type (sans modèle, celui de « Location » sert).</p>
        <p class="mb-2 text-xs text-muted">Pour l’assistant vocal : <code>Salle de bain | sdb, douche | photo</code> (synonymes séparés par des virgules ; « photo » ou « photo: Chambre » demande une photo de la pièce en fin de ménage).</p>
        <UTextarea v-model="checklistText" :rows="8" class="w-full" />
        <UButton class="mt-2" block variant="soft" label="Enregistrer" @click="saveChecklist" />
      </UCard>
      <CleaningRecurrences :place-id="placeId" :assignee-items="assigneeItems" @generated="refresh()" />
      <UCard>
        <template #header><b class="text-sm">Coût par défaut</b></template>
        <div class="space-y-2">
          <UFormField v-for="t in CLEANING_TYPES" :key="t" :label="CLEANING_TYPE_LABEL[t]">
            <UInput v-model="costText[t]" inputmode="decimal" placeholder="€" class="w-full" />
          </UFormField>
        </div>
        <UButton class="mt-2" block variant="soft" label="Enregistrer" @click="saveCosts" />
      </UCard>
      <UCard>
        <template #header><b class="text-sm">Séjours (Rocket Host / PMS)</b></template>
        <p v-for="p in upcomingStays" :key="p.from" class="text-sm">{{ dayFr(p.from) }} {{ hourFr(p.from) }} → {{ dayFr(p.until) }} {{ hourFr(p.until) }}</p>
        <p v-if="!upcomingStays.length" class="text-xs text-muted">Aucun séjour à venir transmis. Un ménage personnel ou d’entretien pendant un séjour est signalé.</p>
      </UCard>
      <UCard>
        <template #header><b class="text-sm">E-mails (tous les lieux)</b></template>
        <div class="space-y-2">
          <USwitch v-for="n in NOTIFICATIONS" :key="n.key" :model-value="notifications[n.key] ?? true" :label="n.label" @update:model-value="v => setNotification(n.key, v)" />
        </div>
        <p class="mt-2 text-xs text-muted">Envoyés par Rocket Mailer ; sans configuration, rien ne part (mode démo).</p>
      </UCard>
    </div>
  </div>
</template>
