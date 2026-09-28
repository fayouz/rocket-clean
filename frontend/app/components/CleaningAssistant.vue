<script setup lang="ts">
import type { CleaningReport, CleaningTask, StockLine } from '~/types/place'
import type { Command } from '~/utils/assistant/parser'
import { HELP, LEVEL_WORDS, confirmation, nextOpen, parseCommand, photoAreas, remaining, remainingSentence } from '~/utils/assistant/parser'

// Voice assistant of one cleaning, for the cleaner on the phone (in CleaningCard: /menage and the secret link /m/<token>).
// Reads the current checklist point aloud and listens (hands-free or push-to-talk) for commands; free speech ticks points,
// reports stock and problems after a spoken confirmation; at the end, a guided photo round per area, then the compte
// rendu. Everything goes through the card's own actions (same API and offline queue as manual ticks). Parsing:
// utils/assistant/parser.ts; speech: composables/useSpeech.ts (browser only, no audio sent to our server).
export interface AssistantActions {
  check: (index: number, done: boolean) => Promise<void>
  setStatus: (status: string) => Promise<void>
  setStock: (line: StockLine, level: string, quantity?: number | null) => Promise<void>
  incident: (text: string) => Promise<void>
  photo: (file: File, moment: string, area?: string) => Promise<void>
  report: () => Promise<CleaningReport | null>
}
const props = defineProps<{ task: CleaningTask, levels: StockLine[], actions: AssistantActions }>()
const open = defineModel<boolean>('open', { default: false })

type Mode = 'steps' | 'photos' | 'report'
const mode = ref<Mode>('steps')
const current = ref(0)
const paused = ref(false)
const pending = ref<{ command: Command, text: string } | null>(null)
const lastSaid = ref('')
const log = ref<{ who: 'me' | 'bot', text: string }[]>([])
const typed = ref('')
const askDamagePhoto = ref(false)
const report = ref<CleaningReport | null>(null)
const areaIndex = ref(0)

const speech = useSpeech(sentence => handle(sentence))
const checklist = computed(() => props.task.checklist)
const step = computed(() => checklist.value[current.value])
const doneCount = computed(() => checklist.value.filter(c => c.done).length)
const areas = computed(() => photoAreas(checklist.value))
const area = computed(() => areas.value[areaIndex.value])

function note(who: 'me' | 'bot', text: string) {
  log.value = [...log.value.slice(-11), { who, text }]
}
async function say(text: string) {
  lastSaid.value = text
  note('bot', text)
  await speech.speak(text)
}

function stepSentence(): string {
  if (!checklist.value.length) return 'Ce ménage n’a pas de checklist. Dites « terminer » quand vous avez fini.'
  const s = step.value
  if (!s) return remainingSentence(checklist.value)
  return `Point ${current.value + 1} sur ${checklist.value.length} : ${s.label}${s.done ? ', déjà fait' : ''}.`
}

async function begin() {
  mode.value = props.task.status === 'done' ? 'report' : 'steps'
  paused.value = false
  pending.value = null
  current.value = Math.max(0, nextOpen(checklist.value, 0))
  if (props.task.status === 'todo') await props.actions.setStatus('in_progress')
  if (mode.value === 'report') {
    await showReport()
    return
  }
  const intro = speech.supported.value ? 'Assistant de ménage. Dites « aide » pour les commandes.' : 'Assistant de ménage.'
  await say(`${intro} ${remainingSentence(checklist.value)} ${stepSentence()}`)
}

watch(open, (v) => {
  if (v) begin()
  else {
    speech.stop()
    speech.silence()
  }
})

async function advance(from: number) {
  const next = nextOpen(checklist.value, from)
  if (next === -1) {
    current.value = checklist.value.length
    await say(`Tous les points sont faits. ${areas.value.length ? 'Dites « terminer » pour la tournée photo et le compte rendu.' : 'Dites « terminer » pour finir.'}`)
    return
  }
  current.value = next
  await say(stepSentence())
}

async function markCurrent() {
  const s = step.value
  if (!s) return advance(0)
  if (!s.done) await props.actions.check(current.value, true)
  await advance(current.value + 1)
}

