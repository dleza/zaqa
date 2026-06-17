<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminActionModal from '@/Components/AdminActionModal.vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { AlertTriangle, Ban, Plus, RefreshCcw, Save, ShieldCheck, Users } from 'lucide-vue-next'
import { computed, ref } from 'vue'
import Swal from 'sweetalert2'

const props = defineProps<{
  category: any
  level1_memberships: Array<any>
  level2_memberships: Array<any>
  level1_users: Array<{ id: number; name: string; email: string }>
  level2_users: Array<{ id: number; name: string; email: string }>
  links: Record<string, string>
}>()

const addLevel1Open = ref(false)
const addLevel2Open = ref(false)
const editOpen = ref(false)
const selected = ref<any | null>(null)

const addLevel1Form = useForm({
  user_id: '' as any,
  review_level: 'level1',
  priority: '' as any,
})

const addLevel2Form = useForm({
  user_id: '' as any,
  review_level: 'level2',
  priority: '' as any,
})

const editForm = useForm({
  is_active: true,
  is_available: true,
  unavailable_reason: '',
  unavailable_until: '' as any,
  priority: '' as any,
})

const typeLabel = computed(() => {
  return props.category.type === 'foreign_country' ? 'Foreign (Country of award)' : 'Local (Awarding institution)'
})

function openEdit(m: any) {
  selected.value = m
  editForm.is_active = !!m.is_active
  editForm.is_available = !!m.is_available
  editForm.unavailable_reason = m.unavailable_reason ?? ''
  editForm.unavailable_until = m.unavailable_until ? (m.unavailable_until as string).slice(0, 10) : ''
  editForm.priority = m.priority ?? ''
  editOpen.value = true
}

function addLevel1Member() {
  addLevel1Form.post(props.links.add_level1_member, { preserveScroll: true, onSuccess: () => ((addLevel1Open.value = false), addLevel1Form.reset('user_id', 'priority')) })
}

function addLevel2Member() {
  addLevel2Form.post(props.links.add_level2_member, { preserveScroll: true, onSuccess: () => ((addLevel2Open.value = false), addLevel2Form.reset('user_id', 'priority')) })
}

function saveMember() {
  if (!selected.value) return
  editForm.post(`/admin/verification/assignment-categories/${props.category.id}/members/${selected.value.id}`, {
    preserveScroll: true,
    onSuccess: () => (editOpen.value = false),
  })
}

async function removeMember(m: any) {
  const res = await Swal.fire({
    icon: 'warning',
    title: 'Remove officer from category?',
    showCancelButton: true,
    confirmButtonText: 'Remove',
    cancelButtonText: 'Cancel',
  })
  if (!res.isConfirmed) return
  router.delete(`/admin/verification/assignment-categories/${props.category.id}/members/${m.id}`, { preserveScroll: true })
}

async function deactivate() {
  const res = await Swal.fire({
    icon: 'warning',
    title: 'Deactivate category?',
    html: `<div class="text-left text-sm text-text-muted">Auto-assignment will not use this category until reactivated.</div>`,
    showCancelButton: true,
    confirmButtonText: 'Deactivate',
    cancelButtonText: 'Cancel',
  })
  if (!res.isConfirmed) return
  router.post(props.links.deactivate, {}, { preserveScroll: true })
}

async function reactivate() {
  const res = await Swal.fire({
    icon: 'question',
    title: 'Reactivate category?',
    showCancelButton: true,
    confirmButtonText: 'Reactivate',
    cancelButtonText: 'Cancel',
  })
  if (!res.isConfirmed) return
  router.post(props.links.reactivate, {}, { preserveScroll: true })
}
</script>

