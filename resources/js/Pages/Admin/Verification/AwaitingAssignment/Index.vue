<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminActionModal from '@/Components/AdminActionModal.vue'
import AdminTablePagination from '@/Components/AdminTablePagination.vue'
import ReferenceSearchFilters from '@/Components/Admin/ReferenceSearchFilters.vue'
import { Link, router, useForm, usePage } from '@inertiajs/vue3'
import { UserPlus } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'

type QueueRow = {
  id: number
  qualification_title?: string | null
  verification_reference_number?: string | null
  can_assign_level2?: boolean
}

const props = defineProps<{
  qualifications: any
  pageVariant: 'level1' | 'level2'
  filters: {
    application_reference?: string
    qualification_reference?: string
    overdue?: string | null
    overdue_days?: string | null
    submitted_from?: string | null
    submitted_to?: string | null
    foreign?: string | null
    qualification_type_id?: string | null
    awarding_institution_id?: string | null
    country_id?: string | null
    sort?: string
    direction?: string
  }
  level1Users: Array<{ id: number; name: string; email: string }>
  level2Users: Array<{ id: number; name: string; email: string }>
  can: { assign_level1: boolean; assign_level2: boolean }
}>()

const page = usePage()

const listBasePath = computed(() =>
  props.pageVariant === 'level1'
    ? '/admin/verification/awaiting-level1-assignment'
    : '/admin/verification/awaiting-level2-assignment',
)

const bulkAssignPath = computed(() =>
  props.pageVariant === 'level1'
    ? '/admin/verification/awaiting-level1-assignment/bulk-assign'
    : '/admin/verification/awaiting-level2-assignment/bulk-assign',
)

const pageTitle = computed(() =>
  props.pageVariant === 'level1' ? 'Awaiting Level 1 Assignment' : 'Awaiting Level 2 Assignment',
)

const pageDescription = computed(() =>
  props.pageVariant === 'level1'
    ? 'Qualifications waiting for a Level 1 officer.'
    : 'Qualifications ready for Level 2 review but not yet assigned.',
)

const emptyMessage = computed(() =>
  props.pageVariant === 'level1'
    ? 'No qualifications are waiting for Level 1 assignment.'
    : 'No qualifications are waiting for Level 2 assignment.',
)

const canBulkAssign = computed(() =>
  props.pageVariant === 'level1' ? props.can.assign_level1 : props.can.assign_level2,
)

const applicationReference = ref((props.filters.application_reference ?? '').toString())
const qualificationReference = ref((props.filters.qualification_reference ?? '').toString())
const overdue = ref((props.filters.overdue ?? '').toString())
const overdueDays = ref((props.filters.overdue_days ?? '').toString())
const submittedFrom = ref((props.filters.submitted_from ?? '').toString())
const submittedTo = ref((props.filters.submitted_to ?? '').toString())
const foreign = ref((props.filters.foreign ?? '').toString())
const sort = ref((props.filters.sort ?? 'deadline').toString())
const direction = ref((props.filters.direction ?? 'asc').toString())

const assignOpen = ref(false)
const assignTargetId = ref<number | null>(null)
const assignTargetLabel = ref('')

const assignForm = useForm({
  assigned_to_user_id: '' as number | string,
  comment: '',
})

const bulkAssignOpen = ref(false)
const selectedIds = ref<number[]>([])

const bulkAssignForm = useForm({
  officer_id: '' as number | string,
  qualification_ids: [] as number[],
  comment: '',
})

const assigneeOptions = computed(() =>
  props.pageVariant === 'level1' ? props.level1Users : props.level2Users,
)

const selectableRows = computed(() =>
  (props.qualifications.data as QueueRow[]).filter((row) => isRowSelectable(row)),
)

const selectedCount = computed(() => selectedIds.value.length)

const allVisibleSelected = computed(() => {
  const visible = selectableRows.value
  if (visible.length === 0) return false
  return visible.every((row) => selectedIds.value.includes(row.id))
})

