<?php

namespace ErnestDefoe\Docket\Api;

use Closure;

/**
 * Loading something for every model on a page at once. Each model's getter
 * returns a closure, which the API server resolves after every other getter;
 * the first of them to run loads it for all the models that waited.
 *
 * @template T of object
 */
class Deferred
{
    /** @var T[] */
    protected array $waiting = [];

    /**
     * @param T $model
     * @param callable(T[]): mixed $load loads for every waiting model
     * @param callable(): mixed $value the model's value, once loaded
     */
    public function later(object $model, callable $load, callable $value): Closure
    {
        $this->waiting[] = $model;

        return function () use ($load, $value) {
            if ($this->waiting) {
                $batch = $this->waiting;
                $this->waiting = [];
                $load($batch);
            }

            return $value();
        };
    }
}
