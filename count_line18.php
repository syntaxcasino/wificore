<?php
// Enumerate every generated RSC line with its number and length
// Using same logic as ZeroConfigPPPoEGenerator with typical hAP lite values
$id = '9172e01f';
$bridge = "pppoe-br-$id";
$p = [
    'profile'           => "pppoe-prof-$id",
    'pool'              => "pppoe-pool-$id",
    'service'           => "pppoe-svc-$id",
    'pppoe_list'        => "PPPOE-$id",
    'pppoe_active_list' => "PPPOE-ACTIVE-$id",
    'wan_list'          => 'WAN',
    'gateway_ip'        => '100.64.0.1',
    'range_start'       => '100.64.0.2',
    'range_end'         => '100.64.0.255',
    'dns_primary'       => '8.8.8.8',
    'dns_secondary'     => '8.8.4.4',
    'radius_server'     => '10.8.0.1',
    'radius_secret'     => 'testing123',
    'management_subnet' => '10.0.0.0/8',
    // hAP lite has ether2, ether3, ether4, ether5 as LAN ports
    'interfaces'        => ['ether2', 'ether3', 'ether4', 'ether5'],
    'vlan_required'     => false,
    'vlan_id'           => null,
];
$managementPorts = '22,8291,8728,8729';

$s = [];
$s[] = "/log info \"PPPoE-$id-START\"";                                                                  // 1
$s[] = ":do { /radius remove [find comment~\"PPPoE-$id\"]; } on-error={}";                               // 2
$s[] = "/radius add service=ppp address={$p['radius_server']} secret=\"{$p['radius_secret']}\" authentication-port=1812 accounting-port=1813 timeout=3s comment=\"PPPoE-$id\""; // 3
$s[] = "/ppp aaa set use-radius=yes accounting=yes interim-update=5m";                                  // 4
$s[] = ":do { :if ([:len [/ip pool find name=\"{$p['pool']}\"]] = 0) do={ /ip pool add name=\"{$p['pool']}\" ranges={$p['range_start']}-{$p['range_end']} comment=\"PPPoE-$id\" } } on-error={}"; // 5
$s[] = ":do { :if ([:len [/interface list find name={$p['wan_list']}]] = 0) do={ /interface list add name={$p['wan_list']} } } on-error={}"; // 6
$s[] = ":do { :if ([:len [/interface list find name={$p['pppoe_list']}]] = 0) do={ /interface list add name={$p['pppoe_list']} } } on-error={}"; // 7
$s[] = ":do { :if ([:len [/interface list find name={$p['pppoe_active_list']}]] = 0) do={ /interface list add name={$p['pppoe_active_list']} } } on-error={}"; // 8
$s[] = ":local pWan \"{$p['wan_list']}\"";                                                              // 9
$s[] = ":do { :if ([:len [/interface list member find list=\$pWan interface=\"ether1\"]] = 0) do={ /interface list member add list=\$pWan interface=\"ether1\" } } on-error={}"; // 10
$s[] = ":local pProf \"{$p['profile']}\"";                                                              // 11
$s[] = ":local pPool \"{$p['pool']}\"";                                                                  // 12
$s[] = ":local pGw \"{$p['gateway_ip']}\"";                                                             // 13
$s[] = ":local pDns \"{$p['dns_primary']},{$p['dns_secondary']}\"";                                     // 14
$s[] = ":local pList \"{$p['pppoe_active_list']}\"";                                                    // 15
$s[] = ":do { :if ([:len [/ppp profile find name=\$pProf]] = 0) do={ /ppp profile add name=\$pProf comment=\"PPPoE-$id\" } } on-error={}"; // 16
$s[] = ":do { /ppp profile set [find name=\$pProf] local-address=\$pGw remote-address=\$pPool dns-server=\$pDns interface-list=\$pList only-one=yes } on-error={}"; // 17
$s[] = ":do { /ppp profile set [find name=\$pProf] change-tcp-mss=yes use-compression=no use-encryption=no session-timeout=0 add-default-route=no } on-error={}"; // 18
$s[] = ":local pBr \"{$bridge}\"";                                                                      // 19
$s[] = ":do { :if ([:len [/interface bridge find name=\$pBr]] = 0) do={ /interface bridge add name=\$pBr protocol-mode=rstp comment=\"PPPoE-$id\" } } on-error={}"; // 20

foreach ($p['interfaces'] as $iface) {
    $s[] = ":do { :if ([:len [/interface bridge port find bridge=\$pBr interface=\"{$iface}\"]] = 0) do={ /interface bridge port add bridge=\$pBr interface=\"{$iface}\" comment=\"PPPoE-$id\" } } on-error={}";
}

