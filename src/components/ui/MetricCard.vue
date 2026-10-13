<script setup lang="ts">
withDefaults(defineProps<{ label: string; value: string | number; detail?: string; tone?: 'blue' | 'green' | 'amber' | 'violet'; interactive?: boolean }>(), { interactive: false })
const emit = defineEmits<{ click: [] }>()
</script>

<template>
  <button v-if="interactive" type="button" class="metric-card metric-card--interactive" :class="`metric-card--${tone ?? 'blue'}`" @click="emit('click')">
    <span class="metric-card__label">{{ label }}</span>
    <strong class="metric-card__value">{{ value }}</strong>
    <span v-if="detail" class="metric-card__detail">{{ detail }}</span>
  </button>
  <article v-else class="metric-card" :class="`metric-card--${tone ?? 'blue'}`">
    <span class="metric-card__label">{{ label }}</span><strong class="metric-card__value">{{ value }}</strong><span v-if="detail" class="metric-card__detail">{{ detail }}</span>
  </article>
</template>

<style scoped>
.metric-card{display:grid;gap:7px;min-width:0;width:100%;padding:17px 18px;border:1px solid var(--line);border-radius:var(--radius-md);background:var(--surface);box-shadow:var(--shadow-card);text-align:left}.metric-card__label{color:var(--muted);font-size:12px;font-weight:650}.metric-card__value{color:var(--ink);font-size:clamp(22px,2vw,29px);font-weight:760;letter-spacing:-.04em;line-height:1.15}.metric-card__detail{color:var(--muted);font-size:12px;line-height:1.4}.metric-card--green{border-top:2px solid #52a77f}.metric-card--amber{border-top:2px solid #d69a3c}.metric-card--violet{border-top:2px solid #8270c0}.metric-card--blue{border-top:2px solid var(--brand)}.metric-card--interactive{cursor:pointer;transition:transform .16s var(--ease-ui),box-shadow .16s var(--ease-ui),border-color .16s var(--ease-ui)}.metric-card--interactive:hover{transform:translateY(-1px);box-shadow:var(--shadow-raised);border-color:#bdcbed}
</style>
