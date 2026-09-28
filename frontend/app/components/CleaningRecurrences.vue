<script setup lang="ts">
import type { CleaningRecurrence, CleaningType } from '~/types/place'

// Recurring cleanings of a place (administrators): weekly on some days, or monthly (a day of the month or the n-th
// weekday). Saving generates the cleanings of the next days at once; the worker keeps them 14 days ahead.
const props = defineProps<{ placeId: string, assigneeItems: { label: string, value: string }[] }>()
const emit = defineEmits<{ generated: [] }>()
const api = useApi()
const toast = useToast()
const NONE = 'none'

const { data: recurrences, refresh } = await useAsyncData(`recurrences-${props.placeId}`, () => api<CleaningRecurrence[]>(`/api/places/${props.placeId}/recurrences`), { default: () => [] })
const typeItems = CLEANING_TYPES.map(t => ({ label: CLEANING_TYPE_LABEL[t]!, value: t }))
const NTH = [{ label: '1er', value: 1 }, { label: '2e', value: 2 }, { label: '3e', value: 3 }, { label: '4e', value: 4 }, { label: 'Dernier', value: -1 }]
const weekdayItems = WEEKDAY_LABEL.slice(1).map((l, i) => ({ label: l, value: i + 1 }))

const form = reactive({ label: 'Ménage', type: 'personal' as CleaningType, frequency: 'weekly' as 'weekly' | 'monthly', weekdays: [1] as number[], monthlyMode: 'nth' as 'nth' | 'day', monthDay: 1, nth: 1, nthWeekday: 6, time: '10:00', durationMinutes: 120, assigneeId: NONE, cost: '' })

function describe(r: CleaningRecurrence) {
  const when = r.frequency === 'weekly'
    ? r.weekdays.map(d => WEEKDAY_LABEL[d]).join(', ')
    : r.monthDay ? `le ${r.monthDay} du mois` : `${NTH.find(n => n.value === r.nth)?.label} ${WEEKDAY_LABEL[r.nthWeekday ?? 1]} du mois`
  return `${when} à ${r.time} · ${r.durationMinutes} min${r.assignee ? ` · ${r.assignee.name}` : ''}${r.cost !== null ? ` · ${euros(r.cost)}` : ''}`
}

async function create() {
  try {
    await api(`/api/places/${props.placeId}/recurrences`, {
      method: 'POST',
      body: {
        label: form.label, type: form.type, frequency: form.frequency, time: form.time, durationMinutes: form.durationMinutes,
        ...(form.frequency === 'weekly' ? { weekdays: form.weekdays } : form.monthlyMode === 'day' ? { monthDay: form.monthDay } : { nth: form.nth, nthWeekday: form.nthWeekday }),
        ...(form.assigneeId !== NONE ? { assigneeId: form.assigneeId } : {}),
        ...(form.cost.trim() !== '' ? { cost: toCents(form.cost) } : {}),
      },
    })
    await refresh()
    emit('generated')
  }
  catch (error) {
    toast.add({ title: 'Récurrence non créée', description: apiErrorMessage(error), color: 'error' })
  }
}

async function toggle(r: CleaningRecurrence, active: boolean) {
  try {
    await api(`/api/recurrences/${r.id}`, { method: 'PATCH', body: { active } })
    await refresh()
    emit('generated')
  }
  catch (error) {
    toast.add({ title: 'Non enregistré', description: apiErrorMessage(error), color: 'error' })
  }
}

async function remove(r: CleaningRecurrence) {
  try {
    await api(`/api/recurrences/${r.id}`, { method: 'DELETE' })
    await refresh()
    emit('generated')
  }
  catch (error) {
    toast.add({ title: 'Non supprimée', description: apiErrorMessage(error), color: 'error' })
  }
}
</script>

<template>
  <UCard>
    <template #header><b class="text-sm">Récurrences</b></template>
    <div class="space-y-3">
      <div v-for="r in recurrences" :key="r.id" class="flex items-start justify-between gap-2">
        <div>
          <p class="text-sm font-medium">{{ r.label }} <UBadge :color="CLEANING_TYPE_COLOR[r.type]" variant="outline" size="sm">{{ CLEANING_TYPE_LABEL[r.type] }}</UBadge></p>
          <p class="text-xs text-muted">{{ describe(r) }}</p>
        </div>
        <div class="flex items-center gap-1">
          <USwitch :model-value="r.active" aria-label="Active" @update:model-value="v => toggle(r, v)" />
          <UButton icon="i-lucide-trash-2" color="error" variant="ghost" size="sm" aria-label="Supprimer" @click="remove(r)" />
        </div>
      </div>
      <p v-if="!recurrences.length" class="text-xs text-muted">Aucune récurrence.</p>

      <USeparator />
      <UInput v-model="form.label" placeholder="Libellé" class="w-full" />
      <USelect v-model="form.type" :items="typeItems" class="w-full" aria-label="Type" />
      <USelect v-model="form.frequency" :items="[{ label: 'Chaque semaine', value: 'weekly' }, { label: 'Chaque mois', value: 'monthly' }]" class="w-full" aria-label="Fréquence" />
      <USelect v-if="form.frequency === 'weekly'" v-model="form.weekdays" :items="weekdayItems" multiple class="w-full" aria-label="Jours" />
      <template v-else>
        <USelect v-model="form.monthlyMode" :items="[{ label: 'Tel jour de la semaine', value: 'nth' }, { label: 'Tel jour du mois', value: 'day' }]" class="w-full" aria-label="Règle mensuelle" />
        <div v-if="form.monthlyMode === 'nth'" class="flex gap-2">
          <USelect v-model="form.nth" :items="NTH" class="flex-1" aria-label="Rang" />
          <USelect v-model="form.nthWeekday" :items="weekdayItems" class="flex-1" aria-label="Jour" />
        </div>
        <UInput v-else v-model.number="form.monthDay" type="number" min="1" max="31" class="w-full" aria-label="Jour du mois" />
      </template>
      <div class="flex gap-2">
        <UInput v-model="form.time" type="time" class="flex-1" aria-label="Heure" />
        <UInput v-model.number="form.durationMinutes" type="number" min="15" step="15" class="flex-1" aria-label="Durée (min)" />
      </div>
      <USelect v-model="form.assigneeId" :items="assigneeItems" class="w-full" aria-label="Personne" />
      <UInput v-model="form.cost" inputmode="decimal" placeholder="Coût en € (défaut du lieu)" class="w-full" />
      <UButton block icon="i-lucide-repeat" label="Ajouter la récurrence" @click="create" />
    </div>
  </UCard>
</template>