$s[] = ":do { /ip dhcp-server remove [find interface=\"{$bridge}\"]; } on-error={}";
$s[] = ":local pSvc \"{$p['service']}\"";
$s[] = ":do { :if ([:len [/interface pppoe-server server find service-name=\$pSvc]] = 0) do={ /interface pppoe-server server add service-name=\$pSvc interface=\$pBr disabled=no comment=\"PPPoE-$id\" } } on-error={}";
$s[] = ":do { /interface pppoe-server server set [find service-name=\$pSvc] interface=\$pBr default-profile=\$pProf disabled=no comment=\"PPPoE-$id\" } on-error={}";
$s[] = ":do { /interface pppoe-server server set [find service-name=\$pSvc] authentication=chap,mschap2 one-session-per-host=yes keepalive-timeout=30 max-mtu=1480 max-mru=1480 } on-error={}";
$s[] = ":if ([:len [/interface pppoe-server server find service-name=\$pSvc]] = 0) do={ :error \"PPPoE-$id: PPPoE server missing\" }";
$s[] = ":local pPList \"{$p['pppoe_list']}\"";
$s[] = ":do { :if ([:len [/interface list member find list=\$pPList interface=\$pBr]] = 0) do={ /interface list member add list=\$pPList interface=\$pBr } } on-error={}";
$s[] = ":do { /ip firewall filter remove [find comment~\"PPPoE-$id-MGMT\"]; } on-error={}";
$s[] = ":do { /ip firewall filter remove [find comment~\"PPPoE-$id-(SNMP-ALLOW|ICMP)\"]; } on-error={}";
$s[] = ":do { /ip firewall filter remove [find comment~\"PPPoE-$id-(DISC|EST-IN|INV-IN|DROP-IN)\"]; } on-error={}";
$s[] = "/ip firewall filter add chain=input protocol=tcp dst-port={$managementPorts} src-address=!{$p['management_subnet']} action=drop place-before=0 comment=\"PPPoE-$id-MGMT-DROP\"";
$s[] = "/ip firewall filter add chain=input in-interface={$bridge} action=drop place-before=0 comment=\"PPPoE-$id-DROP-IN\"";
$s[] = "/ip firewall filter add chain=input in-interface={$bridge} connection-state=invalid action=drop place-before=0 comment=\"PPPoE-$id-INV-IN\"";
$s[] = "/ip firewall filter add chain=input in-interface={$bridge} connection-state=established,related action=accept place-before=0 comment=\"PPPoE-$id-EST-IN\"";
$s[] = "/ip firewall filter add chain=input in-interface={$bridge} protocol=udp dst-port=8863-8864 action=accept place-before=0 comment=\"PPPoE-$id-DISC\"";
$s[] = "/ip firewall filter add chain=input protocol=udp dst-port=161 src-address={$p['management_subnet']} action=accept place-before=0 comment=\"PPPoE-$id-SNMP-ALLOW\"";
$s[] = "/ip firewall filter add chain=input protocol=tcp dst-port={$managementPorts} src-address={$p['management_subnet']} action=accept place-before=0 comment=\"PPPoE-$id-MGMT-ALLOW\"";
$s[] = "/ip firewall filter add chain=input in-interface-list={$p['pppoe_active_list']} protocol=icmp action=accept place-before=0 comment=\"PPPoE-$id-ICMP\"";
$s[] = "/ip firewall filter add chain=input connection-state=established,related action=accept place-before=0 comment=\"PPPoE-$id-MGMT-EST\"";
$s[] = ":do { /ip firewall filter remove [find comment~\"PPPoE-$id-(WAN-EST|EST|INV|DNS|INET|LOCAL|BLOCK-UNAUTH)\"]; } on-error={}";
$s[] = "/ip firewall filter add chain=forward in-interface={$bridge} action=drop place-before=0 comment=\"PPPoE-$id-BLOCK-UNAUTH\"";
$s[] = "/ip firewall filter add chain=forward in-interface-list={$p['pppoe_active_list']} out-interface-list={$p['wan_list']} action=accept place-before=0 comment=\"PPPoE-$id-INET\"";
$s[] = "/ip firewall filter add chain=forward in-interface-list={$p['pppoe_active_list']} connection-state=invalid action=drop place-before=0 comment=\"PPPoE-$id-INV\"";
$s[] = "/ip firewall filter add chain=forward in-interface-list={$p['pppoe_active_list']} connection-state=established,related action=accept place-before=0 comment=\"PPPoE-$id-EST\"";
$s[] = "/ip firewall filter add chain=forward in-interface-list={$p['wan_list']} out-interface-list={$p['pppoe_active_list']} connection-state=established,related action=accept place-before=0 comment=\"PPPoE-$id-WAN-EST\"";
$s[] = ":do { /ip firewall filter remove [find comment~\"GLOBAL-DEFAULT-DROP-\"]; } on-error={}";
$s[] = "/ip firewall filter add chain=input action=drop comment=\"GLOBAL-DEFAULT-DROP-IN\"";
$s[] = "/ip firewall filter add chain=forward action=drop comment=\"GLOBAL-DEFAULT-DROP-FWD\"";
$s[] = ":do { /ip firewall nat remove [find comment=\"PPPoE-$id\"]; } on-error={}";
$s[] = "/ip firewall nat add chain=srcnat in-interface-list={$p['pppoe_active_list']} out-interface-list={$p['wan_list']} action=masquerade comment=\"PPPoE-$id\"";
$s[] = "/ip firewall connection tracking set tcp-established-timeout=1h udp-timeout=30s";
$s[] = "/log info \"PPPoE-$id-DONE\"";

echo "Total lines: " . count($s) . "\n\n";
$violations = [];
foreach ($s as $i => $line) {
    $len = strlen($line);
    $lineNum = $i + 1;
    $flag = $len > 209 ? " *** OVER 209 ***" : "";
    $highlight = ($lineNum === 18) ? " <<< LINE 18" : "";
    if ($len > 100 || $lineNum === 18) {
        echo "L{$lineNum} ({$len}){$flag}{$highlight}: {$line}\n";
    }
    if ($len > 209) $violations[] = "L$lineNum ($len): $line";
}
echo "\nViolations: " . count($violations) . "\n";
