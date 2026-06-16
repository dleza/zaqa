<script setup lang="ts">
import ActionModal from '@/Components/ActionModal.vue'

export type AmendmentCommentHistoryItem = {
  body: string
  created_at: string | null
  stage: string | null
  author_label: string | null
}

const props = defineProps<{
  modelValue: boolean
  qualificationTitle?: string | null
  comments: AmendmentCommentHistoryItem[]
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', v: boolean): void
}>()

function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '—'
  try {
    return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso))
  } catch {
    return String(iso)
  }
}
</script>

<template>
  <ActionModal
    :model-value="modelValue"
    title="Comment history"
    :description="qualificationTitle ? `Reviewer comments for ${qualificationTitle}` : 'Reviewer comments for this qualification'"
    max-width-class="max-w-2xl"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <div v-if="comments.length === 0" class="text-sm text-text-muted">No reviewer comments are available.</div>
    <ol v-else class="space-y-4">
      <li
        v-for="(item, idx) in comments"
        :key="`${item.created_at ?? 'na'}-${idx}`"
        class="rounded-xl border border-border bg-surface-muted/50 px-4 py-4"
      >
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-text-muted">
          <time :datetime="item.created_at ?? undefined" class="font-medium text-text-primary">
            {{ formatDateTime(item.created_at) }}
          </time>
          <span v-if="item.stage">· {{ item.stage }}</span>
        </div>
        <p class="mt-3 whitespace-pre-wrap text-sm leading-relaxed text-text-primary">{{ item.body }}</p>
      </li>
    </ol>

    <template #footer>
      <button
        type="button"
        class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm"
        @click="emit('update:modelValue', false)"
      >
        Close
      </button>
    </template>
  </ActionModal>
</template>
