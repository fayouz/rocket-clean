<script setup lang="ts">
import type { CleaningTask } from '~/types/place'

// Public page of one cleaning (secret link /m/<token>, no account): the cleaner runs its checklist, adds photos,
// sets the stock levels and notes. Nothing else of the application is reachable from here.
definePageMeta({ layout: 'bare', public: true })
const route = useRoute()
const token = String(route.params.token)
const { data: task, error } = await useAsyncData(`public-cleaning-${token}`, () => $fetch<CleaningTask>(`/api/public/cleaning/${encodeURIComponent(token)}`, { baseURL: useRuntimeConfig().public.apiBase as string, headers: { Accept: 'application/json' } }))
useHead({ title: () => task.value ? `Ménage · ${task.value.placeName}` : 'Ménage', meta: [{ name: 'robots', content: 'noindex, nofollow' }, { name: 'referrer', content: 'no-referrer' }] })
</script>

<template>
  <main class="mx-auto max-w-xl space-y-4 px-4 py-8">
    <UAlert v-if="error" color="warning" variant="subtle" icon="i-lucide-link-2-off" title="Lien indisponible" description="Ce lien de ménage est invalide, expiré ou a été remplacé. Demande un nouveau lien." />
    <template v-else-if="task">
      <header>
        <h1 class="text-xl font-bold">Ménage · {{ task.placeName }}</h1>
        <p v-if="task.expiresAt" class="text-xs text-muted">Lien personnel, valable jusqu’au {{ whenFr(task.expiresAt) }}. Ne le partage pas.</p>
      </header>
      <CleaningCard :task="task" :token="token" start-open @updated="t => task = t" />
    </template>
  </main>
</template>
