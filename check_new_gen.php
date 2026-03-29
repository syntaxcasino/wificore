<?php
// Validate new ZeroConfigPPPoEGenerator output — 116 char hard limit
$id     = '9172e01f';
$bridge = "pppoe-br-$id";
$prof   = "pppoe-prof-$id";
$pool   = "pppoe-pool-$id";
$svc    = "pppoe-svc-$id";
$pal    = "PPPOE-ACTIVE-$id";
$pl     = "PPPOE-$id";
$wan    = "WAN";
$gw     = "100.64.0.1";
$dns    = "8.8.8.8,8.8.4.4";
$rs     = "10.8.0.1";
$rsec   = "testing123";
$mgmt   = "10.0.0.0/8";
$mports = "22,8291,8728,8729";
$rs2    = "100.64.0.2";
$re     = "100.64.0.255";
$ifaces = ['ether2','ether3','ether4','ether5'];

$s = [];
$s[] = "/log info \"PPPoE-$id-START\"";
$s[] = ":do { /radius remove [find comment~\"PPPoE-$id\"]; } on-error={}";
$s[] = "/radius add service=ppp address=$rs secret=\"$rsec\" timeout=3s comment=\"PPPoE-$id\"";
$s[] = "/radius set [find comment=\"PPPoE-$id\"] authentication-port=1812 accounting-port=1813";
$s[] = "/ppp aaa set use-radius=yes accounting=yes interim-update=5m";
$s[] = ":do { :if ([:len [/ip pool find name=\"$pool\"]] = 0) do={ /ip pool add name=\"$pool\" comment=\"PPPoE-$id\" } } on-error={}";
$s[] = ":do { /ip pool set [find name=\"$pool\"] ranges=$rs2-$re } on-error={}";
$s[] = ":do { :if ([:len [/interface list find name=$wan]] = 0) do={ /interface list add name=$wan } } on-error={}";
$s[] = ":do { :if ([:len [/interface list find name=$pl]] = 0) do={ /interface list add name=$pl } } on-error={}";
$s[] = ":do { :if ([:len [/interface list find name=$pal]] = 0) do={ /interface list add name=$pal } } on-error={}";
$s[] = ":do { :if ([:len [/interface list member find list=$wan interface=ether1]] = 0) do={ /interface list member add list=$wan interface=ether1 } } on-error={}";
$s[] = ":do { :if ([:len [/ppp profile find name=\"$prof\"]] = 0) do={ /ppp profile add name=\"$prof\" comment=\"PPPoE-$id\" } } on-error={}";
$s[] = ":do { /ppp profile set [find name=\"$prof\"] local-address=$gw remote-address=\"$pool\" } on-error={}";
$s[] = ":do { /ppp profile set [find name=\"$prof\"] dns-server=$dns only-one=yes } on-error={}";
$s[] = ":do { /ppp profile set [find name=\"$prof\"] interface-list=$pal } on-error={}";
$s[] = ":do { /ppp profile set [find name=\"$prof\"] change-tcp-mss=yes use-compression=no } on-error={}";
$s[] = ":do { /ppp profile set [find name=\"$prof\"] use-encryption=no session-timeout=0 } on-error={}";
$s[] = ":do { /ppp profile set [find name=\"$prof\"] add-default-route=no } on-error={}";
$s[] = ":do { :if ([:len [/interface bridge find name=\"$bridge\"]] = 0) do={ /interface bridge add name=\"$bridge\" comment=\"PPPoE-$id\" } } on-error={}";
$s[] = ":do { /interface bridge set [find name=\"$bridge\"] protocol-mode=rstp } on-error={}";
foreach ($ifaces as $iface) {
    $s[] = ":do { :if ([:len [/interface bridge port find bridge=\"$bridge\" interface=\"$iface\"]] = 0) do={ /interface bridge port add bridge=\"$bridge\" interface=\"$iface\" } } on-error={}";
}
$s[] = ":do { /ip dhcp-server remove [find interface=\"$bridge\"]; } on-error={}";
$s[] = ":do { :if ([:len [/interface pppoe-server server find service-name=\"$svc\"]] = 0) do={ /interface pppoe-server server add service-name=\"$svc\" disabled=no } } on-error={}";
$s[] = ":do { /interface pppoe-server server set [find service-name=\"$svc\"] interface=\"$bridge\" } on-error={}";
$s[] = ":do { /interface pppoe-server server set [find service-name=\"$svc\"] default-profile=\"$prof\" disabled=no } on-error={}";
$s[] = ":do { /interface pppoe-server server set [find service-name=\"$svc\"] authentication=chap,mschap2 } on-error={}";
$s[] = ":do { /interface pppoe-server server set [find service-name=\"$svc\"] one-session-per-host=yes keepalive-timeout=30 } on-error={}";
$s[] = ":do { /interface pppoe-server server set [find service-name=\"$svc\"] max-mtu=1480 max-mru=1480 } on-error={}";
$s[] = ":do { :if ([:len [/interface list member find list=$pl interface=\"$bridge\"]] = 0) do={ /interface list member add list=$pl interface=\"$bridge\" } } on-error={}";
$s[] = ":do { /ip firewall filter remove [find comment~\"PPPoE-$id\"]; } on-error={}";
$s[] = "/ip firewall filter add chain=input connection-state=established,related action=accept place-before=0 comment=\"PPPoE-$id-EST-IN\"";
$s[] = "/ip firewall filter add chain=input in-interface-list=$pal protocol=icmp action=accept place-before=0 comment=\"PPPoE-$id-ICMP\"";
$s[] = "/ip firewall filter add chain=input protocol=tcp dst-port=$mports src-address=$mgmt action=accept place-before=0 comment=\"PPPoE-$id-MGMT-ALLOW\"";
$s[] = "/ip firewall filter add chain=input protocol=udp dst-port=161 src-address=$mgmt action=accept place-before=0 comment=\"PPPoE-$id-SNMP-ALLOW\"";
$s[] = "/ip firewall filter add chain=input in-interface=\"$bridge\" protocol=udp dst-port=8863-8864 action=accept place-before=0 comment=\"PPPoE-$id-DISC\"";
$s[] = "/ip firewall filter add chain=input in-interface=\"$bridge\" connection-state=invalid action=drop place-before=0 comment=\"PPPoE-$id-INV-IN\"";
$s[] = "/ip firewall filter add chain=input in-interface=\"$bridge\" action=drop place-before=0 comment=\"PPPoE-$id-DROP-IN\"";
$s[] = "/ip firewall filter add chain=input protocol=tcp dst-port=$mports src-address=!$mgmt action=drop place-before=0 comment=\"PPPoE-$id-MGMT-DROP\"";
$s[] = "/ip firewall filter add chain=forward in-interface-list=$wan out-interface-list=$pal connection-state=established,related action=accept place-before=0 comment=\"PPPoE-$id-WAN-EST\"";
$s[] = "/ip firewall filter add chain=forward in-interface-list=$pal connection-state=established,related action=accept place-before=0 comment=\"PPPoE-$id-EST\"";
$s[] = "/ip firewall filter add chain=forward in-interface-list=$pal connection-state=invalid action=drop place-before=0 comment=\"PPPoE-$id-INV\"";
$s[] = "/ip firewall filter add chain=forward in-interface-list=$pal out-interface-list=$wan action=accept place-before=0 comment=\"PPPoE-$id-INET\"";
$s[] = "/ip firewall filter add chain=forward in-interface=\"$bridge\" action=drop place-before=0 comment=\"PPPoE-$id-BLOCK-UNAUTH\"";
$s[] = ":do { /ip firewall filter remove [find comment~\"GLOBAL-DEFAULT-DROP-\"]; } on-error={}";
$s[] = "/ip firewall filter add chain=input action=drop comment=\"GLOBAL-DEFAULT-DROP-IN\"";
$s[] = "/ip firewall filter add chain=forward action=drop comment=\"GLOBAL-DEFAULT-DROP-FWD\"";
$s[] = ":do { /ip firewall nat remove [find comment=\"PPPoE-$id\"]; } on-error={}";
$s[] = "/ip firewall nat add chain=srcnat in-interface-list=$pal out-interface-list=$wan action=masquerade comment=\"PPPoE-$id\"";
$s[] = "/ip firewall connection tracking set tcp-established-timeout=1h udp-timeout=30s";
$s[] = "/log info \"PPPoE-$id-DONE\"";

$lim = 116;
$violations = [];
$maxLen = 0; $maxN = 0;
foreach ($s as $i => $line) {
    $len = strlen($line);
    $n = $i + 1;
    if ($len > $lim) {
        $violations[] = "L$n ($len): $line";
    }
    if ($len > $maxLen) { $maxLen = $len; $maxN = $n; }
}
echo "Total lines: " . count($s) . "  limit=$lim\n";
echo "Violations >$lim: " . count($violations) . "\n";
foreach ($violations as $v) { echo "  OVER: $v\n"; }
echo "Longest: L$maxN ($maxLen): " . $s[$maxN-1] . "\n";
if (empty($violations)) echo "\nALL LINES OK\n";
