<script setup lang="ts">
defineProps<{ label: string; tone?: 'neutral' | 'success' | 'warning' | 'danger' | 'info' }>()
</script>

<template><span class="state-badge" :class="`state-badge--${tone ?? 'neutral'}`">{{ label }}</span></template>

<style scoped>
.state-badge{display:inline-flex;align-items:center;gap:6px;max-width:100%;padding:5px 9px;border:1px solid transparent;border-radius:999px;font-size:11px;font-weight:700;line-height:1.25;overflow-wrap:anywhere}.state-badge--neutral{background:#f1f4f8;color:#536277}.state-badge--success{background:#eaf6ef;color:#26734e}.state-badge--warning{background:#fff5e4;color:#895c15}.state-badge--danger{background:#fff0ee;color:#a33f38}.state-badge--info{background:#edf3ff;color:#2858b2}
</style>
