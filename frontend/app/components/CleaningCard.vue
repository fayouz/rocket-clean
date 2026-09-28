<script setup lang="ts">
import type { CleaningTask, StockLine } from '~/types/place'

// One cleaning, made for a phone: status buttons, checklist, photos (before/after/damage, straight from the camera,
// with thumbnails and a lightbox), stock levels of the place and notes. Every change is saved at once and the updated
// task is emitted. With "token" it works through the secret link without account (/api/public/cleaning/<token>);
// with "manage" (administrator) it also shows the secret link to copy or regenerate.
const props = defineProps<{ task: CleaningTask, token?: string, manage?: boolean, startOpen?: boolean }>()
const emit = defineEmits<{ updated: [task: CleaningTask] }>()
const api = useApi()
const auth = useAuth()
const config = useRuntimeConfig()
const toast = useToast()
const open = ref(props.startOpen ?? false)
const busy = ref(false)
const notes = ref(props.task.notes ?? '')
watch(() => props.task.notes, v => notes.value = v ?? '')

const doneCount = computed(() => props.task.checklist.filter(c => c.done).length)

// Stock levels of the place (Rocket Place's; none standalone): given by the public view, else loaded when the card opens.
const loaded = ref<StockLine[]>([])
const levels = computed<StockLine[]>(() => props.task.stock ?? loaded.value)
watch(open, async (v) => {
  if (v && !props.token && !loaded.value.length) {
    loaded.value = await api<StockLine[]>(`/api/places/${props.task.placeId}/stock`).catch(() => [])
  }
}, { immediate: true })

const base = computed(() => props.token ? `/api/public/cleaning/${encodeURIComponent(props.token)}` : `/api/cleanings/${props.task.id}`)
async function save(path: string, body: Record<string, unknown> | FormData, method: 'PATCH' | 'POST' = 'PATCH') {
  busy.value = true
  try {
    emit('updated', props.token
      ? await $fetch<CleaningTask>(`${base.value}${path}`, { baseURL: config.public.apiBase as string, method, body, headers: { Accept: 'application/json' } })
      : await api<CleaningTask>(`${base.value}${path}`, { method, body }))
  }
  catch (error) {
    // Secret link without network: the change is shown at once, kept on the phone and sent when back online.
    if (props.token && !(body instanceof FormData) && isNetworkError(error)) keepOffline(path, body, method)
    else toast.add({ title: 'Non enregistré', description: apiErrorMessage(error), color: 'error' })
  }
  finally {
    busy.value = false
  }
}

// Offline change (public page only: status, checklist point, notes, stock level), all idempotent: they set a value.
function keepOffline(path: string, body: Record<string, unknown>, method: 'PATCH' | 'POST') {
  const task = JSON.parse(JSON.stringify(props.task)) as CleaningTask
  let key = `${task.id}:${path}`
  if (typeof body.status === 'string') {
    task.status = body.status as CleaningTask['status']
    key += ':status'
  }
  if (typeof body.notes === 'string') {
    task.notes = body.notes
    key += ':notes'
  }
  for (const c of (body.checklist ?? []) as { index: number, done: boolean }[]) {
    if (task.checklist[c.index]) task.checklist[c.index]!.done = c.done
    key += `:check${c.index}`
  }
  if (typeof body.stockLevelId === 'string') {
    const line = task.stock?.find(l => l.id === body.stockLevelId)
    if (line) line.level = body.level as StockLine['level']
    key += `:stock${body.stockLevelId}`
  }
  offlineEnqueue({ kind: 'public', path: `${base.value}${path}`, method, body, key })
  emit('updated', task)
}

const setStatus = (status: string) => save('', { status })
const check = (index: number, done: boolean) => save('', { checklist: [{ index, done }] })
const saveNotes = () => notes.value !== (props.task.notes ?? '') && save('', { notes: notes.value })

async function setStock(level: { id: string, level: string }, value: string) {
  await save('/stock', { stockLevelId: level.id, level: value }, 'POST')
  const l = loaded.value.find(x => x.id === level.id)
  if (l) l.level = value as StockLine['level']
}

