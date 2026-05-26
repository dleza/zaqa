<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link, useForm, usePage } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { Activity, Camera, CheckCircle2, Clock, Mail, Phone, ShieldCheck, Trash2, UploadCloud, User as UserIcon } from 'lucide-vue-next'
import SingleSelectCombobox from '@/Components/SingleSelectCombobox.vue'
import AdminActionModal from '@/Components/AdminActionModal.vue'

const props = defineProps<{
  profile: any
  departments: Array<{ id: number; name: string; is_active: boolean }>
  recent_activity: Array<{ id: number; module: string; message: string; created_at: string | null; url: string | null }>
  stats: { role: string; cards: Array<{ label: string; value: any }> }
  level1_memberships: Array<any>
}>()

const page = usePage()
const permissions = computed<string[]>(() => ((page.props as any).auth?.permissions ?? []) as string[])

function labelOrDash(v: any) {
  const s = (v ?? '').toString().trim()
  return s.length ? s : '—'
}

function formatDate(iso: string | null | undefined) {
  if (!iso) return '—'
  try {
    return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
  } catch {
    return iso
  }
}

const displayName = computed(() => labelOrDash(props.profile?.name))
const initials = computed(() => {
  const name = (props.profile?.name ?? '').toString().trim()
  if (!name) return ''
  const parts = name.split(/\s+/).filter(Boolean)
  const a = parts[0]?.[0] ?? ''
  const b = parts.length > 1 ? parts[parts.length - 1]?.[0] ?? '' : ''
  return `${a}${b}`.toUpperCase()
})

const statusBadgeClass = computed(() => ((props.profile?.is_active ?? false) ? 'zaqa-badge-success' : 'zaqa-badge-warning'))
const statusLabel = computed(() => ((props.profile?.is_active ?? false) ? 'Active' : 'Inactive'))

const capabilityBadges = computed(() => {
  const caps: Array<{ key: string; label: string; klass: string }> = []
  const p = new Set(permissions.value)
  const add = (key: string, label: string, klass = 'zaqa-badge-secondary') => caps.push({ key, label, klass })

  if (p.has('admin.verification.view') || p.has('verification.pool.view')) add('verification', 'Verification', 'zaqa-badge-info')
  if (p.has('learner_records.view') || p.has('learner_records.import')) add('learner_records', 'Learner Records', 'zaqa-badge-info')
  if (p.has('institution_api.manage') || p.has('institution_api.logs.view')) add('integrations', 'Integrations', 'zaqa-badge-info')
  if (p.has('admin.finance.view') || p.has('finance.dashboard.view')) add('finance', 'Finance', 'zaqa-badge-info')
  if (p.has('reports.view') || p.has('reports.sla.view')) add('reports', 'Reports', 'zaqa-badge-info')
  if (p.has('admin.reference_data.manage')) add('settings', 'System Settings', 'zaqa-badge-info')
  if (p.has('admin.users.view') || p.has('admin.users.manage') || p.has('admin.roles.manage')) add('users', 'User/Admin Mgmt', 'zaqa-badge-info')

  if (caps.length === 0) add('basic', 'Basic access', 'zaqa-badge-secondary')
  return caps.slice(0, 7)
})

const profileForm = useForm<{
  phone_primary: string
  phone_secondary: string
  department_id: number | '' | null
}>({
  phone_primary: (props.profile?.phone_primary ?? '').toString(),
  phone_secondary: (props.profile?.phone_secondary ?? '').toString(),
  department_id: props.profile?.department?.id ?? '',
})

function saveProfile() {
  profileForm.put('/admin/profile', {
    preserveScroll: true,
    onSuccess: () => {
      editDetailsOpen.value = false
    },
  })
}

const photoInput = ref<HTMLInputElement | null>(null)
const photoForm = useForm<{ photo: File | null }>({ photo: null })
const removePhotoForm = useForm({})

const photoModalOpen = ref(false)
const editDetailsOpen = ref(false)

