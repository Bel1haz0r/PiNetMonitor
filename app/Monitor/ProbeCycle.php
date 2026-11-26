<?php

namespace App\Monitor;

/** Everything one sampling cycle observed. Input to the classifier. */
final class ProbeCycle
{
    public function __construct(
        public readonly LinkSnapshot $link,
        public readonly ProbeGroup $gateway,
        public readonly ProbeGroup $externalIcmp,
        public readonly ProbeGroup $externalTcp,
        public readonly ProbeGroup $http,
        public readonly ProbeGroup $tls,
        public readonly ProbeGroup $dnsAssigned,
        public readonly ProbeGroup $dnsPublic,
        public readonly bool $captivePortalSuspected = false,
        public readonly bool $selfTestRunning = false,
    ) {}

    public function anyExternalReachability(): bool
    {
        return $this->externalIcmp->anySuccess()
            || $this->externalTcp->anySuccess()
            || $this->http->anySuccess()
            || $this->tls->anySuccess();
    }

    public function averageExternalRtt(): ?float
    {
        return $this->externalIcmp->averageRtt() ?? $this->externalTcp->averageRtt();
    }
}
