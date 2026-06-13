package ssh

// hotspot_pppoe_commands_test.go — tests every command emitted by
// HotspotService::generateConfig() and PPPoEService::generateConfig()
// Run: go test ./internal/ssh/... -run TestHotspotPPPoECommands -v

import "testing"

func TestHotspotPPPoECommands(t *testing.T) {
	tests := []struct {
		name        string
		cmd         string
		wantErr     bool
		wantFindMut bool
	}{
		// ── PPPoEService::generateConfig – interface list adds ────────────────
		{name: "interface list add LAN", cmd: `/interface list add name="LAN" comment="Local Area Network" `},
		{name: "interface list add WAN", cmd: `/interface list add name="WAN" comment="Wide Area Network" `},
		{name: "interface list member add WAN iface", cmd: `/interface list member add list=WAN interface="ether1"`},
		// ── bridge ───────────────────────────────────────────────────────────
		{name: "bridge add", cmd: `/interface bridge add name="br-pppoe-abc" comment="WiFiCore PPPoE bridge (abc)"`},
		{name: "bridge port remove find bridge+comment", cmd: `/interface bridge port remove [find bridge="br-pppoe-abc" comment="WiFiCore PPPoE port (abc)"]`, wantFindMut: true},
		{name: "bridge port add", cmd: `/interface bridge port add bridge="br-pppoe-abc" interface="ether2" comment="WiFiCore PPPoE port (abc)"`},
		// ── pool ─────────────────────────────────────────────────────────────
		{name: "ip pool remove find name", cmd: `/ip pool remove [find name="pool-pppoe-abc"]`, wantFindMut: true},
		{name: "ip pool add", cmd: `/ip pool add name="pool-pppoe-abc" ranges="10.0.0.10-10.0.0.250" comment="WiFiCore PPPoE pool (abc)"`},
		// ── ip address ───────────────────────────────────────────────────────
		{name: "ip address remove find comment", cmd: `/ip address remove [find comment="WiFiCore PPPoE gateway (abc)"]`, wantFindMut: true},
		{name: "ip address add", cmd: `/ip address add address="192.168.89.1/24" interface="br-pppoe-abc" comment="WiFiCore PPPoE gateway (abc)"`},
		// ── ppp profile ──────────────────────────────────────────────────────
		{name: "ppp profile remove find name", cmd: `/ppp profile remove [find name="pppoe-prof-abc"]`, wantFindMut: true},
		{name: "ppp profile add", cmd: `/ppp profile add name="pppoe-prof-abc" local-address="192.168.89.1" remote-address="pool-pppoe-abc" dns-server="8.8.8.8,1.1.1.1" interface-list=PPPOE-ACTIVE use-compression=no use-encryption=no only-one=no change-tcp-mss=yes rate-limit=""`},
		{name: "ppp profile set find name", cmd: `/ppp profile set [find name="pppoe-prof-abc"] interface-list=PPPOE-ACTIVE`, wantFindMut: true},
		// ── pppoe server ─────────────────────────────────────────────────────
		{name: "pppoe server remove find comment", cmd: `/interface pppoe-server server remove [find comment="WiFiCore PPPoE (abc)"]`, wantFindMut: true},
		{name: "pppoe server add", cmd: `/interface pppoe-server server add service-name="pppoe-abc" interface="br-pppoe-abc" default-profile="pppoe-prof-abc" authentication="chap,mschap2" one-session-per-host=yes keepalive-timeout="10" max-mtu="1480" max-mru="1480" disabled=no comment="WiFiCore PPPoE (abc)"`},
		// ── interface list members ────────────────────────────────────────────
		{name: "interface list member add LAN bridge", cmd: `/interface list member add list=LAN interface="br-pppoe-abc" comment="WiFiCore PPPoE bridge"`},
		{name: "interface list add PPPOE semicolon", cmd: `/interface list add name=PPPOE; `},
		{name: "interface list member add PPPOE", cmd: `/interface list member add list=PPPOE interface="br-pppoe-abc" comment="WiFiCore PPPoE list"`},
		{name: "interface list add PPPOE-ACTIVE semicolon", cmd: `/interface list add name=PPPOE-ACTIVE; `},
		// ── firewall filter ──────────────────────────────────────────────────
		{name: "firewall filter remove find comment NO-FT", cmd: `/ip firewall filter remove [find comment="WiFiCore PPPoE NO-FT (abc)"]`, wantFindMut: true},
		{name: "firewall filter add no-ft", cmd: `/ip firewall filter add chain=forward action=accept connection-state=established,related in-interface-list=PPPOE place-before=0 comment="WiFiCore PPPoE NO-FT (abc)"`},
		{name: "firewall filter remove find comment FW", cmd: `/ip firewall filter remove [find comment="WiFiCore PPPoE FW"]`, wantFindMut: true},
		{name: "firewall filter add FW-DROP", cmd: `/ip firewall filter add chain=forward in-interface="br-pppoe-abc" action=drop place-before=0 comment="WiFiCore PPPoE FW-DROP (abc)"`},
		{name: "firewall filter add FW-RETURN", cmd: `/ip firewall filter add chain=forward in-interface-list=WAN connection-state=established,related action=accept place-before=0 comment="WiFiCore PPPoE FW-RETURN (abc)"`},
		{name: "firewall filter add FW-INET", cmd: `/ip firewall filter add chain=forward in-interface-list=PPPOE-ACTIVE out-interface-list=WAN action=accept place-before=0 comment="WiFiCore PPPoE FW-INET (abc)"`},
		{name: "firewall filter add FW-INV", cmd: `/ip firewall filter add chain=forward in-interface-list=PPPOE-ACTIVE connection-state=invalid action=drop place-before=0 comment="WiFiCore PPPoE FW-INV (abc)"`},
		{name: "firewall filter add FW-EST", cmd: `/ip firewall filter add chain=forward in-interface-list=PPPOE-ACTIVE connection-state=established,related action=accept place-before=0 comment="WiFiCore PPPoE FW-EST (abc)"`},
		// ── NAT ──────────────────────────────────────────────────────────────
		{name: "nat remove find comment PPPoE NAT", cmd: `/ip firewall nat remove [find comment="WiFiCore PPPoE NAT"]`, wantFindMut: true},
		{name: "nat add masquerade PPPOE-ACTIVE", cmd: `/ip firewall nat add chain=srcnat in-interface-list=PPPOE-ACTIVE out-interface-list=WAN action=masquerade comment="WiFiCore PPPoE NAT"`},
		// ── radius ───────────────────────────────────────────────────────────
		{name: "radius remove find service=ppp", cmd: `/radius remove [find service=ppp]`, wantFindMut: true},
		{name: "radius add ppp", cmd: `/radius add service=ppp address="10.8.0.1" secret="testing123" authentication-port=1812 accounting-port=1813 timeout=3s comment="WiFiCore PPPoE (abc)"`},
		{name: "ppp aaa set semicolon", cmd: `/ppp aaa set use-radius=yes accounting=yes interim-update=5m; `},
		{name: "radius incoming set semicolon", cmd: `/radius incoming set accept=yes port=3799; `},
		// ── dns ──────────────────────────────────────────────────────────────
		{name: "ip dns set", cmd: `/ip dns set allow-remote-requests=yes servers="8.8.8.8,1.1.1.1"`},
		// ── generateFooter :log — KNOWN BAD ──────────────────────────────────
		{name: "footer :log (from generateFooter - must be skipped/fail)", cmd: `:log info "Configuration applied successfully"`, wantErr: true},

		// ── HotspotService::generateConfig ────────────────────────────────────
		// :log info at top — KNOWN BAD
		{name: "hotspot :log info at start", cmd: `/log info "=== Starting Hotspot Setup on Router abc ==="`, wantErr: true},
		// bridge
		{name: "hotspot bridge add trailing space", cmd: `/interface bridge add name="br-hotspot-abc" comment="Hotspot Bridge" `},
		{name: "hotspot bridge port add", cmd: `/interface bridge port add bridge="br-hotspot-abc" interface="ether2" comment="Hotspot Interface" `},
		// ip address
		{name: "hotspot ip address remove [/ip address find interface]", cmd: `/ip address remove [/ip address find interface="br-hotspot-abc"]`, wantFindMut: true},
		{name: "hotspot ip address add", cmd: `/ip address add address="192.168.88.1/24" interface="br-hotspot-abc" comment="Hotspot Gateway"`},
		// pool with /ip pool find path prefix
		{name: "hotspot ip pool remove [/ip pool find name]", cmd: `/ip pool remove [/ip pool find name="pool-hotspot-abc"]`, wantFindMut: true},
		{name: "hotspot ip pool add", cmd: `/ip pool add name="pool-hotspot-abc" ranges="192.168.88.10-192.168.88.254" comment="Hotspot IP Pool"`},
		// dhcp-server
		{name: "hotspot dhcp-server remove path-prefixed find", cmd: `/ip dhcp-server remove [/ip dhcp-server find name="dhcp-hotspot-abc"]`, wantFindMut: true},
		{name: "hotspot dhcp-server add", cmd: `/ip dhcp-server add name="dhcp-hotspot-abc" interface="br-hotspot-abc" address-pool="pool-hotspot-abc" lease-time=1h disabled=no`},
		{name: "hotspot dhcp-server network remove path-prefixed find", cmd: `/ip dhcp-server network remove [/ip dhcp-server network find address="192.168.88.0/24"]`, wantFindMut: true},
		{name: "hotspot dhcp-server network add", cmd: `/ip dhcp-server network add address="192.168.88.0/24" gateway="192.168.88.1" dns-server="8.8.8.8,1.1.1.1"`},
		{name: "hotspot dhcp-server network set find address 1", cmd: `/ip dhcp-server network set [/ip dhcp-server network find address="192.168.88.0/24"] comment="Hotspot Network"`, wantFindMut: true},
		{name: "hotspot dhcp-server network set find address 2", cmd: `/ip dhcp-server network set [/ip dhcp-server network find address="192.168.88.0/24"] ntp-server="192.168.88.1"`, wantFindMut: true},
		// hotspot profile
		{name: "hotspot profile remove path-prefixed find", cmd: `/ip hotspot profile remove [/ip hotspot profile find name="hs-profile-abc"]`, wantFindMut: true},
		{name: "hotspot profile add", cmd: `/ip hotspot profile add name="hs-profile-abc" hotspot-address="192.168.88.1"`},
		{name: "hotspot profile set bare name", cmd: `/ip hotspot profile set hs-profile-abc login-by=http-chap,mac-cookie,http-pap`, wantFindMut: true},
		{name: "hotspot profile set rate-limit with quotes", cmd: `/ip hotspot profile set hs-profile-abc rate-limit="10M/10M"`, wantFindMut: true},
		// file set
		{name: "file set name= attribute", cmd: `/file set name="hotspot/login.html" contents="<html><head></head><body>Redirecting...</body></html>"`},
		// hotspot server
		{name: "hotspot server remove path-prefixed find", cmd: `/ip hotspot remove [/ip hotspot find name="hs-server-abc"]`, wantFindMut: true},
		{name: "hotspot server add", cmd: `/ip hotspot add name="hs-server-abc" interface="br-hotspot-abc" profile="hs-profile-abc" address-pool="pool-hotspot-abc" disabled=no`},
		{name: "hotspot server set bare name addresses-per-mac", cmd: `/ip hotspot set "hs-server-abc" addresses-per-mac=2`, wantFindMut: true},
		// hotspot user profile
		{name: "hotspot user profile remove path-prefixed find", cmd: `/ip hotspot user profile remove [/ip hotspot user profile find name="default-hotspot"]`, wantFindMut: true},
		{name: "hotspot user profile add", cmd: `/ip hotspot user profile add name=default-hotspot`},
		{name: "hotspot user profile set add-mac-cookie", cmd: `/ip hotspot user profile set default-hotspot add-mac-cookie=yes`, wantFindMut: true},
		// radius hotspot
		{name: "hotspot radius remove path-prefixed find", cmd: `/radius remove [/radius find service=hotspot]`, wantFindMut: true},
		{name: "hotspot radius add", cmd: `/radius add address="10.8.0.1" service=hotspot secret="testing123"`},
		{name: "hotspot radius set find service authentication-port", cmd: `/radius set [/radius find service=hotspot] authentication-port=1812`, wantFindMut: true},
		// walled garden
		{name: "hotspot walled-garden remove path-prefixed find semicolon", cmd: `/ip hotspot walled-garden remove [/ip hotspot walled-garden find comment="WiFiCore Portal"]; `, wantFindMut: true},
		{name: "hotspot walled-garden add", cmd: `/ip hotspot walled-garden add dst-host="portal.example.com" action=allow comment="WiFiCore Portal"`},
		// firewall filter hotspot
		{name: "hotspot fw filter remove find comment semicolon", cmd: `/ip firewall filter remove [/ip firewall filter find comment="Allow Established/Related"]; `, wantFindMut: true},
		{name: "hotspot fw filter add chain forward", cmd: `/ip firewall filter add chain=forward action=accept connection-state=established,related in-interface="ether1" out-interface="br-hotspot-abc" comment="Allow Hotspot WAN Return"`},
		{name: "hotspot fw filter add drop invalid", cmd: `/ip firewall filter add chain=forward action=drop connection-state=invalid comment="Drop Invalid"`},
		{name: "hotspot fw filter add drop port scanners", cmd: `/ip firewall filter add chain=input action=drop protocol=tcp psd=21,3s,3,1 comment="Drop Port Scanners"`},
		// NAT hotspot
		{name: "hotspot nat remove find comment Hotspot", cmd: `/ip firewall nat remove [find comment="Hotspot"]`, wantFindMut: true},
		{name: "hotspot nat add masquerade src-address", cmd: `/ip firewall nat add chain=srcnat action=masquerade src-address="192.168.88.0/24" out-interface="ether1" comment="Hotspot Internet Access"`},
		{name: "hotspot nat add redirect to-ports 64872", cmd: `/ip firewall nat add chain=dstnat action=redirect to-ports=64872 protocol=tcp dst-port=80 in-interface="br-hotspot-abc"`},
		{name: "hotspot nat set find to-ports 64872", cmd: `/ip firewall nat set [/ip firewall nat find to-ports=64872] comment="HTTP to Hotspot"`, wantFindMut: true},
		// ip service set
		{name: "ip service set telnet disabled", cmd: `/ip service set telnet disabled=yes`, wantFindMut: true},
		{name: "ip service set api address", cmd: `/ip service set api disabled=no address=192.168.56.0/24`, wantFindMut: true},
		// system logging
		{name: "system logging action remove find name", cmd: `/system logging action remove [find name="remote-syslog"]`, wantFindMut: true},
		{name: "system logging action add", cmd: `/system logging action add name="remote-syslog" target=remote remote=192.168.56.1 remote-port=514 remote-log-format=syslog syslog-facility=syslog comment="Hotspot legacy syslog"`},
		{name: "system logging remove find action", cmd: `/system logging remove [find action="remote-syslog"]`, wantFindMut: true},
		{name: "system logging add topics hotspot info", cmd: `/system logging add topics=hotspot,info action="remote-syslog"`},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			_, _, opts, err := translateRouterOSCommand(tt.cmd)
			if (err != nil) != tt.wantErr {
				t.Errorf("translateRouterOSCommand(%q)\n  error=%v wantErr=%v", tt.cmd, err, tt.wantErr)
				return
			}
			if err == nil && opts.findMutation != tt.wantFindMut {
				t.Errorf("translateRouterOSCommand(%q)\n  findMutation=%v want %v", tt.cmd, opts.findMutation, tt.wantFindMut)
			}
		})
	}
}
