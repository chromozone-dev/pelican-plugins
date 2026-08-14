<?php

namespace Chromozone\ReverseProxy\Support;

use Chromozone\ReverseProxy\Enums\AuthMode;
use Chromozone\ReverseProxy\Enums\ProxyVariant;

class DriverStatus
{
    public function __construct(
        public readonly ProxyVariant $variant,
        public readonly AuthMode $authMode,
        public readonly ?string $version = null,
        public readonly int $certificateCount = 0,
    ) {}

    public function summary(): string
    {
        $summary = $this->variant->getLabel();

        if (filled($this->version)) {
            $summary .= ' ' . $this->version;
        }

        return $summary . sprintf(' - %s auth, %d certificate(s) available', $this->authMode->value, $this->certificateCount);
    }
}
