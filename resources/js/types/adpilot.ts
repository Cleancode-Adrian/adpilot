export type Platform = 'google_ads' | 'meta_ads';
export type Severity = 'critical' | 'high' | 'medium' | 'low';

export interface Integration {
    id: number;
    provider: 'google' | 'meta';
    status: 'pending' | 'connected' | 'error';
    settings: Record<string, string | boolean> | null;
    last_synced_at: string | null;
    last_error: string | null;
}

export interface ClientProps {
    id: number;
    name: string;
    website_url: string;
    has_ads: boolean;
    has_care: boolean;
    has_general: boolean;
    admin_url: string | null;
    contact_name: string | null;
    contact_phone: string | null;
    industry: string | null;
    goal: 'sales' | 'leads' | 'mixed';
    lead_events: string[] | null;
    lead_event_names: string[];
    archived_at: string | null;
    currency: string;
    target_roas: number | null;
    target_cpa: number | null;
    monthly_budget: number | null;
    daily_budget: number | null;
    contact_email: string | null;
    auto_send_reports: boolean;
    notes: string | null;
    is_demo: boolean;
    open_count: number;
    integrations: Integration[];
    google_configured: boolean;
    meta_configured: boolean;
    care: CareSummary | null;
    credentials_count: number;
    wp: { status: string; alerts: number; critical: number; updates: number } | null;
    site: { status: 'up' | 'down' | 'unknown' | 'off' | null; ssl_days: number | null; last_response_ms: number | null; audit_score: number | null };
    panel: { id: number; username: string | null; url: string | null; has_secret: boolean } | null;
}

export interface CareSummary {
    period: string;
    total: number;
    done: number;
    overdue: number;
    due_today: number;
    minutes?: number;
}

export type CareStatus = 'todo' | 'done' | 'skipped';

export interface CareTask {
    id: number;
    client_id?: number;
    care_template_id?: number | null;
    period: string;
    title: string;
    description: string | null;
    due_date: string;
    status: CareStatus;
    done_at: string | null;
    note: string | null;
    minutes?: number | null;
    done_by?: { id: number; name: string } | null;
}

export interface CareTemplate {
    id: number;
    title: string;
    description: string | null;
    frequency: 'monthly' | 'quarterly' | 'semiannual' | 'yearly';
    start_month: number;
    due_day: number;
    active: boolean;
}

export interface Stat {
    id: number;
    name: string;
    platform: Platform;
    status?: string | null;
    channel_type?: string | null;
    campaign_name?: string;
    campaign_id?: number;
    impressions: number;
    clicks: number;
    cost: number;
    conversions: number;
    conversion_value: number;
    ctr: number;
    cpc: number;
    cpa: number | null;
    roas: number | null;
    cvr: number;
    frequency?: number | null;
    budget_lost_is?: number | null;
}

export interface KpiValue {
    value: number | null;
    previous: number | null;
    delta: number | null;
}

export interface Kpis {
    period: { from: string; to: string; prev_from: string; prev_to: string };
    ads: Record<'cost' | 'clicks' | 'impressions' | 'conversions' | 'conversion_value' | 'roas' | 'cpa' | 'ctr' | 'cpc', KpiValue>;
    analytics: Record<'sessions' | 'users' | 'transactions' | 'revenue' | 'cvr' | 'aov' | 'leads' | 'lead_cvr' | 'key_events', KpiValue>;
    has_analytics: boolean;
    goal: 'sales' | 'leads' | 'mixed';
    events: { event_name: string; count: number }[];
    platforms: Record<Platform, Stat>;
}

export interface DailyRow {
    date: string;
    google_cost: number;
    meta_cost: number;
    ads_value: number;
    ads_conversions: number;
    sessions: number;
    transactions: number;
    revenue: number;
    leads: number;
}

export interface Channel {
    channel: string;
    sessions: number;
    users: number;
    transactions: number;
    revenue: number;
    cvr: number;
    key_events: number;
    key_cvr: number;
    engagement_rate: number;
}

export interface Recommendation {
    id: number;
    client_id: number;
    client?: { id: number; name: string; currency?: string };
    platform: string;
    rule_key: string;
    severity: Severity;
    kind: 'problem' | 'opportunity';
    entity_name: string | null;
    title: string;
    description: string;
    action: string;
    impact: string | null;
    data: { terms?: { term: string; cost: number; clicks: number; campaign: string | null }[] } | null;
    status: 'open' | 'done' | 'dismissed' | 'resolved';
    detected_at: string;
    resolved_at: string | null;
}

export interface AuditCheck {
    key: string;
    group: string;
    label: string;
    status: 'ok' | 'warn' | 'fail' | 'info';
    details: string;
    fix: string | null;
    weight: number;
}

export interface Audit {
    id: number;
    url: string;
    status: 'pending' | 'running' | 'done' | 'failed';
    score: number | null;
    error: string | null;
    created_at: string;
    results: {
        final_url: string;
        platform: string | null;
        response_ms: number;
        page_kb: number;
        detected: { gtm: string[]; ga4: string[]; google_ads: string[]; meta_pixel: string[]; cmp: string[]; others: string[] };
        pagespeed: { performance: number; lcp_s: number; cls: number; tbt_ms: number } | null;
        checks: AuditCheck[];
        demo?: boolean;
    } | null;
}

export interface ReportRow {
    id: number;
    type: 'client' | 'internal' | 'work';
    period_start: string;
    period_end: string;
    ai_model: string | null;
    sent_at: string | null;
    created_at: string;
    public_url: string;
    pdf_url: string;
}
