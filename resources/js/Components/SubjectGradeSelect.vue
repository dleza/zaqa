<script setup lang="ts">
import { computed } from 'vue'
import {
  CERTIFICATE_SUBJECT_GRADE_OPTIONS,
  legacyGradeWarning,
  selectGradeValue,
} from '@/lib/certificateSubjectGrades'

const props = withDefaults(
  defineProps<{
    modelValue: string
    disabled?: boolean
    savedGrade?: string
    gradeOptions?: string[]
    inputClass?: string
  }>(),
  {
    disabled: false,
    savedGrade: '',
    gradeOptions: () => [],
    inputClass: 'zaqa-input',
  },
)

const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()

const options = computed(() =>
  props.gradeOptions.length > 0 ? props.gradeOptions : [...CERTIFICATE_SUBJECT_GRADE_OPTIONS],
)

const warning = computed(() => legacyGradeWarning(props.savedGrade || props.modelValue))

const selected = computed({
  get: () => selectGradeValue(props.modelValue),
  set: (value: string) => emit('update:modelValue', value),
})
</script>

<template>
  <div>
    <select v-model="selected" :class="inputClass" :disabled="disabled">
      <option value="" disabled>Select grade</option>
      <option v-for="grade in options" :key="grade" :value="grade">{{ grade }}</option>
    </select>
    <p v-if="warning" class="mt-1 text-xs text-amber-800">{{ warning }}</p>
  </div>
</template>