// Photo thumbnails, loaded as blobs (the API needs the Authorization header, or the secret link), freed on unmount.
const thumbs = reactive<Record<string, string>>({})
const lightbox = ref<string | null>(null)
async function loadThumb(fileId: string) {
  if (thumbs[fileId]) return
  try {
    const url = `${base.value}/photos/${fileId}`
    const blob = await $fetch<Blob>(url, { baseURL: config.public.apiBase as string, responseType: 'blob', headers: props.token ? {} : { Authorization: auth.authorizationHeader() ?? '' } })
    thumbs[fileId] = URL.createObjectURL(blob.type ? blob : new Blob([blob], { type: 'image/jpeg' }))
  }
  catch {
    // Thumbnail unavailable (file removed from Rocket Cloud…): the name stays listed.
  }
}
watch([open, () => props.task.photos.length], ([v]) => {
  if (v) props.task.photos.forEach(p => loadThumb(p.fileId))
}, { immediate: true })
onBeforeUnmount(() => Object.values(thumbs).forEach(u => URL.revokeObjectURL(u)))

// Secret link (administrators): copy, or regenerate (the previous one stops working).
const link = ref<{ url: string, expiresAt: string } | null>(null)
async function getLink(regenerate = false) {
  try {
    link.value = await api<{ url: string, expiresAt: string }>(`/api/cleanings/${props.task.id}/link`, { method: regenerate ? 'POST' : 'GET' })
    await navigator.clipboard?.writeText(link.value.url).catch(() => null)
    toast.add({ title: regenerate ? 'Nouveau lien copié' : 'Lien copié', description: regenerate ? 'L’ancien lien ne fonctionne plus.' : undefined, color: 'success' })
  }
  catch (error) {
    toast.add({ title: 'Lien indisponible', description: apiErrorMessage(error), color: 'error' })
  }
}

async function upload(event: Event, moment: string) {
  const input = event.target as HTMLInputElement
  for (const file of Array.from(input.files ?? [])) {
    const form = new FormData()
    form.append('file', file)
    form.append('moment', moment)
    await save('/photos', form, 'POST')
  }
  input.value = ''
}
</script>

