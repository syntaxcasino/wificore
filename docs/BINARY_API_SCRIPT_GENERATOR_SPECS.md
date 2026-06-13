# MikroTik Binary API Script Generator Specifications

## Overview
This document defines the standards for PHP script generators that produce RouterOS commands compatible with the Binary API (not just CLI/scripting).

## Official MikroTik Binary API Constraints

### 1. Supported Actions (from `isActionToken`)
The binary API ONLY supports these action words:
- `print` / `getall` (alias for print)
- `add`
- `set`
- `remove`
- `run` (for scripts)
- `enable`
- `disable`
- `import`

**NOT supported:**
- `:log info/warning/error` - Script interpreter command, no binary API endpoint
- `:delay` - Handled specially by binary client (converted to sleep)
- `:do`, `:if`, `:for` - Script control structures
- `:global`, `:local` - Variable declarations
- `:put`, `:error` - Script output commands
- `:find` (as command prefix) - Must be converted to query filters

### 2. Command Structure
Binary API commands follow this pattern:
```
/<menu-path>/<action> [parameters...]
```

**Examples:**
```
/interface/bridge/add name=br-123
/ppp/profile/set ?name=pppoe-prof-123 local-address=100.64.0.1
/ip/firewall/filter/remove ?comment~=PPPoE-abc123
```

### 3. [find ...] Expression Handling
The `[find ...]` syntax is RouterOS scripting, NOT native binary API. The binary client handles this by:

1. Detecting `[find name="xxx"]` or `[where ...]` patterns
2. Setting `findMutation = true`
3. Converting to API query filters: `?name=xxx` or `?comment~=xxx`
4. The Go client performs: print with filter → get .id → execute actual command with .id

**Supported filter operators in [find]:**
- `name="value"` → `?name=value`
- `comment~"pattern"` → `?comment~=pattern` (regex match)
- `service="ppp"` → `?service=ppp`

### 4. ID Resolution Strategy
For `set`, `remove`, `enable`, `disable` commands:

**Option A: By internal ID (best for binary API)**
```
/ppp/profile/set .id=*1 local-address=100.64.0.1
```

**Option B: By name with findMutation (client-side resolution)**
```
/ppp/profile/set "profile-name" local-address=100.64.0.1
```
This gets converted to:
1. `/ppp/profile/print ?name=profile-name` → returns `.id=*X`
2. `/ppp/profile/set .id=*X local-address=100.64.0.1`

### 5. Escaped Quote Handling
PHP generators output `\"` for quotes inside string values. The binary client must:
1. Recognize `\"` as an escaped quote (not string terminator)
2. Convert to unescaped value for API transmission

Example:
```
Input:  /radius add service=\"ppp\"
Output: /radius/add service=ppp
```

### 6. Value Cleaning Rules
Values must be cleaned of:
- Surrounding quotes: `"value"` → `value`
- Escaped quotes: `\"value\"` → `value`
- Trailing semicolons: `value;` → `value`
- RouterOS variable escapes: `\$` → `$`

## Script Generator Architecture

### Command Types

#### Type 1: Simple Add (no dependencies)
```php
"/ip pool add name=\"{$pool}\" ranges=\"{$rangeStart}-{$rangeEnd}\""
```
Binary API: `/ip/pool/add name=pppoe-pool-abc123 ranges=10.0.0.10-10.0.0.250`

#### Type 2: Set by Name (requires findMutation)
```php
"/ppp profile set \"{$prof}\" local-address=\"{$gw}\""
```
Binary API pattern: `?name={prof}` filter + set with resolved .id

#### Type 3: Remove with Pattern
```php
"/ip firewall filter remove [find comment~=\"PPPoE-{$id}\"]"
```
Binary API: `/ip/firewall/filter/remove ?comment~=PPPoE-abc123`

#### Type 4: Remove Before Add (idempotent pattern)
```php
"/interface bridge port remove [find bridge=\"{$bridge}\"]"
"/interface bridge remove [find name=\"{$bridge}\"]"
"/interface bridge add name=\"{$bridge}\""
```

#### Type 5: Service Hardening (multiple set commands)
```php
"/ip service set [find name=\"api\"] disabled=no address=\"{$allowAddr}\""
```
Binary API: `/ip/service/set ?name=api disabled=no address=10.8.0.0/24`

### Logging Strategy
Since `/log info` is NOT supported by binary API:

**Option A: Skip logging entirely (recommended for binary API)**
- Remove all `/log info` commands
- Rely on provisioning service logs

**Option B: Script-based logging via /system/script**
- Create a temporary script that logs
- Run the script
- Remove the script

**Selected approach:** Skip `/log` commands silently (handled by binary client)

### Delay Handling
`:delay` is handled specially by the binary client:
- Parsed from command: `:delay 500ms` or `:delay 2s`
- Converted to Go `time.Sleep()`
- No actual RouterOS command sent

### Section Organization
Scripts should be organized in clear sections:
1. Identity & AAA Setup
2. RADIUS Configuration
3. IP Pools & Interfaces
4. PPP Profiles & Bridge
5. PPPoE Server
6. Firewall & Security
7. Monitoring & Logging
8. Finalization

## Error Handling Philosophy

### Benign Errors (silently ignored)
- `already have such item` - Item exists, OK to continue
- `no such item` - Item doesn't exist for removal, OK to continue
- `nothing to remove` - Empty remove operation, OK to continue

### Fatal Errors (stop provisioning)
- `bad command name` - Syntax error in command
- `expected end of command` - Malformed parameters
- `invalid value` - Wrong parameter format
- Connection errors

## Implementation Checklist

For each command in generators:
- [ ] Does it use only supported actions (print/add/set/remove/enable/disable/run/import)?
- [ ] Are `[find ...]` expressions properly formatted for findMutation?
- [ ] Are string values properly escaped (using `escapeRouterOsString()`) but NOT double-escaped?
- [ ] Is the command idempotent (can run multiple times safely)?
- [ ] Are `/log` commands either removed or acceptable to skip?
- [ ] Are delays using `:delay <duration>` format?
- [ ] Are chained commands broken into individual commands?

## Testing Strategy

### Unit Tests in binary_client_test.go
Each command pattern must have a test case:
```go
{
    name:         "descriptive test name",
    command:      `/actual/command from generator`,
    wantEndpoint: "/menu/path/action",
    wantFindMut:  true/false,
    wantFilters:  []string{"?filter1=value", "?filter2~=pattern"},
    wantParams:   []string{"key1=value1", "key2=value2"},
}
```

### Integration Testing
1. Generate script from PHP
2. Parse through binary client
3. Verify each command translates correctly
4. Deploy to test router
5. Verify configuration applied correctly

## Migration Checklist

When updating existing generators:
1. Identify all `/log` commands → Remove or accept skipping
2. Identify all `:do {}` blocks → Simplify to direct commands
3. Identify all `:if` statements → Convert to idempotent patterns
4. Identify all `[find ...]` usage → Verify findMutation support
5. Check escaped quotes `\"` → Ensure binary client handles correctly
6. Test each command pattern in binary_client_test.go
7. Full end-to-end provisioning test
