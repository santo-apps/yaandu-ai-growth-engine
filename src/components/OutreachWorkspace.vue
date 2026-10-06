<script setup lang="ts">
import { ref } from 'vue'
import CampaignWorkspace from './CampaignWorkspace.vue'
import MarketingWorkspace from './MarketingWorkspace.vue'
import CampaignActivityWorkspace from './CampaignActivityWorkspace.vue'

const props = defineProps<{ tenantId: string; manager: boolean; knowledgeOnly?: boolean }>()
const emit = defineEmits<{ openProspect: [company: { id: string; name: string }] }>()
const section = ref<'campaigns' | 'content' | 'activity'>(props.knowledgeOnly ? 'content' : 'campaigns')
const knowledgeOnly = ref(Boolean(props.knowledgeOnly))
function showContent() { section.value = 'content'; knowledgeOnly.value = false }
</script>

<template>
  <section class="outreach-workspace">
    <div class="outreach-heading"><div><h2>Outreach workspace</h2><p>Prepare campaigns and review evidence-based message drafts before any approved action.</p></div><span class="outreach-control">Human review required for outbound actions</span></div>
    <nav class="outreach-tabs"><button :class="{active:section==='campaigns'}" @click="section='campaigns'">Campaigns</button><button :class="{active:section==='content'}" @click="showContent">Drafts / Review</button><button :class="{active:section==='activity'}" @click="section='activity'">Activity</button></nav>
    <CampaignWorkspace v-if="section==='campaigns'" :tenant-id="tenantId" :manager="manager" @open-prospect="emit('openProspect', $event)" />
    <MarketingWorkspace v-else-if="section==='content'" :tenant-id="tenantId" :manager="manager" :initial-tab="knowledgeOnly ? 'knowledge' : 'drafts'" @open-prospect="emit('openProspect', $event)" />
    <CampaignActivityWorkspace v-else :tenant-id="tenantId" />
  </section>
</template>

<style scoped>
.outreach-workspace{display:grid;gap:13px}.outreach-heading{display:flex;justify-content:space-between;gap:12px;align-items:center}.outreach-heading h2{margin:0;font-size:15px}.outreach-heading p{margin:5px 0 0;font-size:10px;color:#808a97}.outreach-control{padding:7px 10px;border-radius:18px;background:#edf6f1;color:#3d775d;font-size:9px;font-weight:650}.outreach-tabs{display:flex;gap:16px;border-bottom:1px solid #e5e8ee}.outreach-tabs button{border:0;border-bottom:2px solid transparent;background:none;padding:10px 2px;color:#6d7786;cursor:pointer;font-size:11px}.outreach-tabs button.active{border-color:#4b61a8;color:#354d96;font-weight:700}@media(max-width:650px){.outreach-heading{align-items:flex-start;flex-direction:column}}
</style>
