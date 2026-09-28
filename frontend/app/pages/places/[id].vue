<script setup lang="ts">
import type { Place } from '~/types/place'

// A place: its cleanings, checklist template and planning.
const route = useRoute()
const api = useApi()
const id = computed(() => String(route.params.id))
const { data: place } = await useAsyncData(`place-${id.value}`, () => api<Place>(`/api/places/${id.value}`))
useHead({ title: () => `${place.value?.name ?? 'Lieu'} · ${useAppConfig().rocket.name}` })
</script>

<template>
  <UDashboardPanel id="place">
    <template #header>
      <UDashboardNavbar :title="place?.name ?? 'Lieu'">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>
    </template>
    <template #body>
      <CleaningTab :place-id="id" />
    </template>
  </UDashboardPanel>
</template>