<template>
  <UCard :ui="{ body: 'p-3 sm:p-4' }">
    <button type="button" class="flex w-full items-start justify-between gap-3 text-left" @click="open = !open">
      <div>
        <p class="font-semibold">{{ task.placeName }}</p>
        <p class="text-sm text-muted">
          {{ task.label }} · {{ dayFr(task.scheduledAt) }} {{ hourFr(task.scheduledAt) }}<span v-if="task.dueAt"> → {{ hourFr(task.dueAt) }}</span>
        </p>
        <p class="text-xs text-muted">{{ task.assignee?.name ?? 'Non attribué' }}<span v-if="task.checklist.length"> · {{ doneCount }}/{{ task.checklist.length }} points</span><span v-if="task.photos.length"> · {{ task.photos.length }} photo(s)</span></p>
        <p v-if="!token && (task.origin !== 'clean' || task.cost !== null)" class="text-xs text-muted">
          <span v-if="task.origin !== 'clean'">Créé par {{ task.originApp ?? CLEANING_ORIGIN_LABEL[task.origin] }}</span><span v-if="task.origin !== 'clean' && task.cost !== null"> · </span><span v-if="task.cost !== null">{{ euros(task.cost) }}</span>
        </p>
      </div>
      <div class="flex shrink-0 flex-col items-end gap-1">
        <UBadge :color="CLEANING_STATUS_COLOR[task.status]" variant="subtle">{{ CLEANING_STATUS_LABEL[task.status] }}</UBadge>
        <UBadge v-if="task.type" :color="CLEANING_TYPE_COLOR[task.type]" variant="outline">{{ CLEANING_TYPE_LABEL[task.type] }}</UBadge>
        <UBadge v-if="task.late" color="error" variant="solid">En retard</UBadge>
        <UBadge v-if="task.conflict" color="warning" variant="solid" icon="i-lucide-calendar-x">Pendant un séjour</UBadge>
      </div>
    </button>

    <div v-if="open" class="mt-4 space-y-5">
      <div class="grid grid-cols-2 gap-2">
        <UButton v-if="task.status === 'todo'" block size="lg" icon="i-lucide-play" label="Commencer" :loading="busy" @click="setStatus('in_progress')" />
        <UButton v-if="task.status !== 'done'" block size="lg" color="success" icon="i-lucide-check" label="Terminé" :loading="busy" @click="setStatus('done')" />
        <UButton v-else block size="lg" variant="soft" icon="i-lucide-rotate-ccw" label="Rouvrir" :loading="busy" @click="setStatus('in_progress')" />
      </div>

      <section v-if="task.checklist.length">
        <h3 class="mb-2 text-sm font-semibold">Checklist</h3>
        <div class="space-y-1">
          <label v-for="(c, i) in task.checklist" :key="i" class="flex min-h-11 items-center gap-3 rounded-md px-2 hover:bg-elevated">
            <UCheckbox :model-value="c.done" :disabled="busy" @update:model-value="v => check(i, v === true)" />
            <span :class="c.done ? 'text-muted line-through' : ''">{{ c.label }}</span>
          </label>
        </div>
      </section>

      <section>
        <h3 class="mb-2 text-sm font-semibold">Photos</h3>
        <div class="grid grid-cols-3 gap-2">
          <label v-for="m in ['before', 'after', 'damage']" :key="m" class="cursor-pointer">
            <input type="file" accept="image/*" capture="environment" multiple class="hidden" @change="upload($event, m)">
            <span class="flex min-h-11 items-center justify-center gap-1 rounded-md border border-default text-sm" :class="m === 'damage' ? 'text-error' : ''">
              <UIcon :name="m === 'damage' ? 'i-lucide-triangle-alert' : 'i-lucide-camera'" /> {{ PHOTO_MOMENT_LABEL[m] }}
            </span>
          </label>
        </div>
        <div v-if="task.photos.length" class="mt-2 grid grid-cols-3 gap-2 sm:grid-cols-4">
          <button v-for="p in task.photos" :key="p.fileId" type="button" class="text-left" :disabled="!thumbs[p.fileId]" @click="lightbox = p.fileId">
            <img v-if="thumbs[p.fileId]" :src="thumbs[p.fileId]" :alt="p.name" class="aspect-square w-full rounded-md object-cover">
            <span v-else class="flex aspect-square w-full items-center justify-center rounded-md bg-elevated"><UIcon name="i-lucide-image" class="text-muted" /></span>
            <span class="block truncate text-xs" :class="p.moment === 'damage' ? 'text-error' : 'text-muted'">{{ PHOTO_MOMENT_LABEL[p.moment] }} · {{ whenFr(p.at) }}</span>
          </button>
        </div>
        <UModal :open="lightbox !== null" title="Photo" @update:open="v => !v && (lightbox = null)">
          <template #body>
            <img v-if="lightbox && thumbs[lightbox]" :src="thumbs[lightbox]" alt="Photo du ménage" class="max-h-[75vh] w-full object-contain">
          </template>
        </UModal>
      </section>

      <section>
        <h3 class="mb-2 text-sm font-semibold">Stock</h3>
        <div v-for="l in levels" :key="l.id" class="flex min-h-11 items-center justify-between gap-2">
          <span class="text-sm">{{ l.name }}</span>
          <div class="flex gap-1">
            <UButton
              v-for="lvl in ['ok', 'low', 'empty']" :key="lvl" size="sm" :color="l.level === lvl ? STOCK_LEVEL_COLOR[lvl] : 'neutral'"
              :variant="l.level === lvl ? 'solid' : 'outline'" :label="STOCK_LEVEL_LABEL[lvl]" :disabled="busy" @click="setStock(l, lvl)"
            />
          </div>
        </div>
        <p v-if="!levels.length" class="text-xs text-muted">Aucun article suivi pour ce lieu.</p>
      </section>

      <section>
        <h3 class="mb-2 text-sm font-semibold">Notes</h3>
        <UTextarea v-model="notes" :rows="3" class="w-full" placeholder="Dégât, oubli, remarque…" @blur="saveNotes" />
      </section>

      <section v-if="manage">
        <h3 class="mb-2 text-sm font-semibold">Lien sans compte</h3>
        <p class="mb-2 text-xs text-muted">Lien secret vers ce seul ménage, à envoyer à la personne qui le fait (valable jusqu’au lendemain de l’échéance).</p>
        <div class="flex flex-wrap gap-2">
          <UButton size="sm" variant="soft" icon="i-lucide-link" label="Copier le lien" @click="getLink()" />
          <UButton size="sm" variant="ghost" icon="i-lucide-refresh-cw" label="Régénérer" @click="getLink(true)" />
        </div>
        <p v-if="link" class="mt-2 break-all font-mono text-xs text-muted">{{ link.url }}</p>
      </section>
    </div>
  </UCard>
</template>
