<?php

declare(strict_types=1);

namespace Jwhulette\Pipes\Transformers;

use Illuminate\Support\Collection;
use Jwhulette\Pipes\Contracts\TransformerInterface;
use Jwhulette\Pipes\DataTransferObjects\ConditionalDto;
use Jwhulette\Pipes\Frame;

final class ConditionalTransformer implements TransformerInterface
{
    /**
     * @var Collection<int,ConditionalDto>
     */
    protected Collection $conditionals;

    public function __construct()
    {
        $this->conditionals = new Collection();
    }

    public function __invoke(Frame $frame): Frame
    {
        $this->conditionals->each(function (ConditionalDto $item) use ($frame): void {
            /** @var Collection<string, string> $frameDataAsStringKeys */
            $frameDataAsStringKeys = $frame->data->mapWithKeys(function ($value, $key) {
                $stringValue = is_scalar($value) || is_null($value) || ($value instanceof \Stringable) ? (string) $value : '';

                return [(string) $key => $stringValue];
            });

            $diff = $item->match->diffAssoc($frameDataAsStringKeys);

            if ($diff->count() === 0) {
                $frame->data = $frame->data->replace($item->replace->toArray());
            }
        });

        return $frame;
    }

    /**
     * Add a conditional.
     *
     * @param array<string,string> $match Any associative array of keys to values to match against
     * @param array<string,string> $replace An associative array of keys to values to replace
     *
     * @return ConditionalTransformer
     */
    public function addConditional(array $match, array $replace): self
    {
        $condition = new ConditionalDto($match, $replace);

        $this->conditionals->push($condition);

        return $this;
    }
}
