<?php
$id = 'cc000013-0000-4000-8000-000000000013';
$octet3 = (abs(crc32($id)) % 127) + 1;
$octet4Base = (abs(crc32(strrev($id))) % 63) + 1;
echo "id: $id\n";
echo "crc32: " . crc32($id) . "\n";
echo "abs: " . abs(crc32($id)) . "\n";
echo "octet3: $octet3\n";
echo "octet4Base: $octet4Base\n";
echo "gateway_ip: 172.$octet3.$octet4Base.1\n";
echo "network_cidr: 172.$octet3.$octet4Base.0/24\n";
