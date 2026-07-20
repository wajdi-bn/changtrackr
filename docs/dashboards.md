# Role-aware dashboards

ChargeTrackr exposes one authenticated dashboard endpoint and derives its scope exclusively from the authenticated user's single role and organization assignment. The frontend never sends an organization identifier.

## API

```text
GET /api/dashboard?period=7d|30d|90d
```

The response contains:

- reporting period and comparison label;
- role-specific KPI cards;
- daily trend points and declared chart series;
- status breakdowns;
- recent scoped activity;
- rankings with their formula and period;
- methodology displayed in the interface;
- generation timestamp.

An unsupported period returns `422`. Authentication and `EnsureUserOrganizationScope` reject inactive accounts, invalid role combinations and inactive organization assignments.

## Role scopes

| Role | Scope and principal content |
|---|---|
| Super Admin | All active organizations, users, stations, tracked availability, settled platform revenue, global trends and organization ranking |
| Administrator | Exactly one organization: employees, customers with organization activity, tracked availability, revenue, map, activity and five business rankings |
| Operator | Exactly one organization: availability, unavailable hours, active and period sessions, energy, alerts, map and operational rankings |
| Technician | Only assigned alerts and interventions: open/critical work, resolved work, workload trend, statuses and serviced stations |
| Client | Only personal sessions and payments across organizations: active sessions, energy, spend, memberships and most-used stations |

Clients remain global users. Their personal dashboard can therefore combine their own sessions from multiple charging organizations without exposing another client's data.

## Period and comparison

The selected window includes today and exposes one daily point for each calendar date. A KPI comparison uses the immediately preceding window of equal length. When the previous value is zero, the API returns no percentage instead of presenting an artificial infinite increase.

## Availability formula

Availability is reconstructed from station-level `availability_transitions`, not from a stored dashboard percentage:

```text
tracked availability = operational monitored seconds / total monitored seconds
unavailable hours = (total monitored seconds - operational monitored seconds) / 3600
```

`available` and `charging` are operational. `offline`, `unavailable`, `faulted`, `reserved` and `maintenance` are unavailable to a new charging request. Monitoring begins at `availability_monitoring_started_at`, or at station creation when no explicit monitoring start exists.

The state at the beginning of the period is reconstructed from the last prior transition. If no prior transition exists, the first transition's `from_status` is used; a station with no transition uses its current calculated state for its monitored interval.

## Business formulas

- Settled revenue and client spending include only `paid` payments whose `paid_at` belongs to the period.
- Energy and session rankings use sessions whose `started_at` belongs to the period.
- Organization customers are global clients with at least one session on that organization's stations.
- Top clients are ranked by settled payment amount.
- Top technicians are ranked by resolved interventions whose `ended_at` belongs to the period.
- Top operators are ranked by authored alert and intervention audit events in the period.
- Top stations are ranked by delivered energy; regions are ranked by session count.

Every formula is returned in `methodology` or the ranking description and displayed next to the result.

## Frontend refresh

The dashboard query is invalidated after station availability, OCPP command, charging session, charging attempt and personal notification events. A 30-second polling fallback covers events without a dedicated Reverb channel.

## Verification

`DashboardApiTest` covers all five roles, invalid periods, organization isolation, client isolation across organizations, technician assignments and historical availability reconstruction. The empty-data path returns zero values and empty collections without division by zero.
