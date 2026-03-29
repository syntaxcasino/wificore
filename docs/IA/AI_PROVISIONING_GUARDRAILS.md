1. Purpose

This document defines mandatory safety checks that AI tools must perform before generating or deploying router configuration.

The provisioning system manages:

PPPoE subscriber services

WireGuard management tunnels

Firewall policies

Remote monitoring

Automated deployments

Incorrect firewall rules can:

block SSH access

block WireGuard VPN

disable monitoring

lock administrators out of routers

break customer connectivity

AI must prevent unsafe configurations from being deployed.

2. Absolute Safety Rule

Before deploying any configuration, the AI must verify:

management access remains reachable

This includes:

SSH

Winbox

API

WireGuard

SNMP monitoring

If any rule may block these services, deployment must stop.

3. Required Management Access

Routers must always allow management access from trusted networks.

Trusted networks include:

10.0.0.0/8
WireGuard management subnet
Monitoring servers
Provisioning servers

AI must verify that firewall rules explicitly allow these networks.

Example safe rule:

add action=accept chain=input src-address=10.0.0.0/8 protocol=tcp dst-port=22,8291,8728,8729 comment="MGMT-ALLOW"
4. Mandatory Firewall Rule Order

Firewall rule order must follow strict rules.

INPUT chain required order
accept established,related
allow WireGuard port
allow trusted management networks
allow monitoring (SNMP)
allow PPPoE discovery
drop unwanted interface traffic
global drop rule (last)

If the AI detects a drop rule before allow rules, it must flag the configuration as unsafe.

5. Global Drop Rule Protection

Drop rules such as:

add action=drop chain=input

must always be the last rule.

If a drop rule appears before:

management rules

WireGuard rules

monitoring rules

the configuration is unsafe.

AI must refuse deployment.

6. WireGuard Connectivity Protection

WireGuard tunnels are used for router management.

AI must verify the firewall allows:

UDP port 51830

Example safe rule:

add action=accept chain=input protocol=udp dst-port=51830 comment="Allow WireGuard"

Blocking this port may break remote access.

7. SSH Access Protection

SSH must remain accessible.

The firewall must allow:

port 22

from trusted networks.

Unsafe example:

drop chain=input dst-port=22

This rule must never appear before the allow rule.

8. Interface List Safety

Provisioning uses interface lists such as:

WAN
PPPOE
PPPOE-ACTIVE

AI must ensure firewall rules referencing interface lists are valid.

Incorrect usage can break routing policies.

Example safe rule:

add action=accept chain=forward in-interface-list=PPPOE-ACTIVE out-interface-list=WAN
9. PPPoE Network Protection

Firewall rules must not block PPPoE traffic.

AI must verify rules allow:

PPPoE discovery ports
8863
8864

Example:

add action=accept chain=input protocol=udp dst-port=8863-8864

Blocking these ports prevents customer authentication.

10. NAT Safety

NAT rules must allow subscriber traffic to reach the internet.

Example safe rule:

add action=masquerade chain=srcnat out-interface-list=WAN

If NAT rules are missing, internet connectivity will fail.

11. Router Lockout Detection

AI must analyze generated firewall rules and detect the following risks:

Critical lockout conditions
global drop rule placed early
blocking SSH port before allow rules
blocking WireGuard port
removing trusted management networks
dropping all input traffic

If any of these conditions exist, the AI must stop provisioning.

12. Automatic Configuration Validation

Before deployment the AI must simulate rule evaluation.

Checklist:

can SSH connect?
can WireGuard connect?
can SNMP connect?
can PPPoE authenticate?
can WAN traffic pass?

If any check fails, the configuration must be rejected.

13. Idempotent Provisioning

Provisioning scripts must be safe to run multiple times.

AI must avoid creating duplicates such as:

duplicate firewall rules
duplicate interface lists
duplicate bridges
duplicate PPPoE servers

Provisioning must check if resources already exist.

14. Safe Deployment Sequence

Configuration must be applied in the following order:

interfaces
wireguard
routing
pppoe
firewall rules
nat
monitoring

Firewall rules must always be deployed after core networking.

15. Emergency Recovery Safeguard

Before applying firewall rules, the system should ensure a fallback exists.

Recommended safety mechanism:

Temporary rule allowing management:

add action=accept chain=input src-address=10.0.0.0/8 comment="TEMP-MGMT-SAFETY"

Remove only after provisioning succeeds.

16. AI Configuration Review Protocol

When reviewing router configuration, AI must perform:

firewall rule audit
management access validation
wireguard connectivity check
pppoe functionality validation
nat validation
17. Deployment Refusal Conditions

AI must refuse to deploy configuration if:

management access may be blocked
wireguard port is blocked
ssh port is blocked
global drop rule is misplaced

Instead, AI must generate a safe corrected configuration.

18. Final Principle

Routers provide connectivity for real users.

A bad configuration can:

disconnect entire networks

lock administrators out

break provisioning systems

interrupt internet access

AI must prioritize:

safety
connectivity
observability
recoverability

over speed or convenience.

✅ Recommended usage

When prompting the AI in Windsurf Editor, include:

Follow:
/docs/AI_ENGINEERING_RULES.md
/docs/AI_PROVISIONING_GUARDRAILS.md

before generating any router configuration.