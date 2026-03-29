# Windsurf AI Workspace Rules

The AI must always follow the documents located in:

docs/AI/

Important documents:

- docs/AI/AI_ENGINEERING_RULES.md
- docs/AI/AI_PROVISIONING_GUARDRAILS.md
- docs/AI/AI_ROUTER_AUTOMATION_PLAYBOOK.md

Before generating or modifying code, the AI must:

1. Read these documents.
2. Apply all safety and architecture rules.
3. Generate minimal patches only.
4. Never break provisioning or router management access.
5. Follow Laravel service architecture and queue job safety rules.

If a task involves router configuration or provisioning logic, the AI must prioritize the rules defined in:

docs/AI/AI_PROVISIONING_GUARDRAILS.md