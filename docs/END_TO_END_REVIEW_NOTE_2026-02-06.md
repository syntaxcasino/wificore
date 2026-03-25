# End-to-End Review Note (2026-02-06)

## Scope
- Captive portal routing and package loading (public endpoints and portal config).
- Tenant/router identification (subdomain vs router IP).
- Payment flow (initiate -> callback -> provisioning).
- RADIUS and hotspot session activation.
- Hybrid/hotspot captive portal URL generation.

## Findings
1. Public package loading relies on router-based tenant detection via `Router::withoutGlobalScope()` and `router->tenant_id`, but routers live in tenant schemas and do not include a tenant_id column. This can return null outside tenant context and break router-based tenant detection.
2. Public package loading uses `orWhereHas('routers')` with `package_router` in tenant schema. Without an explicit tenant search_path, router-specific packages may be missed or error if the join targets public schema.
3. Payment initiation validates `router_id` with `exists:routers,id` and auto-detects router by IP using the Router model; this can fail when routers are not in the public schema.
4. Zero-config hotspot/hybrid generators build the captive portal URL from `$router->tenant`, but Router has no tenant relationship defined, so portal redirects may resolve to null unless another layer populates it.
5. Captive portal config expects `router_id` in `/portal/config`, while the current public portal view uses `/public/packages`. This can lead to mismatched tenant identification depending on the access path.

## Status
Issues found during review. Do not proceed with code edits until the tenant/router identification strategy is confirmed.

## Decision Needed
Choose a single source of truth for tenant identification on public/captive endpoints:
- Subdomain-only (e.g., `<tenant_slug>.wificore.traidsolutions.com`), or
- Router-aware (requires a public router registry or explicit tenant_id propagation).
