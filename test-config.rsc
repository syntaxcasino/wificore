/ip service set api disabled=no port=8728
/ip service set ssh disabled=no port=22 address=""
/user add name=traidnet_user password="joPHHX1xfg6m" group=full
/ip firewall filter add chain=input protocol=tcp dst-port=22 action=accept place-before=0 comment="Allow SSH access"
/system identity set name="ggn-hsp-01"
/system note set note="Managed by Traidnet Solution LTD"
/interface wireguard add name=wg-b882d13d listen-port=51830 private-key="+IXRrvQdk7AO3IwOvZY4OQ4nlTFQ7dALcPdAmH/BpkY="
/ip address add address=10.100.1.1/32 interface=wg-b882d13d
/interface wireguard peers add interface=wg-b882d13d public-key="79EZlTBo190wG9xH+5ebUzwRzWT1X5yaabiqOvanW0A=" preshared-key="KRswbjid7pw7ePrKOFw00CJ1jPLvjkBFgMb6+6s9AA0=" endpoint-address=144.91.71.208 endpoint-port=51830 allowed-address=0.0.0.0/0 persistent-keepalive=00:00:25
/ip firewall filter add chain=input action=accept protocol=udp dst-port=51830 comment="Allow WireGuard VPN"
