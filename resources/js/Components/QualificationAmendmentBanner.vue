<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import QualificationAmendmentCommentHistoryModal from '@/Components/QualificationAmendmentCommentHistoryModal.vue'

const props = defineProps<{
  applicationId: number
  qualification: {
    id: number
    title_of_qualification?: string | null
    amendment_comment?: string | null
    latest_amendment_comment?: { body?: string; stage?: string | null; created_at?: string | null } | null
    returned_to_applicant_at?: string | null
    send_back_reopen_level?: string | null
    amendment_comments_count?: number
    amendment_comment_history?: Array<{
      body: string
      created_at: string | null
      stage: string | null
      author_label: string | null
    }>
  }
  compact?: boolean
}>()

const historyOpen = ref(false)

const commentHistory = computed(() =>
  Array.isArray(props.qualification.amendment_comment_history) ? props.qualification.amendment_comment_history : [],
)

const hasCommentHistory = computed(() => commentHistory.value.length > 0)

const latestComment = computed(
  () =>
    props.qualification.latest_amendment_comment?.body ??
    props.qualification.amendment_comment ??
    null,
)

const stageLabel = computed(() => {
  const fromLatest = props.qualification.latest_amendment_comment?.stage
  if (fromLatest) return fromLatest
  const level = (props.qualification.send_back_reopen_level ?? '').toString()
  if (level === 'level2') return 'Level 2 review'
  if (level === 'level1') return 'Level 1 review'
  return null
})

function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return ''
  try {
    return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso))
  } catch {
    return String(iso)
  }
}
</script>

<template>
  <div
    class="rounded-2xl border border-amber-300/50 bg-amber-50 px-4 py-4 sm:px-5"
    :class="compact ? '' : 'sm:py-5'"
  >
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
      <div class="min-w-0">
        <div class="text-sm font-semibold text-amber-950">Correction required for this qualification</div>
        <div class="mt-0.5 text-sm font-medium text-text-primary">
          {{ qualification.title_of_qualification || 'Qualification' }}
        </div>
        <div v-if="stageLabel || qualification.returned_to_applicant_at" class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-amber-950/80">
          <span v-if="stageLabel">Returned from {{ stageLabel }}</span>
          <span v-if="qualification.returned_to_applicant_at">
            {{ stageLabel ? '· ' : '' }}{{ formatDateTime(qualification.returned_to_applicant_at) }}
          </span>
        </div>
        <p v-if="latestComment" class="mt-3 text-sm font-medium text-amber-950">Reviewer comment</p>
        <p v-if="latestComment" class="mt-1 whitespace-pre-wrap text-sm leading-relaxed text-amber-950/90">
          {{ latestComment }}
        </p>
        <p v-else class="mt-2 text-sm leading-relaxed text-amber-950/90">
          Please update this qualification and submit corrections to ZAQA when done.
        </p>
      </div>
      <div class="flex shrink-0 flex-col gap-2 sm:items-end">
        <Link
          :href="`/applicant/applications/${applicationId}/qualifications/${qualification.id}/amend`"
          class="zaqa-btn zaqa-btn-warning h-10 px-4 py-2 text-sm"
        >
          Edit qualification
        </Link>
        <button
          v-if="hasCommentHistory"
          type="button"
          class="zaqa-btn zaqa-btn-secondary h-10 px-4 py-2 text-sm"
          @click="historyOpen = true"
        >
          View comment history
        </button>
      </div>
    </div>

    <QualificationAmendmentCommentHistoryModal
      v-model="historyOpen"
      :qualification-title="qualification.title_of_qualification"
      :comments="commentHistory"
    />
  </div>
</template>