const someVisibleSelected = computed(() => {
  const visible = selectableRows.value
  return visible.some((row) => selectedIds.value.includes(row.id)) && !allVisibleSelected.value
})

const bulkOfficerName = computed(() => {
  const id = Number(bulkAssignForm.officer_id)
  if (!id) return ''
  return assigneeOptions.value.find((u) => u.id === id)?.name ?? ''
})

const bulkActionLabel = computed(() =>
  props.pageVariant === 'level1' ? 'Assign selected to Level 1 officer' : 'Assign selected to Level 2 officer',
)

function isRowSelectable(row: QueueRow): boolean {
  if (!canBulkAssign.value) return false
  if (props.pageVariant === 'level1') return true
  return Boolean(row.can_assign_level2)
}

function isRowSelected(id: number): boolean {
  return selectedIds.value.includes(id)
}

function toggleRowSelection(id: number) {
  if (isRowSelected(id)) {
    selectedIds.value = selectedIds.value.filter((x) => x !== id)
  } else {
    selectedIds.value = [...selectedIds.value, id]
  }
}

function toggleSelectAllVisible() {
  if (allVisibleSelected.value) {
    const visibleIds = new Set(selectableRows.value.map((row) => row.id))
    selectedIds.value = selectedIds.value.filter((id) => !visibleIds.has(id))
  } else {
    const merged = new Set([...selectedIds.value, ...selectableRows.value.map((row) => row.id)])
    selectedIds.value = [...merged]
  }
}

function clearSelection() {
  selectedIds.value = []
}

function openBulkAssignConfirm() {
  if (selectedCount.value < 1 || !bulkAssignForm.officer_id) return
  bulkAssignOpen.value = true
}

function submitBulkAssign() {
  bulkAssignForm.qualification_ids = [...selectedIds.value]
  bulkAssignForm.post(bulkAssignPath.value, {
    preserveScroll: true,
    onSuccess: () => {
      bulkAssignOpen.value = false
      bulkAssignForm.reset('officer_id', 'qualification_ids', 'comment')
      clearSelection()
    },
  })
}

function formatQualVerificationState(raw: string | null | undefined): string {
  const s = (raw ?? '').toString().trim()
  if (!s) return '—'
  const labels: Record<string, string> = {
    awaiting_assignment: 'Awaiting assignment',
    assigned_to_level1: 'Assigned — Level 1',
    under_level1_review: 'Under Level 1 review',
    under_level2_review: 'Under Level 2 review',
    auto_verified_pending_level2: 'Auto-verified — pending Level 2',
    returned_to_applicant: 'Returned to applicant',
  }
  return labels[s] ?? s.replace(/_/g, ' ')
}

function formatDate(iso: string | null | undefined): string {
  if (!iso) return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return '—'
  return d.toLocaleDateString()
}

function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return '—'
  return d.toLocaleString()
}

function openAssignModal(row: QueueRow) {
  assignTargetId.value = row.id
  assignTargetLabel.value = row.verification_reference_number || row.qualification_title || `Qualification #${row.id}`
  assignForm.reset()
  assignForm.clearErrors()
  assignOpen.value = true
}

function submitAssign() {
  if (!assignTargetId.value) return
  const url =
    props.pageVariant === 'level1'
      ? `/admin/verification/qualifications/${assignTargetId.value}/assign`
      : `/admin/verification/qualifications/${assignTargetId.value}/assign-level2`

  assignForm.post(url, {
    preserveScroll: true,
    onSuccess: () => {
      assignOpen.value = false
      assignTargetId.value = null
    },
  })
}

function toggleDirection(nextSort: string) {
  if (sort.value === nextSort) {
    direction.value = direction.value === 'asc' ? 'desc' : 'asc'
  } else {
    sort.value = nextSort
    direction.value = nextSort === 'deadline' || nextSort === 'submitted' ? 'asc' : 'asc'
  }
}

function clearFilters() {
  applicationReference.value = ''
  qualificationReference.value = ''
  overdue.value = ''
  overdueDays.value = ''
  submittedFrom.value = ''
  submittedTo.value = ''
  foreign.value = ''
  sort.value = 'deadline'
  direction.value = 'asc'
}

