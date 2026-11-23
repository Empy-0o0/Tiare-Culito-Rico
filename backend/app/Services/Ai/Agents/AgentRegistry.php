<?php

namespace App\Services\Ai\Agents;

/** Catálogo cerrado de agentes disponibles. No se ejecuta nada fuera de esta lista. */
final class AgentRegistry
{
    /** @var array<string, Agent> */
    private array $agents = [];

    public function __construct(TenderIntelligenceAgent $tender, CostIntelligenceAgent $cost)
    {
        foreach ([$tender, $cost] as $agent) {
            $this->agents[$agent->id()] = $agent;
        }
    }

    public function find(string $id): ?Agent
    {
        return $this->agents[$id] ?? null;
    }

    /** @return array<string, Agent> */
    public function all(): array
    {
        return $this->agents;
    }
}
