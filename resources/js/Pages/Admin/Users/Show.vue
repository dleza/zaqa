<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { Activity, CheckCircle2, Clock, Mail, Phone, ShieldCheck, User as UserIcon } from 'lucide-vue-next'
import { computed } from 'vue'

const props = defineProps<{
  user: {
    id: number
    name: string
    first_name: string | null
    last_name: string | null
    email: string
    phone_primary: string | null
    phone_secondary: string | null
    is_active: boolean
    disabled_at: string | null
    last_login_at: string | null
    created_at: string | null
    roles: string[]
    primary_role: string | null
    profile_photo_url: string | null
    department: { id: number; name: string } | null
  }
  recent_activity: Array<{
    id: number
    module: string
    message: string
    created_at: string | null
    url: string | null
    actor_name_snapshot: string | null
  }>
  stats: { role: string; cards: Array<{ label: string; value: string | number }> }
  level1_memberships: Array<any>
  access_areas: Array<{ key: string; label: string; klass: string }>
  permission_count: number
  can: { edit: boolean; disable: boolean; resend_login_email?: boolean }
  resend_login_email_url?: string
}>()

const blockForm = useForm({})
const unblockForm = useForm({})
const resendLoginEmailForm = useForm({})

function block() {
  blockForm.post(`/admin/users/${props.user.id}/block`, { preserveScroll: true })
}

function unblock() {
  unblockForm.post(`/admin/users/${props.user.id}/unblock`, { preserveScroll: true })
}

function resendLoginEmail() {
  if (!props.resend_login_email_url) return
  if (!confirm('Resend login details email to this user? A new temporary password will be generated.')) return
  resendLoginEmailForm.post(props.resend_login_email_url, { preserveScroll: true })
}

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

const displayName = computed(() => labelOrDash(props.user?.name))
const initials = computed(() => {
  const name = (props.user?.name ?? '').toString().trim()
  if (!name) return ''
  const parts = name.split(/\s+/).filter(Boolean)
  const first = parts[0]?.[0] ?? ''
  const last = parts.length > 1 ? parts[parts.length - 1]?.[0] ?? '' : ''
  return `${first}${last}`.toUpperCase()
})

const statusLabel = computed(() => {
  if (props.user?.disabled_at) return 'Disabled'
  return props.user?.is_active ? 'Active' : 'Inactive'
})

const statusBadgeClass = computed(() => {
  if (props.user?.disabled_at) return 'zaqa-badge-danger'
  return props.user?.is_active ? 'zaqa-badge-success' : 'zaqa-badge-warning'
})
</script>