watch(
  () => props.qualifications.data,
  () => {
    const visibleIds = new Set((props.qualifications.data as QueueRow[]).map((row) => row.id))
    selectedIds.value = selectedIds.value.filter((id) => visibleIds.has(id))
  },
)

watch([applicationReference, qualificationReference, overdue, overdueDays, submittedFrom, submittedTo, foreign, sort, direction, listBasePath], () => {
  router.get(
    listBasePath.value,
    {
      application_reference: applicationReference.value || null,
      qualification_reference: qualificationReference.value || null,
      foreign: foreign.value || null,
      sort: sort.value || null,
      direction: direction.value || null,
    },
    { preserveState: true, replace: true, preserveScroll: true },
  )
})
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
          <UserPlus class="h-4 w-4" aria-hidden="true" />
          Pending Verification
        </div>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">{{ pageTitle }}</h1>
        <p class="mt-1 text-sm text-text-muted">{{ pageDescription }}</p>
      </div>
      <div class="flex items-center gap-2">
        <Link href="/admin/verification/pool" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Verification pool</Link>
      </div>
    </div>

    <div
      v-if="(page.props.flash as any)?.success"
      class="mt-4 rounded-xl border border-success/30 bg-success/10 px-4 py-3 text-sm text-success"
    >
      {{ (page.props.flash as any).success }}
    </div>

    <div class="mt-6 overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
      <div class="border-b border-border bg-surface-muted px-5 py-4">
        <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
          <div class="xl:col-span-2">
            <label class="mb-1 block text-xs font-semibold text-text-muted">Reference search</label>
            <ReferenceSearchFilters
              v-model:application-reference="applicationReference"
              v-model:qualification-reference="qualificationReference"
              compact
            />
            <p class="mt-1 text-xs text-text-muted">Search is limited to application or qualification reference for faster results.</p>
          </div>
          <div>
            <label class="mb-1 block text-xs font-semibold text-text-muted">Submitted from</label>
            <input v-model="submittedFrom" type="date" class="zaqa-input h-10 w-full" />
          </div>
          <div>
            <label class="mb-1 block text-xs font-semibold text-text-muted">Submitted to</label>
            <input v-model="submittedTo" type="date" class="zaqa-input h-10 w-full" />
          </div>
          <div>
            <label class="mb-1 block text-xs font-semibold text-text-muted">Local/Foreign</label>
            <select v-model="foreign" class="zaqa-input h-10 w-full">
              <option value="">Local + Foreign</option>
              <option value="0">Local</option>
              <option value="1">Foreign</option>
            </select>
          </div>
          <div>
            <label class="mb-1 block text-xs font-semibold text-text-muted">SLA</label>
            <select v-model="overdue" class="zaqa-input h-10 w-full" @change="overdueDays = ''">
              <option value="">All SLA</option>
              <option value="1">Overdue</option>
            </select>
          </div>
          <div>
            <label class="mb-1 block text-xs font-semibold text-text-muted">Overdue by</label>
            <select v-model="overdueDays" class="zaqa-input h-10 w-full" @change="overdue = ''">
              <option value="">Any</option>
              <option value="30">30+ days</option>
              <option value="60">60+ days</option>
              <option value="90">90+ days</option>
            </select>
          </div>
          <div>
            <label class="mb-1 block text-xs font-semibold text-text-muted">Sort</label>
            <select v-model="sort" class="zaqa-input h-10 w-full">
              <option value="deadline">SLA due date</option>
              <option value="submitted">Submitted date</option>
              <option value="reference">Qualification reference</option>
              <option value="application">Application reference</option>
              <option value="country">Country</option>
              <option value="institution">Institution</option>
              <option value="type">Qualification type</option>
            </select>
          </div>
          <div>
            <label class="mb-1 block text-xs font-semibold text-text-muted">Sort direction</label>
            <select v-model="direction" class="zaqa-input h-10 w-full">
              <option value="asc">Ascending</option>
              <option value="desc">Descending</option>
            </select>
          </div>
          <div class="flex items-end">
            <button type="button" class="zaqa-btn zaqa-btn-secondary h-10 w-full px-4 text-sm" @click="clearFilters">
              Clear filters
            </button>
          </div>
        </div>
      </div>

      <div
        v-if="canBulkAssign && selectedCount > 0"
        class="flex flex-col gap-3 border-b border-border bg-brand/5 px-5 py-3 sm:flex-row sm:items-center sm:justify-between"
      >
        <div class="text-sm font-semibold text-text-primary">{{ selectedCount }} selected</div>
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
          <label class="text-sm text-text-muted">{{ bulkActionLabel }}</label>
          <select v-model="bulkAssignForm.officer_id" class="zaqa-input h-10 min-w-[14rem]">
            <option value="" disabled>Select officer…</option>
            <option v-for="u in assigneeOptions" :key="u.id" :value="u.id">{{ u.name }} ({{ u.email }})</option>
          </select>
          <button
            type="button"
            class="zaqa-btn zaqa-btn-primary h-10 px-4 text-sm"
            :disabled="!bulkAssignForm.officer_id || bulkAssignForm.processing"
            @click="openBulkAssignConfirm"
          >
            Assign selected
          </button>
        </div>
      </div>

      <div v-if="qualifications.data.length === 0" class="px-5 py-8 text-sm text-text-muted">
        {{ emptyMessage }}
      </div>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
            <tr>
              <th v-if="canBulkAssign" class="w-10 px-3 py-3 text-left">
                <input
                  type="checkbox"
                  class="h-4 w-4 rounded border-border"
                  :checked="allVisibleSelected"
                  :indeterminate="someVisibleSelected"
                  :disabled="selectableRows.length === 0"
                  aria-label="Select all on this page"
                  @change="toggleSelectAllVisible"
                />
              </th>
              <th class="px-5 py-3 text-left">
                <button type="button" class="inline-flex items-center gap-1 hover:text-text-primary" @click="toggleDirection('reference')">
                  Qualification ref
                </button>
              </th>
              <th class="px-5 py-3 text-left">
                <button type="button" class="inline-flex items-center gap-1 hover:text-text-primary" @click="toggleDirection('application')">
                  Application
                </button>
              </th>
              <th class="px-5 py-3 text-left">Qualification</th>
              <th class="px-5 py-3 text-left">Holder</th>
              <th class="px-5 py-3 text-left">Local/Foreign</th>
              <th class="px-5 py-3 text-left">
                <button type="button" class="inline-flex items-center gap-1 hover:text-text-primary" @click="toggleDirection('submitted')">
                  Submitted
                </button>
              </th>
              <th class="px-5 py-3 text-left">
                <button type="button" class="inline-flex items-center gap-1 hover:text-text-primary" @click="toggleDirection('deadline')">
                  SLA due
                </button>
              </th>
              <th class="px-5 py-3 text-left">State</th>
              <th class="px-5 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border/60">
            <tr v-for="row in qualifications.data" :key="row.id" class="hover:bg-surface-muted/60">
              <td v-if="canBulkAssign" class="px-3 py-3">
                <input
                  v-if="isRowSelectable(row)"
                  type="checkbox"
                  class="h-4 w-4 rounded border-border"
                  :checked="isRowSelected(row.id)"
                  :aria-label="`Select qualification ${row.verification_reference_number ?? row.id}`"
                  @change="toggleRowSelection(row.id)"
                />
              </td>
              <td class="px-5 py-3 font-mono text-text-primary">{{ row.verification_reference_number ?? '—' }}</td>
              <td class="px-5 py-3">
                <div class="font-semibold text-text-primary">{{ row.application?.application_number ?? '—' }}</div>
              </td>
              <td class="px-5 py-3">
                <div class="font-semibold text-text-primary">{{ row.qualification_title ?? '—' }}</div>
                <div class="mt-0.5 text-xs text-text-muted">
                  {{ row.qualification_type ?? '—' }} · {{ row.country_of_award ?? '—' }} · {{ row.awarding_institution ?? '—' }}
                </div>
              </td>
              <td class="px-5 py-3 text-text-primary">{{ row.holder_name ?? row.applicant_name ?? '—' }}</td>
              <td class="px-5 py-3 text-text-primary">{{ row.is_foreign ? 'Foreign' : 'Local' }}</td>
              <td class="px-5 py-3 text-text-primary">{{ formatDate(row.application?.submitted_at) }}</td>
              <td class="px-5 py-3 text-text-primary">{{ formatDateTime(row.service_deadline_at) }}</td>
              <td class="px-5 py-3 text-text-primary">{{ formatQualVerificationState(row.verification_state) }}</td>
              <td class="px-5 py-3 text-right">
                <div class="flex flex-wrap justify-end gap-2">
                  <button
                    v-if="pageVariant === 'level1' && can.assign_level1"
                    type="button"
                    class="zaqa-btn zaqa-btn-primary h-9 px-3 py-2 text-xs"
                    @click="openAssignModal(row)"
                  >
                    Assign Level 1
                  </button>
                  <button
                    v-else-if="pageVariant === 'level2' && can.assign_level2 && row.can_assign_level2"
                    type="button"
                    class="zaqa-btn zaqa-btn-primary h-9 px-3 py-2 text-xs"
                    @click="openAssignModal(row)"
                  >
                    Assign Level 2
                  </button>
                  <Link :href="`/admin/verification/qualifications/${row.id}`" class="zaqa-btn zaqa-btn-secondary h-9 px-3 py-2 text-xs">
                    View
                  </Link>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <AdminTablePagination :paginator="qualifications" label="qualifications" />
    </div>

    <AdminActionModal
      v-model="assignOpen"
      :title="pageVariant === 'level1' ? 'Assign Level 1 reviewer' : 'Assign Level 2 reviewer'"
      :description="`Assign ${assignTargetLabel} to an officer.`"
    >
      <div class="space-y-4">
        <div>
          <label class="text-sm font-semibold text-text-primary">Assign to</label>
          <select v-model="assignForm.assigned_to_user_id" class="zaqa-input mt-2">
            <option value="" disabled>Select officer…</option>
            <option v-for="u in assigneeOptions" :key="u.id" :value="u.id">{{ u.name }} ({{ u.email }})</option>
          </select>
          <div v-if="assignForm.errors.assigned_to_user_id" class="mt-1 text-xs text-danger">{{ assignForm.errors.assigned_to_user_id }}</div>
        </div>
        <div>
          <label class="text-sm font-semibold text-text-primary">Comment (optional)</label>
          <textarea v-model="assignForm.comment" class="zaqa-input mt-2 h-auto min-h-[5rem] py-3" placeholder="Optional internal comment" />
        </div>
        <div v-if="assignForm.errors.qualification" class="text-xs text-danger">{{ assignForm.errors.qualification }}</div>
      </div>
      <template #footer>
        <button type="button" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm" @click="assignOpen = false">Cancel</button>
        <button type="button" class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm" :disabled="assignForm.processing" @click="submitAssign">
          Save assignment
        </button>
      </template>
    </AdminActionModal>

    <AdminActionModal
      v-model="bulkAssignOpen"
      title="Assign selected qualifications"
      :description="`You are about to assign ${selectedCount} qualifications to ${bulkOfficerName}.`"
    >
      <div v-if="bulkAssignForm.errors.officer_id" class="text-xs text-danger">{{ bulkAssignForm.errors.officer_id }}</div>
      <div v-if="bulkAssignForm.errors.qualification_ids" class="text-xs text-danger">{{ bulkAssignForm.errors.qualification_ids }}</div>
      <template #footer>
        <button type="button" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm" @click="bulkAssignOpen = false">Cancel</button>
        <button type="button" class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm" :disabled="bulkAssignForm.processing" @click="submitBulkAssign">
          Confirm assignment
        </button>
      </template>
    </AdminActionModal>
  </AdminLayout>
</template>
