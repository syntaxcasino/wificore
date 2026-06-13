package ssh

// generator_commands_test.go — verifies every command pattern emitted by the
// PHP ZeroConfig* generators parses correctly through translateRouterOSCommand.
// Run: go test ./internal/ssh/... -run TestGeneratorCommands

import (
	"testing"
)

func TestGeneratorCommands(t *testing.T) {
	tests := []struct {
		name         string
		cmd          string
		wantEndpoint string
		wantFindMut  bool
		wantFilters  []string
		wantParams   []string
		wantErr      bool
	}{
		// ── IP Pool ──────────────────────────────────────────────────────────
		{
			name:         "ip pool remove [find name]",
			cmd:          `/ip pool remove [find name="hs-pool-abcd1234"]`,
			wantEndpoint: "/ip/pool/remove",
			wantFindMut:  true,
			wantFilters:  []string{"?name=hs-pool-abcd1234"},
		},
		{
			name:         "ip pool add",
			cmd:          `/ip pool add name="hs-pool-abcd1234" ranges="10.0.0.10-10.0.0.250" comment="hs-abcd1234"`,
			wantEndpoint: "/ip/pool/add",
			wantFindMut:  false,
			wantParams:   []string{"name=hs-pool-abcd1234", "ranges=10.0.0.10-10.0.0.250", "comment=hs-abcd1234"},
		},
		// ── DHCP Server ──────────────────────────────────────────────────────
		{
			name:         "ip dhcp-server remove [find name]",
			cmd:          `/ip dhcp-server remove [find name="hs-dhcp-abcd1234"]`,
			wantEndpoint: "/ip/dhcp-server/remove",
			wantFindMut:  true,
			wantFilters:  []string{"?name=hs-dhcp-abcd1234"},
		},
		{
			name:         "ip dhcp-server add",
			cmd:          `/ip dhcp-server add name="hs-dhcp-abcd1234" interface="hs-br-abcd1234" address-pool="hs-pool-abcd1234" lease-time=1h disabled=no authoritative=yes`,
			wantEndpoint: "/ip/dhcp-server/add",
			wantFindMut:  false,
			wantParams:   []string{"name=hs-dhcp-abcd1234", "interface=hs-br-abcd1234", "address-pool=hs-pool-abcd1234", "lease-time=1h", "disabled=no", "authoritative=yes"},
		},
		{
			name:         "ip dhcp-server network remove [find comment]",
			cmd:          `/ip dhcp-server network remove [find comment="hs-net-abcd1234"]`,
			wantEndpoint: "/ip/dhcp-server/network/remove",
			wantFindMut:  true,
			wantFilters:  []string{"?comment=hs-net-abcd1234"},
		},
		{
			name:         "ip dhcp-server network add",
			cmd:          `/ip dhcp-server network add address="192.168.1.0/24" gateway="192.168.1.1" dns-server="8.8.8.8,8.8.4.4" comment="hs-net-abcd1234"`,
			wantEndpoint: "/ip/dhcp-server/network/add",
			wantFindMut:  false,
			wantParams:   []string{"address=192.168.1.0/24", "gateway=192.168.1.1", "dns-server=8.8.8.8,8.8.4.4", "comment=hs-net-abcd1234"},
		},
		// ── Interface VLAN ───────────────────────────────────────────────────
		{
			name:         "interface vlan remove [find name]",
			cmd:          `/interface vlan remove [find name="vlan-hotspot-100-ether2"]`,
			wantEndpoint: "/interface/vlan/remove",
			wantFindMut:  true,
			wantFilters:  []string{"?name=vlan-hotspot-100-ether2"},
		},
		{
			name:         "interface vlan add",
			cmd:          `/interface vlan add name="vlan-hotspot-100-ether2" vlan-id="100" interface="ether2" comment="Hotspot VLAN"`,
			wantEndpoint: "/interface/vlan/add",
			wantFindMut:  false,
			wantParams:   []string{"name=vlan-hotspot-100-ether2", "vlan-id=100", "interface=ether2", "comment=Hotspot VLAN"},
		},
		// ── Bridge Port ──────────────────────────────────────────────────────
		{
			name:         "interface bridge port remove [find bridge+interface]",
			cmd:          `/interface bridge port remove [find bridge="hs-br-abcd1234" interface="ether2"]`,
			wantEndpoint: "/interface/bridge/port/remove",
			wantFindMut:  true,
			wantFilters:  []string{"?bridge=hs-br-abcd1234", "?interface=ether2"},
		},
		{
			name:         "interface bridge port add",
			cmd:          `/interface bridge port add bridge="hs-br-abcd1234" interface="ether2" comment="Hotspot Access Port"`,
			wantEndpoint: "/interface/bridge/port/add",
			wantFindMut:  false,
			wantParams:   []string{"bridge=hs-br-abcd1234", "interface=ether2", "comment=Hotspot Access Port"},
		},
		// ── Bridge Add ───────────────────────────────────────────────────────
		{
			name:         "interface bridge add",
			cmd:          `/interface bridge add name="hs-br-abcd1234" comment="Hotspot Bridge"`,
			wantEndpoint: "/interface/bridge/add",
			wantFindMut:  false,
			wantParams:   []string{"name=hs-br-abcd1234", "comment=Hotspot Bridge"},
		},
		// ── IP Address ───────────────────────────────────────────────────────
		{
			name:         "ip address remove [find interface+comment]",
			cmd:          `/ip address remove [find interface="hs-br-abcd1234" comment="Hotspot Gateway"]`,
			wantEndpoint: "/ip/address/remove",
			wantFindMut:  true,
			wantFilters:  []string{"?interface=hs-br-abcd1234", "?comment=Hotspot Gateway"},
		},
		{
			name:         "ip address add",
			cmd:          `/ip address add address="192.168.1.1/24" interface="hs-br-abcd1234" comment="Hotspot Gateway"`,
			wantEndpoint: "/ip/address/add",
			wantFindMut:  false,
			wantParams:   []string{"address=192.168.1.1/24", "interface=hs-br-abcd1234", "comment=Hotspot Gateway"},
		},
		// ── Firewall NAT (Hotspot) ────────────────────────────────────────────
		{
			name:         "ip firewall nat remove [find comment]",
			cmd:          `/ip firewall nat remove [find comment="hs-nat-abcd1234"]`,
			wantEndpoint: "/ip/firewall/nat/remove",
			wantFindMut:  true,
			wantFilters:  []string{"?comment=hs-nat-abcd1234"},
		},
		{
			name:         "ip firewall nat add srcnat masquerade",
			cmd:          `/ip firewall nat add chain="srcnat" action="masquerade" src-address="192.168.1.0/24" out-interface-list="WAN" comment="hs-nat-abcd1234"`,
			wantEndpoint: "/ip/firewall/nat/add",
			wantFindMut:  false,
			wantParams:   []string{"chain=srcnat", "action=masquerade", "src-address=192.168.1.0/24", "out-interface-list=WAN", "comment=hs-nat-abcd1234"},
		},
		// ── Hotspot Walled Garden ────────────────────────────────────────────
		{
			name:         "ip hotspot walled-garden remove [find comment]",
			cmd:          `/ip hotspot walled-garden remove [find comment="WiFiCore Portal"]`,
			wantEndpoint: "/ip/hotspot/walled-garden/remove",
			wantFindMut:  true,
			wantFilters:  []string{"?comment=WiFiCore Portal"},
		},
		{
			name:         "ip hotspot walled-garden add",
			cmd:          `/ip hotspot walled-garden add dst-host="portal.example.com" action="allow" comment="WiFiCore Portal"`,
			wantEndpoint: "/ip/hotspot/walled-garden/add",
			wantFindMut:  false,
			wantParams:   []string{"dst-host=portal.example.com", "action=allow", "comment=WiFiCore Portal"},
		},
		// ── RADIUS ───────────────────────────────────────────────────────────
		{
			name:         "radius remove [find service=ppp]",
			cmd:          `/radius remove [find service="ppp"]`,
			wantEndpoint: "/radius/remove",
			wantFindMut:  true,
			wantFilters:  []string{"?service=ppp"},
		},
		{
			name:         "radius add ppp",
			cmd:          `/radius add service="ppp" address="10.8.0.1" secret="testing123" authentication-port="1812" accounting-port="1813" timeout="3s" comment="WiFiCore PPPoE (abcd1234)"`,
			wantEndpoint: "/radius/add",
			wantFindMut:  false,
			wantParams:   []string{"service=ppp", "address=10.8.0.1", "secret=testing123", "authentication-port=1812", "accounting-port=1813", "timeout=3s", "comment=WiFiCore PPPoE (abcd1234)"},
		},
		// ── System Logging Action ────────────────────────────────────────────
		{
			name:         "system logging action remove [find name]",
			cmd:          `/system logging action remove [find name="remote-syslog"]`,
			wantEndpoint: "/system/logging/action/remove",
			wantFindMut:  true,
			wantFilters:  []string{"?name=remote-syslog"},
		},
		{
			name:         "system logging action add",
			cmd:          `/system logging action add name="remote-syslog" target=remote remote=192.168.1.1 remote-port=514 comment="Hotspot legacy syslog"`,
			wantEndpoint: "/system/logging/action/add",
			wantFindMut:  false,
			wantParams:   []string{"name=remote-syslog", "target=remote", "remote=192.168.1.1", "remote-port=514", "comment=Hotspot legacy syslog"},
		},
		// ── Firewall Filter ──────────────────────────────────────────────────
		{
			name:         "ip firewall filter remove [find comment exact]",
			cmd:          `/ip firewall filter remove [find comment="hyb-fw-abcd1234"]`,
			wantEndpoint: "/ip/firewall/filter/remove",
			wantFindMut:  true,
			wantFilters:  []string{"?comment=hyb-fw-abcd1234"},
		},
		// ── Routing Table ────────────────────────────────────────────────────
		{
			name:         "routing table remove [find name]",
			cmd:          `/routing table remove [find name="to_wan1"]`,
			wantEndpoint: "/routing/table/remove",
			wantFindMut:  true,
			wantFilters:  []string{"?name=to_wan1"},
		},
		{
			name:         "routing table add",
			cmd:          `/routing table add name="to_wan1" disabled=no`,
			wantEndpoint: "/routing/table/add",
			wantFindMut:  false,
			wantParams:   []string{"name=to_wan1", "disabled=no"},
		},
		// ── Interface List Member ────────────────────────────────────────────
		{
			name:         "interface list member remove [find list+interface]",
			cmd:          `/interface list member remove [find list="WAN" interface="ether1"]`,
			wantEndpoint: "/interface/list/member/remove",
			wantFindMut:  true,
			wantFilters:  []string{"?list=WAN", "?interface=ether1"},
		},
		// ── Firewall Mangle ──────────────────────────────────────────────────
		{
			name:         "ip firewall mangle remove [find comment]",
			cmd:          `/ip firewall mangle remove [find comment="WiFiCore PCC WAN1"]`,
			wantEndpoint: "/ip/firewall/mangle/remove",
			wantFindMut:  true,
			wantFilters:  []string{"?comment=WiFiCore PCC WAN1"},
		},
		{
			name:         "ip firewall mangle add",
			cmd:          `/ip firewall mangle add chain=prerouting in-interface-list=LAN dst-address-type=!local per-connection-classifier=both-addresses-and-ports:2/0 action=mark-connection new-connection-mark=wan1_conn passthrough=yes comment="WiFiCore PCC WAN1"`,
			wantEndpoint: "/ip/firewall/mangle/add",
			wantFindMut:  false,
		},
		// ── IP Route ─────────────────────────────────────────────────────────
		{
			name:         "ip route remove [find comment]",
			cmd:          `/ip route remove [find comment="WiFiCore PCC Route WAN1"]`,
			wantEndpoint: "/ip/route/remove",
			wantFindMut:  true,
			wantFilters:  []string{"?comment=WiFiCore PCC Route WAN1"},
		},
		// ── PPPoE Server ─────────────────────────────────────────────────────
		{
			name:         "interface pppoe-server server remove [find interface]",
			cmd:          `/interface pppoe-server server remove [find interface="hs-br-abcd1234"]`,
			wantEndpoint: "/interface/pppoe-server/server/remove",
			wantFindMut:  true,
			wantFilters:  []string{"?interface=hs-br-abcd1234"},
		},
		// ── File Set ─────────────────────────────────────────────────────────
		{
			name:         "file set with name= attribute",
			cmd:          `/file set name="hotspot/login.html" contents="<html><head></head><body>Redirect</body></html>"`,
			wantEndpoint: "/file/set",
			wantFindMut:  false,
			wantParams:   []string{`name=hotspot/login.html`, `contents=<html><head></head><body>Redirect</body></html>`},
		},
		// ── PPP AAA ──────────────────────────────────────────────────────────
		{
			name:         "ppp aaa set use-radius",
			cmd:          `/ppp aaa set use-radius="yes" accounting="yes" interim-update="5m"`,
			wantEndpoint: "/ppp/aaa/set",
			wantFindMut:  false,
			wantParams:   []string{"use-radius=yes", "accounting=yes", "interim-update=5m"},
		},
		// ── SNMP ─────────────────────────────────────────────────────────────
		{
			name:         "snmp set enabled",
			cmd:          `/snmp set enabled=yes`,
			wantEndpoint: "/snmp/set",
			wantFindMut:  false,
			wantParams:   []string{"enabled=yes"},
		},
		// ── System Scheduler ─────────────────────────────────────────────────
		{
			name:         "system scheduler remove by name",
			cmd:          `/system scheduler remove "PPPoE-abcd1234-OPS-SCHED"`,
			wantEndpoint: "/system/scheduler/remove",
			wantFindMut:  true,
			wantFilters:  []string{"?name=PPPoE-abcd1234-OPS-SCHED"},
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			endpoint, params, opts, err := translateRouterOSCommand(tt.cmd)
			if (err != nil) != tt.wantErr {
				t.Fatalf("translateRouterOSCommand(%q) error=%v wantErr=%v", tt.cmd, err, tt.wantErr)
			}
			if err != nil {
				return
			}
			if endpoint != tt.wantEndpoint {
				t.Errorf("endpoint=%q want %q", endpoint, tt.wantEndpoint)
			}
			if opts.findMutation != tt.wantFindMut {
				t.Errorf("findMutation=%v want %v (cmd: %s)", opts.findMutation, tt.wantFindMut, tt.cmd)
			}
			if tt.wantFilters != nil {
				if len(opts.findFilters) != len(tt.wantFilters) {
					t.Errorf("findFilters=%v want %v", opts.findFilters, tt.wantFilters)
				} else {
					for i := range opts.findFilters {
						if opts.findFilters[i] != tt.wantFilters[i] {
							t.Errorf("findFilters[%d]=%q want %q", i, opts.findFilters[i], tt.wantFilters[i])
						}
					}
				}
			}
			if tt.wantParams != nil {
				if len(params) != len(tt.wantParams) {
					t.Errorf("params=%v want %v", params, tt.wantParams)
				} else {
					for i := range params {
						if params[i] != tt.wantParams[i] {
							t.Errorf("params[%d]=%q want %q", i, params[i], tt.wantParams[i])
						}
					}
				}
			}
		})
	}
}
