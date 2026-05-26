# Payment Investigation And Logging Plan

## Current Findings

- PPPoE and hotspot payment flows currently span multiple controllers and callbacks, which makes failures hard to trace without a dedicated payment trace log.
- The existing payment path writes to `laravel.log`, `mpesa_raw_callback.log`, and `SystemLog`, but there is no landlord-controlled switch for persistent incident logging.
- The PPPoE dashboard and payment screens already use cache, but they still pay a cold-start cost on first render and after invalidation.
- The immediate critical issue is payment failure visibility. Dashboard latency is important, but payment traceability comes first.

## Immediate Goal

Make payment failures traceable end to end, then use the new trace data to fix the actual failing hop.

## Execution Plan

1. Add a landlord/sysadmin logging mode.
- Store the mode in the public `system_payment_settings` singleton.
- Supported modes: `stdout`, `persistent`, `both`.
- Default to `stdout`.

2. Add a dedicated payment trace logger.
- Write a structured payment trace line for every key step.
- When `persistent` is enabled, write to a file-backed trace log.
- When `stdout` is enabled, emit the same trace to container stdout/stderr.
- Keep the normal `SystemLog` record as a second source of truth.

3. Wire the payment flow into the shared logger.
- STK initiation.
- Callback received.
- Transaction map lookup.
- Tenant resolution.
- PPPoE branch.
- Hotspot branch.
- Duplicate callback detection.
- Post-payment job dispatch.
- Exceptions.

4. Use the trace output to isolate the failure.
- If initiation fails, inspect the M-Pesa response.
- If callback arrives but no mapping is found, inspect the public transaction map.
- If callback maps correctly but payment still does not progress, inspect the tenant-context branch and queue dispatch.
- If the callback never arrives, inspect STK initiation and M-Pesa request logs.

5. Once the root cause is confirmed, patch it and keep persistent logging on until stable.

6. Switch the logging mode back to `stdout` after the payment path is stable.

## Operational Notes

- Persistent logs are intended for incident response and root-cause capture.
- The switch back to `stdout` should be a normal landlord/sysadmin action, not a code change.
- This plan should be executed before any broader dashboard optimization work.
