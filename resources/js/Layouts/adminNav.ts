import {
  BadgeCheck,
  Banknote,
  BookOpen,
  ClipboardList,
  FileText,
  FileSpreadsheet,
  FilePenLine,
  Globe,
  LayoutDashboard,
  ShieldCheck,
  Sparkles,
  Users,
  UserPlus,
  Building2,
  GraduationCap,
  Coins,
  Tags,
  BarChart3,
  KeyRound,
  MessageSquare,
} from 'lucide-vue-next'

export type AdminNavItem = {
  label: string
  href?: string
  icon?: any
  activeStartsWith?: string
  activeExact?: boolean
  requiredAnyPermissions?: string[]
  children?: AdminNavItem[]
}

export type AdminNavSection = {
  label: string
  items: AdminNavItem[]
  requiredAnyPermissions?: string[]
}

const reportsSectionPermissions = [
  'reports.view',
  'finance.reports.view',
  'reports.finance.view',
  'reports.verification.view',
  'reports.certificates.view',
  'reports.sla.view',
  'verification.level1.process',
  'sms.logs.view',
]

const financeReportPermissions = ['finance.reports.view', 'reports.finance.view']
const verificationReportPermissions = ['reports.view', 'reports.verification.view']
const certificateReportPermissions = ['reports.view', 'reports.certificates.view']
const slaReportPermissions = ['reports.view', 'reports.sla.view']

