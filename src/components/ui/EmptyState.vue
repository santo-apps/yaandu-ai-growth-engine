<script setup lang="ts">
defineProps<{ title: string; description: string; icon?: string }>()
</script>

<template>
  <div class="empty-state-card" role="status">
    <span v-if="icon" class="empty-state-card__icon" aria-hidden="true">{{ icon }}</span>
    <div><h3>{{ title }}</h3><p>{{ description }}</p><slot /></div>
  </div>
</template>

<style scoped>
.empty-state-card{display:flex;align-items:flex-start;gap:13px;padding:22px;border:1px dashed var(--line-strong);border-radius:var(--radius-md);background:var(--surface-soft)}.empty-state-card__icon{display:grid;place-items:center;flex:0 0 36px;width:36px;height:36px;border-radius:11px;background:#edf3ff;color:var(--brand);font-size:17px}.empty-state-card h3{margin:1px 0 4px;color:var(--ink);font-size:14px}.empty-state-card p{margin:0;color:var(--muted);font-size:13px;line-height:1.5}
</style>
