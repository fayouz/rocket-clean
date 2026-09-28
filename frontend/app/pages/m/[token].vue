<script setup lang="ts">
import type { CleaningTask } from '~/types/place'

// Public page of one cleaning (secret link /m/<token>, no account): the cleaner runs its checklist, adds photos,
// sets the stock levels and notes. Nothing else of the application is reachable from here.
definePageMeta({ layout: 'bare', public: true })
const route = useRoute()
const token = String(route.params.token)
// Offline: the last loaded cleaning stays on the phone (7 days) and is shown with a "Hors ligne" badge; ticks made
// meanwhile are queued (components/CleaningCard.vue) and sent when the network is back, then the page reloads it.
const cacheKey = `cleaning:${token}`
const stale = ref(false)
const { data: task, error, refresh } = await useAsyncData(`public-cleaning-${token}`, async () => {
  try {
    const fresh = await $fetch<CleaningTask>(`/api/public/cleaning/${encodeURIComponent(token)}`, { baseURL: useRuntimeConfig().public.apiBase as string, headers: { Accept: 'application/json' } })
    stale.value = false
    return fresh
  }
  catch (e) {
    const kept = import.meta.client && isNetworkError(e) ? offlineLoad<CleaningTask>(cacheKey) : null
    if (!kept) throw e
    stale.value = true
    return kept
  }
})
watch(task, t => t && offlineSave(cacheKey, t), { immediate: true })
const pwa = usePwa()
watch(pwa.synced, () => refresh())
// Installed from here, the app opens this cleaning (manifest with start_url /m/<token>).
useHead({
  title: () => task.value ? `Ménage · ${task.value.placeName}` : 'Ménage',
  meta: [{ name: 'robots', content: 'noindex, nofollow' }, { name: 'referrer', content: 'no-referrer' }],
  link: [{ rel: 'manifest', href: `/m/${encodeURIComponent(token)}/manifest.webmanifest`, key: 'manifest' }],
})
</script>

<template>
  <main class="mx-auto max-w-xl space-y-4 px-4 py-8">
    <UAlert v-if="error" color="warning" variant="subtle" icon="i-lucide-link-2-off" title="Lien indisponible" description="Ce lien de ménage est invalide, expiré ou a été remplacé. Demande un nouveau lien." />
    <template v-else-if="task">
      <header class="space-y-2">
        <div class="flex flex-wrap items-center gap-2 pe-10">
          <h1 class="text-xl font-bold">Ménage · {{ task.placeName }}</h1>
          <OfflineBadge :stale="stale" />
        </div>
        <p v-if="task.expiresAt" class="text-xs text-muted">Lien personnel, valable jusqu’au {{ whenFr(task.expiresAt) }}. Ne le partage pas.</p>
        <PwaInstallButton />
      </header>
      <CleaningCard :task="task" :token="token" start-open @updated="t => task = t" />
    </template>
  </main>
</template>
