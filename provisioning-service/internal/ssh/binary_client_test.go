package ssh

import (
	"testing"
)

func TestTranslateRouterOSCommand_FindMutation(t *testing.T) {
	tests := []struct {
		name            string
		command         string
		wantEndpoint    string
		wantFindMut     bool
		wantFilters     []string
		wantParams      []string
		wantErr         bool
	}{
		{
			name:         "ppp profile set with find and extra param",
			command:      `/ppp profile set [/ppp profile find name="pppoe-prof-9d54fcf5"] interface-list="PA-9d54fcf5"`,
			wantEndpoint: "/ppp/profile/set",
			wantFindMut:  true,
			wantFilters:  []string{"?name=pppoe-prof-9d54fcf5"},
			wantParams:   []string{"interface-list=PA-9d54fcf5"},
		},
		{
			name:         "ppp profile set with find only",
			command:      `/ppp profile set [/ppp profile find name="pppoe-prof-02c48ed4"] rate-limit=""`,
			wantEndpoint: "/ppp/profile/set",
			wantFindMut:  true,
			wantFilters:  []string{"?name=pppoe-prof-02c48ed4"},
			wantParams:   []string{"rate-limit="},
		},
		{
			name:         "ppp profile set with find and multiple params",
			command:      `/ppp profile set [/ppp profile find name="pppoe-prof-9d54fcf5"] change-tcp-mss=yes use-compression=no only-one=yes`,
			wantEndpoint: "/ppp/profile/set",
			wantFindMut:  true,
			wantFilters:  []string{"?name=pppoe-prof-9d54fcf5"},
			wantParams:   []string{"change-tcp-mss=yes", "use-compression=no", "only-one=yes"},
		},
		{
			name:         "interface bridge set with find",
			command:      `/interface bridge set [/interface bridge find name="br-9d54fcf5"] protocol-mode="rstp"`,
			wantEndpoint: "/interface/bridge/set",
			wantFindMut:  true,
			wantFilters:  []string{"?name=br-9d54fcf5"},
			wantParams:   []string{"protocol-mode=rstp"},
		},
		{
			name:         "ip firewall filter remove with find",
			command:      `/ip firewall filter remove [/ip firewall filter find comment~="PPPoE-9d54fcf5"]`,
			wantEndpoint: "/ip/firewall/filter/remove",
			wantFindMut:  true,
			wantFilters:  []string{"?comment~=PPPoE-9d54fcf5"},
			wantParams:   []string{},
		},

		{
			name:         "escaped identity value from script source",
			command:      `/system identity set name=\"chr\";`,
			wantEndpoint: "/system/identity/set",
			wantFindMut:  false,
			wantFilters:  nil,
			wantParams:   []string{"name=chr"},
		},
		{
			name:         "escaped radius comment with spaces",
			command:      `/radius add service=\"ppp\" address=\"10.8.0.1\" comment=\"WiFiCore PPPoE (723504cd)\";`,
			wantEndpoint: "/radius/add",
			wantFindMut:  false,
			wantFilters:  nil,
			wantParams:   []string{"service=ppp", "address=10.8.0.1", "comment=WiFiCore PPPoE (723504cd)"},
		},
		{
			name:         "ppp profile set by cli name",
			command:      `/ppp profile set "pppoe-prof-723504cd" local-address="100.64.0.1" remote-address="pppoe-pool-723504cd"`,
			wantEndpoint: "/ppp/profile/set",
			wantFindMut:  true,
			wantFilters:  []string{"?name=pppoe-prof-723504cd"},
			wantParams:   []string{"local-address=100.64.0.1", "remote-address=pppoe-pool-723504cd"},
		},
		{
			name:         "ppp aaa set simple",
			command:      `/ppp aaa set use-radius=yes accounting=yes`,
			wantEndpoint: "/ppp/aaa/set",
			wantFindMut:  false,
			wantFilters:  nil,
			wantParams:   []string{"use-radius=yes", "accounting=yes"},
		},
		// PPPoE Generator Patterns
		{
			name:         "radius remove with find pattern",
			command:      `/radius remove [find service="ppp" comment~"WiFiCore PPPoE"]`,
			wantEndpoint: "/radius/remove",
			wantFindMut:  true,
			wantFilters:  []string{"?service=ppp", "?comment~=WiFiCore PPPoE"},
			wantParams:   nil,
		},
		{
			name:         "radius add with escaped quotes",
			command:      `/radius add service="ppp" address="10.8.0.1" secret="test123" comment="WiFiCore PPPoE (abc123)"`,
			wantEndpoint: "/radius/add",
			wantFindMut:  false,
			wantFilters:  nil,
			wantParams:   []string{"service=ppp", "address=10.8.0.1", "secret=test123", "comment=WiFiCore PPPoE (abc123)"},
		},
		{
			name:         "interface bridge port remove with find",
			command:      `/interface bridge port remove [find bridge="br-abc123"]`,
			wantEndpoint: "/interface/bridge/port/remove",
			wantFindMut:  true,
			wantFilters:  []string{"?bridge=br-abc123"},
			wantParams:   nil,
		},
		{
			name:         "interface bridge set with find by name",
			command:      `/interface bridge set [find name="br-abc123"] protocol-mode="rstp"`,
			wantEndpoint: "/interface/bridge/set",
			wantFindMut:  true,
			wantFilters:  []string{"?name=br-abc123"},
			wantParams:   []string{"protocol-mode=rstp"},
		},
		{
			name:         "firewall filter remove with comment regex",
			command:      `/ip firewall filter remove [find comment~"PPPoE-abc123"]`,
			wantEndpoint: "/ip/firewall/filter/remove",
			wantFindMut:  true,
			wantFilters:  []string{"?comment~=PPPoE-abc123"},
			wantParams:   nil,
		},
		{
			name:         "pppoe server remove by service name",
			command:      `/interface pppoe-server server remove [find service-name="pppoe-svc-abc123"]`,
			wantEndpoint: "/interface/pppoe-server/server/remove",
			wantFindMut:  true,
			wantFilters:  []string{"?service-name=pppoe-svc-abc123"},
			wantParams:   nil,
		},
		{
			name:         "ip service set by find name",
			command:      `/ip service set [find name="api"] disabled=no address="10.8.0.0/24"`,
			wantEndpoint: "/ip/service/set",
			wantFindMut:  true,
			wantFilters:  []string{"?name=api"},
			wantParams:   []string{"disabled=no", "address=10.8.0.0/24"},
		},
		{
			name:         "ip pool add simple",
			command:      `/ip pool add name="pppoe-pool-abc123" ranges="10.0.0.10-10.0.0.250"`,
			wantEndpoint: "/ip/pool/add",
			wantFindMut:  false,
			wantFilters:  nil,
			wantParams:   []string{"name=pppoe-pool-abc123", "ranges=10.0.0.10-10.0.0.250"},
		},
		{
			name:         "interface list member add",
			command:      `/interface list member add list="PL-abc123" interface="br-abc123"`,
			wantEndpoint: "/interface/list/member/add",
			wantFindMut:  false,
			wantFilters:  nil,
			wantParams:   []string{"list=PL-abc123", "interface=br-abc123"},
		},
		{
			name:         "log command skipped by binary api",
			command:      `/log info "PPPoE-abc123-START"`,
			wantEndpoint: "",
			wantFindMut:  false,
			wantFilters:  nil,
			wantParams:   nil,
			wantErr:      true,
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			endpoint, params, opts, err := translateRouterOSCommand(tt.command)

			if (err != nil) != tt.wantErr {
				t.Errorf("translateRouterOSCommand() error = %v, wantErr %v", err, tt.wantErr)
				return
			}
			if endpoint != tt.wantEndpoint {
				t.Errorf("endpoint = %q, want %q", endpoint, tt.wantEndpoint)
			}
			if opts.findMutation != tt.wantFindMut {
				t.Errorf("findMutation = %v, want %v", opts.findMutation, tt.wantFindMut)
			}
			if len(opts.findFilters) != len(tt.wantFilters) {
				t.Errorf("findFilters = %v, want %v", opts.findFilters, tt.wantFilters)
			} else {
				for i := range opts.findFilters {
					if opts.findFilters[i] != tt.wantFilters[i] {
						t.Errorf("findFilters[%d] = %q, want %q", i, opts.findFilters[i], tt.wantFilters[i])
					}
				}
			}
			if len(params) != len(tt.wantParams) {
				t.Errorf("params = %v, want %v", params, tt.wantParams)
			} else {
				for i := range params {
					if params[i] != tt.wantParams[i] {
						t.Errorf("params[%d] = %q, want %q", i, params[i], tt.wantParams[i])
					}
				}
			}
		})
	}
}

func TestExtractFindFilters(t *testing.T) {
	tests := []struct {
		input    string
		expected []string
	}{
		{
			`[/ppp profile find name="pppoe-prof-9d54fcf5"]`,
			[]string{"?name=pppoe-prof-9d54fcf5"},
		},
		{
			`[/ip firewall filter find comment~="PPPoE-9d54fcf5"]`,
			[]string{"?comment~=PPPoE-9d54fcf5"},
		},
		{
			`[/interface bridge find name="br-02c48ed4"]`,
			[]string{"?name=br-02c48ed4"},
		},
		{
			`[/ppp profile find where name="pppoe-prof-8a6c1687"]`,
			[]string{"?name=pppoe-prof-8a6c1687"},
		},
	}

	for _, tt := range tests {
		t.Run(tt.input, func(t *testing.T) {
			result := extractFindFilters(tt.input)
			if len(result) != len(tt.expected) {
				t.Errorf("extractFindFilters(%q) = %v, want %v", tt.input, result, tt.expected)
				return
			}
			for i := range result {
				if result[i] != tt.expected[i] {
					t.Errorf("extractFindFilters(%q)[%d] = %q, want %q", tt.input, i, result[i], tt.expected[i])
				}
			}
		})
	}
}