async function apply(command: Command) {
  switch (command.kind) {
    case 'check':
      await props.actions.check(command.index, true)
      await say(`« ${command.label} » coché. ${remainingSentence(checklist.value)}`)
      if (command.index === current.value) await advance(current.value + 1)
      return
    case 'stock': {
      const line = props.levels.find(l => l.id === command.id)
      if (!line) return
      await props.actions.setStock(line, command.level, command.quantity)
      await say(`Stock enregistré : ${command.name}, ${LEVEL_WORDS[command.level]}.`)
      return
    }
    case 'incident':
      await props.actions.incident(command.text)
      askDamagePhoto.value = true
      await say('Problème noté. Prenez une photo du dégât avec le bouton rouge « Photo du dégât ».')
      return
    case 'finish':
      await finish(true)
  }
}

async function handle(sentence: string) {
  note('me', sentence)
  const command = parseCommand(sentence, checklist.value, props.levels)
  if (pending.value) {
    const waiting = pending.value
    if (command.kind === 'yes') {
      pending.value = null
      return apply(waiting.command)
    }
    pending.value = null
    if (command.kind === 'no') return say('D’accord, j’annule.')
  }
  if (paused.value && !['resume', 'where', 'remaining', 'repeat', 'help'].includes(command.kind)) return
  if (mode.value === 'photos') return handlePhotos(command)
  if (mode.value === 'report') {
    if (command.kind === 'repeat') return say(lastSaid.value)
    return
  }

  switch (command.kind) {
    case 'next': return markCurrent()
    case 'skip': return advance(current.value + 1)
    case 'previous':
      current.value = Math.max(0, Math.min(current.value, checklist.value.length) - 1)
      return say(stepSentence())
    case 'repeat': return say(lastSaid.value || stepSentence())
    case 'pause':
      paused.value = true
      return say('En pause. Dites « reprendre » pour continuer.')
    case 'resume':
      paused.value = false
      return say(stepSentence())
    case 'where': return say(`${stepSentence()} ${doneCount.value} fait${doneCount.value > 1 ? 's' : ''} sur ${checklist.value.length}.`)
    case 'remaining': return say(remainingSentence(checklist.value))
    case 'help': return say(HELP)
    case 'photo': return say('Appuyez sur un bouton photo : les navigateurs n’ouvrent l’appareil photo que sur un geste.')
    case 'finish': {
      const left = remaining(checklist.value)
      if (left > 0) {
        pending.value = { command, text: `Il reste ${left} point${left > 1 ? 's' : ''} non fait${left > 1 ? 's' : ''}. Je termine quand même ?` }
        return say(pending.value.text)
      }
      return finish(true)
    }
    case 'yes': case 'no': return say('Il n’y a rien à confirmer.')
    case 'check': case 'stock': case 'incident':
      pending.value = { command, text: confirmation(command) }
      return say(pending.value.text)
    case 'unknown': return say('Je n’ai pas compris. Dites « aide » pour les commandes.')
  }
}

// ——— End: photo round, then completion and compte rendu ———

const photographed = computed(() => new Set(props.task.photos.filter(p => p.area).map(p => p.area!.toLowerCase())))
async function finish(withPhotos: boolean) {
  const missing = areas.value.findIndex(a => !photographed.value.has(a.toLowerCase()))
  if (withPhotos && missing !== -1) {
    mode.value = 'photos'
    areaIndex.value = missing
    return say(`Tournée photo : ${areas.value.length} pièce${areas.value.length > 1 ? 's' : ''}. ${photoPrompt()}`)
  }
  await props.actions.setStatus('done')
  await showReport()
}

function photoPrompt() {
  return area.value ? `Prenez une photo : ${area.value}. Appuyez sur le bouton photo, ou dites « passer ».` : ''
}

async function nextArea() {
  const next = areas.value.findIndex((a, i) => i > areaIndex.value && !photographed.value.has(a.toLowerCase()))
  if (next === -1) return finish(false)
  areaIndex.value = next
  await say(photoPrompt())
}

