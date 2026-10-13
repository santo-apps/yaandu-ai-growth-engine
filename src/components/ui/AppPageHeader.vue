<script setup lang="ts">
defineProps<{ eyebrow?: string; title: string; description?: string }>()
</script>

<template>
  <header class="app-page-header">
    <div class="app-page-header__copy">
      <div v-if="eyebrow" class="app-page-header__eyebrow">{{ eyebrow }}</div>
      <h1>{{ title }}</h1>
      <p v-if="description">{{ description }}</p>
    </div>
    <div v-if="$slots.actions" class="app-page-header__actions"><slot name="actions" /></div>
  </header>
</template>

<style scoped>
.app-page-header{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;margin-bottom:24px;min-width:0}.app-page-header__copy{min-width:0}.app-page-header__eyebrow{margin-bottom:7px;color:var(--muted);font-size:11px;font-weight:750;letter-spacing:.12em;text-transform:uppercase}.app-page-header h1{margin:0;color:var(--ink);font-size:clamp(24px,2.4vw,32px);font-weight:750;letter-spacing:-.045em;line-height:1.15;overflow-wrap:anywhere}.app-page-header p{margin:8px 0 0;color:var(--muted);font-size:14px;line-height:1.5}.app-page-header__actions{display:flex;align-items:center;gap:8px;flex:0 0 auto}@media(max-width:600px){.app-page-header{align-items:flex-start;flex-direction:column;gap:12px;margin-bottom:18px}.app-page-header__actions{width:100%}}
</style>
