Engineering Rules for AI-Assisted Development
Purpose

This document defines rules that AI tools must follow when modifying or analyzing the codebase.

The system manages:

Router provisioning

MikroTik automation

WireGuard connectivity

PPPoE services

Queue-based deployments

Multi-tenant infrastructure

Payment integrations

Network monitoring

AI tools must prioritize:

system stability

backward compatibility

network safety

minimal code changes






1. GLOBAL AI SAFETY RULES

AI must always follow these rules.

Never Do The Following

AI must never:

Rewrite large files without explicit request

Rename queue jobs

Rename service classes

Change API routes

Modify database schema

Remove logging

Change provisioning order

Remove firewall safety rules

These changes can break production infrastructure.

Always Use Minimal Patches

When modifying code:

Identify root cause

Change only necessary lines

Provide patch-style modifications

Maintain existing architecture

Preserve Observability

AI must never remove:

logging statements

error handling

monitoring hooks

metrics collection

These are required for production debugging.





2. LARAVEL ARCHITECTURE RULES

The backend is built with Laravel.

AI must respect the existing architecture.

Service Layer Pattern

Business logic must remain inside:

app/Services/

Controllers must remain thin.

Controllers should only:

validate requests

call services

return responses

AI must not move logic into controllers.

Job-Based Provisioning

Router provisioning runs through queue jobs.

Example:

DeployRouterServiceJob

These jobs must remain:

idempotent

retry-safe

stateless

AI must not change:

constructor parameters
queue names
dispatch behavior
Tenant Isolation

The system is multi-tenant.

Every database query must respect tenant context.

AI must not remove:

tenant scoping
tenant middleware
tenant-aware traits





3. QUEUE WORKER RELIABILITY RULES

Provisioning runs through background workers.

AI must ensure queue jobs are safe.

Idempotent Jobs

Jobs must be safe to run multiple times.

Provisioning logic must check:

existing configuration
existing interfaces
existing firewall rules

AI must never generate scripts that fail if executed twice.

Retry Safety

Jobs may retry automatically.

AI must ensure:

no duplicate router resources
no duplicate firewall rules
no duplicate interface lists
Logging

Every provisioning attempt must log:

router_id
service_id
execution time
failure reason

Logging must never be removed.







4. MIKROTIK PROVISIONING SAFETY RULES

Routers run MikroTik RouterOS.

AI must follow strict configuration safety practices.

Management Access Must Always Work

Routers are managed via:

SSH
Winbox
API
WireGuard

AI must ensure firewall rules never block management access.

Allowed networks include:

10.0.0.0/8
WireGuard network
monitoring servers
Firewall Rule Ordering

Firewall rule order is critical.

Correct INPUT chain order:

accept established,related
allow WireGuard VPN
allow management networks
allow SNMP monitoring
allow PPPoE discovery
drop unwanted traffic
global drop rule last

Incorrect rule order can lock administrators out.

Global Drop Rules

Global drop rules must always be the last rule.

Example:

add action=drop chain=input comment=GLOBAL-DEFAULT-DROP-IN

AI must never place drop rules earlier.

Interface Lists

The provisioning system relies on interface lists.

Examples:

WAN
PPPOE
PPPOE-ACTIVE

AI must verify these lists are used consistently in firewall rules.

PPPoE Safety

Provisioning must verify:

bridge configuration
pppoe server interface
ip pool
ppp profile

Misconfiguration can break subscriber access.

WireGuard Safety

WireGuard provides remote management.

AI must verify:

listen port
allowed-address
routing rules
firewall exceptions

WireGuard traffic must never be blocked.







5. ROUTER LOCKOUT PREVENTION

AI must detect configurations that may lock administrators out.

Potential risks include:

firewall drop rules before allow rules
blocking WireGuard interface
blocking SSH port
removing trusted networks

If such a configuration is detected:

AI must refuse to deploy it and propose a safe alternative.





6. SAFE PROVISIONING STRATEGY

Provisioning must follow this sequence.

1 configure interfaces
2 configure WireGuard
3 configure routing
4 configure PPPoE
5 configure firewall rules
6 configure NAT
7 apply monitoring

Firewall rules should be applied last.




7. FRONTEND RULES

The frontend is built with Vue.js.

AI must preserve:

component structure
router configuration
API integration
state management

AI must not rename:

API endpoints
route names
store modules



8. DATABASE RULES

AI must not:

rename tables
change migrations
remove indexes
modify primary keys

Database schema changes must be approved manually.




9. SECURITY RULES

AI must not introduce vulnerabilities.

Always validate:

user input
router commands
external API responses

Sensitive values must remain stored in:

environment variables
secure vaults





10. CODE GENERATION RULES

When generating code, AI must:

follow Laravel conventions
follow existing naming patterns
preserve service boundaries
avoid duplication

Large refactors must be proposed separately.




11. DEBUGGING RULES

When debugging failures, AI must:

1 analyze logs
2 analyze stacktrace
3 analyze configuration
4 identify root cause
5 propose minimal fix

AI must not guess without evidence.





12. WINDSURF AI WORKFLOW

When using Windsurf Editor, the workflow must be:

1 read AI_ENGINEERING_RULES.md
2 analyze relevant code
3 identify root cause
4 generate minimal patch
5 validate safety

AI must prioritize system stability over speed.





Final Principle

The system manages production network infrastructure.

Any incorrect modification can:

break internet access

lock administrators out of routers

disrupt provisioning

impact customer connectivity

AI must always prioritize:

safety
stability
observability
minimal changes