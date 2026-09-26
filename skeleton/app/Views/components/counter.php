<?php

/**
 * @file counter.php
 * @brief Template of App\Components\Counter.
 * @var int $count
 * @var int $max
 */
?>
<div class="counter">
    <strong><?= $count ?></strong> / <?= $max ?>
    <button type="button" data-action="increment" <?= $count >= $max ? 'disabled' : '' ?>>+1</button>
    <button type="button" data-action="reset">Reset</button>
</div>
