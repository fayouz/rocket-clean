<script setup lang="ts">
// "Installer l'application": the browser's dialog on Android/Chrome, the steps to follow on iPhone/iPad (Safari has
// no install prompt). Hidden once installed, or when the browser cannot install it.
withDefaults(defineProps<{ block?: boolean }>(), { block: false })
const pwa = usePwa()
const help = ref(false)
async function click() {
  if (!await pwa.install()) help.value = true
}
</script>

<template>
  <template v-if="pwa.canInstall.value">
    <UButton icon="i-lucide-smartphone" variant="soft" size="sm" label="Installer l’application" :block="block" @click="click" />
    <UModal v-model:open="help" title="Installer sur l’iPhone">
      <template #body>
        <ol class="list-decimal space-y-2 ps-5 text-sm">
          <li>Ouvre cette page dans <b>Safari</b>.</li>
          <li>Touche <UIcon name="i-lucide-share" class="align-middle" /> <b>Partager</b> (en bas de l’écran).</li>
          <li>Choisis <UIcon name="i-lucide-square-plus" class="align-middle" /> <b>Sur l’écran d’accueil</b>, puis <b>Ajouter</b>.</li>
        </ol>
        <p class="mt-3 text-xs text-muted">L’application s’ouvre alors en plein écran depuis son icône.</p>
      </template>
    </UModal>
  </template>
</template>
