<?php

namespace AG\ElasticApmLaravel\Contracts;

use AG\ElasticApmLaravel\Agent;
use Illuminate\Container\Container;
use Illuminate\Support\Collection;

interface DataCollector
{
    /*
     * This allows making a concrete Agent available to the collector after everything
     * is booted. The JobCollector class makes extensive use of the Agent, unlike other
     * collectors. I think long term, creating a separate "middleware" for jobs will
     * make make this unnecessary.
     */
    public function useAgent(Agent $agent): void;

    public function collect(): Collection;

    public function getName(): string;

    public static function registerEventListeners(Container $app): void;

    public function reset(): void;
}