<template>
  <AdminLayout>
    <div class="rounded-2xl border border-[#0B3A66]/15 bg-gradient-to-br from-[#0B3A66] via-[#0B3A66] to-[#0076BD] p-6 text-white shadow-lg sm:p-8">
      <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
        <div class="flex min-w-0 items-start gap-4">
          <div class="relative">
            <img
              v-if="user.profile_photo_url"
              :src="user.profile_photo_url"
              alt="User profile photo"
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
              Managed account
            </div>
            <h1 class="mt-2 truncate text-2xl font-bold tracking-tight sm:text-3xl">{{ displayName }}</h1>
            <div class="mt-1 flex flex-wrap items-center gap-2 text-sm text-white/90">
              <span class="inline-flex items-center gap-1.5 rounded-full border border-white/25 bg-white/10 px-3 py-1 text-xs font-semibold">
                <Mail class="h-3.5 w-3.5" aria-hidden="true" />
                {{ labelOrDash(user.email) }}
              </span>
              <span class="inline-flex items-center gap-1.5 rounded-full border border-white/25 bg-white/10 px-3 py-1 text-xs font-semibold">
                <CheckCircle2 class="h-3.5 w-3.5" aria-hidden="true" />
                {{ statusLabel }}
              </span>
              <span v-if="user.primary_role" class="inline-flex items-center gap-1.5 rounded-full border border-white/25 bg-white/10 px-3 py-1 text-xs font-semibold">
                {{ user.primary_role }}
              </span>
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-3 text-xs text-white/80">
              <span class="inline-flex items-center gap-1.5">
                <Clock class="h-3.5 w-3.5" aria-hidden="true" />
                Last login: {{ formatDate(user.last_login_at) }}
              </span>
              <span class="text-white/50">•</span>
              <span>Joined: {{ formatDate(user.created_at) }}</span>
            </div>
          </div>
        </div>

        <div class="flex shrink-0 flex-col gap-2 sm:flex-row sm:items-center">
          <Link href="/admin/users" class="inline-flex items-center justify-center rounded-xl border border-white/25 bg-white/10 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-white/20">
            Back
          </Link>
          <Link
            v-if="can.edit"
            :href="`/admin/users/${user.id}/edit`"
            class="inline-flex items-center justify-center rounded-xl border border-white/25 bg-white/10 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-white/20"
          >
            Edit user
          </Link>
          <button
            v-if="can.resend_login_email"
            type="button"
            class="inline-flex items-center justify-center gap-2 rounded-xl border border-white/25 bg-white/10 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-white/20 disabled:opacity-60"
            :disabled="resendLoginEmailForm.processing"
            @click="resendLoginEmail"
          >
            <Mail class="h-4 w-4" aria-hidden="true" />
            Resend login email
          </button>
          <button
            v-if="can.disable && user.disabled_at"
            type="button"
            class="inline-flex items-center justify-center rounded-xl border border-white/25 bg-[#F18230] px-4 py-2.5 text-sm font-semibold text-white shadow-md transition hover:bg-[#e07828] disabled:opacity-60"
            :disabled="unblockForm.processing"
            @click="unblock"
          >
            Unblock user
          </button>
          <button
            v-else-if="can.disable"
            type="button"
            class="inline-flex items-center justify-center rounded-xl border border-white/25 bg-[#F18230] px-4 py-2.5 text-sm font-semibold text-white shadow-md transition hover:bg-[#e07828] disabled:opacity-60"
            :disabled="blockForm.processing"
            @click="block"
          >
            Block user
          </button>
        </div>
      </div>
    </div>

    <div v-if="(stats?.cards ?? []).length" class="mt-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <div
        v-for="card in stats.cards"
        :key="card.label"
        class="rounded-2xl border border-border bg-surface px-5 py-4 shadow-sm"
      >
        <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">{{ card.label }}</div>
        <div class="mt-3 text-2xl font-bold tracking-tight text-text-primary">{{ card.value }}</div>
      </div>
    </div>

    <div class="mt-8 grid gap-6 lg:grid-cols-12">
      <div class="space-y-6 lg:col-span-4">
        <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="flex items-start justify-between gap-4">
            <div>
              <div class="text-sm font-semibold text-text-primary">Account & contact</div>
              <div class="mt-1 text-xs text-text-muted">Managed account details currently stored in the system.</div>
            </div>
            <span class="zaqa-badge" :class="statusBadgeClass">{{ statusLabel }}</span>
          </div>

          <div class="mt-5 grid gap-3 text-sm">
            <div class="flex items-center justify-between gap-3">
              <div class="text-text-muted">First name</div>
              <div class="font-semibold text-text-primary">{{ labelOrDash(user.first_name) }}</div>
            </div>
            <div class="flex items-center justify-between gap-3">
              <div class="text-text-muted">Last name</div>
              <div class="font-semibold text-text-primary">{{ labelOrDash(user.last_name) }}</div>
            </div>
            <div class="flex items-center justify-between gap-3">
              <div class="text-text-muted">Primary phone</div>
              <div class="font-semibold text-text-primary">{{ labelOrDash(user.phone_primary) }}</div>
            </div>
            <div class="flex items-center justify-between gap-3">
              <div class="text-text-muted">Secondary phone</div>
              <div class="font-semibold text-text-primary">{{ labelOrDash(user.phone_secondary) }}</div>
            </div>
            <div class="flex items-center justify-between gap-3">
              <div class="text-text-muted">Department</div>
              <div class="font-semibold text-text-primary">{{ labelOrDash(user.department?.name) }}</div>
            </div>
          </div>
        </div>

        <div class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="text-sm font-semibold text-text-primary">Role & access</div>
          <div class="mt-1 text-xs text-text-muted">Assigned roles and the main access areas implied by those permissions.</div>

          <div class="mt-4">
            <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Roles</div>
            <div class="mt-2 flex flex-wrap gap-2">
              <span v-for="role in (user.roles ?? [])" :key="role" class="zaqa-badge">{{ role }}</span>
              <span v-if="(user.roles ?? []).length === 0" class="text-xs text-text-muted">—</span>
            </div>
          </div>

          <div class="mt-4">
            <div class="text-xs font-semibold uppercase tracking-wider text-text-muted">Access areas</div>
            <div class="mt-2 flex flex-wrap gap-2">
              <span v-for="area in access_areas" :key="area.key" class="zaqa-badge" :class="area.klass">{{ area.label }}</span>
            </div>
            <div class="mt-2 text-xs text-text-muted">Permissions: {{ permission_count }}</div>
          </div>
        </div>

        <div v-if="(level1_memberships ?? []).length" class="rounded-2xl border border-border bg-surface p-6 shadow-sm">
          <div class="text-sm font-semibold text-text-primary">Level 1 categories</div>
          <div class="mt-1 text-xs text-text-muted">Current category memberships and availability for assignment routing.</div>

          <div class="mt-4 space-y-3">
            <div v-for="membership in level1_memberships" :key="membership.id" class="rounded-xl border border-border bg-surface-muted/30 p-4">
              <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                  <Link
                    v-if="membership.category?.url"
                    :href="membership.category.url"
                    class="block truncate text-sm font-semibold text-text-primary hover:underline"
                  >
                    {{ membership.category?.name ?? '—' }}
                  </Link>
                  <div v-else class="truncate text-sm font-semibold text-text-primary">{{ membership.category?.name ?? '—' }}</div>
                  <div class="mt-1 text-xs text-text-muted">
                    {{ membership.category?.type === 'foreign_country' ? 'Foreign (countries)' : 'Local (institutions)' }} •
                    Mapped: {{ membership.category?.mapped_count ?? 0 }}
                  </div>
                </div>
                <span class="zaqa-badge" :class="membership.is_available ? 'zaqa-badge-success' : 'zaqa-badge-warning'">
                  {{ membership.is_available ? 'Available' : 'Unavailable' }}
                </span>
              </div>

              <div v-if="!membership.is_available" class="mt-2 text-xs text-text-muted">
                <div v-if="membership.unavailable_reason">Reason: {{ membership.unavailable_reason }}</div>
                <div v-if="membership.unavailable_until">Until: {{ formatDate(membership.unavailable_until) }}</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="space-y-6 lg:col-span-8">
        <div class="overflow-hidden rounded-2xl border border-border bg-surface shadow-sm">
          <div class="border-b border-border bg-surface-muted px-5 py-4">
            <div class="flex items-start justify-between gap-3">
              <div>
                <div class="text-sm font-semibold text-text-primary">Recent activity</div>
                <div class="mt-1 text-xs text-text-muted">Latest actions by this user or changes recorded against this account.</div>
              </div>
              <Activity class="h-5 w-5 text-text-muted" aria-hidden="true" />
            </div>
          </div>

          <div v-if="(recent_activity ?? []).length === 0" class="px-5 py-6">
            <div class="rounded-2xl border border-border bg-surface-muted p-6 text-center">
              <div class="text-sm font-semibold text-text-primary">No activity yet</div>
              <div class="mt-1 text-xs text-text-muted">This user’s recent actions will appear here.</div>
            </div>
          </div>

          <div v-else class="divide-y divide-border/60">
            <div v-for="item in recent_activity" :key="item.id" class="px-5 py-4">
              <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                  <div class="truncate text-sm font-semibold text-text-primary">
                    <Link v-if="item.url" :href="item.url" class="hover:underline">{{ item.message }}</Link>
                    <span v-else>{{ item.message }}</span>
                  </div>
                  <div class="mt-0.5 text-xs text-text-muted">
                    <span class="font-semibold text-text-primary">{{ item.module }}</span>
                    <span v-if="item.actor_name_snapshot" class="mx-1">•</span>
                    <span v-if="item.actor_name_snapshot">By {{ item.actor_name_snapshot }}</span>
                  </div>
                </div>
                <div class="text-xs text-text-muted">{{ formatDate(item.created_at) }}</div>
              </div>
            </div>
          </div>
        </div>

        <div class="rounded-2xl border border-border bg-surface p-5 shadow-sm">
          <div class="flex items-start gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-[#0B3A66]/10 text-[#0B3A66]">
              <Phone class="h-5 w-5" aria-hidden="true" />
            </div>
            <div>
              <div class="text-sm font-semibold text-text-primary">Operational note</div>
              <div class="mt-1 text-xs text-text-muted">
                Use the edit page for profile details and role changes. Use block / unblock separately for account access control.
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>