export const adminNavSections: AdminNavSection[] = [
  {
    label: '',
    items: [
      {
        label: 'Dashboard',
        href: '/admin/dashboard',
        icon: LayoutDashboard,
        activeStartsWith: '/admin/dashboard',
        requiredAnyPermissions: ['dashboard.view'],
      },
    ],
  },
  {
    label: 'User management',
    requiredAnyPermissions: ['admin.users.view', 'admin.applicants.view', 'admin.roles.view', 'admin.roles.manage'],
    items: [
      {
        label: 'Users',
        href: '/admin/users',
        icon: Users,
        activeStartsWith: '/admin/users',
        requiredAnyPermissions: ['admin.users.view'],
      },
      {
        label: 'Applicants',
        href: '/admin/applicants',
        icon: Users,
        activeStartsWith: '/admin/applicants',
        requiredAnyPermissions: ['admin.applicants.view'],
      },
      {
        label: 'Roles & permissions',
        href: '/admin/roles',
        icon: ShieldCheck,
        activeStartsWith: '/admin/roles',
        requiredAnyPermissions: ['admin.roles.view', 'admin.roles.manage'],
      },
    ],
  },
  {
    label: 'Verified Applications',
    requiredAnyPermissions: ['admin.applications.view', 'admin.certificates.view'],
    items: [
      {
        label: 'Applications pool',
        href: '/admin/applications',
        icon: ClipboardList,
        activeStartsWith: '/admin/applications',
        activeExact: true,
        requiredAnyPermissions: ['admin.applications.view'],
      },
      {
        label: 'Qualifications',
        href: '/admin/applications/qualifications',
        icon: GraduationCap,
        activeStartsWith: '/admin/applications/qualifications',
        requiredAnyPermissions: ['admin.applications.view'],
      },
      {
        label: 'Search For Application',
        href: '/admin/applications/track',
        icon: FileText,
        activeStartsWith: '/admin/applications/track',
        requiredAnyPermissions: ['admin.applications.view'],
      },
      {
        label: 'Certificates',
        href: '/admin/certificates',
        icon: BadgeCheck,
        activeStartsWith: '/admin/certificates',
        requiredAnyPermissions: ['admin.certificates.view'],
      },
    ],
  },
  {
    label: 'Pending Verification',
    requiredAnyPermissions: ['verification.pool.view', 'verification.level1.process'],
    items: [
      {
        label: 'Applications Pool',
        href: '/admin/verification/pool',
        icon: ShieldCheck,
        activeStartsWith: '/admin/verification/pool',
        requiredAnyPermissions: ['verification.pool.view'],
      },
      {
        label: 'Awaiting Level 1 Assignment',
        href: '/admin/verification/awaiting-level1-assignment',
        icon: UserPlus,
        activeStartsWith: '/admin/verification/awaiting-level1-assignment',
        requiredAnyPermissions: ['verification.assign'],
      },
      {
        label: 'Awaiting Level 2 Assignment',
        href: '/admin/verification/awaiting-level2-assignment',
        icon: UserPlus,
        activeStartsWith: '/admin/verification/awaiting-level2-assignment',
        requiredAnyPermissions: ['verification.assign'],
      },
      {
        label: 'Category by Country',
        href: '/admin/verification/pool/country',
        icon: Globe,
        activeStartsWith: '/admin/verification/pool/country',
        requiredAnyPermissions: ['verification.pool.view'],
      },
      {
        label: 'Category by Awarding Institution',
        href: '/admin/verification/pool/awarding-institution',
        icon: Building2,
        activeStartsWith: '/admin/verification/pool/awarding-institution',
        requiredAnyPermissions: ['verification.pool.view'],
      },
      {
        label: 'Assigned to Me',
        href: '/admin/verification/assigned-to-me',
        icon: Users,
        activeStartsWith: '/admin/verification/assigned-to-me',
        requiredAnyPermissions: ['verification.level1.process', 'verification.level2.review'],
      },
      {
        label: 'Awaiting applicant (my send-backs)',
        href: '/admin/verification/awaiting-applicant-resubmission',
        icon: ClipboardList,
        activeStartsWith: '/admin/verification/awaiting-applicant-resubmission',
        requiredAnyPermissions: ['verification.send_back', 'verification.pool.view'],
      },
      {
        label: 'Auto-Verified Pending Review',
        href: '/admin/verification/auto-verified',
        icon: Sparkles,
        activeStartsWith: '/admin/verification/auto-verified',
        requiredAnyPermissions: ['verification.level2.review'],
      },
    ],
  },
  {
    label: 'Reports',
    requiredAnyPermissions: reportsSectionPermissions,
    items: [
      {
        label: 'Reports overview',
        href: '/admin/reports',
        icon: BarChart3,
        activeStartsWith: '/admin/reports',
        activeExact: true,
        requiredAnyPermissions: reportsSectionPermissions,
      },
      {
        label: 'Finance reports',
        href: '/admin/reports/payments',
        icon: BarChart3,
        activeStartsWith: '/admin/reports/payments',
        requiredAnyPermissions: financeReportPermissions,
      },
      {
        label: 'Applications overview',
        href: '/admin/reports/applications',
        icon: BarChart3,
        activeStartsWith: '/admin/reports/applications',
        requiredAnyPermissions: verificationReportPermissions,
      },
      {
        label: 'Qualification verification',
        href: '/admin/reports/qualifications',
        icon: BarChart3,
        activeStartsWith: '/admin/reports/qualifications',
        requiredAnyPermissions: verificationReportPermissions,
      },
      {
        label: 'Verifier performance',
        href: '/admin/reports/verifiers',
        icon: BarChart3,
        activeStartsWith: '/admin/reports/verifiers',
        requiredAnyPermissions: verificationReportPermissions,
      },
      {
        label: 'Awarding institutions',
        href: '/admin/reports/awarding-institutions',
        icon: BarChart3,
        activeStartsWith: '/admin/reports/awarding-institutions',
        requiredAnyPermissions: verificationReportPermissions,
      },
      {
        label: 'Turnaround & SLA',
        href: '/admin/reports/sla',
        icon: BarChart3,
        activeStartsWith: '/admin/reports/sla',
        requiredAnyPermissions: slaReportPermissions,
      },
      {
        label: 'Certificates issued',
        href: '/admin/reports/certificates',
        icon: BarChart3,
        activeStartsWith: '/admin/reports/certificates',
        requiredAnyPermissions: certificateReportPermissions,
      },
      {
        label: 'My performance',
        href: '/admin/reports/my-performance',
        icon: BarChart3,
        activeStartsWith: '/admin/reports/my-performance',
        requiredAnyPermissions: ['verification.level1.process'],
      },
      {
        label: 'SMS logs',
        href: '/admin/settings/sms/logs',
        icon: MessageSquare,
        activeStartsWith: '/admin/settings/sms/logs',
        requiredAnyPermissions: ['sms.logs.view'],
      },
    ],
  },
  {
    label: 'Finance',
    requiredAnyPermissions: ['finance.dashboard.view', 'finance.payment_proofs.view', 'finance.payments.view'],
    items: [
      {
        label: 'Dashboard',
        href: '/admin/finance',
        icon: Banknote,
        activeStartsWith: '/admin/finance',
        requiredAnyPermissions: ['finance.dashboard.view'],
      },
      {
        label: 'Payment proof review',
        href: '/admin/finance/payment-proofs',
        icon: Banknote,
        activeStartsWith: '/admin/finance/payment-proofs',
        requiredAnyPermissions: ['finance.payment_proofs.view'],
      },
      {
        label: 'Processed payments',
        href: '/admin/finance/payments',
        icon: Banknote,
        activeStartsWith: '/admin/finance/payments',
        requiredAnyPermissions: ['finance.payments.view'],
      },
    ],
  },
  {
    label: 'Learner records',
    requiredAnyPermissions: ['learner_records.view', 'learner_record_submissions.view'],
    items: [
      {
        label: 'Records',
        href: '/admin/learner-records',
        icon: BookOpen,
        activeStartsWith: '/admin/learner-records',
        activeExcludePrefixes: ['/admin/learner-records/submissions'],
        requiredAnyPermissions: ['learner_records.view'],
      },
      {
        label: 'Pending submissions',
        href: '/admin/learner-records/submissions',
        icon: ClipboardList,
        activeStartsWith: '/admin/learner-records/submissions',
        requiredAnyPermissions: ['learner_record_submissions.view'],
      },
      {
        label: 'Imports',
        href: '/admin/learner-records/imports',
        icon: FileSpreadsheet,
        activeStartsWith: '/admin/learner-records/imports',
        requiredAnyPermissions: ['learner_records.view'],
      },
    ],
  },
  {
    label: 'System settings',
    requiredAnyPermissions: [
      'settings.countries.view',
      'settings.certificate_subjects.view',
      'settings.awarding_institutions.view',
      'settings.qualification_types.view',
      'settings.qualification_titles.view',
      'settings.billing_categories.view',
      'settings.fees.view',
      'settings.departments.view',
      'settings.document_signatures.view',
      'verification.assign',
      'sms.balance.view',
    ],
    items: [
      {
        label: 'Countries',
        href: '/admin/settings/countries',
        icon: Globe,
        activeStartsWith: '/admin/settings/countries',
        requiredAnyPermissions: ['settings.countries.view'],
      },
      {
        label: 'Subjects',
        href: '/admin/settings/certificate-subjects',
        icon: BookOpen,
        activeStartsWith: '/admin/settings/certificate-subjects',
        requiredAnyPermissions: ['settings.certificate_subjects.view'],
      },
      {
        label: 'Awarding Institutions',
        href: '/admin/settings/awarding-institutions',
        icon: Building2,
        activeStartsWith: '/admin/settings/awarding-institutions',
        requiredAnyPermissions: ['settings.awarding_institutions.view'],
      },
      {
        label: 'Qualification Types',
        href: '/admin/settings/qualification-types',
        icon: GraduationCap,
        activeStartsWith: '/admin/settings/qualification-types',
        requiredAnyPermissions: ['settings.qualification_types.view'],
      },
      {
        label: 'Qualification Titles',
        href: '/admin/settings/qualification-titles',
        icon: BookOpen,
        activeStartsWith: '/admin/settings/qualification-titles',
        requiredAnyPermissions: ['settings.qualification_titles.view'],
      },
      {
        label: 'Billing Categories',
        href: '/admin/settings/billing-categories',
        icon: Tags,
        activeStartsWith: '/admin/settings/billing-categories',
        requiredAnyPermissions: ['settings.billing_categories.view'],
      },
      {
        label: 'Fees',
        href: '/admin/settings/fees',
        icon: Coins,
        activeStartsWith: '/admin/settings/fees',
        requiredAnyPermissions: ['settings.fees.view'],
      },
      {
        label: 'Departments',
        href: '/admin/settings/departments',
        icon: Users,
        activeStartsWith: '/admin/settings/departments',
        requiredAnyPermissions: ['settings.departments.view'],
      },
      {
        label: 'Document Signatures',
        href: '/admin/settings/document-signatures',
        icon: FilePenLine,
        activeStartsWith: '/admin/settings/document-signatures',
        requiredAnyPermissions: ['settings.document_signatures.view'],
      },
      {
        label: 'SMS Balance',
        href: '/admin/settings/sms/balance',
        icon: MessageSquare,
        activeStartsWith: '/admin/settings/sms/balance',
        requiredAnyPermissions: ['sms.balance.view', 'sms.balance.manage'],
      },
      {
        label: 'Assignment Categories',
        href: '/admin/verification/assignment-categories',
        icon: Users,
        activeStartsWith: '/admin/verification/assignment-categories',
        requiredAnyPermissions: ['verification.assign'],
      },
    ],
  },
  {
    label: 'Integrations',
    requiredAnyPermissions: ['institution_api.manage', 'institution_api.logs.view', 'institution_api.docs.view'],
    items: [
      {
        label: 'Institution API Clients',
        href: '/admin/integrations/institution-api-clients',
        icon: KeyRound,
        activeStartsWith: '/admin/integrations/institution-api-clients',
        requiredAnyPermissions: ['institution_api.manage'],
      },
      {
        label: 'Institution Pull Integrations',
        href: '/admin/integrations/institution-integrations',
        icon: Globe,
        activeStartsWith: '/admin/integrations/institution-integrations',
        requiredAnyPermissions: ['institution_api.manage'],
      },
      {
        label: 'Institution API Logs',
        href: '/admin/integrations/institution-api-logs',
        icon: FileText,
        activeStartsWith: '/admin/integrations/institution-api-logs',
        requiredAnyPermissions: ['institution_api.logs.view'],
      },
      {
        label: 'Institution Pull Lookup Logs',
        href: '/admin/integrations/institution-pull-lookup-logs',
        icon: FileText,
        activeStartsWith: '/admin/integrations/institution-pull-lookup-logs',
        requiredAnyPermissions: ['institution_api.logs.view'],
      },
      {
        label: 'Institution API Docs',
        href: '/docs/institution-api',
        icon: BookOpen,
        activeStartsWith: '/docs/institution-api',
        requiredAnyPermissions: ['institution_api.docs.view'],
      },
    ],
  },
]
