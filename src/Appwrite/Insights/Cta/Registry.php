<?php

namespace Appwrite\Insights\Cta;

use Appwrite\Extend\Exception;

class Registry
{
    /**
     * @var array<string, Action>
     */
    private array $actions = [];

    public function register(Action $action): void
    {
        $this->actions[$action->getName()] = $action;
    }

    public function has(string $name): bool
    {
        return isset($this->actions[$name]);
    }

    /**
     * Resolve an action by name.
     *
     * @throws Exception When the action is not registered.
     */
    public function get(string $name): Action
    {
        if (!isset($this->actions[$name])) {
            throw new Exception(Exception::INSIGHT_CTA_ACTION_NOT_REGISTERED, 'CTA action "' . $name . '" is not registered.');
        }

        return $this->actions[$name];
    }

    /**
     * @return array<string, Action>
     */
    public function all(): array
    {
        return $this->actions;
    }
}