function onPhotoPicked(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0] ?? null
  photoForm.photo = file
}

function uploadPhoto() {
  if (!photoForm.photo) return
  photoForm.post('/admin/profile/photo', {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => {
      photoForm.reset('photo')
      if (photoInput.value) photoInput.value.value = ''
      photoModalOpen.value = false
    },
  })
}

function removePhoto() {
  removePhotoForm.delete('/admin/profile/photo', {
    preserveScroll: true,
    onSuccess: () => {
      photoForm.reset('photo')
      if (photoInput.value) photoInput.value.value = ''
      photoModalOpen.value = false
    },
  })
}
</script>

<template>
  <AdminLayout>
    <!-- Header -->
    <div class="rounded-2xl border border-[#0B3A66]/15 bg-gradient-to-br from-[#0B3A66] via-[#0B3A66] to-[#0076BD] p-6 text-white shadow-lg sm:p-8">
      <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
        <div class="flex min-w-0 items-start gap-4">
          <div class="relative">
            <img
              v-if="profile?.profile_photo_url"
              :src="profile.profile_photo_url"
              alt="Profile photo"
              class="h-16 w-16 rounded-2xl border border-white/25 object-cover shadow-sm sm:h-20 sm:w-20"
            />
            <div
              v-else
              class="flex h-16 w-16 items-center justify-center rounded-2xl border border-white/25 bg-white/10 text-white shadow-sm sm:h-20 sm:w-20"
              aria-hidden="true"
            >
              <span v-if="initials" class="text-lg font-bold">{{ initials }}</span>
              <UserIcon v-else class="h-7 w-7 opacity-90" aria-hidden="true" />
            </div>
          </div>

          <div class="min-w-0">
            <div class="inline-flex items-center gap-2 text-xs font-semibold text-white/70">
              <ShieldCheck class="h-4 w-4" aria-hidden="true" />
              Account
            </div>
            <h1 class="mt-2 truncate text-2xl font-bold tracking-tight sm:text-3xl">{{ displayName }}</h1>
            <div class="mt-1 flex flex-wrap items-center gap-2 text-sm text-white/90">
              <span class="inline-flex items-center gap-1.5 rounded-full border border-white/25 bg-white/10 px-3 py-1 text-xs font-semibold">
                <Mail class="h-3.5 w-3.5" aria-hidden="true" />
                {{ labelOrDash(profile?.email) }}
              </span>
              <span class="inline-flex items-center gap-1.5 rounded-full border border-white/25 bg-white/10 px-3 py-1 text-xs font-semibold">
                <CheckCircle2 class="h-3.5 w-3.5" aria-hidden="true" />
                {{ statusLabel }}
              </span>
              <span v-if="profile?.primary_role" class="inline-flex items-center gap-1.5 rounded-full border border-white/25 bg-white/10 px-3 py-1 text-xs font-semibold">
                {{ profile.primary_role }}
              </span>
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-3 text-xs text-white/80">
              <span class="inline-flex items-center gap-1.5">
                <Clock class="h-3.5 w-3.5" aria-hidden="true" />
                Last login: {{ formatDate(profile?.last_login_at) }}
              </span>
              <span class="text-white/50">•</span>
              <span>Joined: {{ formatDate(profile?.created_at) }}</span>
            </div>
          </div>
        </div>

        <div class="flex shrink-0 flex-col gap-2 sm:flex-row sm:items-center">
          <button
            type="button"
            class="inline-flex items-center justify-center gap-2 rounded-xl border border-white/25 bg-white/10 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-white/20 disabled:opacity-60"
            @click="photoModalOpen = true"
          >
            <Camera class="h-4 w-4" aria-hidden="true" />
            Manage photo
          </button>

          <Link
            href="/admin/change-password"
            class="inline-flex items-center justify-center rounded-xl border border-white/25 bg-[#F18230] px-4 py-2.5 text-sm font-semibold text-white shadow-md transition hover:bg-[#e07828]"
          >
            Change password
          </Link>

          <div class="text-xs text-white/70 sm:text-right">JPG / PNG / WEBP • Max 2MB</div>
        </div>
      </div>
    </div>



    <div class="mt-8 grid gap-6 lg:grid-cols-12">
      <!-- Left column -->
      <div class="space-y-6 lg:col-span-4">
        <!-- Contact / account details -->
        <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="flex items-start justify-between gap-4">
            <div>
              <div class="text-sm font-semibold text-text-primary">Account & contact</div>
              <div class="mt-1 text-xs text-text-muted">Your contact details and department on file.</div>
            </div>
            <span class="zaqa-badge" :class="statusBadgeClass">{{ statusLabel }}</span>
          </div>

          <div class="mt-5 grid gap-3 text-sm">
            <div class="flex items-center justify-between gap-3">
              <div class="text-text-muted">Primary phone</div>
              <div class="font-semibold text-text-primary">{{ labelOrDash(profile?.phone_primary) }}</div>
            </div>
            <div class="flex items-center justify-between gap-3">
              <div class="text-text-muted">Secondary phone</div>
              <div class="font-semibold text-text-primary">{{ labelOrDash(profile?.phone_secondary) }}</div>
            </div>
            <div class="flex items-center justify-between gap-3">
              <div class="text-text-muted">Department</div>
              <div class="font-semibold text-text-primary">{{ labelOrDash(profile?.department?.name) }}</div>
            </div>
          </div>

          <div class="mt-5 flex items-center justify-end">
            <button type="button" class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm" @click="editDetailsOpen = true">Edit details</button>
          </div>
        </div>

        <!-- Role & access -->
        <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="text-sm font-semibold text-text-primary">Role & access</div>
          <div class="mt-1 text-xs text-text-muted">Summary of your assigned roles and key access areas.</div>

          <div class="mt-4">
            <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Roles</div>
            <div class="mt-2 flex flex-wrap gap-2">
              <span v-for="r in (profile?.roles ?? [])" :key="r" class="zaqa-badge">{{ r }}</span>
              <span v-if="(profile?.roles ?? []).length === 0" class="text-xs text-text-muted">—</span>
            </div>
          </div>

          <div class="mt-4">
            <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Access areas</div>
            <div class="mt-2 flex flex-wrap gap-2">
              <span v-for="c in capabilityBadges" :key="c.key" class="zaqa-badge" :class="c.klass">{{ c.label }}</span>
            </div>
            <div class="mt-2 text-xs text-text-muted">Permissions: {{ permissions.length }}</div>
          </div>
        </div>

        <!-- Level 1 availability (read-only) -->
        <div v-if="(level1_memberships ?? []).length" class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="text-sm font-semibold text-text-primary">My Level 1 categories</div>
          <div class="mt-1 text-xs text-text-muted">Availability is managed by Level 2 / Super Admin.</div>

          <div class="mt-4 space-y-3">
            <div v-for="m in level1_memberships" :key="m.id" class="rounded-xl border border-border bg-surface-muted/30 p-4">
              <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                  <Link
                    v-if="m.category?.url"
                    :href="m.category.url"
                    class="block truncate text-sm font-semibold text-text-primary hover:underline"
                  >
                    {{ m.category?.name ?? '—' }}
                  </Link>
                  <div v-else class="truncate text-sm font-semibold text-text-primary">{{ m.category?.name ?? '—' }}</div>
                  <div class="mt-1 text-xs text-text-muted">
                    {{ m.category?.type === 'foreign_country' ? 'Foreign (countries)' : 'Local (institutions)' }} •
                    Mapped: {{ m.category?.mapped_count ?? 0 }}
                  </div>
                </div>
                <div class="shrink-0 text-right">
                  <span class="zaqa-badge" :class="m.is_available ? 'zaqa-badge-success' : 'zaqa-badge-warning'">
                    {{ m.is_available ? 'Available' : 'Unavailable' }}
                  </span>
                </div>
              </div>
              <div v-if="!m.is_available" class="mt-2 text-xs text-text-muted">
                <div v-if="m.unavailable_reason">Reason: {{ m.unavailable_reason }}</div>
                <div v-if="m.unavailable_until">Until: {{ new Date(m.unavailable_until).toLocaleString() }}</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Right column -->
      <div class="space-y-6 lg:col-span-8">
        <!-- Recent activity -->
        <div class="overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
          <div class="border-b border-border bg-surface-muted px-5 py-4">
            <div class="flex items-start justify-between gap-3">
              <div>
                <div class="text-sm font-semibold text-text-primary">Recent activity</div>
                <div class="mt-1 text-xs text-text-muted">Latest 10 actions captured for your account.</div>
              </div>
              <Activity class="h-5 w-5 text-text-muted" aria-hidden="true" />
            </div>
          </div>

          <div v-if="(recent_activity ?? []).length === 0" class="px-5 py-6">
            <div class="rounded-2xl border border-border bg-surface-muted p-6 text-center">
              <div class="text-sm font-semibold text-text-primary">No activity yet</div>
              <div class="mt-1 text-xs text-text-muted">Your recent actions will appear here.</div>
            </div>
          </div>

          <div v-else class="divide-y divide-border/60">
            <div v-for="a in recent_activity" :key="a.id" class="px-5 py-4">
              <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                  <div class="truncate text-sm font-semibold text-text-primary">
                    <Link v-if="a.url" :href="a.url" class="hover:underline">{{ a.message }}</Link>
                    <span v-else>{{ a.message }}</span>
                  </div>
                  <div class="mt-0.5 text-xs text-text-muted">
                    <span class="font-semibold text-text-primary">{{ a.module }}</span>
                  </div>
                </div>
                <div class="text-xs text-text-muted">
                  <div class="text-right">{{ a.created_at ? formatDate(a.created_at) : '—' }}</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Security / session -->
        <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="flex items-start justify-between gap-3">
            <div>
              <div class="text-sm font-semibold text-text-primary">Security</div>
              <div class="mt-1 text-xs text-text-muted">Session and account security overview.</div>
            </div>
            <ShieldCheck class="h-5 w-5 text-text-muted" aria-hidden="true" />
          </div>

          <div class="mt-5 grid gap-3 text-sm sm:grid-cols-2">
            <div class="rounded-xl border border-border bg-surface-muted/30 p-4">
              <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Last login</div>
              <div class="mt-1 font-semibold text-text-primary">{{ formatDate(profile?.last_login_at) }}</div>
            </div>
            <div class="rounded-xl border border-border bg-surface-muted/30 p-4">
              <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Account created</div>
              <div class="mt-1 font-semibold text-text-primary">{{ formatDate(profile?.created_at) }}</div>
            </div>
          </div>

          <div class="mt-4 flex flex-wrap items-center justify-end gap-2">
            <Link href="/admin/change-password" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm">Change password</Link>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal: Manage photo -->
    <AdminActionModal
      v-model="photoModalOpen"
      title="Manage profile photo"
      description="Upload a new profile photo (JPG/PNG/WEBP, max 2MB). This affects how your avatar appears in the admin portal."
      max-width-class="max-w-xl"
    >
      <div class="space-y-4">
        <div class="flex items-center gap-4">
          <img
            v-if="profile?.profile_photo_url"
            :src="profile.profile_photo_url"
            alt="Current profile photo"
            class="h-16 w-16 rounded-2xl border border-border object-cover"
          />
          <div v-else class="flex h-16 w-16 items-center justify-center rounded-2xl border border-border bg-surface-muted text-text-muted">
            <span v-if="initials" class="text-lg font-bold">{{ initials }}</span>
            <UserIcon v-else class="h-6 w-6" aria-hidden="true" />
          </div>
          <div class="min-w-0">
            <div class="text-sm font-semibold text-text-primary">Choose a new photo</div>
            <div class="mt-1 text-xs text-text-muted">Tip: square images work best.</div>
          </div>
        </div>

        <div class="rounded-xl border border-border bg-surface-muted/30 p-4">
          <input
            ref="photoInput"
            type="file"
            accept="image/jpeg,image/png,image/webp"
            class="block w-full text-sm text-text-primary file:mr-3 file:rounded-lg file:border file:border-border file:bg-surface file:px-3 file:py-2 file:text-sm file:font-semibold"
            @change="onPhotoPicked"
          />
          <div v-if="photoForm.errors.photo" class="mt-2 text-xs text-danger">{{ photoForm.errors.photo }}</div>
          <div v-else-if="photoForm.photo" class="mt-2 text-xs text-text-muted">Selected: {{ photoForm.photo.name }}</div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-2">
          <button
            v-if="profile?.profile_photo_url"
            type="button"
            class="zaqa-btn zaqa-btn-secondary inline-flex items-center gap-2 px-4 py-2 text-sm text-danger"
            :disabled="removePhotoForm.processing"
            @click="removePhoto"
          >
            <Trash2 class="h-4 w-4" aria-hidden="true" />
            Remove photo
          </button>

          <button
            type="button"
            class="zaqa-btn zaqa-btn-primary inline-flex items-center gap-2 px-4 py-2 text-sm disabled:opacity-60"
            :disabled="!photoForm.photo || photoForm.processing"
            @click="uploadPhoto"
          >
            <UploadCloud class="h-4 w-4" aria-hidden="true" />
            {{ photoForm.processing ? 'Uploading…' : 'Upload photo' }}
          </button>
        </div>
      </div>
    </AdminActionModal>

    <!-- Modal: Edit profile details -->
    <AdminActionModal
      v-model="editDetailsOpen"
      title="Edit profile details"
      description="Update your contact details. Role, email, and status changes are managed by administrators."
      max-width-class="max-w-2xl"
    >
      <form id="profileDetailsForm" class="space-y-4" @submit.prevent="saveProfile">
        <div>
          <label class="text-sm font-semibold text-text-primary">Primary phone</label>
          <div class="mt-1 relative">
            <Phone class="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-text-muted" aria-hidden="true" />
            <input v-model="profileForm.phone_primary" class="zaqa-input pl-10" autocomplete="tel" placeholder="e.g. 2609..." />
          </div>
          <div v-if="profileForm.errors.phone_primary" class="mt-1 text-xs text-danger">{{ profileForm.errors.phone_primary }}</div>
        </div>

        <div>
          <label class="text-sm font-semibold text-text-primary">Secondary phone (optional)</label>
          <div class="mt-1 relative">
            <Phone class="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-text-muted" aria-hidden="true" />
            <input v-model="profileForm.phone_secondary" class="zaqa-input pl-10" autocomplete="tel" placeholder="—" />
          </div>
          <div v-if="profileForm.errors.phone_secondary" class="mt-1 text-xs text-danger">{{ profileForm.errors.phone_secondary }}</div>
        </div>

        <SingleSelectCombobox
          v-model="profileForm.department_id"
          label="Department"
          placeholder="Select…"
          :options="
            departments.map((d) => ({
              id: d.id,
              label: d.name + (d.is_active ? '' : ' (inactive)'),
              disabled: !d.is_active && d.id !== (profile?.department?.id ?? null),
            }))
          "
          :error="profileForm.errors.department_id"
        />
      </form>

      <template #footer>
        <button type="button" class="zaqa-btn zaqa-btn-secondary px-4 py-2 text-sm" @click="editDetailsOpen = false">Cancel</button>
        <button type="submit" form="profileDetailsForm" class="zaqa-btn zaqa-btn-primary px-4 py-2 text-sm" :disabled="profileForm.processing">
          {{ profileForm.processing ? 'Saving…' : 'Save changes' }}
        </button>
      </template>
    </AdminActionModal>
  </AdminLayout>
</template>
