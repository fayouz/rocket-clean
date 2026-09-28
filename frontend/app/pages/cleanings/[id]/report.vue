<script setup lang="ts">
import type { CleaningReport } from '~/types/place'

// Compte rendu of a cleaning, for the managers (and the person who did it): GET /api/cleanings/{id}/report.
const route = useRoute()
const api = useApi()
const id = String(route.params.id)
const { data: report, error } = await useAsyncData(`cleaning-report-${id}`, () => api<CleaningReport>(`/api/cleanings/${id}/report`))
useHead({ title: () => `Compte rendu · ${report.value?.placeName ?? 'Ménage'}` })
</script>

<template>
  <UDashboardPanel id="cleaning-report">
    <template #header>
      <UDashboardNavbar :title="report ? `Compte rendu · ${report.placeName}` : 'Compte rendu'">
        <template #leading>
          <UButton icon="i-lucide-arrow-left" variant="ghost" aria-label="Retour" @click="$router.back()" />
        </template>
      </UDashboardNavbar>
    </template>
    <template #body>
      <div class="mx-auto max-w-2xl">
        <UAlert v-if="error" color="error" variant="subtle" title="Compte rendu indisponible" :description="apiErrorMessage(error)" />
        <UCard v-else-if="report">
          <CleaningReportView :report="report" />
        </UCard>
      </div>
    </template>
  </UDashboardPanel>
</template>
