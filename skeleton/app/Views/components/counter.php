<?php

/**
 * @file counter.php
 * @brief Template of App\Components\Counter.
 *
 * @var int $count
 * @var int $max
 */
?>
<div class="counter">
    <span class="counter__value"><?= $count ?></span>
    <span class="counter__limit">of <?= $max ?></span>
    <button type="button" class="btn btn--primary" data-action="increment" <?= $count >= $max ? 'disabled' : '' ?>>Add one</button>
    <button type="button" class="btn" data-action="reset">Reset</button>
</div>
