<script setup lang="ts">
defineProps<{ label: string }>()
</script>

<template>
  <div class="loading-state" role="status" aria-live="polite">
    <span class="loading-state__spinner" aria-hidden="true"></span>
    <span>{{ label }}</span>
  </div>
</template>

<style scoped>
.loading-state{display:flex;align-items:center;justify-content:center;gap:10px;min-height:78px;padding:18px;color:var(--muted);font-size:13px}.loading-state__spinner{width:17px;height:17px;border:2px solid #dce5f4;border-top-color:var(--brand);border-radius:50%;animation:loading-spin .75s linear infinite}@keyframes loading-spin{to{transform:rotate(360deg)}}
</style>
