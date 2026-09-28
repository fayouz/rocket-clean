<script setup lang="ts">
import type { CleaningReport } from '~/types/place'

// Compte rendu of a cleaning (written on completion): duration, checklist done/skipped, problems, stock, photos, notes.
defineProps<{ report: CleaningReport }>()
</script>

<template>
  <div class="space-y-4 text-sm">
    <UAlert v-if="report.draft" color="neutral" variant="subtle" icon="i-lucide-hourglass" title="Ménage pas encore terminé" description="État actuel ; le compte rendu est figé à la fin du ménage." />
    <div class="grid grid-cols-3 gap-2 text-center">
      <div class="rounded-md bg-elevated p-2">
        <p class="text-lg font-semibold">{{ report.durationMinutes ?? '—' }}<span v-if="report.durationMinutes !== null" class="text-xs"> min</span></p>
        <p class="text-xs text-muted">Durée</p>
      </div>
      <div class="rounded-md bg-elevated p-2">
        <p class="text-lg font-semibold">{{ report.checklist.done.length }}/{{ report.checklist.total }}</p>
        <p class="text-xs text-muted">Points faits</p>
      </div>
      <div class="rounded-md bg-elevated p-2">
        <p class="text-lg font-semibold">{{ report.photos.length }}</p>
        <p class="text-xs text-muted">Photos</p>
      </div>
    </div>
    <p class="text-xs text-muted">
      {{ report.placeName }} · {{ report.label }}<span v-if="report.assignee"> · {{ report.assignee }}</span>
      <span v-if="report.startedAt"> · {{ whenFr(report.startedAt) }} → {{ hourFr(report.completedAt) }}</span>
    </p>
    <section v-if="report.checklist.skipped.length">
      <h4 class="mb-1 font-semibold">Points non faits</h4>
      <ul class="list-inside list-disc text-warning">
        <li v-for="(s, i) in report.checklist.skipped" :key="i">{{ s }}</li>
      </ul>
    </section>
    <section v-if="report.checklist.done.length">
      <h4 class="mb-1 font-semibold">Points faits</h4>
      <ul class="list-inside list-disc text-muted">
        <li v-for="(s, i) in report.checklist.done" :key="i">{{ s }}</li>
      </ul>
    </section>
    <section v-if="report.incidents.length">
      <h4 class="mb-1 font-semibold text-error">Problèmes signalés</h4>
      <ul class="list-inside list-disc">
        <li v-for="(s, i) in report.incidents" :key="i">{{ s.text }} <span class="text-xs text-muted">({{ hourFr(s.at) }})</span></li>
      </ul>
    </section>
    <section v-if="report.stock.length">
      <h4 class="mb-1 font-semibold">Stock</h4>
      <ul class="list-inside list-disc">
        <li v-for="(s, i) in report.stock" :key="i">{{ s.item }} : {{ STOCK_LEVEL_LABEL[s.level] }}<span v-if="s.quantity"> ({{ s.quantity }} utilisé{{ s.quantity > 1 ? 's' : '' }})</span></li>
      </ul>
    </section>
    <section v-if="report.photos.length">
      <h4 class="mb-1 font-semibold">Photos</h4>
      <ul class="list-inside list-disc text-muted">
        <li v-for="p in report.photos" :key="p.fileId">{{ PHOTO_MOMENT_LABEL[p.moment] }}<span v-if="p.area"> · {{ p.area }}</span> · {{ hourFr(p.at) }}</li>
      </ul>
    </section>
    <section v-if="report.notes">
      <h4 class="mb-1 font-semibold">Notes</h4>
      <p class="whitespace-pre-line">{{ report.notes }}</p>
    </section>
  </div>
</template>
