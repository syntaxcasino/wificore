#!/bin/bash
# Fix all [find name=...] expressions in :do{} blocks for binary API compatibility

cd /home/kja2aro/Projects/traidnet/wificore-hotfix/backend/app/Services/MikroTik

# Pattern 1: /xxx remove [/xxx find name="..."] → /xxx remove "..."
sed -i 's|\(/[a-z/]* remove\) \[/[a-z/ ]* find name=\\"|\1 \\"|g' ZeroConfig*.php

# Pattern 2: /xxx set [/xxx find name="..."] → /xxx set "..."
sed -i 's|\(/[a-z/]* set\) \[/[a-z/ ]* find name=\\"|\1 \\"|g' ZeroConfig*.php

# Pattern 3: /xxx remove [/xxx find service="..."] → /xxx remove [find service="..."]
sed -i 's|/radius remove \[/radius find service=|/radius remove [find service=|g' ZeroConfig*.php

# Pattern 4: /xxx set [/xxx find service="..."] → /xxx set [find service="..."]
sed -i 's|/radius set \[/radius find service=|/radius set [find service=|g' ZeroConfig*.php

# Pattern 5: /interface list member remove [/interface list member find list="..."] → /interface list member remove [find list="..."]
sed -i 's|/interface list member remove \[/interface list member find |/interface list member remove [find |g' ZeroConfig*.php

# Pattern 6: /ip firewall filter remove [/ip firewall filter find comment~"..."] → /ip firewall filter remove [find comment~"..."]
sed -i 's|/ip firewall filter remove \[/ip firewall filter find |/ip firewall filter remove [find |g' ZeroConfig*.php

# Pattern 7: /ip firewall nat remove [/ip firewall nat find comment="..."] → /ip firewall nat remove [find comment="..."]
sed -i 's|/ip firewall nat remove \[/ip firewall nat find |/ip firewall nat remove [find |g' ZeroConfig*.php

# Pattern 8: /system logging remove [/system logging find comment="..."] → /system logging remove [find comment="..."]
sed -i 's|/system logging remove \[/system logging find |/system logging remove [find |g' ZeroConfig*.php

# Pattern 9: /interface vlan remove [/interface vlan find name="..."] → /interface vlan remove "..."
sed -i 's|/interface vlan remove \[/interface vlan find name=\\"|\"/interface vlan remove \\"|g' ZeroConfig*.php

# Pattern 10: /ip pool remove [/ip pool find name="..."] → /ip pool remove "..."
sed -i 's|/ip pool remove \[/ip pool find name=\\"|\"/ip pool remove \\"|g' ZeroConfig*.php

# Pattern 11: /ip dhcp-server remove [/ip dhcp-server find name="..."] → /ip dhcp-server remove "..."
sed -i 's|/ip dhcp-server remove \[/ip dhcp-server find name=\\"|\"/ip dhcp-server remove \\"|g' ZeroConfig*.php

# Pattern 12: /ip hotspot profile remove [/ip hotspot profile find name="..."] → /ip hotspot profile remove "..."
sed -i 's|/ip hotspot profile remove \[/ip hotspot profile find name=\\"|\"/ip hotspot profile remove \\"|g' ZeroConfig*.php

# Pattern 13: /ip hotspot remove [/ip hotspot find name="..."] → /ip hotspot remove "..."
sed -i 's|/ip hotspot remove \[/ip hotspot find name=\\"|\"/ip hotspot remove \\"|g' ZeroConfig*.php

# Pattern 14: /ip hotspot user profile remove [/ip hotspot user profile find name="..."] → /ip hotspot user profile remove "..."
sed -i 's|/ip hotspot user profile remove \[/ip hotspot user profile find name=\\"|\"/ip hotspot user profile remove \\"|g' ZeroConfig*.php

# Pattern 15: /ip hotspot profile set [/ip hotspot profile find name="..."] → /ip hotspot profile set "..."
sed -i 's|/ip hotspot profile set \[/ip hotspot profile find name=\\"|\"/ip hotspot profile set \\"|g' ZeroConfig*.php

# Pattern 16: /interface bridge port remove [/interface bridge port find bridge="..."] → /interface bridge port remove [find bridge="..."]
sed -i 's|/interface bridge port remove \[/interface bridge port find |/interface bridge port remove [find |g' ZeroConfig*.php

# Pattern 17: /system script remove [/system script find name="..."] → /system script remove "..."
sed -i 's|/system script remove \[/system script find name=\\"|\"/system script remove \\"|g' ZeroConfig*.php

# Pattern 18: /system scheduler remove [/system scheduler find name="..."] → /system scheduler remove "..."
sed -i 's|/system scheduler remove \[/system scheduler find name=\\"|\"/system scheduler remove \\"|g' ZeroConfig*.php

# Pattern 19: /queue type remove [/queue type find name="..."] → /queue type remove "..."
sed -i 's|/queue type remove \[/queue type find name=\\"|\"/queue type remove \\"|g' ZeroConfig*.php

# Pattern 20: /snmp community remove [/snmp community find name="..."] → /snmp community remove "..."
sed -i 's|/snmp community remove \[/snmp community find name=|/snmp community remove |g' ZeroConfig*.php

echo "Fixed all [find] expressions in ZeroConfig generators"