async function handlePhotos(command: Command) {
  switch (command.kind) {
    case 'next': case 'skip': return nextArea()
    case 'repeat': case 'where': case 'photo': return say(photoPrompt())
    case 'finish': return finish(false)
    case 'help': return say('Appuyez sur le bouton photo. Dites « passer » pour la pièce suivante, « terminer » pour finir sans photo.')
    default: return say('Tournée photo en cours. Dites « passer » ou « terminer ».')
  }
}

async function takePhoto(event: Event, moment: 'after' | 'damage') {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file) return
  if (moment === 'damage') {
    await props.actions.photo(file, 'damage')
    askDamagePhoto.value = false
    return say('Photo du dégât ajoutée.')
  }
  await props.actions.photo(file, 'after', area.value)
  await say('Photo ajoutée.')
  await nextArea()
}

async function showReport() {
  mode.value = 'report'
  report.value = await props.actions.report()
  const r = report.value
  const done = r ? r.checklist.done.length : doneCount.value
  const total = r ? r.checklist.total : checklist.value.length
  const parts = ['Ménage terminé.']
  if (r?.durationMinutes != null) parts.push(`Durée : ${r.durationMinutes} minute${r.durationMinutes > 1 ? 's' : ''}.`)
  if (total) parts.push(`${done} point${done > 1 ? 's' : ''} sur ${total}.`)
  if (r?.incidents.length) parts.push(`${r.incidents.length} problème${r.incidents.length > 1 ? 's' : ''} signalé${r.incidents.length > 1 ? 's' : ''}.`)
  if (r?.stock.length) parts.push(`${r.stock.length} relevé${r.stock.length > 1 ? 's' : ''} de stock.`)
  parts.push('Merci !')
  speech.stop()
  await say(parts.join(' '))
}

function submitTyped() {
  const text = typed.value.trim()
  typed.value = ''
  if (text) handle(text)
}
const toggleHandsFree = (v: boolean) => v ? speech.start() : speech.stop()
</script>

