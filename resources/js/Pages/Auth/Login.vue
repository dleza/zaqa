<script setup lang="ts">
import { ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import InputError from '@/Components/InputError.vue'
import { zaqaLogoUrl } from '@/constants/zaqaLogo'
import {
  ArrowRight,
  Clock3,
  Headphones,
  Lock,
  Mail,
  Shield,
  ShieldCheck,
  Users,
} from 'lucide-vue-next'

const form = useForm({
  identifier: '',
  password: '',
  remember: false,
})

const showPassword = ref(false)

function submit() {
  form.post('/login')
}

const heroFeatures = [
  {
    icon: Shield,
    title: 'Secure & Protected',
    description: 'Your data is encrypted and protected with industry-standard security.',
  },
  {
    icon: Clock3,
    title: 'Real-time Access',
    description: 'Get real-time verification results and application updates.',
  },
  {
    icon: Users,
    title: 'Trusted Platform',
    description: 'The official platform of the Zambia Qualifications Authority.',
  },
]

const supportItems = [
  {
    icon: Lock,
    title: 'Secure Connection',
    description: 'SSL encrypted',
  },
  {
    icon: ShieldCheck,
    title: 'Your Information is Safe',
    description: 'We do not share your data',
  },
  {
    icon: Headphones,
    title: 'Need Help?',
    description: 'info@zaqa.gov.zm  |  +260 211 225 087',
  },
]

const loginBackgroundUrl = '/images/login-background.png'

const loginOverlayGradient =
  'linear-gradient(90deg, rgba(0, 62, 126, 0.78) 0%, rgba(0, 95, 170, 0.58) 34%, rgba(0, 115, 186, 0.28) 50%, rgba(0, 115, 186, 0.12) 62%, rgba(244, 248, 252, 0.55) 74%, rgba(244, 248, 252, 0.82) 84%, rgba(255, 255, 255, 0.96) 100%)'
</script>

<template>
  <GuestLayout :card="false" max-width-class="max-w-none" content-padding-class="p-0" :hide-header="true" :center-content="false">
    <div class="relative flex min-h-screen w-full flex-col overflow-hidden bg-[#F4F8FC]">
      <!-- Full-page background: building image + blue fade -->
      <div aria-hidden="true" class="pointer-events-none absolute inset-0 z-0">
        <div
          class="absolute inset-0 bg-cover bg-center bg-no-repeat"
          :style="{ backgroundImage: `url('${loginBackgroundUrl}')` }"
        />
        <div class="absolute inset-0" :style="{ background: loginOverlayGradient }" />
      </div>

      <!-- Extended white logo header -->
      <div
        class="absolute left-0 top-0 z-20 flex h-[110px] w-[min(92vw,720px)] items-center gap-4 rounded-br-[72px] bg-white px-5 sm:h-[125px] sm:gap-5 sm:px-8 sm:rounded-br-[96px] lg:h-[135px] lg:w-[min(44vw,720px)] lg:rounded-br-[120px] lg:px-10"
      >
        <img :src="zaqaLogoUrl" alt="ZAQA logo" class="h-11 w-auto shrink-0 object-contain sm:h-12 lg:h-14" />
        <span class="h-10 w-px shrink-0 bg-slate-200" aria-hidden="true" />
        <span class="text-xs font-bold uppercase tracking-[0.16em] text-[#0F2A4D] sm:text-sm lg:text-[15px] lg:tracking-[0.18em]">
          Verification Portal
        </span>
      </div>

      <div class="relative z-10 flex flex-1 flex-col lg:min-h-0 lg:flex-row">
        <!-- Left hero panel -->
        <aside class="relative flex min-h-[320px] flex-col text-white lg:min-h-0 lg:w-[55%] lg:shrink-0">
          <!-- Hero content -->
          <div
            class="relative z-10 flex flex-1 flex-col px-6 pb-8 pt-[7.5rem] sm:px-10 sm:pb-10 sm:pt-[8.25rem] lg:px-12 lg:pb-12 lg:pt-[9.5rem]"
          >
            <div class="max-w-xl">
              <p class="text-xs font-bold uppercase tracking-[0.22em] text-[#EF7D00] sm:text-sm">WELCOME BACK</p>
              <h1 class="mt-3 text-4xl font-bold tracking-tight text-white sm:text-5xl lg:text-[3.25rem] lg:leading-tight">
                Member Login
              </h1>
              <div class="mt-4 h-1 w-16 rounded-full bg-[#EF7D00]" aria-hidden="true" />

              <p class="mt-6 max-w-lg text-sm leading-relaxed text-white/85 sm:text-[15px]">
                Access your account to manage applications, verify qualifications and manage your organisation.
              </p>
            </div>

            <div class="mt-8 space-y-5 sm:mt-10 sm:space-y-6 lg:mt-12">
              <div v-for="feature in heroFeatures" :key="feature.title" class="flex gap-4">
                <span
                  class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-white/35 bg-white/10"
                >
                  <component :is="feature.icon" class="h-5 w-5 text-white" aria-hidden="true" />
                </span>
                <div>
                  <div class="text-sm font-semibold text-white sm:text-base">{{ feature.title }}</div>
                  <p class="mt-1 text-xs leading-relaxed text-white/75 sm:text-sm">{{ feature.description }}</p>
                </div>
              </div>
            </div>

            <div class="mt-auto pt-10 lg:pt-12">
              <div class="h-px w-full bg-[#EF7D00]/80" aria-hidden="true" />
              <div class="mt-4 flex flex-wrap items-center gap-x-3 gap-y-2 text-xs font-semibold text-white/90 sm:text-sm">
                <span class="inline-flex items-center gap-2">
                  <Shield class="h-4 w-4 text-[#EF7D00]" aria-hidden="true" />
                  Government Recognised
                </span>
                <span class="hidden text-[#EF7D00] sm:inline" aria-hidden="true">•</span>
                <span>Official ZAQA System</span>
              </div>
            </div>
          </div>
        </aside>

        <!-- Right login section -->
        <section class="relative flex flex-1 flex-col px-4 py-8 sm:px-8 sm:py-10 lg:w-[45%] lg:px-10 lg:py-12">

          <div class="relative flex flex-1 items-center justify-center">
            <div
              class="w-full max-w-[580px] rounded-[28px] bg-white px-6 py-8 shadow-[0_24px_80px_-32px_rgba(15,42,77,0.28)] sm:px-10 sm:py-12 lg:px-12 lg:py-14"
            >
              <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-[#0073BA]/10">
                <Lock class="h-7 w-7 text-[#0073BA]" aria-hidden="true" />
              </div>

              <h2 class="mt-6 text-center text-2xl font-bold tracking-tight text-[#0F2A4D] sm:text-[1.75rem]">
                Log in to your account
              </h2>
              <p class="mt-2 text-center text-sm text-slate-500">
                Use your email address or phone number to continue.
              </p>

              <form class="mt-8 space-y-5" @submit.prevent="submit">
                <div>
                  <label class="text-[11px] font-bold uppercase tracking-[0.14em] text-slate-500">
                    Email or phone number
                  </label>
                  <div class="relative mt-2">
                    <Mail
                      class="pointer-events-none absolute left-4 top-1/2 h-[18px] w-[18px] -translate-y-1/2 text-slate-400"
                      aria-hidden="true"
                    />
                    <input
                      v-model="form.identifier"
                      type="text"
                      placeholder="Enter your email or phone number"
                      class="h-14 w-full rounded-xl border border-slate-200 bg-white pl-12 pr-4 text-sm text-[#0F2A4D] outline-none transition placeholder:text-slate-400 focus:border-[#0073BA] focus:ring-4 focus:ring-[#0073BA]/15"
                      autocomplete="username"
                    />
                  </div>
                  <InputError :message="form.errors.identifier" />
                </div>

                <div>
                  <label class="text-[11px] font-bold uppercase tracking-[0.14em] text-slate-500">Password</label>
                  <div class="relative mt-2">
                    <Lock
                      class="pointer-events-none absolute left-4 top-1/2 h-[18px] w-[18px] -translate-y-1/2 text-slate-400"
                      aria-hidden="true"
                    />
                    <input
                      v-model="form.password"
                      :type="showPassword ? 'text' : 'password'"
                      placeholder="Enter your password"
                      class="h-14 w-full rounded-xl border border-slate-200 bg-white pl-12 pr-12 text-sm text-[#0F2A4D] outline-none transition placeholder:text-slate-400 focus:border-[#0073BA] focus:ring-4 focus:ring-[#0073BA]/15"
                      autocomplete="current-password"
                    />
                    <button
                      type="button"
                      class="absolute inset-y-0 right-2 inline-flex items-center justify-center rounded-lg px-3 text-slate-400 transition hover:text-[#0073BA] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0073BA]/30"
                      :aria-label="showPassword ? 'Hide password' : 'Show password'"
                      @click="showPassword = !showPassword"
                    >
                      <svg v-if="!showPassword" viewBox="0 0 24 24" fill="none" class="h-5 w-5" aria-hidden="true">
                        <path
                          d="M2.25 12s3.75-7.5 9.75-7.5S21.75 12 21.75 12s-3.75 7.5-9.75 7.5S2.25 12 2.25 12Z"
                          stroke="currentColor"
                          stroke-width="1.75"
                          stroke-linecap="round"
                          stroke-linejoin="round"
                        />
                        <path
                          d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"
                          stroke="currentColor"
                          stroke-width="1.75"
                          stroke-linecap="round"
                          stroke-linejoin="round"
                        />
                      </svg>
                      <svg v-else viewBox="0 0 24 24" fill="none" class="h-5 w-5" aria-hidden="true">
                        <path
                          d="M3 3l18 18"
                          stroke="currentColor"
                          stroke-width="1.75"
                          stroke-linecap="round"
                          stroke-linejoin="round"
                        />
                        <path
                          d="M10.585 10.585A2.999 2.999 0 0 0 12 15a3 3 0 0 0 2.415-4.415"
                          stroke="currentColor"
                          stroke-width="1.75"
                          stroke-linecap="round"
                          stroke-linejoin="round"
                        />
                        <path
                          d="M6.71 6.71C4.08 8.446 2.25 12 2.25 12s3.75 7.5 9.75 7.5c1.7 0 3.23-.39 4.56-1.03"
                          stroke="currentColor"
                          stroke-width="1.75"
                          stroke-linecap="round"
                          stroke-linejoin="round"
                        />
                        <path
                          d="M9.53 4.77A9.34 9.34 0 0 1 12 4.5c6 0 9.75 7.5 9.75 7.5a16.46 16.46 0 0 1-3.27 4.46"
                          stroke="currentColor"
                          stroke-width="1.75"
                          stroke-linecap="round"
                          stroke-linejoin="round"
                        />
                      </svg>
                    </button>
                  </div>
                  <InputError :message="form.errors.password" />
                </div>

                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                  <label class="flex items-center gap-2.5 text-sm text-[#0F2A4D]">
                    <input
                      v-model="form.remember"
                      type="checkbox"
                      class="rounded border-slate-300 text-[#0073BA] focus:ring-[#0073BA]/25"
                    />
                    Remember me
                  </label>
                  <Link href="/forgot-password" class="text-sm font-medium text-[#0073BA] hover:underline">
                    Forgot password?
                  </Link>
                </div>

                <button
                  type="submit"
                  class="group inline-flex h-14 w-full items-center justify-center gap-2 rounded-xl bg-[#0073BA] text-base font-semibold text-white shadow-[0_12px_28px_-10px_rgba(0,115,186,0.65)] transition hover:bg-[#0066a6] disabled:opacity-70"
                  :disabled="form.processing"
                >
                  Log in
                  <ArrowRight class="h-5 w-5 transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
                </button>
              </form>

              <div class="my-6 flex items-center gap-3 text-xs text-slate-400">
                <div class="h-px flex-1 bg-slate-200" />
                <span>or</span>
                <div class="h-px flex-1 bg-slate-200" />
              </div>

              <p class="text-center text-sm text-slate-500">
                Don&apos;t have an account?
                <Link href="/register" class="font-semibold text-[#0073BA] hover:underline">
                  Sign-up and Apply
                </Link>
                <ArrowRight class="ml-0.5 inline h-4 w-4 -translate-y-px text-[#0073BA]" aria-hidden="true" />
              </p>
            </div>
          </div>
        </section>
      </div>

      <!-- Bottom support strip -->
      <footer class="relative z-10 border-t-2 border-[#EF7D00] bg-white">
        <div class="mx-auto grid max-w-7xl gap-6 px-6 py-6 sm:grid-cols-3 sm:gap-8 sm:px-10 sm:py-7">
          <div v-for="item in supportItems" :key="item.title" class="flex gap-4">
            <span
              class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-[#0073BA]/25 bg-[#0073BA]/5"
            >
              <component :is="item.icon" class="h-5 w-5 text-[#0073BA]" aria-hidden="true" />
            </span>
            <div>
              <div class="text-sm font-semibold text-[#0F2A4D]">{{ item.title }}</div>
              <p class="mt-1 text-xs leading-relaxed text-slate-500 sm:text-sm">{{ item.description }}</p>
            </div>
          </div>
        </div>
      </footer>
    </div>
  </GuestLayout>
</template>
