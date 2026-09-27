<?php

declare(strict_types=1);

namespace Core\Scheduling;

/**
 * @file ScheduledTask.php
 * @brief A method marked with #[Schedule].
 */

/**
 * @class ScheduledTask
 * @brief Identifies a scheduled method and its schedule.
 */
final class ScheduledTask
{
    /**
     * @brief ScheduledTask constructor.
     *
     * @param class-string $class Declaring class.
     * @param string $method Method name.
     * @param Schedule $schedule Schedule definition.
     */
    public function __construct(
        public readonly string $class,
        public readonly string $method,
        public readonly Schedule $schedule
    ) {
    }

    /**
     * @brief Returns the task key used for locking and state ("Class::method").
     *
     * @return string
     */
    public function key(): string
    {
        return self::keyFor($this->class, $this->method);
    }

    /**
     * @brief Builds the task key of a method.
     *
     * @param string $class Class name.
     * @param string $method Method name.
     * @return string
     */
    public static function keyFor(string $class, string $method): string
    {
        return ltrim($class, '\\') . '::' . $method;
    }
}
