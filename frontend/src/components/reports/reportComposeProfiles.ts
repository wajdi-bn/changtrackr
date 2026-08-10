import type { InternalReportCategory, InternalReportPriority } from '../../types/reporting'

export type ReportComposeVariant = 'admin' | 'operator' | 'technician'

export interface ReportComposeTemplate {
  key: string
  label: string
  description: string
  title: string
  category: InternalReportCategory
  priority: InternalReportPriority
  summary: string
  body: string
}

interface ReportComposeProfile {
  eyebrow: string
  guidance: string
  categories: InternalReportCategory[]
  templates: ReportComposeTemplate[]
}

export const reportComposeProfiles: Record<ReportComposeVariant, ReportComposeProfile> = {
  admin: {
    eyebrow: 'Decision report',
    guidance: 'Frame the result, the business impact and the decision expected from the recipient.',
    categories: ['performance', 'operations', 'incident', 'maintenance', 'intervention', 'handover'],
    templates: [
      {
        key: 'performance-review',
        label: 'Performance review',
        description: 'Business, network and workforce results for a management decision.',
        title: 'Organization performance review',
        category: 'performance',
        priority: 'normal',
        summary: 'Summary of the verified results and the decisions required for the next period.',
        body: 'Verified results\n\nKey variance or risk\n\nOperational and financial impact\n\nDecision required\n\nOwner and target date',
      },
      {
        key: 'sla-escalation',
        label: 'SLA escalation',
        description: 'Escalate overdue alerts, interventions or service commitments.',
        title: 'SLA and service-risk escalation',
        category: 'incident',
        priority: 'urgent',
        summary: 'Service commitments require immediate ownership and a recovery decision.',
        body: 'Affected service or stations\n\nVerified SLA breach\n\nCustomer and business impact\n\nCurrent mitigation\n\nDecision and owner required',
      },
      {
        key: 'capacity-plan',
        label: 'Capacity decision',
        description: 'Align workforce, stations and planned maintenance capacity.',
        title: 'Operations capacity decision',
        category: 'operations',
        priority: 'important',
        summary: 'Capacity constraints and recommended allocation for the covered period.',
        body: 'Demand and workload\n\nAvailable capacity\n\nConstraint or dependency\n\nRecommended allocation\n\nApproval required',
      },
    ],
  },
  operator: {
    eyebrow: 'Control-room report',
    guidance: 'Record verified station state, actions already taken and the next operator action.',
    categories: ['handover', 'operations', 'incident', 'maintenance'],
    templates: [
      {
        key: 'shift-handover',
        label: 'Shift handover',
        description: 'Transfer active risks and actions to the next control-room shift.',
        title: 'Network shift handover',
        category: 'handover',
        priority: 'important',
        summary: 'Verified network state and items requiring continuity during the next shift.',
        body: 'Network state at handover\n\nActive sessions or unavailable stations\n\nActions completed\n\nOpen alerts and interventions\n\nNext checks and owners',
      },
      {
        key: 'incident-escalation',
        label: 'Incident escalation',
        description: 'Escalate an operational event with evidence and immediate impact.',
        title: 'Charging-network incident escalation',
        category: 'incident',
        priority: 'urgent',
        summary: 'An operational incident requires coordinated technical or management action.',
        body: 'Detection time and source\n\nAffected station or connector\n\nObserved OCPP state\n\nActions already attempted\n\nImpact, owner and next deadline',
      },
      {
        key: 'availability-watch',
        label: 'Availability watch',
        description: 'Share recurring connectivity or station-state observations.',
        title: 'Station availability watch',
        category: 'operations',
        priority: 'normal',
        summary: 'Stations requiring monitoring based on connectivity and computed availability.',
        body: 'Stations under watch\n\nHeartbeat and status evidence\n\nRecurring pattern\n\nActions completed\n\nMonitoring instruction for the next shift',
      },
    ],
  },
  technician: {
    eyebrow: 'Field report',
    guidance: 'Describe physical observations, evidence, work performed and the verified final state.',
    categories: ['intervention', 'maintenance', 'incident', 'handover'],
    templates: [
      {
        key: 'diagnosis',
        label: 'Field diagnosis',
        description: 'Report findings and evidence before a repair decision.',
        title: 'Field diagnosis and recommendation',
        category: 'intervention',
        priority: 'important',
        summary: 'Verified diagnosis, immediate safety status and recommended corrective action.',
        body: 'Station and connector inspected\n\nSymptoms reproduced\n\nMeasurements and evidence\n\nProbable root cause\n\nRecommended action and required parts',
      },
      {
        key: 'maintenance-completion',
        label: 'Maintenance completion',
        description: 'Confirm completed work, tests and remaining observations.',
        title: 'Maintenance completion report',
        category: 'maintenance',
        priority: 'normal',
        summary: 'Maintenance work completed and final operational checks recorded.',
        body: 'Planned work performed\n\nParts or consumables used\n\nSafety checks\n\nFunctional tests and final state\n\nFollow-up recommendation',
      },
      {
        key: 'field-blocker',
        label: 'Field blocker',
        description: 'Escalate blocked work, missing access or required parts.',
        title: 'Field intervention blocker',
        category: 'incident',
        priority: 'urgent',
        summary: 'Field work cannot continue without a decision, access or additional resources.',
        body: 'Work attempted\n\nBlocking condition\n\nSafety and service impact\n\nEvidence attached\n\nRequired decision, part or access',
      },
    ],
  },
}
