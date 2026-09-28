<script setup lang="ts">
import type { Place } from '~/types/place'

// The places cleanings are about: Rocket Place's when configured (managed there), else local places created here.
const api = useApi()
const toast = useToast()
const { isAdmin } = useAuth()
useHead({ title: `Lieux · ${useAppConfig().rocket.name}` })

const { data: places, refresh } = await useAsyncData('places', () => api<Place[]>('/api/places'), { default: () => [] })
const fromPlace = computed(() => places.value.some(p => p.source === 'place'))

const showCreate = ref(false)
const name = ref('')
const creating = ref(false)
async function create() {
  creating.value = true
  try {
    await api('/api/places', { method: 'POST', body: { name: name.value } })
    showCreate.value = false
    name.value = ''
    await refresh()
    toast.add({ title: 'Lieu créé', color: 'success' })
  }
  catch (error) {
    toast.add({ title: 'Non créé', description: apiErrorMessage(error), color: 'error' })
  }
  creating.value = false
}

async function remove(place: Place) {
  if (!confirm(`Supprimer « ${place.name} » et ses ménages ?`)) return
  try {
    await api(`/api/places/${place.id}`, { method: 'DELETE' })
    await refresh()
  }
  catch (error) {
    toast.add({ title: 'Non supprimé', description: apiErrorMessage(error), color: 'error' })
  }
}
</script>

<template>
  <UDashboardPanel id="places">
    <template #header>
      <UDashboardNavbar title="Lieux">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton v-if="isAdmin && !fromPlace" icon="i-lucide-plus" label="Ajouter un lieu" @click="showCreate = true" />
        </template>
      </UDashboardNavbar>
    </template>
    <template #body>
      <p v-if="fromPlace" class="text-sm text-muted">Lieux de Rocket Place : ils se créent et se modifient dans Rocket Place.</p>
      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <UCard v-for="p in places" :key="p.id">
          <div class="flex items-center justify-between gap-2">
            <NuxtLink :to="`/places/${p.id}`" class="font-semibold hover:underline">{{ p.name }}</NuxtLink>
            <UButton v-if="isAdmin && p.source === 'local'" icon="i-lucide-trash-2" color="error" variant="ghost" size="sm" aria-label="Supprimer" @click="remove(p)" />
          </div>
        </UCard>
      </div>
      <UCard v-if="!places.length">
        <p class="text-sm text-muted">Aucun lieu. {{ isAdmin ? '« Ajouter un lieu » pour commencer (ou configure Rocket Place).' : 'Un administrateur doit d’abord créer un lieu.' }}</p>
      </UCard>
    </template>

    <UModal v-model:open="showCreate" title="Ajouter un lieu">
      <template #body>
        <UFormField label="Nom"><UInput v-model="name" class="w-full" /></UFormField>
      </template>
      <template #footer>
        <UButton variant="ghost" label="Annuler" @click="showCreate = false" />
        <UButton :loading="creating" label="Créer" @click="create" />
      </template>
    </UModal>
  </UDashboardPanel>
</template>
