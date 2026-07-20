# Intervention reports

## Workflow

An assigned technician starts an intervention before completing its report. The guided workflow contains four steps:

1. Confirm the diagnosis and root cause.
2. Record the actions performed and parts used.
3. Upload at least one before photo and one after photo.
4. Confirm the safety checks, choose the field outcome, and submit.

Submitting the report atomically creates an immutable report snapshot, completes the work order, records the event, and applies the linked alert or maintenance lifecycle.

## Outcomes

- `operational`: the work order and linked alert are resolved.
- `operational-monitoring`: the work order and linked alert are resolved, with monitoring recorded in the report.
- `follow-up-required`: the work order is completed, but the linked alert returns to the unassigned queue for another intervention.

## Evidence security

- Evidence is stored on Laravel's private `local` disk under an intervention-specific directory.
- Accepted formats are JPEG, PNG, and WebP, with a 5 MB limit per file and 10 photos per intervention.
- A photo is returned only through an authenticated, policy-protected endpoint.
- Technicians can add or remove evidence only on their own active interventions.
- Once a final report exists, the report and its evidence are read-only.
- The SHA-256 checksum, original filename, MIME type, size, uploader, phase, and timestamps are retained.

## API

- `POST /api/interventions/{intervention}/photos`: upload private evidence.
- `GET /api/interventions/{intervention}/photos/{photo}`: stream authorized evidence inline.
- `DELETE /api/interventions/{intervention}/photos/{photo}`: remove evidence before submission.
- `POST /api/interventions/{intervention}/report`: validate and submit the final report.

Directly changing an intervention to `resolved` through the generic update endpoint is rejected. The guided report is the only completion path.