<template>
  <UModal v-model:open="open" fullscreen title="Assistant vocal" description="Guidage pas à pas de la checklist, à la voix ou avec les boutons.">
    <template #body>
      <div class="mx-auto flex max-w-xl flex-col gap-4">
        <UAlert v-if="!speech.supported.value" color="neutral" variant="subtle" icon="i-lucide-mic-off" title="Reconnaissance vocale indisponible" description="Ce navigateur ne reconnaît pas la voix (essayez Chrome ou Safari). Les boutons et la saisie font la même chose." />
        <UAlert v-else-if="speech.error.value" color="warning" variant="subtle" icon="i-lucide-mic-off" :description="speech.error.value" />

        <!-- Checklist, step by step -->
        <template v-if="mode === 'steps'">
          <div>
            <div class="mb-1 flex justify-between text-xs text-muted">
              <span>{{ task.placeName }}</span><span>{{ doneCount }}/{{ checklist.length }} faits</span>
            </div>
            <UProgress :model-value="checklist.length ? (doneCount / checklist.length) * 100 : 100" size="sm" />
          </div>
          <div class="rounded-lg border border-default p-4 text-center">
            <p v-if="step" class="text-xs text-muted">Point {{ current + 1 }} sur {{ checklist.length }}<span v-if="paused"> · en pause</span></p>
            <p class="mt-1 text-2xl font-semibold" :class="step?.done ? 'text-muted line-through' : ''">{{ step?.label ?? 'Tous les points sont faits' }}</p>
          </div>
          <div class="grid grid-cols-2 gap-2">
            <UButton block size="xl" variant="outline" icon="i-lucide-chevron-left" label="Précédent" @click="handle('précédent')" />
            <UButton block size="xl" color="success" icon="i-lucide-check" label="C’est fait" @click="handle('c’est fait')" />
            <UButton block size="xl" variant="outline" icon="i-lucide-skip-forward" label="Passer" @click="handle('passer')" />
            <UButton block size="xl" variant="outline" icon="i-lucide-repeat" label="Répéter" @click="handle('répète')" />
          </div>
          <UButton block size="lg" variant="soft" icon="i-lucide-flag" label="Terminer le ménage" @click="handle('terminer')" />
        </template>

        <!-- Photo round -->
        <template v-else-if="mode === 'photos'">
          <div class="rounded-lg border border-default p-4 text-center">
            <p class="text-xs text-muted">Tournée photo · {{ areaIndex + 1 }} sur {{ areas.length }}</p>
            <p class="mt-1 text-2xl font-semibold">{{ area }}</p>
          </div>
          <label class="cursor-pointer">
            <input type="file" accept="image/*" capture="environment" class="hidden" @change="takePhoto($event, 'after')">
            <span class="flex min-h-16 items-center justify-center gap-2 rounded-lg bg-primary text-lg font-semibold text-inverted"><UIcon name="i-lucide-camera" class="size-6" /> Prendre la photo</span>
          </label>
          <div class="grid grid-cols-2 gap-2">
            <UButton block size="lg" variant="outline" icon="i-lucide-skip-forward" label="Passer" @click="handle('passer')" />
            <UButton block size="lg" color="success" icon="i-lucide-flag" label="Terminer" @click="handle('terminer')" />
          </div>
        </template>

        <!-- Compte rendu -->
        <template v-else>
          <CleaningReportView v-if="report" :report="report" />
          <UAlert v-else color="neutral" variant="subtle" icon="i-lucide-cloud-off" title="Ménage terminé" description="Le compte rendu sera disponible dès le retour du réseau." />
          <UButton block size="lg" variant="soft" label="Fermer" @click="open = false" />
        </template>

        <!-- Confirmation -->
        <UCard v-if="pending" :ui="{ body: 'p-3' }">
          <p class="mb-2 font-medium">{{ pending.text }}</p>
          <div class="grid grid-cols-2 gap-2">
            <UButton block size="lg" color="success" label="Oui" @click="handle('oui')" />
            <UButton block size="lg" variant="outline" label="Non" @click="handle('non')" />
          </div>
        </UCard>

        <label v-if="askDamagePhoto" class="cursor-pointer">
          <input type="file" accept="image/*" capture="environment" class="hidden" @change="takePhoto($event, 'damage')">
          <span class="flex min-h-12 items-center justify-center gap-2 rounded-lg border border-error text-error"><UIcon name="i-lucide-triangle-alert" /> Photo du dégât</span>
        </label>

        <!-- Voice -->
        <div v-if="mode !== 'report'" class="flex flex-col items-center gap-2">
          <template v-if="speech.supported.value">
            <UButton
              size="xl" class="rounded-full" :color="speech.listening.value ? 'error' : 'primary'"
              :icon="speech.listening.value ? 'i-lucide-mic' : 'i-lucide-mic-off'"
              :label="speech.continuous.value ? 'J’écoute (mains libres)' : speech.listening.value ? 'J’écoute…' : 'Appuyer pour parler'"
              :disabled="speech.continuous.value" @click="speech.listenOnce()"
            />
            <USwitch :model-value="speech.continuous.value" label="Mains libres (écoute continue)" @update:model-value="toggleHandsFree" />
          </template>
          <p v-if="speech.interim.value" class="text-sm italic text-muted">{{ speech.interim.value }}…</p>
        </div>
        <form class="flex gap-2" @submit.prevent="submitTyped">
          <UInput v-model="typed" class="flex-1" placeholder="Ou écrire : « il manque du café »…" aria-label="Commande écrite" />
          <UButton type="submit" icon="i-lucide-send" variant="soft" aria-label="Envoyer" />
        </form>

        <div v-if="log.length" class="space-y-1 rounded-lg bg-elevated p-3 text-sm" aria-live="polite">
          <p v-for="(l, i) in log" :key="i" :class="l.who === 'me' ? 'text-right' : 'text-muted'">
            <span v-if="l.who === 'me'" class="rounded bg-primary/10 px-2 py-0.5">{{ l.text }}</span><span v-else>{{ l.text }}</span>
          </p>
        </div>
        <p class="text-xs text-muted">
          La voix est reconnue par le navigateur du téléphone ; Rocket Clean ne reçoit ni n’enregistre aucun son.
          Sur Chrome, le son est transcrit par Google.
        </p>
      </div>
    </template>
  </UModal>
</template>
