<?php

namespace App\Services\RouterDriver;

/**
 * Device Capabilities
 * 
 * Describes what features a router driver supports.
 */
readonly class DriverCapabilities
{
    /**
     * @param string $vendor Router vendor name
     * @param string $supportedModels Regex pattern or comma-separated list
     * @param bool $supportsPppoe PPPoE server support
     * @param bool $supportsHotspot Hotspot support
     * @param bool $supportsVlan VLAN support
     * @param bool $supportsCoA Change of Authorization support
     * @param bool $supportsRadius RADIUS integration
     * @param bool $supportsRestApi REST API available
     * @param bool $supportsSsh SSH access available
     * @param bool $supportsSnmp SNMP monitoring
     * @param bool $supportsApiSsl API-SSL support
     * @param array $supportedAuthMethods Authentication methods
     * @param string $maxFirmwareVersion Maximum tested firmware
     * @param string $minFirmwareVersion Minimum required firmware
     */
    public function __construct(
        public string $vendor,
        public string $supportedModels,
        public bool $supportsPppoe = true,
        public bool $supportsHotspot = true,
        public bool $supportsVlan = true,
        public bool $supportsCoA = false,
        public bool $supportsRadius = true,
        public bool $supportsRestApi = false,
        public bool $supportsSsh = true,
        public bool $supportsSnmp = true,
        public bool $supportsApiSsl = false,
        public array $supportedAuthMethods = ['pap', 'chap', 'mschap2'],
        public string $maxFirmwareVersion = '',
        public string $minFirmwareVersion = ''
    ) {}

    /**
     * Check if a specific model is supported
     */
    public function supportsModel(string $model): bool
    {
        $models = array_map('trim', explode(',', $this->supportedModels));
        
        foreach ($models as $pattern) {
            if (fnmatch($pattern, $model, FNM_CASEFOLD)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Convert to array for serialization
     */
    public function toArray(): array
    {
        return [
            'vendor' => $this->vendor,
            'supported_models' => $this->supportedModels,
            'supports_pppoe' => $this->supportsPppoe,
            'supports_hotspot' => $this->supportsHotspot,
            'supports_vlan' => $this->supportsVlan,
            'supports_coa' => $this->supportsCoA,
            'supports_radius' => $this->supportsRadius,
            'supports_rest_api' => $this->supportsRestApi,
            'supports_ssh' => $this->supportsSsh,
            'supports_snmp' => $this->supportsSnmp,
            'supports_api_ssl' => $this->supportsApiSsl,
            'supported_auth_methods' => $this->supportedAuthMethods,
            'max_firmware_version' => $this->maxFirmwareVersion,
            'min_firmware_version' => $this->minFirmwareVersion,
        ];
    }
}
