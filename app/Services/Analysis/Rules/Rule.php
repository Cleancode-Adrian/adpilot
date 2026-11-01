<?php

namespace App\Services\Analysis\Rules;

use App\Services\Analysis\RuleContext;

abstract class Rule
{
    abstract public function key(): string;

    /** @return iterable<array> lista ustaleń (findings) */
    abstract public function evaluate(RuleContext $ctx): iterable;

    protected function finding(array $data): array
    {
        return array_merge([
            'rule_key' => $this->key(),
            'kind' => 'problem',
            'entity_type' => null,
            'entity_id' => null,
            'entity_name' => null,
            'impact' => null,
            'data' => null,
        ], $data);
    }
}
