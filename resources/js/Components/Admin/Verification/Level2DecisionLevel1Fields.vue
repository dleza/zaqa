<script setup lang="ts">
const FINDINGS_MAX_LENGTH = 10000
const ACCREDITATION_MAX_LENGTH = 2000

defineProps<{
  findings: string
  accreditationStatement: string
  findingsError?: string
  accreditationStatementError?: string
  accreditationRequired?: boolean
}>()

const emit = defineEmits<{
  'update:findings': [value: string]
  'update:accreditationStatement': [value: string]
}>()
</script>

<template>
  <div class="space-y-4">
    <p class="rounded-xl border border-border/70 bg-surface-muted/40 px-4 py-3 text-xs text-text-muted">
      These values were submitted by Level 1. You may correct wording before making the final decision. Changes will be audited.
    </p>

    <div>
      <label class="text-sm font-semibold text-text-primary">Findings</label>
      <textarea
        :value="findings"
        class="zaqa-input mt-2 h-auto min-h-[7rem] resize-y py-3"
        rows="4"
        :maxlength="FINDINGS_MAX_LENGTH"
        placeholder="Level 1 findings for this qualification."
        @input="emit('update:findings', ($event.target as HTMLTextAreaElement).value)"
      />
      <div class="mt-1.5 flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
        <p v-if="findingsError" class="text-xs text-danger">{{ findingsError }}</p>
        <p class="ml-auto shrink-0 text-xs tabular-nums text-text-muted">{{ findings.length }} / {{ FINDINGS_MAX_LENGTH }}</p>
      </div>
    </div>

    <div>
      <label class="text-sm font-semibold text-text-primary">
        Accreditation Statement
        <span v-if="accreditationRequired" class="text-danger">*</span>
        <span v-else class="text-xs font-normal text-text-muted">(optional)</span>
      </label>
      <textarea
        :value="accreditationStatement"
        class="zaqa-input mt-2 h-auto min-h-[5rem] resize-y py-3"
        rows="3"
        :maxlength="ACCREDITATION_MAX_LENGTH"
        placeholder="Accreditation statement for certificate recognition."
        @input="emit('update:accreditationStatement', ($event.target as HTMLTextAreaElement).value)"
      />
      <div class="mt-1.5 flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
        <p v-if="accreditationStatementError" class="text-xs text-danger">{{ accreditationStatementError }}</p>
        <p class="ml-auto shrink-0 text-xs tabular-nums text-text-muted">{{ accreditationStatement.length }} / {{ ACCREDITATION_MAX_LENGTH }}</p>
      </div>
    </div>
  </div>
</template>
