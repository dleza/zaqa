<script setup lang="ts">
import Workspace from '@/Pages/Applicant/Applications/Qualifications/Workspace.vue'

defineProps<{
  application: any
  qualificationId?: number | null
  countries: Array<{ id: number; name: string; iso_code?: string | null }>
  qualificationTypes: Array<any>
  certificateSubjects: Array<{ id: number; name: string }>
  subjectGradeOptions?: string[]
}>()
</script>

<template>
  <Workspace v-bind="$props" institutional-mode />
</template>