<template>
  <AdminLayout>
    <div class="flex flex-col gap-4">
      <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
          <div class="min-w-0 flex-1">
            <div class="inline-flex items-center gap-2 text-xs font-semibold text-text-muted">
              <ShieldCheck class="h-4 w-4" aria-hidden="true" />
              Verification
            </div>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">{{ category.name }}</h1>
            <div class="mt-2 flex flex-wrap items-center gap-2">
              <span class="zaqa-badge zaqa-badge-secondary">{{ typeLabel }}</span>
              <span class="zaqa-badge" :class="category.is_active ? 'zaqa-badge-success' : 'zaqa-badge-warning'">{{ category.is_active ? 'Active' : 'Inactive' }}</span>
              <span class="zaqa-badge zaqa-badge-secondary">L1: {{ category.level1_members_count ?? 0 }}</span>
              <span class="zaqa-badge zaqa-badge-secondary">L2: {{ category.level2_members_count ?? 0 }}</span>
              <span v-if="category.missing_level2_officers" class="zaqa-badge zaqa-badge-warning inline-flex items-center gap-1">
                <AlertTriangle class="h-3 w-3" aria-hidden="true" />
                No Level 2 officers
              </span>
              <template v-if="category.type === 'foreign_country'">
                <span v-for="c in (category.countries ?? []).slice(0, 4)" :key="`c-${c.id}`" class="zaqa-badge zaqa-badge-secondary">
                  {{ c.name }} ({{ c.iso_code }})
                </span>
                <span v-if="(category.countries ?? []).length > 4" class="zaqa-badge zaqa-badge-secondary">+{{ (category.countries ?? []).length - 4 }} more</span>
              </template>
              <template v-else>
                <span v-for="i in (category.awarding_institutions ?? []).slice(0, 4)" :key="`i-${i.id}`" class="zaqa-badge zaqa-badge-secondary">
                  {{ i.name }}{{ i.is_active ? '' : ' (inactive)' }}
                </span>
                <span v-if="(category.awarding_institutions ?? []).length > 4" class="zaqa-badge zaqa-badge-secondary">+{{ (category.awarding_institutions ?? []).length - 4 }} more</span>
              </template>
            </div>
            <div class="mt-3 text-xs text-text-muted">
              Last Level 1 assigned: {{ category.last_assigned_user?.name ?? '—' }} <span v-if="category.last_assigned_at">• {{ new Date(category.last_assigned_at).toLocaleString() }}</span>
            </div>
          </div>

          <div class="flex shrink-0 flex-wrap items-center justify-start gap-2 lg:flex-nowrap lg:justify-end">
            <Link :href="links.index" class="zaqa-btn zaqa-btn-secondary shrink-0 px-4 py-2 text-sm">Back</Link>
            <Link :href="links.edit" class="zaqa-btn zaqa-btn-secondary shrink-0 px-4 py-2 text-sm">Edit</Link>
            <button type="button" class="zaqa-btn zaqa-btn-primary inline-flex shrink-0 items-center gap-2 whitespace-nowrap px-4 py-2 text-sm" @click="addLevel1Open = true">
              <Plus class="h-4 w-4 shrink-0" aria-hidden="true" />
              Add Level 1 officer
            </button>
            <button type="button" class="zaqa-btn zaqa-btn-primary inline-flex shrink-0 items-center gap-2 whitespace-nowrap px-4 py-2 text-sm" @click="addLevel2Open = true">
              <Plus class="h-4 w-4 shrink-0" aria-hidden="true" />
              Add Level 2 officer
            </button>
            <button v-if="category.is_active" type="button" class="zaqa-btn zaqa-btn-secondary inline-flex shrink-0 items-center gap-2 whitespace-nowrap px-4 py-2 text-sm" @click="deactivate">
              <Ban class="h-4 w-4 shrink-0" aria-hidden="true" />
              Deactivate
            </button>
            <button v-else type="button" class="zaqa-btn zaqa-btn-secondary inline-flex shrink-0 items-center gap-2 whitespace-nowrap px-4 py-2 text-sm" @click="reactivate">
              <RefreshCcw class="h-4 w-4 shrink-0" aria-hidden="true" />
              Reactivate
            </button>
          </div>
        </div>
      </div>

      <div class="grid grid-cols-1 gap-4 xl:grid-cols-2 xl:items-stretch">
        <section class="flex h-full flex-col rounded-2xl border border-border bg-surface p-4 shadow-sm sm:p-5">
          <div>
            <div class="text-sm font-semibold text-text-primary">Level 1 Officers</div>
            <div class="mt-1 text-xs text-text-muted">Used for Level 1 category auto-assignment. Unavailable officers are skipped.</div>
          </div>

          <div class="mt-4 min-h-0 flex-1 overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
                <tr>
                  <th class="px-3 py-2 text-left">Officer</th>
                  <th class="px-3 py-2 text-left">State</th>
                  <th class="px-3 py-2 text-left">Availability</th>
                  <th class="px-3 py-2 text-left">Workload</th>
                  <th class="px-3 py-2 text-left">Last assigned</th>
                  <th class="px-3 py-2 text-right">Actions</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border/60">
                <tr v-for="m in level1_memberships" :key="m.id" class="hover:bg-surface-muted/60">
                  <td class="px-3 py-2.5">
                    <div class="font-semibold text-text-primary">{{ m.user?.name ?? '—' }}</div>
                    <div class="mt-0.5 text-xs text-text-muted">{{ m.user?.email ?? '' }}</div>
                  </td>
                  <td class="px-3 py-2.5">
                    <span class="zaqa-badge" :class="m.is_active ? 'zaqa-badge-success' : 'zaqa-badge-warning'">{{ m.is_active ? 'Active' : 'Inactive' }}</span>
                  </td>
                  <td class="px-3 py-2.5">
                    <div class="flex flex-col gap-1">
                      <span class="zaqa-badge" :class="m.is_available ? 'zaqa-badge-success' : 'zaqa-badge-warning'">{{ m.is_available ? 'Available' : 'Unavailable' }}</span>
                      <div v-if="m.unavailable_until" class="text-xs text-text-muted">Until: {{ new Date(m.unavailable_until).toLocaleString() }}</div>
                      <div v-if="m.unavailable_reason" class="text-xs text-text-muted">{{ m.unavailable_reason }}</div>
                    </div>
                  </td>
                  <td class="px-3 py-2.5 text-text-primary">{{ m.workload_active ?? 0 }}</td>
                  <td class="px-3 py-2.5 text-text-primary">
                    <span v-if="m.last_assigned_at">{{ new Date(m.last_assigned_at).toLocaleString() }}</span>
                    <span v-else class="text-text-muted">—</span>
                  </td>
                  <td class="px-3 py-2.5 text-right">
                    <div class="inline-flex items-center gap-2">
                      <button type="button" class="zaqa-btn zaqa-btn-secondary h-9 shrink-0 px-3 py-2 text-xs" @click="openEdit(m)">Edit</button>
                      <button type="button" class="zaqa-btn zaqa-btn-secondary h-9 shrink-0 px-3 py-2 text-xs" @click="removeMember(m)">Remove</button>
                    </div>
                  </td>
                </tr>
                <tr v-if="level1_memberships.length === 0">
                  <td colspan="6" class="px-3 py-4 text-center text-sm text-text-muted">No Level 1 officers assigned to this category yet.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section class="flex h-full flex-col rounded-2xl border border-border bg-surface p-4 shadow-sm sm:p-5">
          <div>
            <div class="flex items-center gap-2 text-sm font-semibold text-text-primary">
              <Users class="h-4 w-4 text-text-muted" aria-hidden="true" />
              Level 2 Officers
            </div>
            <div class="mt-1 text-xs text-text-muted">Used when Level 1 completes review. If none are available, qualifications fall back to the Level 2 pool.</div>
          </div>

          <div class="mt-4 min-h-0 flex-1 overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead class="bg-surface-muted text-xs font-semibold text-text-muted">
                <tr>
                  <th class="px-3 py-2 text-left">Officer</th>
                  <th class="px-3 py-2 text-left">State</th>
                  <th class="px-3 py-2 text-left">Availability</th>
                  <th class="px-3 py-2 text-left">Workload</th>
                  <th class="px-3 py-2 text-left">Last assigned</th>
                  <th class="px-3 py-2 text-right">Actions</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border/60">
                <tr v-for="m in level2_memberships" :key="m.id" class="hover:bg-surface-muted/60">
                  <td class="px-3 py-2.5">
                    <div class="font-semibold text-text-primary">{{ m.user?.name ?? '—' }}</div>
                    <div class="mt-0.5 text-xs text-text-muted">{{ m.user?.email ?? '' }}</div>
                  </td>
                  <td class="px-3 py-2.5">
                    <span class="zaqa-badge" :class="m.is_active ? 'zaqa-badge-success' : 'zaqa-badge-warning'">{{ m.is_active ? 'Active' : 'Inactive' }}</span>
                  </td>
                  <td class="px-3 py-2.5">
                    <div class="flex flex-col gap-1">
                      <span class="zaqa-badge" :class="m.is_available ? 'zaqa-badge-success' : 'zaqa-badge-warning'">{{ m.is_available ? 'Available' : 'Unavailable' }}</span>
                      <div v-if="m.unavailable_until" class="text-xs text-text-muted">Until: {{ new Date(m.unavailable_until).toLocaleString() }}</div>
                      <div v-if="m.unavailable_reason" class="text-xs text-text-muted">{{ m.unavailable_reason }}</div>
                    </div>
                  </td>
                  <td class="px-3 py-2.5 text-text-primary">{{ m.workload_active ?? 0 }}</td>
                  <td class="px-3 py-2.5 text-text-primary">
                    <span v-if="m.last_assigned_at">{{ new Date(m.last_assigned_at).toLocaleString() }}</span>
                    <span v-else class="text-text-muted">—</span>
                  </td>
                  <td class="px-3 py-2.5 text-right">
                    <div class="inline-flex items-center gap-2">
                      <button type="button" class="zaqa-btn zaqa-btn-secondary h-9 shrink-0 px-3 py-2 text-xs" @click="openEdit(m)">Edit</button>
                      <button type="button" class="zaqa-btn zaqa-btn-secondary h-9 shrink-0 px-3 py-2 text-xs" @click="removeMember(m)">Remove</button>
                    </div>
                  </td>
                </tr>
                <tr v-if="level2_memberships.length === 0">
                  <td colspan="6" class="px-3 py-4 text-center text-sm text-text-muted">No Level 2 officers assigned to this category yet.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </div>
    </div>

    <AdminActionModal v-model="addLevel1Open" title="Add Level 1 officer" description="Only users with Level 1 review permission are listed.">
      <div class="space-y-4">
        <div>
          <label class="text-sm font-semibold text-text-primary">Officer</label>
          <select v-model="addLevel1Form.user_id" class="zaqa-input mt-2">
            <option value="" disabled>Select officer…</option>
            <option v-for="u in level1_users" :key="u.id" :value="u.id">{{ u.name }} ({{ u.email }})</option>
          </select>
          <div v-if="addLevel1Form.errors.user_id" class="mt-1 text-xs text-danger">{{ addLevel1Form.errors.user_id }}</div>
        </div>
        <div>
          <label class="text-sm font-semibold text-text-primary">Priority (optional)</label>
          <input v-model="addLevel1Form.priority" type="number" min="0" max="1000" class="zaqa-input mt-2 h-10" placeholder="Lower is higher priority" />
        </div>
      </div>
      <template #footer>
        <button type="button" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm" @click="addLevel1Open = false">Cancel</button>
        <button type="button" class="zaqa-btn zaqa-btn-primary inline-flex items-center gap-2 px-4 py-2 text-sm" :disabled="addLevel1Form.processing" @click="addLevel1Member">
          <Save class="h-4 w-4" aria-hidden="true" />
          Add
        </button>
      </template>
    </AdminActionModal>

    <AdminActionModal v-model="addLevel2Open" title="Add Level 2 officer" description="Only users with Level 2 review permission are listed.">
      <div class="space-y-4">
        <div>
          <label class="text-sm font-semibold text-text-primary">Officer</label>
          <select v-model="addLevel2Form.user_id" class="zaqa-input mt-2">
            <option value="" disabled>Select officer…</option>
            <option v-for="u in level2_users" :key="u.id" :value="u.id">{{ u.name }} ({{ u.email }})</option>
          </select>
          <div v-if="addLevel2Form.errors.user_id" class="mt-1 text-xs text-danger">{{ addLevel2Form.errors.user_id }}</div>
        </div>
        <div>
          <label class="text-sm font-semibold text-text-primary">Priority (optional)</label>
          <input v-model="addLevel2Form.priority" type="number" min="0" max="1000" class="zaqa-input mt-2 h-10" placeholder="Lower is higher priority" />
        </div>
      </div>
      <template #footer>
        <button type="button" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm" @click="addLevel2Open = false">Cancel</button>
        <button type="button" class="zaqa-btn zaqa-btn-primary inline-flex items-center gap-2 px-4 py-2 text-sm" :disabled="addLevel2Form.processing" @click="addLevel2Member">
          <Save class="h-4 w-4" aria-hidden="true" />
          Add
        </button>
      </template>
    </AdminActionModal>

    <AdminActionModal v-model="editOpen" title="Edit officer membership" description="Availability is scoped to this category only.">
      <div v-if="selected" class="space-y-4">
        <div class="rounded-xl border border-border bg-surface-muted p-4">
          <div class="text-sm font-semibold text-text-primary">{{ selected.user?.name ?? 'Officer' }}</div>
          <div class="mt-0.5 text-xs text-text-muted">{{ selected.user?.email ?? '' }}</div>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
          <label class="inline-flex items-center gap-2 text-sm text-text-primary">
            <input type="checkbox" v-model="editForm.is_active" />
            Membership active
          </label>
          <label class="inline-flex items-center gap-2 text-sm text-text-primary">
            <input type="checkbox" v-model="editForm.is_available" />
            Available
          </label>
        </div>
        <div>
          <label class="text-sm font-semibold text-text-primary">Unavailable until (optional)</label>
          <input v-model="editForm.unavailable_until" type="date" class="zaqa-input mt-2 h-10" />
        </div>
        <div>
          <label class="text-sm font-semibold text-text-primary">Reason (optional)</label>
          <input v-model="editForm.unavailable_reason" class="zaqa-input mt-2 h-10" placeholder="e.g. Leave / Training" />
        </div>
        <div>
          <label class="text-sm font-semibold text-text-primary">Priority (optional)</label>
          <input v-model="editForm.priority" type="number" min="0" max="1000" class="zaqa-input mt-2 h-10" />
        </div>
      </div>
      <template #footer>
        <button type="button" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm" @click="editOpen = false">Cancel</button>
        <button type="button" class="zaqa-btn zaqa-btn-primary inline-flex items-center gap-2 px-4 py-2 text-sm" :disabled="editForm.processing" @click="saveMember">
          <Save class="h-4 w-4" aria-hidden="true" />
          Save
        </button>
      </template>
    </AdminActionModal>
  </AdminLayout>
</template>
