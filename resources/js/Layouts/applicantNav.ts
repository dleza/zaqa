import type { Component } from 'vue'

import {
  LayoutDashboard,
  FilePlus,
  Files,
  ReceiptText,
  Receipt,
  CreditCard,
  UserCircle,
  KeyRound,
  Layers,
} from 'lucide-vue-next'

export type ApplicantNavItem = {
  key: string
  label: string
  href: string
  icon: Component
  badgeKey?: string
  /** Visible only when applicant_type is institution */
  institutionOnly?: boolean
}

export type ApplicantNavSection = {
  key: string
  label: string
  items: ApplicantNavItem[]
}

export const applicantNavSections: ApplicantNavSection[] = [
  {
    key: 'dashboard',
    label: 'Dashboard',
    items: [{ key: 'dashboard', label: 'Dashboard', href: '/applicant/dashboard', icon: LayoutDashboard }],
  },
  {
    key: 'verification',
    label: 'Verification',
    items: [
      { key: 'submit', label: 'Submit Application', href: '/applicant/applications/new', icon: FilePlus },
      {
        key: 'multiple',
        label: 'Multiple Applications',
        href: '/applicant/applications/multiple/new',
        icon: Layers,
        institutionOnly: true,
      },
      { key: 'applications', label: 'My Applications', href: '/applicant/applications', icon: Files, badgeKey: 'applications' },
    ],
  },
  {
    key: 'payments',
    label: 'Payments',
    items: [
      { key: 'invoices', label: 'Invoices', href: '/applicant/invoices', icon: ReceiptText, badgeKey: 'invoices' },
      { key: 'payments', label: 'Payments', href: '/applicant/payments', icon: CreditCard },
      { key: 'receipts', label: 'Receipts', href: '/applicant/receipts', icon: Receipt },
    ],
  },

];

